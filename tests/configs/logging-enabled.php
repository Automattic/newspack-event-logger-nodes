<?php
return [
	'base_directory'   => '/tmp/event-logger-nodes-test',
	'num_partitions'   => 1,
	'num_segments'     => 2,
	'segment_size'     => 4096,
	'max_lifespan'     => 0,
	'enable_logging'   => true,
	'enable_workers'   => false,
	'memcache_servers' => [],
	'allowed_users'    => [],
	'skip_urls'        => [],
	'log_urls'         => [],
	'custom_colors'    => [],
	'custom_events'    => [],
	'log_events'       => [],
	'log_memory'       => false,
	'flush_every_line' => true,
];
