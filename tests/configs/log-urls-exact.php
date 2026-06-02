<?php
return [
	'base_directory'   => '/tmp/event-logger-nodes-test',
	'num_partitions'   => 1,
	'num_segments'     => 2,
	'segment_size'     => 1024,
	'max_lifespan'     => 0,
	'enable_logging'   => true,
	'memcache_servers' => [],
	'allowed_users'    => [],
	'skip_urls'        => [],
	'log_urls'         => [ '/news?' ],
];
