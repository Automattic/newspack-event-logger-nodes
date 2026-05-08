<?php
/**
 * Admin: Settings page (top-level + Settings submenu) and per-option granular
 * worker-restart on save.
 *
 * Port of `\Newspack_Event_Logger\Admin\Admin` from the legacy plugin, adapted
 * for the nodes runtime:
 *  - Namespace `Newspack_Event_Logger_Nodes\Admin`.
 *  - WP option keys renamed from `event_logger_*` to `newspack_event_logger_nodes_*`.
 *  - Settings page slug renamed from `newspack-event-logger-settings` to
 *    `newspack-event-logger-nodes-settings`.
 *  - Settings group / page slug renamed from `event_logger_options_group` /
 *    `event_logger` to `newspack_event_logger_nodes_options_group` /
 *    `newspack_event_logger_nodes`.
 *  - Restart-channel calls `\Newspack_Nodes\Lock::request_restart_at(...)` (the
 *    static path-keyed form) — no instance needed.
 *  - Permission gate via `current_user_allowed()`: requires `manage_options`
 *    plus optional `allowed_users` whitelist from Config.
 *  - Per-option worker-restart classification (no_impact / supervisor_only /
 *    all_workers / request_workers / job_workers) preserved 1:1 with upstream.
 *    The `LogReader::get_registered_readers()` lookup is replaced with a static
 *    list of canonical worker groups (`request-workers`, `job-workers`)
 *    matching the topology naming in this plugin.
 *  - Total-storage display sums `segment_size × num_segments × num_partitions ×
 *    num_logs` where `num_logs` comes from the
 *    `newspack_event_logger_nodes_num_logs` filter (renamed from
 *    `newspack_event_logger_num_logs`).
 *
 * Does NOT mount React dashboards — that wiring stays in the main plugin file's
 * `admin_enqueue_scripts` hook. This class owns the WP-Settings-API surface
 * only: the legacy `/options-general.php?page=newspack-event-logger-nodes-settings`
 * settings page plus the top-level "Event Logger" landing menu.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Admin;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Nodes\Lock;

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
	public const MENU_SLUG = 'newspack-event-logger-nodes-settings';

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

	/**
	 * Core option names that get cleared by `handle_reset_settings()`.
	 *
	 * Kept on the class so child plugins can extend via the `…_reset_options`
	 * filter without re-listing these.
	 *
	 * @var string[]
	 */
	private static array $option_names = [
		'newspack_event_logger_nodes_enable_logging',
		'newspack_event_logger_nodes_base_directory',
		'newspack_event_logger_nodes_num_partitions',
		'newspack_event_logger_nodes_num_segments',
		'newspack_event_logger_nodes_segment_size',
		'newspack_event_logger_nodes_max_lifespan',
		'newspack_event_logger_nodes_memcache_servers',
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

		$config        = Config::load_config( 'full' );
		$allowed_users = $config['allowed_users'] ?? [];
		if ( empty( $allowed_users ) ) {
			return true;
		}

		if ( ! \function_exists( 'wp_get_current_user' ) ) {
			return true; // CLI / no user context — don't lock out admins running CLI tools.
		}
		$current_user = \wp_get_current_user();
		return $current_user && \in_array( $current_user->user_login, $allowed_users, true );
	}

	public function __construct() {
		\add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
		\add_action( 'admin_init', [ $this, 'register_settings' ] );
		\add_action( 'admin_post_' . self::RESET_ACTION, [ $this, 'handle_reset_settings' ] );

		// Per-option granular worker-restart on save. Both `added_option` (first
		// save) and `updated_option` (subsequent saves) fire this so newly-added
		// options trigger the right restart class too.
		\add_action( 'updated_option', [ $this, 'maybe_request_worker_restart' ], 10, 1 );
		\add_action( 'added_option', [ $this, 'maybe_request_worker_restart' ], 10, 1 );
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
	public function render_settings_page(): void {
		if ( ! self::current_user_allowed() ) {
			\wp_die( \esc_html__( 'You do not have permission to access this page.', 'newspack-event-logger-nodes' ) );
		}
		$reset_url = \function_exists( 'admin_url' )
			? \admin_url( 'admin-post.php' )
			: '/wp-admin/admin-post.php';
		?>
		<div class="wrap event-logger-nodes-settings-wrap">
			<h1><?php \esc_html_e( 'Event Logger Settings', 'newspack-event-logger-nodes' ); ?></h1>
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
			?>
		</div>
		<?php
	}

	/**
	 * Register settings with the WP Settings API.
	 *
	 * Wires four core options (enable_logging, base_directory, num_partitions,
	 * num_segments, segment_size, max_lifespan, memcache_servers) plus the
	 * General + Storage sections. Child plugins extend by hooking
	 * `admin_init` AFTER this runs and calling
	 * `add_settings_section/field()` on `self::SETTINGS_PAGE`.
	 */
	public function register_settings(): void {
		// Boolean toggle.
		\register_setting(
			self::OPTIONS_GROUP,
			'newspack_event_logger_nodes_enable_logging',
			[ 'sanitize_callback' => 'absint' ]
		);

		// Path. Sanitize: no null bytes, no `..`, must be absolute, trailing slash stripped.
		\register_setting(
			self::OPTIONS_GROUP,
			'newspack_event_logger_nodes_base_directory',
			[
				'sanitize_callback' => function ( $value ) {
					$value = \sanitize_text_field( $value );
					if ( \str_contains( $value, "\0" ) || \str_contains( $value, '..' ) ) {
						return '';
					}
					if ( '' === $value || '/' !== $value[0] ) {
						return '';
					}
					return \rtrim( $value, '/' );
				},
			]
		);

		// Integers — empty string preserved for "use default".
		\register_setting(
			self::OPTIONS_GROUP,
			'newspack_event_logger_nodes_num_partitions',
			[ 'sanitize_callback' => [ $this, 'sanitize_int_or_empty' ] ]
		);
		\register_setting(
			self::OPTIONS_GROUP,
			'newspack_event_logger_nodes_num_segments',
			[ 'sanitize_callback' => [ $this, 'sanitize_int_or_empty' ] ]
		);
		\register_setting(
			self::OPTIONS_GROUP,
			'newspack_event_logger_nodes_segment_size',
			[ 'sanitize_callback' => [ $this, 'sanitize_int_or_empty' ] ]
		);
		\register_setting(
			self::OPTIONS_GROUP,
			'newspack_event_logger_nodes_max_lifespan',
			[ 'sanitize_callback' => [ $this, 'sanitize_int_or_empty' ] ]
		);

		// Newline-separated host:port list. Not autoloaded (read by workers, not request path).
		\register_setting(
			self::OPTIONS_GROUP,
			'newspack_event_logger_nodes_memcache_servers',
			[
				'sanitize_callback' => [ $this, 'sanitize_memcache_servers' ],
				'autoload'          => false,
			]
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

		// Storage section.
		\add_settings_section(
			'newspack_event_logger_nodes_storage_section',
			\__( 'Storage Settings', 'newspack-event-logger-nodes' ),
			[ $this, 'storage_section_callback' ],
			self::SETTINGS_PAGE
		);
		\add_settings_field(
			'num_partitions',
			\__( 'Num Partitions', 'newspack-event-logger-nodes' ),
			[ $this, 'num_partitions_callback' ],
			self::SETTINGS_PAGE,
			'newspack_event_logger_nodes_storage_section'
		);
		\add_settings_field(
			'num_segments',
			\__( 'Num Segments', 'newspack-event-logger-nodes' ),
			[ $this, 'num_segments_callback' ],
			self::SETTINGS_PAGE,
			'newspack_event_logger_nodes_storage_section'
		);
		\add_settings_field(
			'segment_size',
			\__( 'Segment Size', 'newspack-event-logger-nodes' ),
			[ $this, 'segment_size_callback' ],
			self::SETTINGS_PAGE,
			'newspack_event_logger_nodes_storage_section'
		);
		\add_settings_field(
			'max_lifespan',
			\__( 'Minimum Retention', 'newspack-event-logger-nodes' ),
			[ $this, 'max_lifespan_callback' ],
			self::SETTINGS_PAGE,
			'newspack_event_logger_nodes_storage_section'
		);
		\add_settings_field(
			'total_storage',
			\__( 'Total Log Storage', 'newspack-event-logger-nodes' ),
			[ $this, 'total_storage_callback' ],
			self::SETTINGS_PAGE,
			'newspack_event_logger_nodes_storage_section'
		);
		\add_settings_field(
			'base_directory',
			\__( 'Base Directory', 'newspack-event-logger-nodes' ),
			[ $this, 'base_directory_callback' ],
			self::SETTINGS_PAGE,
			'newspack_event_logger_nodes_storage_section'
		);
		\add_settings_field(
			'memcache_servers',
			\__( 'Memcache Servers', 'newspack-event-logger-nodes' ),
			[ $this, 'memcache_servers_callback' ],
			self::SETTINGS_PAGE,
			'newspack_event_logger_nodes_storage_section'
		);
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
		return \absint( $input );
	}

	/**
	 * Sanitize memcache servers option (newline-separated host:port list).
	 *
	 * Underscore is allowed in hostnames so Docker container names like
	 * `mem_cache_1` validate.
	 *
	 * @param mixed $value Newline-separated server list.
	 * @return string Sanitized servers (one per line) or empty string if all invalid.
	 */
	public function sanitize_memcache_servers( $value ): string {
		if ( '' === $value || null === $value ) {
			return '';
		}
		$lines           = \explode( "\n", (string) $value );
		$sanitized_lines = [];
		foreach ( $lines as $line ) {
			$line = \trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( \preg_match( '/^[a-zA-Z0-9._\-]+:\d{1,5}$/', $line ) ) {
				$sanitized_lines[] = $line;
			}
		}
		return \implode( "\n", $sanitized_lines );
	}

	/**
	 * Sanitize an array of strings. Used by child plugins for `log_urls`,
	 * `skip_urls`, `log_events`, `custom_events`, etc. Public so child plugins
	 * can reuse it via `[ Admin::class, 'sanitize_array_strings' ]`.
	 *
	 * Coerces non-string scalars to string, drops anything non-scalar,
	 * trims whitespace, and strips empty values.
	 *
	 * @param mixed $value Array (or non-array, treated as empty).
	 * @return array Sanitized array of strings.
	 */
	public function sanitize_array_strings( $value ): array {
		if ( ! \is_array( $value ) ) {
			return [];
		}
		$result = [];
		foreach ( $value as $k => $v ) {
			if ( \is_bool( $v ) || \is_int( $v ) ) {
				// Preserve assoc-array-with-true-values shape (custom_events).
				$result[ \sanitize_text_field( (string) $k ) ] = $v;
				continue;
			}
			if ( ! \is_scalar( $v ) ) {
				continue;
			}
			$s = \trim( \sanitize_text_field( (string) $v ) );
			if ( '' !== $s ) {
				if ( \is_int( $k ) ) {
					$result[] = $s;
				} else {
					$result[ \sanitize_text_field( (string) $k ) ] = $s;
				}
			}
		}
		return $result;
	}

	// -- Section callbacks --------------------------------------------------

	public function general_section_callback(): void {
		echo '<p>' . \esc_html__( 'Enable or disable event logging.', 'newspack-event-logger-nodes' ) . '</p>';
	}

	public function storage_section_callback(): void {
		echo '<p>' . \esc_html__( 'Configure log storage and infrastructure settings.', 'newspack-event-logger-nodes' ) . '</p>';
	}

	// -- Field callbacks ----------------------------------------------------

	public function enable_logging_callback(): void {
		$enabled = \get_option( 'newspack_event_logger_nodes_enable_logging', 1 );
		?>
		<input type="hidden" name="newspack_event_logger_nodes_enable_logging" value="0" />
		<input type="checkbox" id="enable_logging" name="newspack_event_logger_nodes_enable_logging" value="1" <?php \checked( 1, $enabled ); ?> />
		<label for="enable_logging"><?php \esc_html_e( 'Enable event logging', 'newspack-event-logger-nodes' ); ?></label>
		<?php
	}

	public function base_directory_callback(): void {
		$defaults = Config::load_config_defaults();
		$this->render_directory_field(
			'base_directory',
			(string) ( $defaults['base_directory'] ?? '/tmp/newspack-nodes' ),
			\__( 'Base directory for logs, locks, and offsets.', 'newspack-event-logger-nodes' )
		);
	}

	public function num_partitions_callback(): void {
		$defaults = Config::load_config_defaults();
		$this->render_number_field(
			'num_partitions',
			(int) ( $defaults['num_partitions'] ?? 1 ),
			1,
			16,
			\__( 'Number of log partitions for parallel processing.', 'newspack-event-logger-nodes' )
		);
	}

	public function num_segments_callback(): void {
		$defaults = Config::load_config_defaults();
		$this->render_number_field(
			'num_segments',
			(int) ( $defaults['num_segments'] ?? 4 ),
			2,
			32,
			\__( 'Number of segments to retain per partition.', 'newspack-event-logger-nodes' )
		);
	}

	public function segment_size_callback(): void {
		$defaults = Config::load_config_defaults();
		$this->render_number_field(
			'segment_size',
			(int) ( $defaults['segment_size'] ?? ( 64 * 1024 * 1024 ) ),
			1048576,
			536870912,
			\__( 'Maximum segment size in bytes.', 'newspack-event-logger-nodes' )
		);
	}

	public function max_lifespan_callback(): void {
		$defaults = Config::load_config_defaults();
		$this->render_number_field(
			'max_lifespan',
			(int) ( $defaults['max_lifespan'] ?? 86400 ),
			0,
			604800,
			\__( 'Minimum retention in seconds. 0 = disabled (pure count-based).', 'newspack-event-logger-nodes' )
		);
	}

	/**
	 * Memcache servers field callback. Newline-separated `host:port` textarea
	 * with placeholder showing the configured defaults.
	 */
	public function memcache_servers_callback(): void {
		$defaults        = Config::load_config_defaults();
		$default_servers = $defaults['memcache_servers'] ?? [ '127.0.0.1:11211' ];
		if ( ! \is_array( $default_servers ) ) {
			$default_servers = [ '127.0.0.1:11211' ];
		}
		$default_text = \implode( "\n", $default_servers );
		$value        = \get_option( 'newspack_event_logger_nodes_memcache_servers', '' );
		?>
		<div style="display: flex; align-items: flex-start; gap: 10px;">
			<div style="flex: 1;">
				<textarea id="memcache_servers" name="newspack_event_logger_nodes_memcache_servers" rows="3" class="regular-text code" placeholder="<?php echo \esc_attr( $default_text ); ?>"><?php echo \esc_textarea( $value ); ?></textarea>
				<p class="description">
					<?php \esc_html_e( 'Memcache servers (one per line, format: host:port). Used for stats aggregation and SSE.', 'newspack-event-logger-nodes' ); ?>
					<br><?php \esc_html_e( 'Default:', 'newspack-event-logger-nodes' ); ?> <?php echo \esc_html( \implode( ', ', $default_servers ) ); ?>
				</p>
			</div>
			<button type="button" class="button button-secondary newspack-event-logger-nodes-reset-text"
				data-field="memcache_servers" data-default=""
				title="<?php \esc_attr_e( 'Reset to default', 'newspack-event-logger-nodes' ); ?>">↺</button>
		</div>
		<?php
	}

	/**
	 * Total-storage field. Computed bytes display: `segment_size × num_segments
	 * × num_partitions × num_logs`. `num_logs` is filterable so child plugins
	 * (Jobs, Performance, etc.) can register their additional log streams.
	 */
	public function total_storage_callback(): void {
		$defaults       = Config::load_config_defaults();
		$segment_size   = \get_option( 'newspack_event_logger_nodes_segment_size', '' );
		$num_segments   = \get_option( 'newspack_event_logger_nodes_num_segments', '' );
		$num_partitions = \get_option( 'newspack_event_logger_nodes_num_partitions', '' );

		// Use config defaults for empty values.
		$segment_size   = '' === $segment_size ? (int) ( $defaults['segment_size'] ?? ( 64 * 1024 * 1024 ) ) : (int) $segment_size;
		$num_segments   = '' === $num_segments ? (int) ( $defaults['num_segments'] ?? 4 ) : (int) $num_segments;
		$num_partitions = '' === $num_partitions ? (int) ( $defaults['num_partitions'] ?? 1 ) : (int) $num_partitions;

		$num_logs    = (int) \apply_filters( 'newspack_event_logger_nodes_num_logs', 0 );
		$total_bytes = $segment_size * $num_segments * $num_partitions * $num_logs;
		$total_mb    = \round( $total_bytes / ( 1024 * 1024 ) );
		$total_gb    = \round( $total_bytes / ( 1024 * 1024 * 1024 ), 2 );
		$segment_mb  = \round( $segment_size / ( 1024 * 1024 ) );

		if ( $total_gb >= 1 ) {
			$display = \sprintf( '%s MB (%s GB)', \number_format( $total_mb ), \number_format( $total_gb, 2 ) );
		} else {
			$display = \sprintf( '%s MB', \number_format( $total_mb ) );
		}
		?>
		<div id="total_storage_display" style="font-weight: 500; font-size: 14px; padding: 8px 0;">
			<?php echo \esc_html( $display ); ?>
		</div>
		<p class="description">
		<?php
		\printf(
			/* translators: 1: segment size in MB, 2: number of segments, 3: number of partitions, 4: number of logs */
			\esc_html__( 'Calculated as: %1$s MB segment × %2$s segments × %3$s partitions × %4$s logs', 'newspack-event-logger-nodes' ),
			\esc_html( (string) $segment_mb ),
			\esc_html( (string) $num_segments ),
			\esc_html( (string) $num_partitions ),
			\esc_html( (string) $num_logs )
		);
		?>
		</p>
		<?php
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
		$nonce = isset( $_POST[ self::RESET_NONCE ] ) ? \sanitize_text_field( \wp_unslash( $_POST[ self::RESET_NONCE ] ) ) : '';
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
		}
		exit;
	}

	/**
	 * Per-option granular worker-restart on save.
	 *
	 * Workers pick up restart requests on their next graceful exit point
	 * (segment-close in WorkerBase). Categories — preserved 1:1 from upstream
	 * with naming adapted to this plugin's worker groups:
	 *
	 *  no_impact_options:        runtime-only; checked per-request, no restart needed.
	 *  supervisor_only_options:  the supervisor refreshes config each loop;
	 *                            no worker restart.
	 *  all_workers_options:      base directory / segment layout changes —
	 *                            every worker must rebuild file handles.
	 *  request_workers_options:  memcache / auto-disable / stats salt — only
	 *                            the request-side workers (RequestBuilder,
	 *                            FlameBuilder consumer chain) need to restart.
	 *  job_workers_options:      log_events / custom_events / log_memory /
	 *                            flush_every_line — JobRouter / JobWorker
	 *                            handler registration depends on these.
	 *
	 * Worker groups in this plugin:
	 *  - `request-workers` (RequestBuilder + FlameBuilder + StatsAggregator)
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
			'num_partitions',
		];
		if ( \in_array( $short, $supervisor_only_options, true ) ) {
			return;
		}

		// 3. All workers (request + job) need restart.
		$all_workers_options = [
			'base_directory',
			'num_segments',
			'segment_size',
			'max_lifespan',
		];

		// 4. Request-side workers only.
		$request_workers_options = [
			'memcache_servers',
			'auto_disable_threshold',
			'auto_protect_time_threshold',
			'stats_salt',
		];

		// 5. Job-side workers only.
		$job_workers_options = [
			'log_events',
			'custom_events',
			'significant_events',
			'log_memory',
			'flush_every_line',
		];

		$worker_groups = [];
		if ( \in_array( $short, $all_workers_options, true ) ) {
			$worker_groups = [ 'request-workers', 'job-workers' ];
		} elseif ( \in_array( $short, $request_workers_options, true ) ) {
			$worker_groups = [ 'request-workers' ];
		} elseif ( \in_array( $short, $job_workers_options, true ) ) {
			$worker_groups = [ 'job-workers' ];
		}

		// Allow child plugins to extend the restart map for options they own.
		// Filter receives [ option_short_name => [ group1, group2, ... ] ] and
		// returns a (possibly extended) array of groups to restart.
		if ( \function_exists( 'apply_filters' ) ) {
			$filtered = \apply_filters( 'newspack_event_logger_nodes/worker_restart_groups', $worker_groups, $short );
			if ( \is_array( $filtered ) ) {
				$worker_groups = \array_values( \array_unique( \array_filter( $filtered, 'is_string' ) ) );
			}
		}

		if ( empty( $worker_groups ) ) {
			return;
		}

		try {
			$config         = Config::load_config( 'full' );
			$locks_dir      = Config::get_locks_directory();
			$num_partitions = (int) ( $config['num_partitions'] ?? 1 );
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
				Lock::request_restart_at( $lock_dir );
			}
		}
	}

	// -- Private renderers --------------------------------------------------

	private function render_directory_field( string $field, string $default, string $description ): void {
		$value = \get_option( self::OPTION_PREFIX . $field, '' );
		?>
		<div style="display: flex; align-items: flex-start; gap: 10px;">
			<div style="flex: 1;">
				<input type="text" id="<?php echo \esc_attr( $field ); ?>"
					name="<?php echo \esc_attr( self::OPTION_PREFIX . $field ); ?>"
					value="<?php echo \esc_attr( $value ); ?>"
					class="regular-text code"
					placeholder="<?php echo \esc_attr( $default ); ?>" />
				<p class="description">
					<?php echo \esc_html( $description ); ?>
					(<?php \esc_html_e( 'default', 'newspack-event-logger-nodes' ); ?>: <?php echo \esc_html( $default ); ?>)
				</p>
			</div>
			<button type="button" class="button button-secondary newspack-event-logger-nodes-reset-text"
				data-field="<?php echo \esc_attr( $field ); ?>" data-default=""
				title="<?php \esc_attr_e( 'Reset to default', 'newspack-event-logger-nodes' ); ?>">↺</button>
		</div>
		<?php
	}

	private function render_number_field( string $field, int $default, int $min, int $max, string $description ): void {
		$value = \get_option( self::OPTION_PREFIX . $field, '' );
		// Show empty (with placeholder) if not set or equals default.
		$display_value = ( '' === $value || (int) $value === $default ) ? '' : $value;
		$input_class   = $max > 999 ? 'regular-text' : 'small-text';
		?>
		<div style="display: flex; align-items: flex-start; gap: 10px;">
			<div style="flex: 1;">
				<input type="number" id="<?php echo \esc_attr( $field ); ?>"
					name="<?php echo \esc_attr( self::OPTION_PREFIX . $field ); ?>"
					value="<?php echo \esc_attr( $display_value ); ?>"
					min="<?php echo \esc_attr( (string) $min ); ?>"
					max="<?php echo \esc_attr( (string) $max ); ?>"
					class="<?php echo \esc_attr( $input_class ); ?>"
					placeholder="<?php echo \esc_attr( (string) $default ); ?>" />
				<p class="description"><?php echo \esc_html( $description ); ?></p>
			</div>
			<button type="button" class="button button-secondary newspack-event-logger-nodes-reset-number"
				data-field="<?php echo \esc_attr( $field ); ?>"
				title="<?php \esc_attr_e( 'Clear (use default)', 'newspack-event-logger-nodes' ); ?>">↺</button>
		</div>
		<?php
	}
}
