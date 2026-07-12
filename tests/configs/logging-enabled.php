<?php
return [
	'base_directory'   => '/tmp/event-logger-nodes-test',
	'num_partitions'   => 1,
	'num_segments'     => 2,
	'segment_size'     => 4096,
	'max_lifespan'     => 0,
	'min_segments'     => 2,
	'max_segments'     => 2,
	'min_lifetime'     => 0,
	'max_lifetime'     => 0,
	'enable_logging'   => true,
	'memcache_servers' => [],
	'allowed_users'    => [],
	'custom_colors'    => [],
	'log_memory'       => false,
	'flush_every_line' => true,
];
