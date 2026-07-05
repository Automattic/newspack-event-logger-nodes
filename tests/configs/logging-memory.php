<?php
return [
	'base_directory'   => '/tmp/event-logger-nodes-test',
	'num_partitions'   => 1,
	'num_segments'     => 2,
	'segment_size'     => 4096,
	'max_lifespan'     => 0,
	'enable_logging'   => true,
	'memcache_servers' => [],
	'allowed_users'    => [],
	'custom_colors'    => [],
	'log_memory'       => true,
	'flush_every_line' => true,
];
