<?php
/**
 * Newspack Event Logger Nodes (application) test configuration baseline.
 *
 * Loaded via LOCAL_NEWSPACK_NODES_CONF environment variable (set in
 * phpunit.xml and bootstrap.php). Its base directory is this process's own,
 * NEWSPACK_TEST_BASE_DIR, which bootstrap.php sets. Tests that need a different
 * base_directory write their own per-test config file in setUp and
 * point LOCAL_NEWSPACK_NODES_CONF at it via TestCase::use_base_dir().
 *
 * @package Newspack_Event_Logger_Nodes
 */

return [
	'base_directory'   => \getenv( 'NEWSPACK_TEST_BASE_DIR' ) ?: throw new \LogicException( 'NEWSPACK_TEST_BASE_DIR is unset; tests/bootstrap.php sets it' ),
	'num_partitions'   => 1,
	'segment_size'     => 1024,
	'min_segments'     => 2,
	'num_segments'     => 2,
	'min_lifetime'     => 0,
	'lifetime'         => 0,
	'memcache_servers' => [],
	'enable_logging'   => false,
	'enable_jobs'      => false,
];
