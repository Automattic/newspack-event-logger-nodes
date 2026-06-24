<?php
/**
 * Admin: application-side WP-Settings-API surface and per-option granular
 * worker-restart on save.
 *
 * Owns ONLY the application-level options:
 *   - enable_logging
 *   - log_urls / skip_urls / log_events / custom_events
 *   - enable_jobs
 *   - significant_events
 *   - auto_disable_threshold / auto_protect_time_threshold
 *   - log_memory / flush_every_line
 *
 * Substrate-level options (base_directory, partitioning, memcache_servers,
 * enable_workers) live on `\Newspack_Nodes\Admin\Admin` under the
 * `newspack_nodes_*` prefix. This class may READ substrate values via
 * `\Newspack_Nodes\Config` but must NOT WRITE them. The aggregator spoke
 * list is owned by the substrate `\Newspack_Nodes\Vault`, not here.
 *
 * Settings group / option-prefix:
 *   - Settings group:     `newspack_event_logger_nodes_options_group`
 *   - Settings page slug: `newspack_event_logger_nodes`
 *   - Menu page slug:     `newspack-event-logger-nodes`
 *   - Option prefix:      `newspack_event_logger_nodes_`
 *
 * Per-option worker-restart classification preserved for application options
 * only; substrate options classify on substrate Admin.
 *
 * Does NOT mount React dashboards — that wiring stays in the main plugin file's
 * `admin_enqueue_scripts` hook. This class owns the WP-Settings-API surface
 * only: the `/options-general.php?page=newspack-event-logger-nodes`
 * settings page.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Admin;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\Settings_Schema;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\CLI;
use Newspack_Nodes\Config as RuntimeConfig;
use Newspack_Nodes\Config_System\Field_Reset_Assets;
use Newspack_Nodes\Config_System\Reset_Gate;
use Newspack_Nodes\Config_System\Restart_Planner;
use Newspack_Nodes\Config_System\Settings_Renderer;

\defined( 'ABSPATH' ) || exit;

/**
 * Admin settings page.
 */
class Admin {

	/**
	 * Settings group registered with `register_setting()`. WordPress uses this
	 * to scope nonce verification and validation when the form posts to
	 * `options.php`.
	 */
	public const OPTIONS_GROUP = 'newspack_event_logger_nodes_options_group';

	/**
	 * Settings page slug used by `add_settings_section/field()` and
	 * `do_settings_sections()`. Distinct from the menu-page slug below.
	 */
	public const SETTINGS_PAGE = 'newspack_event_logger_nodes';

	/**
	 * Menu page slug used by `add_options_page()` (the URL fragment after
	 * `?page=`).
	 */
	public const MENU_SLUG = 'newspack-event-logger-nodes';

	/**
	 * WP-option name prefix. All admin-managed options live under this prefix.
	 * Worker-restart classification (see `maybe_request_worker_restart`) keys
	 * off it.
	 */
	public const OPTION_PREFIX = 'newspack_event_logger_nodes_';

	/**
	 * Nonce action / field name for the reset-to-defaults form.
	 */
	public const RESET_ACTION = 'newspack_event_logger_nodes_reset_settings';
	public const RESET_NONCE  = 'newspack_event_logger_nodes_reset_nonce';

	/** Hidden-input array name carrying per-field reset marks ({option} => "1"). */
	public const RESET_MARK_FIELD = 'newspack_event_logger_nodes_reset';

	/**
	 * Nonce action / field name for the flush-memcache-stats form.
	 */
	public const FLUSH_STATS_ACTION = 'newspack_event_logger_nodes_flush_stats';
	public const FLUSH_STATS_NONCE  = 'newspack_event_logger_nodes_flush_stats_nonce';

	/**
	 * Application-level option names cleared by `handle_reset_settings()`.
	 *
	 * Substrate-level options (base_directory, num_partitions, num_segments,
	 * segment_size, max_lifespan, memcache_servers, enable_workers) live on
	 * `\Newspack_Nodes\Admin\Admin` and reset via its own form. Application
	 * admin only owns the keys below. (The aggregator spoke list is NOT here —
	 * it's owned by the substrate `\Newspack_Nodes\Vault` and managed by its
	 * `vault` REST CRUD, not the settings form, so the reset doesn't touch it.)
	 *
	 * Derived from the single Settings_Schema in `register_settings()` (the
	 * `setting_option_names()` set). Kept as a property of this exact name because
	 * AdminTest reads it by reflection and `handle_reset_settings()` reads it as
	 * the base reset list (extendable via the `…_reset_options` filter). EVERY
	 * settings-form option (booleans + multi-selects included) appears here so a
	 * reset clears them all and the shared `Reset_Gate` attaches its gate to each.
	 * (The aggregator spoke list is excluded — owned by the substrate
	 * `\Newspack_Nodes\Vault` and managed by its `vault` REST CRUD, not a
	 * settings-form field.)
	 *
	 * @var array<int, string>
	 */
	private static array $option_names = [];

	/**
	 * Text-like keys that get a `pre_update_option_{$option}` delete-on-blank
	 * filter: a blank submission (or `↺` field-reset) deletes the row so the file
	 * default resurfaces under presence-based Config, instead of storing '' (which
	 * would override the default). The scalar subset of $option_names — checkbox
	 * bools and multi-select arrays are EXCLUDED (an unchecked box / empty
	 * selection is a real override). Derived from the Schema's
	 * `delete_on_blank_options()` in `register_settings()`.
	 *
	 * @var array<int, string>
	 */
	private static array $delete_on_blank_options = [];

	public function __construct() {
		\add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
		\add_action( 'admin_init', [ $this, 'register_settings' ] );
		\add_action( 'admin_post_' . self::RESET_ACTION, [ $this, 'handle_reset_settings' ] );
		\add_action( 'admin_post_' . self::FLUSH_STATS_ACTION, [ $this, 'handle_flush_stats' ] );
		\add_action( 'newspack_event_logger_nodes/settings_after_form', [ $this, 'render_effective_config_section' ] );
		\add_action( 'newspack_event_logger_nodes/settings_after_form', [ $this, 'render_maintenance_section' ] );

		// Per-option granular worker-restart on save. Both `added_option` (first
		// save) and `updated_option` (subsequent saves) fire this so newly-added
		// options trigger the right restart class too.
		\add_action( 'updated_option', [ $this, 'maybe_request_worker_restart' ], 10, 1 );
		\add_action( 'added_option', [ $this, 'maybe_request_worker_restart' ], 10, 1 );

		// Skip writing a WP option when the value matches the config-file
		// default — keeps the options table clean and lets file-side changes
		// actually take effect instead of being shadowed by a stale stored
		// copy of the old default. Strict comparison so a bool-defaulted
		// option (`enable_logging: true`) doesn't trip on the absint-int form
		// of a user-saved "on" (`1 != true` is false under loose comparison
		// but the user definitely wants the value written).
		\add_filter( 'pre_update_option', [ $this, 'skip_default_writes' ], 10, 3 );
	}

	// -- Field callbacks ----------------------------------------------------

	public static function enable_logging_callback(): void {
		self::render_checkbox(
			'enable_logging',
			\__( 'Enable event logging', 'newspack-event-logger-nodes' ),
			1
		);
	}

	/**
	 * Echo a boolean checkbox via the shared Settings_Renderer: checked from the
	 * stored option (file-default fallback), the `data-nn-reset-default` hint from
	 * the file default, all under the per-field reset wrapper.
	 *
	 * @param string $field        Option short-name (no prefix).
	 * @param string $label        Visible label text.
	 * @param int    $hard_default 0/1 fallback when neither WP option nor file default exists.
	 */
	private static function render_checkbox( string $field, string $label, int $hard_default = 0 ): void {
		$html = Settings_Renderer::checkbox(
			$field,
			self::OPTION_PREFIX . $field,
			1 === self::bool_option_with_file_default( $field, $hard_default ),
			1 === self::bool_file_default( $field, $hard_default ),
			$label,
			self::reset_mark_name( $field )
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Settings_Renderer escapes every field.
	}

	/**
	 * Resolve a boolean toggle's display value, preferring the WP options row
	 * but falling back to the file-config default (not `get_option`'s hard-coded
	 * fallback) when the row is missing. Required because `skip_default_writes`
	 * deletes the WP option whenever the user saves a value that matches the
	 * file default — without this fallback, every box that mirrors a file
	 * default would render unchecked despite the file saying otherwise.
	 *
	 * @param string $short_key    Option key without the `newspack_event_logger_nodes_` prefix.
	 * @param int    $hard_default 0 or 1 — used only if both the WP option AND the file
	 *                              default are absent (e.g., on a brand-new install
	 *                              with no config-file override for this key).
	 */
	private static function bool_option_with_file_default( string $short_key, int $hard_default = 0 ): int {
		$file_default = self::bool_file_default( $short_key, $hard_default );
		$stored       = \get_option(
			"newspack_event_logger_nodes_{$short_key}",
			$file_default
		);
		return \is_numeric( $stored ) ? (int) $stored : 0;
	}

	/**
	 * The file-config default for a boolean toggle as 0/1 — what a reset
	 * restores (reset deletes the option row, resurfacing this value). Used by
	 * the `data-nn-reset-default` attribute so the reset-toggle JS previews the
	 * real default state, NOT the current stored value.
	 *
	 * @param string $short_key    Option key without the `newspack_event_logger_nodes_` prefix.
	 * @param int    $hard_default 0 or 1 — used only if the file default is absent.
	 */
	private static function bool_file_default( string $short_key, int $hard_default = 0 ): int {
		$defaults = Config::load_config_defaults();
		return \array_key_exists( $short_key, $defaults )
			? self::bool_to_int( $defaults[ $short_key ] )
			: $hard_default;
	}

	/**
	 * The single bool→int 0/1 coercion shared by the checkbox render path
	 * (`bool_file_default`) and the default-write skip (`skip_default_writes`).
	 *
	 * @param mixed $value Any value (only bools are coerced; numerics pass via (int)(bool)).
	 */
	private static function bool_to_int( $value ): int {
		return (int) (bool) $value;
	}

	// -- Private renderers --------------------------------------------------

	/** Hidden-input name that flags $field for per-field reset (deleted on Save). */
	private static function reset_mark_name( string $field ): string {
		return Reset_Gate::mark_name( self::RESET_MARK_FIELD, self::OPTION_PREFIX . $field );
	}

	// ---- Debugging field callbacks ---------------------------------------

	public static function log_memory_callback(): void {
		self::render_checkbox(
			'log_memory',
			\__( 'Append peak_mb to every complete() log entry so memory growth is visible across the request timeline.', 'newspack-event-logger-nodes' )
		);
	}

	public static function flush_every_line_callback(): void {
		self::render_checkbox(
			'flush_every_line',
			\__( 'Flush write buffer after every log line. Survives OOM kills — last line before crash is preserved on disk. Trades throughput for crash survivability.', 'newspack-event-logger-nodes' )
		);
	}

	// ---- Instrumentation field callbacks ---------------------------------

	public static function log_urls_callback(): void {
		$stored = \get_option( 'newspack_event_logger_nodes_log_urls', [] );
		$values = self::normalize_string_list( $stored );
		self::render_array_field(
			'log_urls',
			$values,
			[],
			\__( 'Only log URLs containing these substrings. Leave empty to log all requests.', 'newspack-event-logger-nodes' ),
			'/calendar, /events/, article.fcgi'
		);
	}

	/**
	 * Normalize an option that may be either a list of strings, an assoc array
	 * (with hook names as keys), or null/false (option missing) into a flat,
	 * deduplicated list of strings. Used by the array-field callbacks to feed
	 * the React TagInputField, which expects a JSON-encoded list of strings.
	 *
	 * @param mixed $stored Raw option value.
	 * @return array<int,string>
	 */
	private static function normalize_string_list( $stored ): array {
		if ( ! \is_array( $stored ) ) {
			return [];
		}
		$out = [];
		foreach ( $stored as $k => $v ) {
			$candidate = \is_string( $v ) && '' !== $v ? $v : ( \is_string( $k ) ? $k : null );
			if ( null === $candidate ) {
				continue;
			}
			$candidate = \trim( $candidate );
			if ( '' !== $candidate ) {
				$out[] = $candidate;
			}
		}
		return \array_values( \array_unique( $out ) );
	}

	/**
	 * Render a tag-input field for array settings — the mount markup the
	 * React `TagInputField` tree (built into `build/performance-logger/index.js`)
	 * looks for at `#event-logger-{$field}`. The hidden input named
	 * `newspack_event_logger_nodes_{$field}` carries the JSON-encoded value
	 * back to PHP on save.
	 *
	 * @param string         $field       Field name (without prefix).
	 * @param array<string>  $values      Current values (flat list of strings).
	 * @param array<string>  $default     Default values for the Reset button.
	 * @param string         $description Field description.
	 * @param string         $examples    Optional example values.
	 */
	private static function render_array_field( string $field, array $values, array $default, string $description, string $examples = '' ): void {
		$html = Settings_Renderer::react_mount(
			$field,
			'event-logger-' . $field,
			'event-logger-tag-input',
			self::OPTION_PREFIX . $field,
			\wp_json_encode( $values ) ?: '',
			\wp_json_encode( $default ) ?: '',
			$description,
			self::reset_mark_name( $field )
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Settings_Renderer escapes every field.
		if ( '' !== $examples ) {
			echo '<p class="description">'
				. \esc_html__( 'Examples:', 'newspack-event-logger-nodes' ) . ' '
				. \esc_html( $examples ) . '</p>';
		}
	}

	public static function skip_urls_callback(): void {
		// Defaults are the operator-shipped values in the config FILE, not
		// the merged-with-WP-options view — clicking "Reset to default" on
		// a field that the operator just modified should restore the file
		// value, not the value they just typed.
		$defaults = Config::load_config_defaults();
		$default  = self::normalize_string_list( $defaults['skip_urls'] ?? [] );
		$stored   = \get_option( 'newspack_event_logger_nodes_skip_urls', null );
		$values   = ( null === $stored || false === $stored )
			? $default
			: self::normalize_string_list( $stored );
		self::render_array_field(
			'skip_urls',
			$values,
			$default,
			\__( 'Never log URLs containing these substrings. Checked first — always wins over Log URLs.', 'newspack-event-logger-nodes' ),
			'/wp-cron.php, /wp-admin/admin-ajax.php'
		);
	}

	public static function log_events_callback(): void {
		$defaults = Config::load_config_defaults();
		// Reset chip restores the file-default `log_events` value (not
		// `recommended_log_events` — that's a separate config key that
		// drives the Select Hooks modal's highlight, not the runtime list).
		$default  = self::normalize_string_list( $defaults['log_events'] ?? [] );
		$stored   = \get_option( 'newspack_event_logger_nodes_log_events', [] );
		$values   = self::normalize_string_list( $stored );
		self::render_array_field(
			'log_events',
			$values,
			$default,
			\__( 'Hooks to time. Use Select Hooks to browse the registered set, or Reset to restore the file default.', 'newspack-event-logger-nodes' ),
			''
		);
	}

	public static function custom_events_callback(): void {
		// custom_events is stored as assoc array; the React tree expects a flat
		// list of strings (the keys).
		$stored = \get_option( 'newspack_event_logger_nodes_custom_events', [] );
		$values = \is_array( $stored ) ? \array_keys( $stored ) : [];
		$values = self::normalize_string_list( $values );
		self::render_array_field(
			'custom_events',
			$values,
			[],
			\__( 'Custom events to time. Use Select Events to choose from the registered custom-event registry.', 'newspack-event-logger-nodes' ),
			''
		);
	}

	// ---- Workers field callbacks -----------------------------------------

	public static function significant_events_callback(): void {
		$stored = \get_option( 'newspack_event_logger_nodes_significant_events', [] );
		$values = self::normalize_string_list( $stored );
		\sort( $values, SORT_NATURAL | SORT_FLAG_CASE );
		self::render_array_field(
			'significant_events',
			$values,
			[],
			\__( 'Events/hooks that exceeded the time threshold at least once. Protected from auto-disable. Remove to allow auto-disabling.', 'newspack-event-logger-nodes' ),
			''
		);
	}

	public function render_settings_page(): void {
		if ( ! self::current_user_allowed() ) {
			\wp_die( \esc_html__( 'You do not have permission to access this page.', 'newspack-event-logger-nodes' ) );
		}
		$reset_url = \function_exists( 'admin_url' )
			? \admin_url( 'admin-post.php' )
			: '/wp-admin/admin-post.php';
		?>
		<div class="wrap event-logger-settings-wrap newspack-nodes-theme">
			<h1><?php \esc_html_e( 'Event Logger Settings', 'newspack-event-logger-nodes' ); ?></h1>
			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag.
			if ( isset( $_GET['flushed'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$restarted = isset( $_GET['restarted'] ) && \is_numeric( $_GET['restarted'] ) ? (int) $_GET['restarted'] : 0;
				echo '<div class="notice notice-success is-dismissible"><p>'
					. \esc_html(
						\sprintf(
							/* translators: %d = number of workers restarted. */
							\_n(
								'Cache flushed. %d worker restart requested — fresh prefix takes effect on its next graceful exit.',
								'Cache flushed. %d workers restart requested — fresh prefix takes effect on their next graceful exit.',
								$restarted,
								'newspack-event-logger-nodes'
							),
							$restarted
						)
					) . '</p></div>';
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['reset'] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>'
					. \esc_html__( 'Settings reset to defaults.', 'newspack-event-logger-nodes' )
					. '</p></div>';
			}
			?>
			<form method="post" action="options.php">
				<?php
				\settings_fields( self::OPTIONS_GROUP );
				\do_settings_sections( self::SETTINGS_PAGE );
				?>
				<p class="submit">
					<?php \submit_button( \__( 'Save Settings', 'newspack-event-logger-nodes' ), 'primary', 'submit', false ); ?>
					<span style="display:inline-block; margin-left: 10px;">
						<input type="button" class="button button-secondary"
							value="<?php \esc_attr_e( 'Reset to Defaults', 'newspack-event-logger-nodes' ); ?>"
							onclick="if ( confirm( '<?php echo \esc_js( \__( 'Are you sure you want to reset all settings to defaults? This cannot be undone.', 'newspack-event-logger-nodes' ) ); ?>' ) ) { document.getElementById( 'newspack-event-logger-nodes-reset-form' ).submit(); }" />
					</span>
				</p>
			</form>
			<form id="newspack-event-logger-nodes-reset-form" method="post" action="<?php echo \esc_url( $reset_url ); ?>" style="display:none;">
				<input type="hidden" name="action" value="<?php echo \esc_attr( self::RESET_ACTION ); ?>">
				<?php \wp_nonce_field( self::RESET_ACTION, self::RESET_NONCE ); ?>
			</form>
			<?php
			// Allow child plugins (Performance, Aggregator, etc.) to inject sections
			// below the form. Matches the legacy plugin's
			// `newspack_event_logger_settings_after_form` hook.
			\do_action( 'newspack_event_logger_nodes/settings_after_form' );
			Field_Reset_Assets::enqueue();
			echo Field_Reset_Assets::highlight_style(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static CSS literal.
			?>
		</div>
		<?php
	}

	/**
	 * Permission gate: `manage_options` baseline + optional `allowed_users`
	 * whitelist from Config.
	 *
	 * Empty `allowed_users` means "all users with manage_options". When the
	 * whitelist is populated, the current user's `user_login` must be a member.
	 * This is intentional — manage_options is required even for whitelisted
	 * users, so a demoted account loses access immediately without needing the
	 * whitelist updated.
	 *
	 * @return bool True if user is allowed.
	 */
	public static function current_user_allowed(): bool {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return false;
		}

		$config        = Config::load_config();
		$allowed_users = $config['allowed_users'] ?? [];
		if ( empty( $allowed_users ) || ! \is_array( $allowed_users ) ) {
			return true;
		}

		if ( ! \function_exists( 'wp_get_current_user' ) ) {
			return true; // CLI / no user context — don't lock out admins running CLI tools.
		}
		$current_user = \wp_get_current_user();
		return \in_array( $current_user->user_login, $allowed_users, true );
	}

	/**
	 * `pre_update_option` filter: skip writing options whose value still equals the default.
	 *
	 * @param mixed  $value     New option value about to be written.
	 * @param string $option    Full option name.
	 * @param mixed  $old_value Current stored value.
	 * @return mixed The value to persist (unchanged unless short-circuited).
	 */
	public function skip_default_writes( mixed $value, string $option, mixed $old_value ): mixed {
		$key = \substr( $option, \strlen( 'newspack_event_logger_nodes_' ) );
		if ( $key === $option || '' === $key ) {
			return $value;
		}
		$defaults = Config::load_config_defaults();
		if ( ! \array_key_exists( $key, $defaults ) ) {
			return $value;
		}
		// Normalize bool defaults (`true`/`false` in config files) to int —
		// our bool-typed sanitize_callbacks (`absint`) always produce int
		// 0/1, so without this the strict compare never matches a bool
		// default and the filter is a no-op for every toggle. Other types
		// (int, string, array) are compared as-is.
		$default = $defaults[ $key ];
		if ( \is_bool( $default ) ) {
			$default = self::bool_to_int( $default );
		}
		if ( $value !== $default ) {
			return $value;
		}
		// User is actually changing the stored value back to the default —
		// drop the row so the file default kicks in next read.
		if ( $value !== $old_value ) {
			\delete_option( $option );
		}
		return $old_value;
	}

	/**
	 * Top-level "Event Logger" page + Settings submenu under Settings.
	 *
	 * The top-level menu is gated through the existing `admin_menu` hook in
	 * the main plugin file (which mounts dashboards as submenus). This callback
	 * adds ONLY the Settings submenu under the standard Settings menu, matching
	 * the legacy plugin's dual-mount pattern (top-level for dashboards, Settings
	 * menu for configuration).
	 */
	public function add_admin_menu(): void {
		if ( ! self::current_user_allowed() ) {
			return;
		}
		if ( ! \function_exists( 'add_options_page' ) ) {
			return;
		}
		\add_options_page(
			\__( 'Event Logger Settings', 'newspack-event-logger-nodes' ),
			\__( 'Event Logger', 'newspack-event-logger-nodes' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Sanitize the `custom_events` option.
	 *
	 * Stored as an associative array `[ hook_name => true, ... ]` for cheap
	 * `isset()` lookups in `is_enabled()`. The React tree posts a flat list of
	 * hook names (or a JSON-encoded list); convert to the assoc form.
	 *
	 * Already-assoc input is preserved (idempotent on re-saves).
	 *
	 * @param mixed $value List of hook names, JSON, or assoc array.
	 * @return array<string,bool> Assoc map keyed by hook name, values `true`.
	 */
	public static function sanitize_custom_events( $value ): array {
		// Detect assoc-shaped input (idempotent path on re-save).
		if ( \is_array( $value ) ) {
			$first_key = \array_key_first( $value );
			if ( null !== $first_key && \is_string( $first_key ) && ! \is_numeric( $first_key ) ) {
				$out = [];
				foreach ( $value as $k => $_v ) {
					$key = \trim( \sanitize_text_field( (string) $k ) );
					if ( '' !== $key ) {
						$out[ $key ] = true;
					}
				}
				return $out;
			}
		}

		$names = self::sanitize_array_strings( $value );
		$out   = [];
		foreach ( $names as $name ) {
			$out[ $name ] = true;
		}
		return $out;
	}

	/**
	 * Sanitize an array of strings.
	 *
	 * Used for `log_urls`, `skip_urls`, `log_events`, `significant_events`.
	 * The React TagInputField posts a JSON-encoded array via a hidden input,
	 * so this accepts BOTH array input (programmatic / direct WP form posts)
	 * AND JSON-encoded strings (the React tree's post format).
	 *
	 * Coerces non-string scalars to string, drops anything non-scalar,
	 * trims whitespace, and strips empty values. Values are reindexed so
	 * downstream callers don't see gaps from filter().
	 *
	 * @param mixed $value Array, JSON string, or other (treated as empty).
	 * @return array<string> Sanitized list of strings (zero-indexed).
	 */
	public static function sanitize_array_strings( mixed $value ): array {
		// React tree posts JSON via a hidden input — decode first.
		if ( \is_string( $value ) ) {
			$trimmed = \trim( $value );
			if ( '' === $trimmed ) {
				return [];
			}
			$decoded = \json_decode( $trimmed, true, 32 );
			if ( \is_array( $decoded ) ) {
				$value = $decoded;
			} else {
				// Try unslashing — WordPress slashes form input by default.
				$decoded = \json_decode( \wp_unslash( $trimmed ), true, 32 );
				if ( \is_array( $decoded ) ) {
					$value = $decoded;
				} else {
					// Fallback: treat as a newline-separated list (legacy textarea
					// shape). Not used by React, but keeps direct CLI/WP-CLI
					// callers happy.
					$value = \explode( "\n", $trimmed );
				}
			}
		}
		if ( ! \is_array( $value ) ) {
			return [];
		}

		$result = [];
		foreach ( $value as $v ) {
			if ( ! \is_scalar( $v ) ) {
				continue;
			}
			$s = \trim( \sanitize_text_field( (string) $v ) );
			if ( '' !== $s ) {
				$result[] = $s;
			}
		}
		return \array_values( \array_unique( $result ) );
	}

	/**
	 * Combined "Auto-Tune" field — both thresholds inline on one row, mirroring
	 * the legacy newspack-performance-workers UI. Storage stays as two separate
	 * options; this is purely a layout consolidation.
	 *
	 * Layout: .event-logger-auto-disable-row flexbox + .event-logger-auto-disable-label
	 * spans (defined in src/performance-logger/styles/settings.scss).
	 */
	public static function auto_tune_callback(): void {
		$count_value   = \get_option( 'newspack_event_logger_nodes_auto_disable_threshold', '' );
		$time_value    = \get_option( 'newspack_event_logger_nodes_auto_protect_time_threshold', '' );
		// Hide a stored 0 so the placeholder ("0") shows through — operators
		// reading "Disable if count > 0" without seeing "0" as a value
		// understand it's disabled rather than "any count > 0".
		$count_display = ( '' === $count_value || 0 === ( \is_numeric( $count_value ) ? (int) $count_value : 0 ) ) ? '' : $count_value;
		$time_display  = ( '' === $time_value || 0.0 === ( \is_numeric( $time_value ) ? (float) $time_value : 0.0 ) ) ? '' : $time_value;
		?>
		<div style="display: flex; align-items: flex-start; gap: 10px;" data-nn-reset="<?php echo \esc_attr( self::reset_mark_name( 'auto_disable_threshold' ) ); ?>">
			<div style="flex: 1;">
				<div class="event-logger-auto-disable-row">
					<label class="event-logger-auto-disable-label">
						<?php \esc_html_e( 'Disable if count >', 'newspack-event-logger-nodes' ); ?>
						<input type="number" id="auto_disable_threshold"
							name="newspack_event_logger_nodes_auto_disable_threshold"
							value="<?php echo \esc_attr( \is_scalar( $count_display ) ? (string) $count_display : '' ); ?>"
							min="0" max="10000"
							class="small-text" placeholder="0" />
					</label>
					<label class="event-logger-auto-disable-label">
						<?php \esc_html_e( 'Protect if avg >=', 'newspack-event-logger-nodes' ); ?>
						<input type="number" id="auto_protect_time_threshold"
							name="newspack_event_logger_nodes_auto_protect_time_threshold"
							value="<?php echo \esc_attr( \is_scalar( $time_display ) ? (string) $time_display : '' ); ?>"
							min="0" max="1000" step="0.1"
							class="small-text" placeholder="0" />
						<?php \esc_html_e( 'ms', 'newspack-event-logger-nodes' ); ?>
					</label>
				</div>
				<p class="description"><?php \esc_html_e( 'Noisy events (count > N) get disabled. Significant events (avg >= M ms) are protected. Set to 0 to disable.', 'newspack-event-logger-nodes' ); ?></p>
			</div>
			<button type="button" class="button button-secondary" data-nn-reset-toggle
				title="<?php \esc_attr_e( 'Reset to default (toggle, then Save)', 'newspack-event-logger-nodes' ); ?>">↺</button>
		</div>
		<?php
	}

	/**
	 * Reset-to-defaults handler — admin-post target.
	 *
	 * Nonce + permission checks before deleting any options.
	 */
	public function handle_reset_settings(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$nonce = isset( $_POST[ self::RESET_NONCE ] ) && \is_string( $_POST[ self::RESET_NONCE ] ) ? \sanitize_text_field( \wp_unslash( $_POST[ self::RESET_NONCE ] ) ) : '';
		if ( '' === $nonce || ! \wp_verify_nonce( $nonce, self::RESET_ACTION ) ) {
			\wp_die( \esc_html__( 'Security check failed.', 'newspack-event-logger-nodes' ) );
		}
		if ( ! self::current_user_allowed() ) {
			\wp_die( \esc_html__( 'You do not have permission to perform this action.', 'newspack-event-logger-nodes' ) );
		}

		// Derive the base reset list from the Schema so this handler works even
		// when invoked before `register_settings()` populated the cached property.
		$options = Settings_Schema::get()->setting_option_names();

		foreach ( $options as $option ) {
			if ( \str_starts_with( $option, self::OPTION_PREFIX ) ) {
				\delete_option( $option );
			}
		}

		$redirect = \function_exists( 'admin_url' )
			? \add_query_arg(
				[
					'page'  => self::MENU_SLUG,
					'reset' => '1',
				],
				\admin_url( 'options-general.php' )
			)
			: '';
		if ( '' !== $redirect ) {
			\wp_safe_redirect( $redirect );
			exit;
		}
		exit;
	}

	/**
	 * Flush memcache stats by rotating the schema salt. Every existing
	 * `evlog[:salt]:p{N}:…` key orphans instantly and ages out by TTL.
	 * request-workers are restarted because FlameBuilder caches `prefix`
	 * at construction — without a restart the live FlameBuilder keeps
	 * writing under the OLD salt, defeating the flush.
	 */
	public function handle_flush_stats(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$nonce = isset( $_POST[ self::FLUSH_STATS_NONCE ] ) && \is_string( $_POST[ self::FLUSH_STATS_NONCE ] ) ? \sanitize_text_field( \wp_unslash( $_POST[ self::FLUSH_STATS_NONCE ] ) ) : '';
		if ( '' === $nonce || ! \wp_verify_nonce( $nonce, self::FLUSH_STATS_ACTION ) ) {
			\wp_die( \esc_html__( 'Security check failed.', 'newspack-event-logger-nodes' ) );
		}
		if ( ! self::current_user_allowed() ) {
			\wp_die( \esc_html__( 'You do not have permission to perform this action.', 'newspack-event-logger-nodes' ) );
		}

		// Stats_Store::flush_all() only rotates the salt option — it doesn't
		// touch the cache, so no memcache handle is needed on this path.
		$config       = Config::load_config();
		$max_lifespan = $config['max_lifespan'] ?? 86400;
		$stats        = new Stats_Store( 0, \is_numeric( $max_lifespan ) ? (int) $max_lifespan : 0 );
		$stats->flush_all();

		// Restart every worker across every active topology. Long-running
		// nodes cache the prefix at construction (Stats_Store reads the
		// salt option ONCE in __construct); they need a restart to pick up
		// the new prefix. Today FlameBuilder is the only writer, but any
		// future Node that uses Stats_Store inherits the same caching, so
		// scoping the restart to one hardcoded topology name would silently
		// break the moment a second consumer landed. Iterating the canonical
		// `Bootstrap::expand_workers()` descriptor list reaches every
		// (type, partition) the supervisor knows about, including custom
		// topologies and operator-disabled subsets, with no naming knowledge
		// baked into this file.
		$restarted = 0;
		try {
			$workers   = Bootstrap::expand_workers();
			$base_dir  = RuntimeConfig::get_base_directory();
			$restarted = ( new CLI( $base_dir ) )->restart_workers( $workers, [], -1 );
		} catch ( \Throwable $e ) {
			// Best-effort: the next supervisor pass picks up the new salt
			// on the next spawn regardless. Log via the substrate's
			// rate-limited stderr so a misconfigured locks_dir is visible.
			\Newspack_Nodes\Core::print_less_often(
				'Stats flush: restart_workers failed — ' . $e->getMessage()
			);
		}

		$redirect = \function_exists( 'admin_url' )
			? \add_query_arg(
				[
					'page'      => self::MENU_SLUG,
					'flushed'   => '1',
					'restarted' => $restarted,
				],
				\admin_url( 'options-general.php' )
			)
			: '';
		if ( '' !== $redirect ) {
			\wp_safe_redirect( $redirect );
			exit;
		}
		exit;
	}

	/**
	 * Register settings with the WP Settings API.
	 *
	 * Wires application-level options ONLY. Substrate options (base_directory,
	 * partitioning, memcache_servers, enable_workers) are registered by
	 * `\Newspack_Nodes\Admin\Admin` under the `newspack_nodes` group. Child
	 * plugins extend by hooking `admin_init` AFTER this runs and calling
	 * `add_settings_section/field()` on `self::SETTINGS_PAGE`.
	 */
	public function register_settings(): void {
		$schema = Settings_Schema::get();

		// Cache the derived reset surface under the historical property names so
		// `handle_reset_settings()` (base list) and AdminTest's reflection reads
		// see the same set the Reset_Gate is wired against.
		self::$option_names            = $schema->setting_option_names();
		self::$delete_on_blank_options = $schema->delete_on_blank_options();

		$schema->register_options( self::OPTIONS_GROUP );

		// Shared per-field reset / delete-on-blank gate (Config_System\Reset_Gate):
		// a reset toggle (any field — booleans + multi-selects included) OR a
		// blanked text-like field deletes the row so the file default resurfaces.
		Reset_Gate::register( self::RESET_MARK_FIELD, self::$option_names, self::$delete_on_blank_options );

		$schema->register_sections_and_fields( self::SETTINGS_PAGE );
	}

	// -- Sanitizers ---------------------------------------------------------

	/**
	 * Sanitize integer option, preserving empty string for "use default".
	 *
	 * @param mixed $input Input value.
	 * @return string|int Empty string or sanitized integer.
	 */
	public static function sanitize_int_or_empty( $input ) {
		if ( '' === $input || null === $input ) {
			return '';
		}
		return \absint( \is_scalar( $input ) ? $input : 0 );
	}

	/**
	 * Sanitize float option, preserving empty string for "use default".
	 *
	 * Mirrors `sanitize_int_or_empty` but with float coercion. Used for
	 * `auto_protect_time_threshold` (milliseconds, can be fractional).
	 *
	 * @param mixed $input Input value.
	 * @return string|float Empty string or sanitized float.
	 */
	public static function sanitize_float_or_empty( $input ) {
		if ( '' === $input || null === $input ) {
			return '';
		}
		if ( ! \is_numeric( $input ) ) {
			return '';
		}
		$f = (float) $input;
		if ( $f < 0 ) {
			return '';
		}
		return $f;
	}

	// -- Section callbacks --------------------------------------------------

	public static function general_section_callback(): void {
		echo '<p>' . \esc_html__( 'Enable or disable event logging.', 'newspack-event-logger-nodes' ) . '</p>';
	}

	public static function instrumentation_section_callback(): void {
		echo '<p>' . \esc_html__( 'URL filters and hooks to time. Use Browse Hooks / Browse Events to populate from the recommended set.', 'newspack-event-logger-nodes' ) . '</p>';
	}

	public static function workers_section_callback(): void {
		echo '<p>' . \esc_html__( 'Automatically disable noisy events and protect slow ones.', 'newspack-event-logger-nodes' ) . '</p>';
	}

	public static function debugging_section_callback(): void {
		echo '<p>' . \esc_html__( 'Diagnostic toggles for tracing OOMs and mysterious slowness. Both add overhead — disable when not needed.', 'newspack-event-logger-nodes' ) . '</p>';
	}

	/**
	 * Render the read-only "Effective Configuration" table below the settings
	 * form. Hooked to `newspack_event_logger_nodes/settings_after_form`;
	 * delegates to the shared substrate Settings_Renderer with ELN's own schema,
	 * option prefix, and effective config so the panel logic lives in exactly one
	 * place across plugins.
	 */
	public function render_effective_config_section(): void {
		Settings_Renderer::render_effective_config_section( Settings_Schema::get(), self::OPTION_PREFIX, Config::load_config() );
	}

	/**
	 * Maintenance section — rendered below the form via
	 * `newspack_event_logger_nodes/settings_after_form`. Mirrors the legacy
	 * `Clear Memcache Stats` button: a confirm dialog feeds a hidden form
	 * that POSTs to `admin-post.php`, which routes to `handle_flush_stats`.
	 */
	public function render_maintenance_section(): void {
		$flush_url = \function_exists( 'admin_url' ) ? \admin_url( 'admin-post.php' ) : '';
		?>
		<hr style="margin: 30px 0;">
		<h2><?php \esc_html_e( 'Maintenance', 'newspack-event-logger-nodes' ); ?></h2>
		<p>
			<input type="button" class="button button-secondary"
				value="<?php \esc_attr_e( 'Flush Cache', 'newspack-event-logger-nodes' ); ?>"
				onclick="if ( confirm( '<?php echo \esc_js( \__( 'Flush all performance stats from memcache? Hourly stats, leaderboards, and URL data will be orphaned (TTL handles cleanup). request-workers will restart so the new salt takes effect immediately. This cannot be undone.', 'newspack-event-logger-nodes' ) ); ?>' ) ) { document.getElementById( 'newspack-event-logger-nodes-flush-form' ).submit(); }" />
			<span class="description" style="margin-left: 10px;">
				<?php \esc_html_e( 'Rotates the stats-salt so every existing stats key in memcache orphans instantly. Per-URL flame data expires via TTL.', 'newspack-event-logger-nodes' ); ?>
			</span>
		</p>
		<form id="newspack-event-logger-nodes-flush-form" method="post" action="<?php echo \esc_url( $flush_url ); ?>" style="display:none;">
			<input type="hidden" name="action" value="<?php echo \esc_attr( self::FLUSH_STATS_ACTION ); ?>">
			<?php \wp_nonce_field( self::FLUSH_STATS_ACTION, self::FLUSH_STATS_NONCE ); ?>
		</form>
		<?php
	}

	/**
	 * Per-option granular worker-restart on save.
	 *
	 * Application-level options only. Substrate options (base_directory,
	 * partitioning, memcache_servers, etc.) are handled by
	 * `\Newspack_Nodes\Admin\Admin::maybe_request_worker_restart()`.
	 *
	 * The save's restart classification is by CONSUMER NODE TYPE (the Field's
	 * `restart:` key — 'supervisor_only', [], 'all', or node-type tokens like
	 * `Flame_Builder` / `Discovery_Collector`), which `Restart_Planner` resolves
	 * to the set of live topologies whose graphs instantiate that node and touches
	 * each one's per-partition lock dir. Workers pick the flag up at their next
	 * graceful exit point (segment-close in Worker_Base). `stats_salt` is rotated
	 * by the flush handler (not a settings Field), so it's classified here — its
	 * stats producer is `Stats_Store`, which runs inside `Flame_Builder`.
	 *
	 * @param string $option Option name (full WP option key).
	 */
	public function maybe_request_worker_restart( string $option ): void {
		if ( ! \str_starts_with( $option, self::OPTION_PREFIX ) ) {
			return;
		}

		// Reset cached config so this process sees the new value if it reads
		// later in the same request.
		Config::reset();

		$short   = \substr( $option, \strlen( self::OPTION_PREFIX ) );
		$restart = 'stats_salt' === $short
			? [ 'Flame_Builder' ]
			: Settings_Schema::get()->restart_for( $short );

		// Wrap the whole resolve+touch: the planner re-enters Config::load_config() via Bootstrap.
		try {
			$locks_dir = Config::get_locks_directory();
			Restart_Planner::request_restarts( $restart, $locks_dir );
		} catch ( \Throwable $e ) {
			// Best-effort: the next supervisor pass picks up the new config.
			return;
		}
	}
}
