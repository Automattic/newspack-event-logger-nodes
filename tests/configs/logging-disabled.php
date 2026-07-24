<?php
return [
	'base_directory'   => '/tmp/event-logger-nodes-test',
	'num_partitions'   => 1,
	'segment_size'     => 1024,
	'min_segments'     => 2,
	'num_segments'     => 2,
	'min_lifetime'     => 0,
	'lifetime'         => 0,
	'enable_logging'   => false,
	'memcache_servers' => [],
	'allowed_users'    => [],
];
