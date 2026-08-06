<?php
/**
 * Admin: application-side WP-Settings-API surface and per-option granular
 * worker-restart on save.
 *
 * Renders exactly three checkboxes, the only application options with a
 * settings field: `enable_logging`, `log_memory`, and `flush_every_line`. The
 * remaining application keys `Settings_Schema` declares — `allowed_users`,
 * `rules`, `hook_start_priority` — are overlay-only (`ui: false`): loaded by
 * Config, never rendered, never reset here.
 *
 * URL filters, hook lists, and auto-tune thresholds are per-rule fields in the
 * `newspack_event_logger_nodes_rules` option, not global settings. That option
 * is edited by the React ruleset editor mounted below the form, through the
 * `rules` service CI — never by the Settings API.
 *
 * Substrate-level options (base_directory, partitioning, memcache_servers)
 * live on `\Newspack_Nodes\Admin\Admin` under the
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
 * Every derived view of the settings — the register/render loops, the reset
 * set, the delete-on-blank subset, the restart classification — comes from the
 * one `Settings_Schema`; this class hand-maintains no parallel list.
 *
 * Per-option worker-restart classification preserved for application options
 * only; substrate options classify on substrate Admin.
 *
 * Does NOT mount React dashboards — that wiring stays in the main plugin file's
 * `admin_enqueue_scripts` hook, which also enqueues the `settings` bundle whose
 * `RulesAdmin` tree fills the `#event-logger-rules-editor` div rendered here.
 * This class owns the WP-Settings-API surface only: the
 * `/options-general.php?page=newspack-event-logger-nodes` settings page.
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
use Newspack_Nodes\Core;
use Newspack_Nodes\Config_System\Field_Reset_Assets;
use Newspack_Nodes\Config_System\Reset_Gate;
use Newspack_Nodes\Config_System\Restart_Planner;
use Newspack_Nodes\Config_System\Settings_Renderer;

\defined( 'ABSPATH' ) || exit;

/**
 * The Event Logger settings page and the option-write behavior around it.
 */
class Admin {

	/**
	 * Nonce action for the flush-memcache-stats form. Doubles as the
	 * `admin_post_{$action}` suffix routing that POST to `handle_flush_stats`.
	 */
	public const FLUSH_STATS_ACTION = 'newspack_event_logger_nodes_flush_stats';

	/** POST field carrying the flush-memcache-stats nonce. */
	public const FLUSH_STATS_NONCE  = 'newspack_event_logger_nodes_flush_stats_nonce';

	/**
	 * Menu page slug used by `add_options_page()` (the URL fragment after
	 * `?page=`).
	 */
	public const MENU_SLUG = 'newspack-event-logger-nodes';

	/**
	 * Settings group registered with `register_setting()`. WordPress uses this
	 * to scope nonce verification and validation when the form posts to
	 * `options.php`.
	 */
	public const OPTIONS_GROUP = 'newspack_event_logger_nodes_options_group';

	/**
	 * WP-option name prefix. All admin-managed options live under this prefix.
	 * Worker-restart classification (see `maybe_request_worker_restart`) keys
	 * off it.
	 */
	public const OPTION_PREFIX = 'newspack_event_logger_nodes_';

	/**
	 * Nonce action for the reset-to-defaults form. Doubles as the
	 * `admin_post_{$action}` suffix routing that POST to `handle_reset_settings`.
	 */
	public const RESET_ACTION = 'newspack_event_logger_nodes_reset_settings';

	/**
	 * Hidden-input array name carrying per-field reset marks ({option} => "1").
	 * The marks ride the ordinary nonce-verified settings POST to `options.php`,
	 * where `Reset_Gate` reads them — NOT the reset-to-defaults form above.
	 */
	public const RESET_MARK_FIELD = 'newspack_event_logger_nodes_reset';

	/** POST field carrying the reset-to-defaults nonce. */
	public const RESET_NONCE  = 'newspack_event_logger_nodes_reset_nonce';

	/**
	 * Settings page slug used by `add_settings_section/field()` and
	 * `do_settings_sections()`. Distinct from the menu-page slug above.
	 */
	public const SETTINGS_PAGE = 'newspack_event_logger_nodes';

	/**
	 * Text-like keys that get a `pre_update_option_{$option}` delete-on-blank
	 * filter: a blank submission deletes the row so the file default resurfaces
	 * under presence-based Config, instead of storing '' (which would override
	 * the default). Bool fields are EXCLUDED — an unchecked box is a real
	 * override — and they reset only through the explicit `↺` mark. Derived from
	 * the Schema's `delete_on_blank_options()` in `register_settings()`, which
	 * classifies every non-`bool` Field as text-like. This plugin's three
	 * settings fields are all bools today, so the list is empty; it stays wired
	 * because the first non-bool Field must behave correctly the day it lands.
	 *
	 * @var array<int, string>
	 */
	private static array $delete_on_blank_options = [];

	/**
	 * Every application option with a settings field — the set `Reset_Gate`
	 * attaches its per-field gate to. Booleans included, so a reset clears them
	 * all.
	 *
	 * Substrate-level options (base_directory, num_partitions, max_segments,
	 * segment_size, min_lifetime, memcache_servers) live on
	 * `\Newspack_Nodes\Admin\Admin` and reset via its own form. The aggregator
	 * spoke list is excluded too: the substrate `\Newspack_Nodes\Vault` owns it
	 * through the `vault` REST CRUD, not a settings-form field.
	 *
	 * Derived from the single Settings_Schema in `register_settings()` (the
	 * `setting_option_names()` set). `handle_reset_settings()` deliberately
	 * re-derives the same list from the Schema rather than reading this cache,
	 * so a reset works before `register_settings()` has ever run. The property
	 * keeps this exact name because `tests/unit/Admin/AdminTest.php` reads it by
	 * reflection to assert the reset set covers every registered setting.
	 *
	 * @var array<int, string>
	 */
	private static array $option_names = [];

	/**
	 * Register the whole admin surface: the Settings submenu, the Settings-API
	 * wiring, both admin-post handlers, the three panels below the form, and the
	 * two option-write filters.
	 *
	 * Constructing a second Admin double-registers every hook, so the plugin
	 * builds exactly one.
	 */
	public function __construct() {
		\add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
		\add_action( 'admin_init', [ $this, 'register_settings' ] );
		\add_action( 'admin_post_' . self::RESET_ACTION, [ $this, 'handle_reset_settings' ] );
		\add_action( 'admin_post_' . self::FLUSH_STATS_ACTION, [ $this, 'handle_flush_stats' ] );
		\add_action( 'newspack_event_logger_nodes/settings_after_form', [ $this, 'render_rules_editor_section' ], 5 );
		\add_action( 'newspack_event_logger_nodes/settings_after_form', [ $this, 'render_effective_config_section' ] );
		\add_action( 'newspack_event_logger_nodes/settings_after_form', [ $this, 'render_maintenance_section' ] );

		// Per-option worker-restart on save (added_option + updated_option).
		\add_action( 'updated_option', [ $this, 'maybe_request_worker_restart' ], 10, 1 );
		\add_action( 'added_option', [ $this, 'maybe_request_worker_restart' ], 10, 1 );

		// Skip storing an option equal to the file default (strict compare).
		\add_filter( 'pre_update_option', [ $this, 'skip_default_writes' ], 10, 3 );
	}

	// -- Field callbacks ----------------------------------------------------

	/**
	 * The master logging switch. Ships on in the config file, and passes a hard
	 * default of 1 so it still renders checked without one.
	 */
	public static function enable_logging_callback(): void {
		self::render_checkbox(
			'enable_logging',
			\__( 'Enable event logging', 'newspack-event-logger-nodes' ),
			1
		);
	}

	// -- Private renderers --------------------------------------------------

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
	 * @return int 0 or 1.
	 */
	private static function bool_option_with_file_default( string $short_key, int $hard_default = 0 ): int {
		$file_default = self::bool_file_default( $short_key, $hard_default );
		$stored       = \get_option(
			"newspack_event_logger_nodes_{$short_key}",
			$file_default
		);
		return Core::num_int( $stored );
	}

	/**
	 * The file-config default for a boolean toggle as 0/1 — what a reset
	 * restores (reset deletes the option row, resurfacing this value). Used by
	 * the `data-nn-reset-default` attribute so the reset-toggle JS previews the
	 * real default state, NOT the current stored value.
	 *
	 * @param string $short_key    Option key without the `newspack_event_logger_nodes_` prefix.
	 * @param int    $hard_default 0 or 1 — used only if the file default is absent.
	 * @return int 0 or 1.
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
	 * @return int 0 or 1.
	 */
	private static function bool_to_int( $value ): int {
		return (int) (bool) $value;
	}

	/** Hidden-input name that flags $field for per-field reset (deleted on Save). */
	private static function reset_mark_name( string $field ): string {
		return Reset_Gate::mark_name( self::RESET_MARK_FIELD, self::OPTION_PREFIX . $field );
	}

	// ---- Debugging field callbacks ---------------------------------------

	/** Peak-memory annotation on every completed request. Ships off. */
	public static function log_memory_callback(): void {
		self::render_checkbox(
			'log_memory',
			\__( 'Append peak_mb to every complete() log entry so memory growth is visible across the request timeline.', 'newspack-event-logger-nodes' )
		);
	}

	/** Unbuffered firehose writes — crash survivability over throughput. Ships off. */
	public static function flush_every_line_callback(): void {
		self::render_checkbox(
			'flush_every_line',
			\__( 'Flush write buffer after every log line. Survives OOM kills — last line before crash is preserved on disk. Trades throughput for crash survivability.', 'newspack-event-logger-nodes' )
		);
	}

	/**
	 * Reset-to-defaults handler — the `admin_post_{RESET_ACTION}` target.
	 *
	 * Nonce + permission checks first, then delete every application settings
	 * row so the file defaults resurface under presence-based Config. Redirects
	 * back to the settings page with `reset=1` and exits either way, so nothing
	 * downstream of the handler runs.
	 *
	 * Deletion is scoped by `OPTION_PREFIX`: a Schema that ever names a foreign
	 * option cannot make this handler delete it.
	 */
	public function handle_reset_settings(): void {
		self::verify_admin_post( self::RESET_NONCE, self::RESET_ACTION );

		// Derive reset list from Schema so this works pre-register_settings().
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
	 * Shared admin-post gate: verify the POSTed nonce (read from $nonce_field
	 * against $action) and the caller's capability, `wp_die`-ing on either
	 * failure. Both admin-post handlers run this identical check first.
	 *
	 * @param string $nonce_field POST key carrying the nonce.
	 * @param string $action      Nonce action to verify against.
	 */
	private static function verify_admin_post( string $nonce_field, string $action ): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$nonce = isset( $_POST[ $nonce_field ] ) && \is_string( $_POST[ $nonce_field ] ) ? \sanitize_text_field( \wp_unslash( $_POST[ $nonce_field ] ) ) : '';
		if ( '' === $nonce || ! \wp_verify_nonce( $nonce, $action ) ) {
			\wp_die( \esc_html__( 'Security check failed.', 'newspack-event-logger-nodes' ) );
		}
		if ( ! self::current_user_allowed() ) {
			\wp_die( \esc_html__( 'You do not have permission to perform this action.', 'newspack-event-logger-nodes' ) );
		}
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
	 * With no user API available — a CLI context — the whitelist is skipped
	 * rather than enforced against a nonexistent login, so `wp` stays usable.
	 *
	 * @return bool True if user is allowed.
	 */
	public static function current_user_allowed(): bool {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return false;
		}

		$allowed_users = Config::value( 'allowed_users' );
		if ( empty( $allowed_users ) || ! \is_array( $allowed_users ) ) {
			return true;
		}

		if ( ! \function_exists( 'wp_get_current_user' ) ) {
			return true; // CLI / no user context — don't lock out CLI admins.
		}
		$current_user = \wp_get_current_user();
		return \in_array( $current_user->user_login, $allowed_users, true );
	}

	/**
	 * Flush memcache stats by rotating the schema salt — the
	 * `admin_post_{FLUSH_STATS_ACTION}` target. Every existing
	 * `evlog[:salt]:p{N}:…` key orphans instantly and ages out by TTL.
	 *
	 * Every worker of every active topology is then flagged for restart, because
	 * `Stats_Store` computes `prefix` in its constructor: a live
	 * `Flame_Builder_Node` holds a store built under the OLD salt and would keep
	 * writing there, defeating the flush. The restart is best-effort — a failure
	 * only delays the new salt until the next worker spawn, so it is logged,
	 * not surfaced.
	 *
	 * Redirects back to the settings page with `flushed=1` and the restart count,
	 * and exits either way.
	 */
	public function handle_flush_stats(): void {
		self::verify_admin_post( self::FLUSH_STATS_NONCE, self::FLUSH_STATS_ACTION );

		// flush_all() only rotates the salt option; no memcache handle needed.
		$max_lifespan = Config::value( 'min_lifetime' );
		$stats        = new Stats_Store( 0, Core::num_int( $max_lifespan ) );
		$stats->flush_all();

		// Restart all workers: they cache the salt prefix at construction.
		$restarted = 0;
		try {
			$workers   = Bootstrap::expand_workers();
			$base_dir  = RuntimeConfig::get_base_directory();
			$restarted = ( new CLI( $base_dir ) )->restart_workers( $workers, [], -1 );
		} catch ( \Throwable $e ) {
			// Best-effort; the next worker spawn picks up the salt regardless.
			\Newspack_Nodes\Core::print_less_often(
				'Stats flush: restart_workers failed — ',
				$e->getMessage()
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
	 * Render the settings page: the `flushed` / `reset` notices, the Settings-API
	 * form posting to `options.php`, the hidden reset form the "Reset to
	 * Defaults" button submits, and then the `settings_after_form` panels.
	 *
	 * Permission is re-checked here rather than trusting the menu-registration
	 * gate alone, since `add_options_page` only enforces `manage_options` while
	 * `current_user_allowed()` also honors the `allowed_users` whitelist.
	 */
	public function render_settings_page(): void {
		if ( ! self::current_user_allowed() ) {
			\wp_die( \esc_html__( 'You do not have permission to access this page.', 'newspack-event-logger-nodes' ) );
		}
		$reset_url = \function_exists( 'admin_url' )
			? \admin_url( 'admin-post.php' )
			: '/wp-admin/admin-post.php';
		?>
		<div class="wrap event-logger-settings-wrap newspack-nodes-theme newspack-nodes-ui">
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
			// Child plugins add sections below form via settings_after_form.
			\do_action( 'newspack_event_logger_nodes/settings_after_form' );
			Field_Reset_Assets::enqueue();
			echo Field_Reset_Assets::highlight_style(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static CSS literal.
			?>
		</div>
		<?php
	}

	/**
	 * `pre_update_option` filter: never store a value equal to the file default.
	 *
	 * Presence-based Config reads a stored row as an override, so a row holding
	 * the default would pin that value and survive a later change to the config
	 * file. Saving a field back to its default therefore DELETES the row and
	 * returns the old value, short-circuiting WordPress's own write.
	 *
	 * Runs on every option WordPress updates, so it bails unless the name
	 * actually carries our prefix and the remainder is a key the file defaults
	 * declare — matched, not stripped by length, or another plugin's option
	 * whose tail happened to equal one of our keys would be judged against our
	 * defaults and deleted.
	 *
	 * @param mixed  $value     New option value about to be written.
	 * @param string $option    Full option name.
	 * @param mixed  $old_value Current stored value.
	 * @return mixed The value to persist, or $old_value when the row was deleted.
	 */
	public function skip_default_writes( mixed $value, string $option, mixed $old_value ): mixed {
		$prefix = 'newspack_event_logger_nodes_';
		if ( ! \str_starts_with( $option, $prefix ) ) {
			return $value;
		}
		$key = \substr( $option, \strlen( $prefix ) );
		if ( '' === $key ) {
			return $value;
		}
		$defaults = Config::load_config_defaults();
		if ( ! \array_key_exists( $key, $defaults ) ) {
			return $value;
		}
		// Normalize bool defaults to int so strict compare matches absint 0/1.
		$default = $defaults[ $key ];
		if ( \is_bool( $default ) ) {
			$default = self::bool_to_int( $default );
		}
		if ( $value !== $default ) {
			return $value;
		}
		// Value set back to default: drop the row so the file default returns.
		if ( $value !== $old_value ) {
			\delete_option( $option );
		}
		return $old_value;
	}

	/**
	 * Add the "Event Logger" entry under the standard Settings menu.
	 *
	 * ONLY that entry. The top-level "Event Logger" menu and its dashboard
	 * submenus belong to the main plugin file's own `admin_menu` closure, so
	 * configuration lives under Settings while dashboards stay top-level.
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
	 * Register settings with the WP Settings API, in Schema order: cache the two
	 * derived option lists, `register_setting()` each field, attach the
	 * `Reset_Gate` to every resettable option, then add the sections and fields
	 * to the settings page.
	 *
	 * Wires application-level options ONLY. Substrate options (base_directory,
	 * partitioning, memcache_servers) are registered by
	 * `\Newspack_Nodes\Admin\Admin` under the `newspack_nodes` group. Child
	 * plugins extend by hooking `admin_init` AFTER this runs and calling
	 * `add_settings_section/field()` on `self::SETTINGS_PAGE`.
	 */
	public function register_settings(): void {
		$schema = Settings_Schema::get();

		// Cache the derived option lists the reset wiring below consumes.
		self::$option_names            = $schema->setting_option_names();
		self::$delete_on_blank_options = $schema->delete_on_blank_options();

		$schema->register_options( self::OPTIONS_GROUP );

		// Reset/delete-on-blank: drops the row so the file default returns.
		Reset_Gate::register( self::RESET_MARK_FIELD, self::$option_names, self::$delete_on_blank_options );

		$schema->register_sections_and_fields( self::SETTINGS_PAGE );
	}

	// -- Section callbacks --------------------------------------------------

	/**
	 * Intro for the General section, which holds `enable_logging`.
	 *
	 * A section renders only when the Schema gives it at least one rendered
	 * field, so of the four declared sections only this one and Debugging reach
	 * the page.
	 */
	public static function general_section_callback(): void {
		echo '<p>' . \esc_html__( 'Enable or disable event logging.', 'newspack-event-logger-nodes' ) . '</p>';
	}

	/**
	 * Intro for the Instrumentation section, which no longer has any field: the
	 * URL filters and hook lists it described became per-rule and moved to the
	 * ruleset editor. The section is declared but never rendered, so this text
	 * never prints.
	 */
	public static function instrumentation_section_callback(): void {
		echo '<p>' . \esc_html__( 'URL filters and hooks to time. Use Browse Hooks / Browse Events to populate from the recommended set.', 'newspack-event-logger-nodes' ) . '</p>';
	}

	/**
	 * Intro for the Performance Workers section, which no longer has any field:
	 * the auto-tune thresholds it described became per-rule. Declared but never
	 * rendered, like Instrumentation above.
	 */
	public static function workers_section_callback(): void {
		echo '<p>' . \esc_html__( 'Automatically disable noisy events and protect slow ones.', 'newspack-event-logger-nodes' ) . '</p>';
	}

	/** Intro for the Debugging section: `log_memory` + `flush_every_line`. */
	public static function debugging_section_callback(): void {
		echo '<p>' . \esc_html__( 'Diagnostic toggles for tracing OOMs and mysterious slowness. Both add overhead — disable when not needed.', 'newspack-event-logger-nodes' ) . '</p>';
	}

	/**
	 * Render the "Logging Rules" section: a heading plus the React mount point
	 * the `settings` bundle renders RulesAdmin into. Hooked (priority 5) to
	 * `newspack_event_logger_nodes/settings_after_form` so it renders above the
	 * effective-config + maintenance panels. The bundle is enqueued on this page
	 * by the main plugin file's `admin_enqueue_scripts` handler.
	 */
	public function render_rules_editor_section(): void {
		?>
		<hr style="margin: 30px 0;">
		<h2><?php \esc_html_e( 'Logging Rules', 'newspack-event-logger-nodes' ); ?></h2>
		<p class="description">
			<?php \esc_html_e( 'Per-URL logging rules: which URLs to log or skip, and — for logged URLs — the hooks, custom events, significant events, and auto-tune thresholds.', 'newspack-event-logger-nodes' ); ?>
		</p>
		<div id="event-logger-rules-editor"></div>
		<?php
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
	 * `newspack_event_logger_nodes/settings_after_form`. A confirm dialog feeds
	 * a hidden form that POSTs to `admin-post.php`, which routes to
	 * `handle_flush_stats`.
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
	 * each one's per-partition lock dir. A worker sees the flag on its next
	 * `Worker_Base::should_continue()` check and exits for a peer's scan to
	 * respawn. `stats_salt` is rotated by the flush handler and is not a settings
	 * Field, so `restart_for()` would return `[]` for it; it is classified inline
	 * here instead, against `Flame_Builder` — the node its `Stats_Store` runs in.
	 *
	 * Best-effort throughout: a planner failure is swallowed because the next
	 * worker loads the new config regardless.
	 *
	 * @param string $option Option name (full WP option key).
	 */
	public function maybe_request_worker_restart( string $option ): void {
		if ( ! \str_starts_with( $option, self::OPTION_PREFIX ) ) {
			return;
		}

		// Reset cached config so a later read this request sees the new value.
		Config::reset();

		$short   = \substr( $option, \strlen( self::OPTION_PREFIX ) );
		$restart = 'stats_salt' === $short
			? [ 'Flame_Builder' ]
			: Settings_Schema::get()->restart_for( $short );

		// resolve+touch: planner re-enters Config::load_config() via Bootstrap.
		try {
			$locks_dir = Config::get_locks_directory();
			Restart_Planner::request_restarts( $restart, $locks_dir );
			// @longform Every live worker's option cache is frozen at boot, so
			// the ones this save does not recycle must be told to re-read.
			Restart_Planner::request_reloads( $locks_dir );
		} catch ( \Throwable $e ) {
			// Best-effort: the next worker generation loads the new config.
			return;
		}
	}
}
