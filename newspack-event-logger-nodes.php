<?php
/**
 * Plugin Name: Newspack Event Logger Nodes
 * Description: Event-logger application built on newspack-nodes runtime.
 * Version: 0.29.0
 * Author: Automattic
 * Requires Plugins: newspack-nodes
 * Requires at least: 6.5
 * Requires PHP: 8.2
 * Text Domain: newspack-event-logger-nodes
 * Domain Path: /languages
 *
 * @package Newspack_Event_Logger_Nodes
 */

\defined( 'ABSPATH' ) || exit;

if ( ! \defined( 'NEWSPACK_EVENT_LOGGER_NODES_VERSION' ) ) {
	\define( 'NEWSPACK_EVENT_LOGGER_NODES_VERSION', '0.29.0' );
}
if ( ! \defined( 'NEWSPACK_EVENT_LOGGER_NODES_DIR' ) ) {
	\define( 'NEWSPACK_EVENT_LOGGER_NODES_DIR', \plugin_dir_path( __FILE__ ) );
}
if ( ! \defined( 'NEWSPACK_EVENT_LOGGER_NODES_URL' ) ) {
	$newspack_event_logger_nodes_url = \function_exists( 'plugin_dir_url' ) ? \plugin_dir_url( __FILE__ ) : '';
	\define( 'NEWSPACK_EVENT_LOGGER_NODES_URL', $newspack_event_logger_nodes_url );
}

require_once NEWSPACK_EVENT_LOGGER_NODES_DIR . 'vendor/autoload.php';

$_newspack_event_logger_nodes_load = static function (): void {
	// XXX: BC alias; Job_Intake moved to substrate; remove next release.
	\class_exists( 'Newspack_Event_Logger_Nodes\\Job_Intake', false )
		|| \class_alias( \Newspack_Nodes\Job_Intake::class, 'Newspack_Event_Logger_Nodes\\Job_Intake' );

	if ( \defined( 'WP_CLI' ) && \WP_CLI ) {
		\WP_CLI::add_command( 'nodes reqgrep', '\\Newspack_Event_Logger_Nodes\\CLI\\Reqgrep_Command' );
		\WP_CLI::add_command( 'nodes ruleset-bench', '\\Newspack_Event_Logger_Nodes\\CLI\\Ruleset_Bench_Command' );
	}

	\add_action(
		\Newspack_Nodes\Config::RESET_ACTION,
		[ \Newspack_Event_Logger_Nodes\Config::class, 'reset_local_cache' ]
	);

	\add_action( 'newspack_nodes/job_worker/before_job', [ \Newspack_Event_Logger_Nodes\Log_Manager::class, 'begin_job_context' ] );
	\add_action( 'newspack_nodes/job_worker/after_job', [ \Newspack_Event_Logger_Nodes\Log_Manager::class, 'end_job_context' ] );

	\Newspack_Nodes\Topology_Registry::register_plugin(
		'Newspack_Event_Logger_Nodes\\',
		NEWSPACK_EVENT_LOGGER_NODES_DIR . 'topologies'
	);

	\Newspack_Nodes\Command_Interpreter_Node::register_namespace( 'Newspack_Event_Logger_Nodes\\App\\' );

	\Newspack_Nodes\Core::register_config_namespace(
		'eln',
		[ \Newspack_Event_Logger_Nodes\Config::class, 'resolve_eln_token' ]
	);

	\Newspack_Nodes\Formatters::register(
		'request-index',
		\Newspack_Event_Logger_Nodes\Request_Builder_Node::format_index_entry( ... )
	);
	\Newspack_Nodes\Formatters::register(
		'flame-index',
		\Newspack_Event_Logger_Nodes\Flame_Builder_Node::format_index_entry( ... )
	);

	// Settings-sync value-resolver filter (writer now lives in the substrate).
	\add_filter(
		'newspack_nodes/settings_sync/value',
		'newspack_event_logger_nodes_resolve_settings_sync_value',
		10,
		2
	);

	new \Newspack_Event_Logger_Nodes\App\Core();

	if ( \function_exists( 'is_admin' ) && \is_admin() ) {
		new \Newspack_Event_Logger_Nodes\Admin\Admin();
		\Newspack_Event_Logger_Nodes\Current_Request_Overlay::init();
	}
};

$_newspack_event_logger_nodes_bootstrap = static function () use (
	$_newspack_event_logger_nodes_load
): void {
	if ( ! \class_exists( '\Newspack_Nodes\Node' ) ) {
		return;
	}

	$_newspack_event_logger_nodes_load();

	\add_action( 'admin_init', [ '\\Newspack_Event_Logger_Nodes\\Config', 'correct_option_autoload' ] );
};

if ( \class_exists( '\Newspack_Nodes\Node' ) ) {
	$_newspack_event_logger_nodes_bootstrap();
} else {
	\add_action( 'plugins_loaded', $_newspack_event_logger_nodes_bootstrap, 11 );
}

// XXX: one-time ruleset migration on activation (deploy reinstalls+activates).
if ( \function_exists( 'register_activation_hook' ) ) {
	\register_activation_hook(
		__FILE__,
		static function (): void {
			if ( \class_exists( '\Newspack_Nodes\Core' ) ) {
				\Newspack_Event_Logger_Nodes\Rule_Set::migrate_from_legacy();
			}
		}
	);
}

const NEWSPACK_EVENT_LOGGER_NODES_RUNTIME_BASENAMES = [ 'firehose', 'jobintake' ];

/**
 * @param array<int, string> $producers Producers registered by prior contributors.
 * @return array<int, string>
 */
function newspack_event_logger_nodes_register_log_producers( array $producers ): array {
	return \array_values( \array_unique(
		\array_merge( $producers, NEWSPACK_EVENT_LOGGER_NODES_RUNTIME_BASENAMES )
	) );
}
\add_filter(
	'newspack_nodes/registered_log_producers',
	'newspack_event_logger_nodes_register_log_producers'
);

/**
 * Resolve a synced option's value for Settings_Sync_Node at consume time.
 *
 * Ported from the legacy Settings_Sync::maybe_queue_static_sync empty→default
 * logic: a blank ('') or absent (false) value for a synced option resolves to
 * its file-backed default — so blanking a field syncs the *default* rather than
 * '' (which would fail a spoke's typed sanitization). The default key is the
 * option name with the `newspack_event_logger_nodes_` / `newspack_nodes_` prefix
 * stripped, looked up in the OWNING config's defaults (`newspack_nodes_` keys —
 * including `remote_*` spoke geometry, which has its own defaults distinct from
 * the hub's — live in the substrate \Newspack_Nodes\Config, the rest in ELN's).
 * Non-blank values and non-synced options pass through unchanged.
 *
 * @api Hooked to `newspack_nodes/settings_sync/value` by the deferred bootstrap.
 * @param mixed  $value  The raw option value Settings_Sync_Node read (default get_option).
 * @param string $option The local WP-option name.
 * @return mixed The resolved value.
 */
function newspack_event_logger_nodes_resolve_settings_sync_value( $value, string $option ) {
	if ( '' === $value || false === $value ) {
		// Route to the OWNING config's defaults (substrate keys aren't ELN's).
		if ( 0 === \strpos( $option, 'newspack_event_logger_nodes_' ) ) {
			$config_key = \substr( $option, \strlen( 'newspack_event_logger_nodes_' ) );
			$defaults   = \Newspack_Event_Logger_Nodes\Config::load_config_defaults();
		} elseif ( 0 === \strpos( $option, 'newspack_nodes_' ) && \class_exists( '\Newspack_Nodes\Config' ) ) {
			$config_key = \substr( $option, \strlen( 'newspack_nodes_' ) );
			$defaults   = \Newspack_Nodes\Config::load_config_defaults();
		} else {
			$config_key = $option;
			$defaults   = \Newspack_Event_Logger_Nodes\Config::load_config_defaults();
		}
		$value = $defaults[ $config_key ] ?? $value;
	}

	// Inline pointer hooks so the ruleset ships hook-complete to spokes.
	if ( \Newspack_Event_Logger_Nodes\Rule_Set::OPTION_RULES === $option && \is_array( $value ) ) {
		$value = \Newspack_Event_Logger_Nodes\Rule_Set::hydrate_array( $value );
	}

	return $value;
}

( static function (): void {
	$entered = false;
	\add_action(
		'newspack_nodes/before_supervisor_run',
		static function () use ( &$entered ): void {
			\Newspack_Event_Logger_Nodes\Log_Manager::begin_job_context( 'newspack-nodes' );
			$entered = true;
		}
	);
	\add_action(
		'newspack_nodes/after_supervisor_run',
		static function () use ( &$entered ): void {
			if ( ! $entered ) {
				return;
			}
			\Newspack_Event_Logger_Nodes\Log_Manager::end_job_context();
			$entered = false;
		}
	);
} )();

function newspack_event_logger_nodes_mount_service_cis( \Newspack_Nodes\Command_Interpreter_Node $base_interpreter ): void {
	$base_interpreter->make_node( 'Discovery_CI',   'discovery' );
	$base_interpreter->make_node( 'Logger_CI',      'logger' );
	$base_interpreter->make_node( 'Events_CI',      'events' );
	$base_interpreter->make_node( 'Performance_CI', 'performance' );
	$base_interpreter->make_node( 'Rules_CI',       'rules' );
}
\add_action( 'newspack_nodes/request_graph_ready', 'newspack_event_logger_nodes_mount_service_cis' );

/**
 * React to a substrate Vault mutation by flagging a supervisor restart so a
 * new/changed server is picked up without waiting for the worker's ~10-minute
 * respawn — the restarted hub-control worker re-loads remotes from the Vault
 * and the settings-sync node graph (Settings_Sync_Node + Discovery_Collector_Node)
 * fans the current settings to them. Decoupled from the Vault via the
 * `newspack_nodes/vault/changed` action. Best-effort (the mutation never fails
 * on it).
 *
 * @param string $id     Server id that changed.
 * @param string $action added | updated | removed.
 */
function newspack_event_logger_nodes_on_vault_changed( string $id, string $action ): void {
	try {
		$base_dir = \Newspack_Nodes\Config::get_base_directory();
		$lock_dir = $base_dir . '/locks/supervisor.lock.d';
		if ( \is_dir( $lock_dir ) ) {
			\Newspack_Nodes\Lock_Node::request_restart_at( $lock_dir );
		}
	} catch ( \Throwable $e ) {
		// Best-effort.
	}
}
\add_action( 'newspack_nodes/vault/changed', 'newspack_event_logger_nodes_on_vault_changed', 10, 2 );

\add_action(
	'admin_menu',
	static function (): void {
		if ( ! \function_exists( 'add_menu_page' ) ) {
			return;
		}
		$performance_callback = static fn () => print( '<div id="event-logger-admin" class="event-logger-admin-page"></div>' );
		\add_menu_page(
			'Event Logger',
			'Event Logger',
			'manage_options',
			'event-logger-overview',
			$performance_callback,
			'dashicons-chart-line',
			80
		);
		\add_submenu_page(
			'event-logger-overview',
			'Performance Dashboard',
			'Performance',
			'manage_options',
			'event-logger-overview',
			$performance_callback
		);
		$dashboards = [
			'event-logger-errors'      => [ 'Error Log', 'Errors', '<div id="event-logger-errors" class="event-logger-admin-page"></div>' ],
			'event-logger-gyroscope'   => [ 'Gyroscope', 'Gyroscope', '<div id="event-logger-gyroscope" class="event-logger-gyroscope-page"></div>' ],
			'event-logger-requests'    => [ 'Request Log', 'Request Log', '<div id="event-logger-stream" class="event-logger-stream-page"></div>' ],
		];
		foreach ( $dashboards as $slug => [ $title, $menu_title, $mount_html ] ) {
			\add_submenu_page(
				'event-logger-overview',
				$title,
				$menu_title,
				'manage_options',
				$slug,
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $mount_html is a hardcoded constant string from $dashboards above, not user input.
			static fn () => print( $mount_html )
			);
		}
	}
);

\add_action(
	'admin_enqueue_scripts',
	static function ( string $hook ): void {
		if ( ! \function_exists( 'wp_enqueue_script' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin-page dispatch, no form data processed.
		$page = isset( $_GET['page'] ) && \is_string( $_GET['page'] ) ? \sanitize_text_field( \wp_unslash( $_GET['page'] ) ) : '';
		$page_to_tree = [
			'event-logger-overview'                  => 'overview',
			'event-logger-errors'                    => 'error-log',
			'event-logger-gyroscope'                 => 'gyroscope',
			'event-logger-requests'                  => 'requests',
			'newspack-event-logger-nodes'            => 'settings',
		];
		if ( ! \array_key_exists( $page, $page_to_tree ) ) {
			return;
		}
		$tree = $page_to_tree[ $page ];

		if ( ! \class_exists( '\Newspack_Nodes\Admin\Admin' ) ) {
			return;
		}

		$rest_url      = \function_exists( 'rest_url' ) ? \rest_url() : '/wp-json/';
		$nonce         = \function_exists( 'wp_create_nonce' ) ? \wp_create_nonce( 'wp_rest' ) : '';
		$restart_nonce       = \function_exists( 'wp_create_nonce' ) ? \wp_create_nonce( 'newspack_nodes_restart_worker' ) : '';
		$localized           = [
			'restUrl'      => \esc_url_raw( $rest_url ),
			'nonce'        => $nonce,
			'restartNonce'      => $restart_nonce,
			'tree'              => $tree,
			'version'           => \NEWSPACK_NODES_VERSION,
		];

		$handle = \Newspack_Nodes\Admin\Admin::enqueue_react_page(
			[
				'handle'           => "newspack-nodes-{$tree}",
				'page'             => $page,
				'dir'              => NEWSPACK_EVENT_LOGGER_NODES_DIR . "build/{$tree}",
				'url'              => NEWSPACK_EVENT_LOGGER_NODES_URL . "build/{$tree}",
				'version_fallback' => NEWSPACK_EVENT_LOGGER_NODES_VERSION,
				'localize'         => $localized,
			]
		);
		if ( null === $handle ) {
			return;
		}

		$retention_seconds = 86400;
		if ( \class_exists( '\\Newspack_Nodes\\Config' ) ) {
			$substrate = \Newspack_Nodes\Config::load_config();
			/** @var int|float|string|bool|null $raw_lifespan */
			$raw_lifespan      = $substrate['max_lifespan'] ?? 86400;
			$retention_seconds = (int) $raw_lifespan;
		}
		$hook_categories = [ '_colors' => [], '_patterns' => [] ];
		$hook_categories_path = NEWSPACK_EVENT_LOGGER_NODES_DIR . 'hook_categories.json';
		if ( \file_exists( $hook_categories_path ) ) {
			// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- local plugin-bundled file, not a remote URL.
			$decoded = \json_decode( (string) \file_get_contents( $hook_categories_path ), true );
			if ( \is_array( $decoded ) ) {
				$hook_categories = $decoded;
			}
		}
		$custom_colors = \Newspack_Event_Logger_Nodes\Config::get_custom_colors();
		$rest_root = \function_exists( 'rest_url' ) ? \rest_url() : '/wp-json/';
		\wp_add_inline_script(
			$handle,
			'window.eventLoggerDashboards = ' . \wp_json_encode( [
				'restUrl'           => $rest_root,
				'nonce'             => $nonce,
				'retentionSeconds'  => $retention_seconds,
			] ) . ';'
			. 'window.eventLoggerHookCategories = ' . \wp_json_encode( $hook_categories ) . ';'
			. 'window.eventLoggerCustomColors = ' . \wp_json_encode( $custom_colors ) . ';',
			'before'
		);

		// settings + overview both render pickers from these window lists.
		if ( 'settings' === $tree || 'overview' === $tree ) {
			$cfg                 = \Newspack_Event_Logger_Nodes\Config::load_config();
			$recommended         = $cfg['recommended_log_events'] ?? [];
			$recommended         = \is_array( $recommended ) ? \array_values( \array_filter( $recommended, 'is_string' ) ) : [];
			$custom_colors       = \Newspack_Event_Logger_Nodes\Config::get_custom_colors();
			\wp_add_inline_script(
				$handle,
				'window.newspackNodesRecommendedHooks = ' . \wp_json_encode( $recommended ) . ';'
				. 'window.newspackNodesCustomColors = ' . \wp_json_encode( $custom_colors ) . ';',
				'before'
			);
		}
	}
);
