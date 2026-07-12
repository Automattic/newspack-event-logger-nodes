<?php
/**
 * M2BootstrapTest: integration test for the
 * `newspack_nodes/request_graph_ready` hook that wires the nine M2
 * service CIs into the substrate's node registry.
 *
 * `HTTP_In::dispatch` lazy-builds the request-scope graph
 * (`_router` / `_command_interpreter` / `_http`) then fires
 * `newspack_nodes/request_graph_ready` so applications can mount their
 * service CIs through the base interpreter's `make_node()` — construct, name,
 * and sink in one atomic step. A single `POST /newspack-nodes/v1/command`
 * round-trip can then address each by short name.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Integration;

use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Rest\HTTP_In_Node;
use Newspack_Nodes\Router_Node;

class M2BootstrapTest extends TestCase {

	/**
	 * Wipe and re-attach both mount callbacks (substrate + app) against the
	 * `newspack_nodes/request_graph_ready` hook so this integration test
	 * exercises the production registration path with no duplicates —
	 * each plugin's own `add_action` is still in `$GLOBALS['_wp_actions']`
	 * from bootstrap, and a second `do_action` would name-collide on
	 * the second mount.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_actions']['newspack_nodes/request_graph_ready'] = [];
		\add_action( 'newspack_nodes/request_graph_ready', 'newspack_nodes_mount_substrate_cis' );
		\add_action( 'newspack_nodes/request_graph_ready', 'newspack_event_logger_nodes_mount_service_cis' );
	}

	/**
	 * Build the request-scope graph (`_router` / `_command_interpreter` /
	 * `_http`) the way `HTTP_In::dispatch` does in production,
	 * then fire `newspack_nodes/request_graph_ready` and confirm each
	 * service CI was registered under its canonical short name. This is the
	 * INTEGRATED graph: `workers`/`aggregator`/`settings`/`status` are
	 * substrate-mounted (`newspack_nodes_mount_substrate_cis`); `discovery`/
	 * `logger`/`events`/`performance` are this plugin's app CIs. The assertion
	 * is that an ELN-installed environment yields the full set.
	 *
	 * `Core::node()` returns null for unknown names, so a non-null lookup
	 * is the contract.
	 */
	public function test_request_graph_ready_registers_all_service_cis(): void {
		( new Router_Node() )->name( Node_Names::ROUTER );
		$base = new Command_Interpreter_Node();
		$base->name( Node_Names::COMMAND_INTERPRETER );
		$base->sink( Core::node( Node_Names::ROUTER ) );
		( new HTTP_In_Node( static fn ( int $code ): null => null ) )->name( Node_Names::HTTP );

		\do_action( 'newspack_nodes/request_graph_ready', $base );

		// `servers` is no longer mounted by ELN — the substrate Vault_CI owns
		// the server registry surface now.
		$expected = [
			// Substrate-mounted (newspack_nodes_mount_substrate_cis):
			'workers',
			'aggregator',
			'settings',
			'status',
			// ELN app CIs (newspack_event_logger_nodes_mount_service_cis):
			'discovery',
			'logger',
			'events',
			'performance',
		];
		foreach ( $expected as $name ) {
			$this->assertNotNull(
				Core::node( $name ),
				"interpreter '{$name}' must be registered under its short name after newspack_nodes/request_graph_ready."
			);
		}
	}

	/**
	 * M4 cutover #1 deletion: the legacy AggregatorStatusController is
	 * replaced by the `Aggregator_CI.status` verb on the unified
	 * `/newspack-nodes/v1/command` endpoint. The dashboard cutover landed
	 * in commit 1350303 and the schema-parity audit confirmed zero gaps,
	 * so the class itself must be gone — not just unreferenced.
	 *
	 * Asserting class non-existence (rather than route non-existence) is
	 * the strongest gate: a stale `register_routes()` call would crash
	 * before the route map could be inspected, and autoloader caching can
	 * resurrect a forgotten file across deploys.
	 */
	public function test_legacy_aggregator_status_controller_class_is_gone(): void {
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Rest\\AggregatorStatusController' ),
			'Legacy AggregatorStatusController class must be deleted; Aggregator_CI.status verb replaces it.'
		);
	}

	public function test_legacy_logger_controller_class_is_gone(): void {
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Rest\\LoggerController' ),
			'Legacy LoggerController class must be deleted; Logger_CI verbs replace it.'
		);
	}

	public function test_legacy_perf_hooks_controller_class_is_gone(): void {
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Rest\\PerfHooksController' ),
			'Legacy PerfHooksController class must be deleted; Performance_CI.hooks_registered + .hooks_categories verbs replace it.'
		);
	}

	public function test_legacy_gyroscope_controller_class_is_gone(): void {
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Rest\\GyroscopeController' ),
			'Legacy GyroscopeController (non-streaming /gyroscope/timeline) must be deleted; GyroscopeStreamController stays for SSE.'
		);
	}

	public function test_legacy_request_log_controller_class_is_gone(): void {
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Rest\\RequestLogController' ),
			'Legacy RequestLogController must be deleted; Performance_CI.request_log_list + .request_log_detail replace it. SSE side is in RequestsStreamController which stays.'
		);
	}

	public function test_legacy_workers_controller_class_is_gone(): void {
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Rest\\WorkersController' ),
			'Legacy WorkersController must be deleted; substrate Workers_CI.dump_metadata + .restart replace it.'
		);
	}

	public function test_legacy_firehose_controller_class_is_gone(): void {
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Rest\\FirehoseController' ),
			'Legacy FirehoseController must be deleted; /logs → substrate Raw_Logs_CI.firehose_logs; /heartbeat → substrate Workers_CI.heartbeat; /status → substrate Raw_Logs_CI.firehose_status. FirehoseStreamController is gone too (M6.9b) — RemoteSource consumes the substrate /messages/stream endpoint directly.'
		);
	}

	// M6 — SSE consolidation onto `/messages/stream`. Gate every deleted
	// class so accidental re-registration (autoloader regression, partial
	// revert) trips here instead of silently double-handling SSE traffic.
	public function test_legacy_rawlogs_controller_class_is_gone(): void {
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Rest\\RawlogsController' ),
			'RawlogsController deleted in M6.9 — RawLogs.js subscribes to /messages/stream directly.'
		);
	}

	public function test_legacy_errors_stream_controller_class_is_gone(): void {
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Rest\\ErrorsStreamController' ),
			'ErrorsStreamController deleted in M6.9 — ErrorLog.js subscribes to /messages/stream directly.'
		);
	}

	public function test_legacy_requests_stream_controller_class_is_gone(): void {
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Rest\\RequestsStreamController' ),
			'RequestsStreamController deleted in M6.9 — RequestStream.js subscribes to /messages/stream against completed.log directly.'
		);
	}

	public function test_legacy_gyroscope_stream_controller_class_is_gone(): void {
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Rest\\GyroscopeStreamController' ),
			'GyroscopeStreamController deleted in M6.9 — Inflight.js subscribes to /messages/stream against gyroscope.log directly.'
		);
	}

	public function test_legacy_firehose_stream_controller_class_is_gone(): void {
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Rest\\FirehoseStreamController' ),
			'FirehoseStreamController deleted in M6.9b — RemoteSource consumes the substrate /messages/stream endpoint with subscribe=firehose.pN.'
		);
	}

	public function test_legacy_sse_controller_base_class_is_gone(): void {
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Rest\\SSEControllerBase' ),
			'SSEControllerBase deleted in M6.10 — all 5 subclasses are gone; substrate SSE_Out_Node owns the SSE wire-format helpers (inlined).'
		);
	}

	public function test_legacy_partition_reader_class_is_gone(): void {
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Partition_Reader' ),
			'Partition_Reader deleted in M6.10 — substrate Consumer is the replacement primitive for log-feed tailing.'
		);
	}

	public function test_legacy_inflight_tracker_class_is_gone(): void {
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\InflightTracker' ),
			'InflightTracker deleted in M6.8 — gyroscope.log carries both inflight + completion record shapes pre-aggregated upstream by RequestFlight + completed:tee; Inflight.js dispatches client-side.'
		);
	}

	public function test_legacy_perf_overview_controller_class_is_gone(): void {
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Rest\\PerfOverviewController' ),
			'Legacy PerfOverviewController must be deleted; Performance_CI.overview replaces it.'
		);
	}

	public function test_legacy_perf_urls_controller_class_is_gone(): void {
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Rest\\PerfUrlsController' ),
			'Legacy PerfUrlsController must be deleted; Performance_CI.urls + .url_detail replace it.'
		);
	}

	public function test_legacy_perf_requests_controller_class_is_gone(): void {
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Rest\\PerfRequestsController' ),
			'Legacy PerfRequestsController must be deleted; Performance_CI.request_search + .request_detail replace it.'
		);
	}

	public function test_legacy_performance_controller_class_is_gone(): void {
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Rest\\PerformanceController' ),
			'Legacy PerformanceController must be deleted; its /performance/dashboard + /performance/timing routes had no JS callers and it delegated to PerfOverviewController + PerfUrlsController (deleted in the same batch).'
		);
	}

	public function test_legacy_performance_controller_base_class_is_gone(): void {
		// Orphaned helper (capability check, partition validation, fixed-window
		// rate limit, not_found_error shape) with no production callers — all
		// service CIs use Service_CI_Node::require_manage_options() instead.
		// The whole includes/rest/ directory was removed with it.
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Rest\\Performance_Controller_Base' ),
			'Orphaned Performance_Controller_Base must be deleted; service CIs use Service_CI_Node helpers instead.'
		);
	}

	public function test_legacy_aggregator_controller_class_is_gone(): void {
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Rest\\AggregatorController' ),
			'Legacy AggregatorController (3-route stub: /status + /servers + /health) must be deleted; Aggregator_CI verbs (status/health/servers) replace it. The Aggregator dashboard cut over in M4.1.'
		);
	}

	public function test_legacy_discovery_controller_class_is_gone(): void {
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Rest\\DiscoveryController' ),
			'Legacy DiscoveryController must be deleted; Discovery_CI.get replaces it (M2 Task 2).'
		);
	}

	public function test_legacy_events_controller_class_is_gone(): void {
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Rest\\EventsController' ),
			'Legacy EventsController must be deleted; Events_CI.recent + .stats verbs replace it (M2 Task 5).'
		);
	}

	public function test_legacy_status_controller_class_is_gone(): void {
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Rest\\StatusController' ),
			'Legacy StatusController must be deleted; Status_CI.get replaces it (M2 Task 2b).'
		);
	}

	public function test_legacy_perf_config_controller_class_is_gone(): void {
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Rest\\PerfConfigController' ),
			'Legacy PerfConfigController must be deleted; Performance_CI.config_get replaces it (M2 Task 11). No JS callers, no server-to-server callers.'
		);
	}

	public function test_legacy_perf_hooks_available_controller_class_is_gone(): void {
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Rest\\PerfHooksAvailableController' ),
			'Legacy PerfHooksAvailableController must be deleted; Performance_CI.hooks_available + .hooks_configure replace it (M2 Task 10). No JS callers, no server-to-server callers.'
		);
	}

	public function test_legacy_servers_controller_class_is_gone(): void {
		// M5.2a cut the only JS caller (aggregator-admin.js) over to the
		// CommandClient → `servers` CI; M5.2c deletes the route. The verb
		// table on Servers_CI is value-equivalent (same {id, url, enabled,
		// logs, has_credentials, is_config} shape, same auth gate, same
		// HTTPS-only URL validation).
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Rest\\ServersController' ),
			'Legacy ServersController must be deleted; Servers_CI.list + .get + .add + .update + .delete + .test verbs replace it (M2 Task 6).'
		);
	}

	public function test_legacy_settings_controller_class_is_gone(): void {
		// M5.2b cut RemoteManager off /settings; the legacy route now has
		// zero callers. Settings_CI.update takes the same four substrate
		// integer settings (num_partitions, max_segments, segment_size,
		// min_lifetime) with the same bounds + manage_options gate.
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Rest\\SettingsController' ),
			'Legacy SettingsController must be deleted; Settings_CI.update replaces it (M2 Task 3).'
		);
	}

	public function test_legacy_perf_settings_controller_class_is_gone(): void {
		// M5.2b cut RemoteManager off /performance/settings; the legacy
		// route now has zero callers. Performance_CI.settings_update takes
		// the same nine perf-tuning options with the same per-type
		// sanitization (int/float/bool/array depth caps).
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Rest\\PerfSettingsController' ),
			'Legacy PerfSettingsController must be deleted; Performance_CI.settings_update replaces it (M2 Task 11).'
		);
	}
}
