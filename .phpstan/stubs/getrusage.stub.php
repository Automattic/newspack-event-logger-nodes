<?php
/**
 * Refine getrusage()'s return type to PHP's documented array shape.
 *
 * PHP core types getrusage() as plain `array`, so every $usage['ru_...']
 * read in Log_Manager::log_resources() and the arithmetic on the tv_sec /
 * tv_usec fields degrades to `mixed` at level 10. This stub (registered
 * under stubFiles, which override the bundled reflection) declares the
 * documented int-keyed shape so those accesses type as `int`. Keys are
 * marked optional (`?`) — fields vary by platform and the caller reads them
 * with `?? 0` — so the defensive reads stay valid. Analysis-only; never
 * affects runtime.
 */

// phpcs:disable

/**
 * @return array{
 *     "ru_utime.tv_sec"?: int,
 *     "ru_utime.tv_usec"?: int,
 *     "ru_stime.tv_sec"?: int,
 *     "ru_stime.tv_usec"?: int,
 *     ru_maxrss?: int,
 *     ru_ixrss?: int,
 *     ru_idrss?: int,
 *     ru_isrss?: int,
 *     ru_minflt?: int,
 *     ru_majflt?: int,
 *     ru_nswap?: int,
 *     ru_inblock?: int,
 *     ru_oublock?: int,
 *     ru_msgsnd?: int,
 *     ru_msgrcv?: int,
 *     ru_nsignals?: int,
 *     ru_nvcsw?: int,
 *     ru_nivcsw?: int
 * }
 */
function getrusage( int $mode = 0 ) {}
