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
 * list (`aggregator_servers`) is application-owned and lives here.
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
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\CLI;
use Newspack_Nodes\Config as Substrate_Config;
use Newspack_Nodes\Config_System\Field_Reset_Assets;
use Newspack_Nodes\Config_System\Reset_Gate;
use Newspack_Nodes\Lock_Node;

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
	 * admin only owns the keys below. (The aggregator spoke list
	 * `aggregator_servers` is NOT here — it's managed by the ServerRegistry REST
	 * CRUD, not the settings form, so the settings reset doesn't touch it.)
	 *
	 * Kept on the class so child plugins can extend via the `…_reset_options`
	 * filter without re-listing these. EVERY settings-form option (booleans +
	 * multi-selects included) must appear here so a reset clears them all, and so
	 * the shared `Reset_Gate` attaches its gate to each.
	 *
	 * @var array<int, string>
	 */
	private static array $option_names = [
		// General.
		'newspack_event_logger_nodes_enable_logging',
		// Instrumentation.
		'newspack_event_logger_nodes_log_urls',
		'newspack_event_logger_nodes_skip_urls',
		'newspack_event_logger_nodes_log_events',
		'newspack_event_logger_nodes_custom_events',
		// Aggregator. (aggregator_servers is excluded — it's managed by the
		// ServerRegistry REST CRUD, not a settings-form field, so there's nothing
		// to reset here.)
		'newspack_event_logger_nodes_enable_aggregator',
		'newspack_event_logger_nodes_remote_num_segments',
		'newspack_event_logger_nodes_remote_segment_size',
		'newspack_event_logger_nodes_remote_max_lifespan',
		// Performance Workers (application-side).
		'newspack_event_logger_nodes_significant_events',
		'newspack_event_logger_nodes_auto_disable_threshold',
		'newspack_event_logger_nodes_auto_protect_time_threshold',
		// Debugging.
		'newspack_event_logger_nodes_log_memory',
		'newspack_event_logger_nodes_flush_every_line',
	];

	/**
	 * Text-like keys that get a `pre_update_option_{$option}` delete-on-blank
	 * filter: a blank submission (or `↺` field-reset) deletes the row so the
	 * file default resurfaces under presence-based Config, instead of storing
	 * '' (which would override the default). This is the scalar subset of
	 * $option_names — checkbox bools (enable_logging, log_memory,
	 * flush_every_line) and multi-select arrays (log_urls, skip_urls,
	 * log_events, custom_events, significant_events) are EXCLUDED: an unchecked
	 * box or empty selection there is a real override.
	 *
	 * @var array<int, string>
	 */
	private static array $delete_on_blank_options = [
		'newspack_event_logger_nodes_auto_disable_threshold',
		'newspack_event_logger_nodes_auto_protect_time_threshold',
		'newspack_event_logger_nodes_remote_num_segments',
		'newspack_event_logger_nodes_remote_segment_size',
		'newspack_event_logger_nodes_remote_max_lifespan',
	];

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

	public function __construct() {
		\add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
		\add_action( 'admin_init', [ $this, 'register_settings' ] );
		\add_action( 'admin_post_' . self::RESET_ACTION, [ $this, 'handle_reset_settings' ] );
		\add_action( 'admin_post_' . self::FLUSH_STATS_ACTION, [ $this, 'handle_flush_stats' ] );
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
			$default = (int) $default;
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
	 * Render the settings page: form + Reset-to-Defaults secondary form.
	 *
	 * The reset is in a separate hidden form so cancelling the confirm()
	 * dialog leaves the main form's pending edits intact. Confirm uses esc_js()
	 * because `onclick=` runs in JS context.
	 */
	/**
	 * Static shim so the main plugin file can wire `add_submenu_page()` without
	 * holding an Admin instance reference. Render is stateless — no $this used —
	 * so a fresh instance is fine. (`new Admin()` is also constructed elsewhere
	 * for hook registration; the two coexist harmlessly.)
	 */
	public static function render_settings_page_static(): void {
		( new self() )->render_settings_page();
	}

	public function render_settings_page(): void {
		if ( ! self::current_user_allowed() ) {
			\wp_die( \esc_html__( 'You do not have permission to access this page.', 'newspack-event-logger-nodes' ) );
		}
		$reset_url = \function_exists( 'admin_url' )
			? \admin_url( 'admin-post.php' )
			: '/wp-admin/admin-post.php';
		?>
		<div class="wrap event-logger-settings-wrap">
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
	 * Register settings with the WP Settings API.
	 *
	 * Wires application-level options ONLY. Substrate options (base_directory,
	 * partitioning, memcache_servers, enable_workers) are registered by
	 * `\Newspack_Nodes\Admin\Admin` under the `newspack_nodes` group. Child
	 * plugins extend by hooking `admin_init` AFTER this runs and calling
	 * `add_settings_section/field()` on `self::SETTINGS_PAGE`.
	 */
	public function register_settings(): void {
		// Boolean toggle.
		\register_setting(
			self::OPTIONS_GROUP,
			'newspack_event_logger_nodes_enable_logging',
			[ 'sanitize_callback' => 'absint' ]
		);

		// General section.
		\add_settings_section(
			'newspack_event_logger_nodes_general_section',
			\__( 'General', 'newspack-event-logger-nodes' ),
			[ $this, 'general_section_callback' ],
			self::SETTINGS_PAGE
		);
		\add_settings_field(
			'enable_logging',
			\__( 'Enable Logging', 'newspack-event-logger-nodes' ),
			[ $this, 'enable_logging_callback' ],
			self::SETTINGS_PAGE,
			'newspack_event_logger_nodes_general_section'
		);

		// -- Instrumentation (URL filters + hooks to time) ------------------
		\register_setting(
			self::OPTIONS_GROUP,
			'newspack_event_logger_nodes_log_urls',
			[ 'sanitize_callback' => [ $this, 'sanitize_array_strings' ] ]
		);
		\register_setting(
			self::OPTIONS_GROUP,
			'newspack_event_logger_nodes_skip_urls',
			[ 'sanitize_callback' => [ $this, 'sanitize_array_strings' ] ]
		);
		\register_setting(
			self::OPTIONS_GROUP,
			'newspack_event_logger_nodes_log_events',
			[ 'sanitize_callback' => [ $this, 'sanitize_array_strings' ] ]
		);
		\register_setting(
			self::OPTIONS_GROUP,
			'newspack_event_logger_nodes_custom_events',
			[ 'sanitize_callback' => [ $this, 'sanitize_custom_events' ] ]
		);

		\add_settings_section(
			'newspack_event_logger_nodes_instrumentation_section',
			\__( 'Instrumentation', 'newspack-event-logger-nodes' ),
			[ $this, 'instrumentation_section_callback' ],
			self::SETTINGS_PAGE
		);
		\add_settings_field(
			'log_urls',
			\__( 'Log URLs', 'newspack-event-logger-nodes' ),
			[ $this, 'log_urls_callback' ],
			self::SETTINGS_PAGE,
			'newspack_event_logger_nodes_instrumentation_section'
		);
		\add_settings_field(
			'skip_urls',
			\__( 'Skip URLs', 'newspack-event-logger-nodes' ),
			[ $this, 'skip_urls_callback' ],
			self::SETTINGS_PAGE,
			'newspack_event_logger_nodes_instrumentation_section'
		);
		\add_settings_field(
			'log_events',
			\__( 'Log Events', 'newspack-event-logger-nodes' ),
			[ $this, 'log_events_callback' ],
			self::SETTINGS_PAGE,
			'newspack_event_logger_nodes_instrumentation_section'
		);
		\add_settings_field(
			'custom_events',
			\__( 'Custom Events', 'newspack-event-logger-nodes' ),
			[ $this, 'custom_events_callback' ],
			self::SETTINGS_PAGE,
			'newspack_event_logger_nodes_instrumentation_section'
		);

		// -- Performance Workers section -----------------------------------
		// A3: enable_workers / enable_jobs / enable_aggregator gates are gone.
		// Worker fleet activation is driven by the substrate's flat
		// `topologies` config list (managed in the substrate Admin's
		// Topologies multi-select). What stays here are the per-fleet
		// app-level knobs that DON'T toggle whether a fleet runs:
		// auto-tune thresholds, significant-events whitelist.
		\register_setting(
			self::OPTIONS_GROUP,
			'newspack_event_logger_nodes_significant_events',
			[
				'sanitize_callback' => [ $this, 'sanitize_array_strings' ],
				'autoload'          => false,
			]
		);
		\register_setting(
			self::OPTIONS_GROUP,
			'newspack_event_logger_nodes_auto_disable_threshold',
			[
				'sanitize_callback' => [ $this, 'sanitize_int_or_empty' ],
				'autoload'          => false,
			]
		);
		\register_setting(
			self::OPTIONS_GROUP,
			'newspack_event_logger_nodes_auto_protect_time_threshold',
			[
				'sanitize_callback' => [ $this, 'sanitize_float_or_empty' ],
				'autoload'          => false,
			]
		);

		\add_settings_section(
			'newspack_event_logger_nodes_workers_section',
			\__( 'Performance Workers', 'newspack-event-logger-nodes' ),
			[ $this, 'workers_section_callback' ],
			self::SETTINGS_PAGE
		);
		\add_settings_field(
			'auto_tune',
			\__( 'Auto-Tune', 'newspack-event-logger-nodes' ),
			[ $this, 'auto_tune_callback' ],
			self::SETTINGS_PAGE,
			'newspack_event_logger_nodes_workers_section'
		);
		\add_settings_field(
			'significant_events',
			\__( 'Significant Events', 'newspack-event-logger-nodes' ),
			[ $this, 'significant_events_callback' ],
			self::SETTINGS_PAGE,
			'newspack_event_logger_nodes_workers_section'
		);

		// -- Remote Servers section -----------------------------------------
		// Aggregator spoke list is managed by the Remote Servers REST CRUD
		// (ServerRegistry → encrypted WP option) — NOT a settings-form
		// field. The aggregator fleet itself runs whenever 'aggregator' is
		// in the substrate `topologies` list.

		\register_setting(
			self::OPTIONS_GROUP,
			'newspack_event_logger_nodes_enable_aggregator',
			[
				'type'              => 'boolean',
				'sanitize_callback' => static fn ( $v ): int => empty( $v ) ? 0 : 1,
				'default'           => 0,
				'autoload'          => true,
			]
		);

		\add_settings_section(
			'newspack_event_logger_nodes_aggregator_section',
			\__( 'Remote Servers', 'newspack-event-logger-nodes' ),
			[ $this, 'aggregator_section_callback' ],
			self::SETTINGS_PAGE
		);
		\add_settings_field(
			'enable_aggregator',
			\__( 'Enable Aggregator', 'newspack-event-logger-nodes' ),
			[ $this, 'enable_aggregator_callback' ],
			self::SETTINGS_PAGE,
			'newspack_event_logger_nodes_aggregator_section'
		);
		\add_settings_field(
			'configured_servers',
			\__( 'Configured Servers', 'newspack-event-logger-nodes' ),
			[ $this, 'configured_servers_callback' ],
			self::SETTINGS_PAGE,
			'newspack_event_logger_nodes_aggregator_section'
		);

		// -- Remote Server Settings section ---------------------------------
		// Storage geometry pushed to remote spokes via SettingsSync. The
		// hub stores these under `_remote_*` so retuning a spoke doesn't
		// accidentally retune the hub itself; spokes receive them under
		// the substrate's `newspack_nodes_*` keys.
		\register_setting(
			self::OPTIONS_GROUP,
			'newspack_event_logger_nodes_remote_num_segments',
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_remote_num_segments' ],
			]
		);
		\register_setting(
			self::OPTIONS_GROUP,
			'newspack_event_logger_nodes_remote_segment_size',
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_remote_segment_size' ],
			]
		);
		\register_setting(
			self::OPTIONS_GROUP,
			'newspack_event_logger_nodes_remote_max_lifespan',
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_remote_max_lifespan' ],
			]
		);
		\add_settings_section(
			'newspack_event_logger_nodes_remote_settings_section',
			\__( 'Remote Server Settings', 'newspack-event-logger-nodes' ),
			[ $this, 'remote_settings_section_callback' ],
			self::SETTINGS_PAGE
		);
		\add_settings_field(
			'remote_num_segments',
			\__( 'Remote Segment Count', 'newspack-event-logger-nodes' ),
			[ $this, 'remote_num_segments_callback' ],
			self::SETTINGS_PAGE,
			'newspack_event_logger_nodes_remote_settings_section'
		);
		\add_settings_field(
			'remote_segment_size',
			\__( 'Remote Segment Size', 'newspack-event-logger-nodes' ),
			[ $this, 'remote_segment_size_callback' ],
			self::SETTINGS_PAGE,
			'newspack_event_logger_nodes_remote_settings_section'
		);
		\add_settings_field(
			'remote_max_lifespan',
			\__( 'Remote Min Retention', 'newspack-event-logger-nodes' ),
			[ $this, 'remote_max_lifespan_callback' ],
			self::SETTINGS_PAGE,
			'newspack_event_logger_nodes_remote_settings_section'
		);

		// -- Debugging section ----------------------------------------------
		\register_setting(
			self::OPTIONS_GROUP,
			'newspack_event_logger_nodes_log_memory',
			[ 'sanitize_callback' => 'absint' ]
		);
		\register_setting(
			self::OPTIONS_GROUP,
			'newspack_event_logger_nodes_flush_every_line',
			[ 'sanitize_callback' => 'absint' ]
		);

		\add_settings_section(
			'newspack_event_logger_nodes_debugging_section',
			\__( 'Debugging', 'newspack-event-logger-nodes' ),
			[ $this, 'debugging_section_callback' ],
			self::SETTINGS_PAGE
		);
		\add_settings_field(
			'log_memory',
			\__( 'Log Memory', 'newspack-event-logger-nodes' ),
			[ $this, 'log_memory_callback' ],
			self::SETTINGS_PAGE,
			'newspack_event_logger_nodes_debugging_section'
		);
		\add_settings_field(
			'flush_every_line',
			\__( 'Flush Every Line', 'newspack-event-logger-nodes' ),
			[ $this, 'flush_every_line_callback' ],
			self::SETTINGS_PAGE,
			'newspack_event_logger_nodes_debugging_section'
		);

		// Shared per-field reset / delete-on-blank gate (Config_System\Reset_Gate):
		// a reset toggle (any field — booleans + multi-selects included) OR a
		// blanked text-like field deletes the row so the file default resurfaces.
		Reset_Gate::register( self::RESET_MARK_FIELD, self::$option_names, self::$delete_on_blank_options );
	}

	// -- Sanitizers ---------------------------------------------------------

	/**
	 * Sanitize integer option, preserving empty string for "use default".
	 *
	 * @param mixed $input Input value.
	 * @return string|int Empty string or sanitized integer.
	 */
	public function sanitize_int_or_empty( $input ) {
		if ( '' === $input || null === $input ) {
			return '';
		}
		return \absint( \is_scalar( $input ) ? $input : 0 );
	}

	/**
	 * Sanitize the remote num_segments setting: clamp to [2, 16], or '' when unset.
	 *
	 * @param int|string|null $value Raw option value (WP sanitize_callback may pass null).
	 * @return int|string Clamped segment count, or '' when blank/unset.
	 */
	public function sanitize_remote_num_segments( int|string|null $value ): int|string {
		if ( '' === $value || null === $value ) {
			return '';
		}
		return \max( 2, \min( 16, \absint( $value ) ) );
	}

	/**
	 * Sanitize the remote segment_size setting: clamp to [1MB, 256MB], or '' when unset.
	 *
	 * @param int|string|null $value Raw option value (WP sanitize_callback may pass null).
	 * @return int|string Clamped byte size, or '' when blank/unset.
	 */
	public function sanitize_remote_segment_size( int|string|null $value ): int|string {
		if ( '' === $value || null === $value ) {
			return '';
		}
		return \max( 1024 * 1024, \min( 256 * 1024 * 1024, \absint( $value ) ) );
	}

	/**
	 * Sanitize the remote max_lifespan setting: clamp to [60, 604800] seconds, or '' when unset.
	 *
	 * @param int|string|null $value Raw option value (WP sanitize_callback may pass null).
	 * @return int|string Clamped lifespan in seconds, or '' when blank/unset.
	 */
	public function sanitize_remote_max_lifespan( int|string|null $value ): int|string {
		if ( '' === $value || null === $value ) {
			return '';
		}
		return \max( 60, \min( 604800, \absint( $value ) ) );
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
	public function sanitize_array_strings( mixed $value ): array {
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
	public function sanitize_custom_events( $value ): array {
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

		$names = $this->sanitize_array_strings( $value );
		$out   = [];
		foreach ( $names as $name ) {
			$out[ $name ] = true;
		}
		return $out;
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
	public function sanitize_float_or_empty( $input ) {
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

	public function general_section_callback(): void {
		echo '<p>' . \esc_html__( 'Enable or disable event logging.', 'newspack-event-logger-nodes' ) . '</p>';
	}

	public function instrumentation_section_callback(): void {
		echo '<p>' . \esc_html__( 'URL filters and hooks to time. Use Browse Hooks / Browse Events to populate from the recommended set.', 'newspack-event-logger-nodes' ) . '</p>';
	}

	public function workers_section_callback(): void {
		echo '<p>' . \esc_html__( 'Automatically disable noisy events and protect slow ones.', 'newspack-event-logger-nodes' ) . '</p>';
	}

	public function debugging_section_callback(): void {
		echo '<p>' . \esc_html__( 'Diagnostic toggles for tracing OOMs and mysterious slowness. Both add overhead — disable when not needed.', 'newspack-event-logger-nodes' ) . '</p>';
	}

	// -- Field callbacks ----------------------------------------------------

	public function enable_logging_callback(): void {
		$enabled = $this->bool_option_with_file_default( 'enable_logging', 1 );
		?>
		<div style="display: flex; align-items: flex-start; gap: 10px;" data-nn-reset="<?php echo \esc_attr( $this->reset_mark_name( 'enable_logging' ) ); ?>">
			<div style="flex: 1;">
				<input type="hidden" name="newspack_event_logger_nodes_enable_logging" value="0" />
				<input type="checkbox" id="enable_logging" name="newspack_event_logger_nodes_enable_logging" value="1" <?php \checked( 1, $enabled ); ?> />
				<label for="enable_logging"><?php \esc_html_e( 'Enable event logging', 'newspack-event-logger-nodes' ); ?></label>
			</div>
			<button type="button" class="button button-secondary" data-nn-reset-toggle
				title="<?php \esc_attr_e( 'Reset to default (toggle, then Save)', 'newspack-event-logger-nodes' ); ?>">↺</button>
		</div>
		<?php
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
	private function bool_option_with_file_default( string $short_key, int $hard_default = 0 ): int {
		$defaults     = Config::load_config_defaults();
		$file_default = \array_key_exists( $short_key, $defaults )
			? (int) (bool) $defaults[ $short_key ]
			: $hard_default;
		$stored = \get_option(
			"newspack_event_logger_nodes_{$short_key}",
			$file_default
		);
		return \is_numeric( $stored ) ? (int) $stored : 0;
	}

	// ---- Aggregator field callbacks --------------------------------------

	public function aggregator_section_callback(): void {
		echo '<p>' . \esc_html__( 'Configure remote Event Logger servers to aggregate logs from. Activate the aggregator fleet by adding `aggregator` to the Topologies list under Nodes Runtime settings.', 'newspack-event-logger-nodes' ) . '</p>';
	}

	public function enable_aggregator_callback(): void {
		$enabled = $this->bool_option_with_file_default( 'enable_aggregator' );
		?>
		<div style="display: flex; align-items: flex-start; gap: 10px;" data-nn-reset="<?php echo \esc_attr( $this->reset_mark_name( 'enable_aggregator' ) ); ?>">
			<div style="flex: 1;">
				<input type="hidden" name="newspack_event_logger_nodes_enable_aggregator" value="0" />
				<input type="checkbox" id="enable_aggregator" name="newspack_event_logger_nodes_enable_aggregator" value="1" <?php \checked( 1, $enabled ); ?> />
				<label for="enable_aggregator"><?php \esc_html_e( 'Show the Aggregator status dashboard in the admin menu.', 'newspack-event-logger-nodes' ); ?></label>
			</div>
			<button type="button" class="button button-secondary" data-nn-reset-toggle
				title="<?php \esc_attr_e( 'Reset to default (toggle, then Save)', 'newspack-event-logger-nodes' ); ?>">↺</button>
		</div>
		<?php
	}

	public function remote_settings_section_callback(): void {
		echo '<p>' . \esc_html__(
			'Storage geometry pushed to remote spokes (may differ from hub settings). Blank fields use the config-file default.',
			'newspack-event-logger-nodes'
		) . '</p>';
	}

	public function remote_num_segments_callback(): void {
		$default_raw = \Newspack_Event_Logger_Nodes\Config::load_config_defaults()['remote_num_segments'] ?? 2;
		$default     = \is_numeric( $default_raw ) ? (int) $default_raw : 0;
		$this->render_number_field(
			'remote_num_segments',
			$default,
			2,
			16,
			\__( 'Number of log segments on remote servers (2-16).', 'newspack-event-logger-nodes' )
		);
	}

	public function remote_segment_size_callback(): void {
		$default_raw = \Newspack_Event_Logger_Nodes\Config::load_config_defaults()['remote_segment_size'] ?? 10485760;
		$default     = \is_numeric( $default_raw ) ? (int) $default_raw : 0;
		$this->render_number_field(
			'remote_segment_size',
			$default,
			1024 * 1024,
			256 * 1024 * 1024,
			\__( 'Segment size on remote servers in bytes (1MB-256MB).', 'newspack-event-logger-nodes' )
		);
	}

	public function remote_max_lifespan_callback(): void {
		$default_raw = \Newspack_Event_Logger_Nodes\Config::load_config_defaults()['remote_max_lifespan'] ?? 3600;
		$default     = \is_numeric( $default_raw ) ? (int) $default_raw : 0;
		$this->render_number_field(
			'remote_max_lifespan',
			$default,
			60,
			604800,
			\__( 'Minimum retention on remote servers in seconds. Spokes keep data at least this long for the aggregator to pull.', 'newspack-event-logger-nodes' )
		);
	}

	/**
	 * Configured Servers field — React mount point.
	 *
	 * The server table + inline "Add New Server" form are rendered entirely by
	 * the React <ServersAdmin> app (`src/aggregator-admin/index.js`, built to
	 * `build/aggregator-admin/`), which mounts into the `#event-aggregator-servers`
	 * div below and is driven by the `servers/*` node graph. CRUD dispatches the
	 * four `servers` verbs through the shared CommandClient against the `servers`
	 * service CI on the unified `/command` endpoint. This callback used to render
	 * the table server-side from Server_Registry::get_all() + a jQuery glue
	 * script; M5.2 follow-up moved the whole view to React, so this now emits ONLY
	 * the mount node (React owns the rows + form, re-listing after each mutation
	 * instead of reloading the page).
	 */
	public function configured_servers_callback(): void {
		?>
		<div id="event-aggregator-servers"></div>
		<?php
	}

	// ---- Instrumentation field callbacks ---------------------------------

	public function log_urls_callback(): void {
		$stored = \get_option( 'newspack_event_logger_nodes_log_urls', [] );
		$values = $this->normalize_string_list( $stored );
		$this->render_array_field(
			'log_urls',
			$values,
			[],
			\__( 'Only log URLs containing these substrings. Leave empty to log all requests.', 'newspack-event-logger-nodes' ),
			'/calendar, /events/, article.fcgi'
		);
	}

	public function skip_urls_callback(): void {
		// Defaults are the operator-shipped values in the config FILE, not
		// the merged-with-WP-options view — clicking "Reset to default" on
		// a field that the operator just modified should restore the file
		// value, not the value they just typed.
		$defaults = Config::load_config_defaults();
		$default  = $this->normalize_string_list( $defaults['skip_urls'] ?? [] );
		$stored   = \get_option( 'newspack_event_logger_nodes_skip_urls', null );
		$values   = ( null === $stored || false === $stored )
			? $default
			: $this->normalize_string_list( $stored );
		$this->render_array_field(
			'skip_urls',
			$values,
			$default,
			\__( 'Never log URLs containing these substrings. Checked first — always wins over Log URLs.', 'newspack-event-logger-nodes' ),
			'/wp-cron.php, /wp-admin/admin-ajax.php'
		);
	}

	public function log_events_callback(): void {
		$defaults = Config::load_config_defaults();
		// Reset chip restores the file-default `log_events` value (not
		// `recommended_log_events` — that's a separate config key that
		// drives the Select Hooks modal's highlight, not the runtime list).
		$default  = $this->normalize_string_list( $defaults['log_events'] ?? [] );
		$stored   = \get_option( 'newspack_event_logger_nodes_log_events', [] );
		$values   = $this->normalize_string_list( $stored );
		$this->render_array_field(
			'log_events',
			$values,
			$default,
			\__( 'Hooks to time. Use Select Hooks to browse the registered set, or Reset to restore the file default.', 'newspack-event-logger-nodes' ),
			''
		);
	}

	public function custom_events_callback(): void {
		// custom_events is stored as assoc array; the React tree expects a flat
		// list of strings (the keys).
		$stored = \get_option( 'newspack_event_logger_nodes_custom_events', [] );
		$values = \is_array( $stored ) ? \array_keys( $stored ) : [];
		$values = $this->normalize_string_list( $values );
		$this->render_array_field(
			'custom_events',
			$values,
			[],
			\__( 'Custom events to time. Use Select Events to choose from the registered custom-event registry.', 'newspack-event-logger-nodes' ),
			''
		);
	}

	// ---- Workers field callbacks -----------------------------------------

	public function significant_events_callback(): void {
		$stored = \get_option( 'newspack_event_logger_nodes_significant_events', [] );
		$values = $this->normalize_string_list( $stored );
		\sort( $values, SORT_NATURAL | SORT_FLAG_CASE );
		$this->render_array_field(
			'significant_events',
			$values,
			[],
			\__( 'Events/hooks that exceeded the time threshold at least once. Protected from auto-disable. Remove to allow auto-disabling.', 'newspack-event-logger-nodes' ),
			''
		);
	}

	/**
	 * Combined "Auto-Tune" field — both thresholds inline on one row, mirroring
	 * the legacy newspack-performance-workers UI. Storage stays as two separate
	 * options; this is purely a layout consolidation.
	 *
	 * Layout: .event-logger-auto-disable-row flexbox + .event-logger-auto-disable-label
	 * spans (defined in src/performance-logger/styles/settings.scss).
	 */
	public function auto_tune_callback(): void {
		$count_value   = \get_option( 'newspack_event_logger_nodes_auto_disable_threshold', '' );
		$time_value    = \get_option( 'newspack_event_logger_nodes_auto_protect_time_threshold', '' );
		// Hide a stored 0 so the placeholder ("0") shows through — operators
		// reading "Disable if count > 0" without seeing "0" as a value
		// understand it's disabled rather than "any count > 0".
		$count_display = ( '' === $count_value || 0 === ( \is_numeric( $count_value ) ? (int) $count_value : 0 ) ) ? '' : $count_value;
		$time_display  = ( '' === $time_value || 0.0 === ( \is_numeric( $time_value ) ? (float) $time_value : 0.0 ) ) ? '' : $time_value;
		?>
		<div style="display: flex; align-items: flex-start; gap: 10px;" data-nn-reset="<?php echo \esc_attr( $this->reset_mark_name( 'auto_disable_threshold' ) ); ?>">
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

	// ---- Debugging field callbacks ---------------------------------------

	public function log_memory_callback(): void {
		$enabled = $this->bool_option_with_file_default( 'log_memory' );
		?>
		<div style="display: flex; align-items: flex-start; gap: 10px;" data-nn-reset="<?php echo \esc_attr( $this->reset_mark_name( 'log_memory' ) ); ?>">
			<div style="flex: 1;">
				<input type="hidden" name="newspack_event_logger_nodes_log_memory" value="0" />
				<label>
					<input type="checkbox" id="log_memory" name="newspack_event_logger_nodes_log_memory" value="1" <?php \checked( 1, $enabled ); ?> />
					<?php \esc_html_e( 'Append peak_mb to every complete() log entry so memory growth is visible across the request timeline.', 'newspack-event-logger-nodes' ); ?>
				</label>
			</div>
			<button type="button" class="button button-secondary" data-nn-reset-toggle
				title="<?php \esc_attr_e( 'Reset to default (toggle, then Save)', 'newspack-event-logger-nodes' ); ?>">↺</button>
		</div>
		<?php
	}

	public function flush_every_line_callback(): void {
		$enabled = $this->bool_option_with_file_default( 'flush_every_line' );
		?>
		<div style="display: flex; align-items: flex-start; gap: 10px;" data-nn-reset="<?php echo \esc_attr( $this->reset_mark_name( 'flush_every_line' ) ); ?>">
			<div style="flex: 1;">
				<input type="hidden" name="newspack_event_logger_nodes_flush_every_line" value="0" />
				<label>
					<input type="checkbox" id="flush_every_line" name="newspack_event_logger_nodes_flush_every_line" value="1" <?php \checked( 1, $enabled ); ?> />
					<?php \esc_html_e( 'Flush write buffer after every log line. Survives OOM kills — last line before crash is preserved on disk. Trades throughput for crash survivability.', 'newspack-event-logger-nodes' ); ?>
				</label>
			</div>
			<button type="button" class="button button-secondary" data-nn-reset-toggle
				title="<?php \esc_attr_e( 'Reset to default (toggle, then Save)', 'newspack-event-logger-nodes' ); ?>">↺</button>
		</div>
		<?php
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
	private function normalize_string_list( $stored ): array {
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
	 * Reset-to-defaults handler — admin-post target.
	 *
	 * Nonce + permission checks before deleting any options. Allows child
	 * plugins to extend the reset list via the
	 * `newspack_event_logger_nodes_reset_options` filter.
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

		$options = self::$option_names;
		if ( \function_exists( 'apply_filters' ) ) {
			$filtered = \apply_filters( 'newspack_event_logger_nodes_reset_options', $options );
			if ( \is_array( $filtered ) ) {
				$options = $filtered;
			}
		}
		foreach ( $options as $option ) {
			if ( \is_string( $option ) && \str_starts_with( $option, self::OPTION_PREFIX ) ) {
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
			$base_dir  = Substrate_Config::get_base_directory();
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
	 * Per-option granular worker-restart on save.
	 *
	 * Application-level options only. Substrate options (base_directory,
	 * partitioning, memcache_servers, etc.) are handled by
	 * `\Newspack_Nodes\Admin\Admin::maybe_request_worker_restart()`.
	 *
	 * Workers pick up restart requests on their next graceful exit point
	 * (segment-close in WorkerBase). Categories:
	 *
	 *  no_impact_options:        runtime-only; checked per-request, no restart needed.
	 *  supervisor_only_options:  the supervisor refreshes config each loop;
	 *                            no worker restart.
	 *  request_workers_options:  auto-disable / significant-events / stats
	 *                            salt — request-side workers (RequestBuilder,
	 *                            FlameBuilder consumer chain) re-read these.
	 *  job_workers_options:      log_events / custom_events / log_memory /
	 *                            flush_every_line — JobRouter / JobWorker
	 *                            handler registration depends on these.
	 *
	 * Worker groups in this plugin:
	 *  - `request-workers` (RequestBuilder + FlameBuilder)
	 *  - `job-workers`     (JobRouter + JobWorker)
	 *  Lock dirs: `{base_dir}/locks/{group}.p{N}.lock.d` per partition.
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

		$short = \substr( $option, \strlen( self::OPTION_PREFIX ) );

		// 1. Runtime-only options (no worker impact).
		$no_impact_options = [
			'log_urls',
			'skip_urls',
		];
		if ( \in_array( $short, $no_impact_options, true ) ) {
			return;
		}

		// 2. Supervisor-only options (it refreshes config each loop).
		$supervisor_only_options = [
			'enable_logging',
			'enable_jobs',
		];
		if ( \in_array( $short, $supervisor_only_options, true ) ) {
			return;
		}

		// 2b. Aggregator: single-partition topology with a fixed lock dir
		// (`aggregator.p0.lock.d`). Toggling enable_aggregator should kick
		// any currently-running aggregator worker so it exits within the
		// next drain tick instead of waiting out its ~595s lifetime.
		// Disabling: the topology filter no longer registers `aggregator`,
		// so SpawnController rejects the worker's self-respawn POST and
		// no replacement starts. Enabling: the supervisor's check_config
		// pass spawns one within ~15s.
		if ( 'enable_aggregator' === $short ) {
			try {
				$locks_dir = Config::get_locks_directory();
				Lock_Node::request_restart_at( "{$locks_dir}/aggregator.p0.lock.d" );
			} catch ( \Throwable $e ) {
				// Best-effort. Next supervisor pass will catch up.
			}
			return;
		}

		// 3. Request-side workers only.
		$request_workers_options = [
			'auto_disable_threshold',
			'auto_protect_time_threshold',
			'significant_events',
			'stats_salt',
		];

		// 4. Job-side workers only.
		$job_workers_options = [
			'log_events',
			'custom_events',
			'log_memory',
			'flush_every_line',
		];

		$worker_groups = [];
		if ( \in_array( $short, $request_workers_options, true ) ) {
			$worker_groups = [ 'request-workers' ];
		} elseif ( \in_array( $short, $job_workers_options, true ) ) {
			$worker_groups = [ 'job-workers' ];
		}

		if ( empty( $worker_groups ) ) {
			return;
		}

		try {
			$config             = Config::load_config();
			$locks_dir          = Config::get_locks_directory();
			$num_partitions_cfg = $config['num_partitions'] ?? 1;
			$num_partitions     = \is_numeric( $num_partitions_cfg ) ? (int) $num_partitions_cfg : 0;
		} catch ( \Throwable $e ) {
			// Locks dir not creatable, base dir misconfigured, etc. Best-effort:
			// the next supervisor pass will pick up the new config.
			return;
		}

		// Touch the restart flag file inside each affected lock dir. The lock
		// holder polls should_restart() from its drain loop and exits cleanly
		// at the next tick. No-op if the dir doesn't exist (worker was never
		// started, or dir was cleaned up after a deploy).
		for ( $p = 0; $p < $num_partitions; $p++ ) {
			foreach ( $worker_groups as $group ) {
				$lock_dir = "{$locks_dir}/{$group}.p{$p}.lock.d";
				Lock_Node::request_restart_at( $lock_dir );
			}
		}
	}

	// -- Private renderers --------------------------------------------------

	/** Hidden-input name that flags $field for per-field reset (deleted on Save). */
	private function reset_mark_name( string $field ): string {
		return Reset_Gate::mark_name( self::RESET_MARK_FIELD, self::OPTION_PREFIX . $field );
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
	private function render_array_field( string $field, array $values, array $default, string $description, string $examples = '' ): void {
		$values_json  = \wp_json_encode( $values ) ?: '';
		$default_json = \wp_json_encode( $default ) ?: '';
		?>
		<div style="display:flex; align-items:flex-start; gap:10px;" data-nn-reset="<?php echo \esc_attr( $this->reset_mark_name( $field ) ); ?>">
			<div style="flex:1;">
				<input type="hidden" id="<?php echo \esc_attr( $field ); ?>_json" name="<?php echo \esc_attr( self::OPTION_PREFIX . $field ); ?>" value="<?php echo \esc_attr( $values_json ); ?>" />
				<div id="event-logger-<?php echo \esc_attr( $field ); ?>"
					data-field="<?php echo \esc_attr( $field ); ?>"
					data-values="<?php echo \esc_attr( $values_json ); ?>"
					data-default="<?php echo \esc_attr( $default_json ); ?>"
					class="event-logger-tag-input"></div>
			</div>
			<button type="button" class="button button-secondary" data-nn-reset-toggle
				title="<?php \esc_attr_e( 'Reset to default (toggle, then Save)', 'newspack-event-logger-nodes' ); ?>">&#x21BA;</button>
		</div>
		<p class="description"><?php echo \esc_html( $description ); ?></p>
		<?php if ( '' !== $examples ) : ?>
		<p class="description"><?php \esc_html_e( 'Examples:', 'newspack-event-logger-nodes' ); ?> <?php echo \esc_html( $examples ); ?></p>
		<?php endif; ?>
		<?php
	}

	private function render_number_field( string $field, int $default, int $min, int $max, string $description ): void {
		$value = \get_option( self::OPTION_PREFIX . $field, '' );
		// Show empty (with placeholder) if not set or equals default.
		$display_value = ( '' === $value || ( \is_numeric( $value ) ? (int) $value : 0 ) === $default ) ? '' : $value;
		$input_class   = $max > 999 ? 'regular-text' : 'small-text';
		?>
		<div style="display: flex; align-items: flex-start; gap: 10px;" data-nn-reset="<?php echo \esc_attr( $this->reset_mark_name( $field ) ); ?>">
			<div style="flex: 1;">
				<input type="number" id="<?php echo \esc_attr( $field ); ?>"
					name="<?php echo \esc_attr( self::OPTION_PREFIX . $field ); ?>"
					value="<?php echo \esc_attr( \is_scalar( $display_value ) ? (string) $display_value : '' ); ?>"
					min="<?php echo \esc_attr( (string) $min ); ?>"
					max="<?php echo \esc_attr( (string) $max ); ?>"
					class="<?php echo \esc_attr( $input_class ); ?>"
					placeholder="<?php echo \esc_attr( (string) $default ); ?>" />
				<p class="description"><?php echo \esc_html( $description ); ?></p>
			</div>
			<button type="button" class="button button-secondary" data-nn-reset-toggle
				title="<?php \esc_attr_e( 'Reset to default (toggle, then Save)', 'newspack-event-logger-nodes' ); ?>">↺</button>
		</div>
		<?php
	}
}
