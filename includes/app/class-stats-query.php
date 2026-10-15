<?php
/**
 * Stats_Query: the fixed vocabulary every performance reader asks the
 * `flame-facts` rows in.
 *
 * A spec names a source, the fields to group and filter by, the measures to
 * answer and the buckets to read. `table_for()` picks the cheapest table that
 * answers it exactly, and `run()` builds one statement over every
 * partition's file: a `UNION ALL` of one arm per attached alias, each joining
 * its own `urls` and `agents` before the union, since their ids are
 * partition-local. Field and measure names reach the SQL only through this
 * class's tables; every value binds.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Bucket;
use Newspack_Event_Logger_Nodes\Flame_Builder_Node;
use Newspack_Event_Logger_Nodes\Flame_Facts_Row_Schema;
use Newspack_Event_Logger_Nodes\Url_Parts;
use Newspack_Nodes\Core;
use Newspack_Nodes\Row_Reader;
use Newspack_Nodes\Row_Table_Node;
use Newspack_Nodes\Table_Unavailable;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An absent status, country, agent, JA4 or From answers `Unknown`, as does a
 * status outside the builder's status bounds; a request with no error answers
 * `-`, the record's own spelling. A filter matches what a group answers,
 * `Unknown` included. Worker requests count unless a filter leaves them out.
 * A sort ranks a null last in either order, and a `url` group also sorts by
 * its `url_name`, server then path.
 *
 * @phpstan-type Spec array{source:string, group:list<string>, filter:array<string,list<scalar|null>>, measure:list<string>, buckets:list<array{0:int,1:int}>, sort:string, order:string, limit:int, offset:int, search:string, match:string}
 */
final class Stats_Query {

	/** Group/filter fields per source; each maps to a SQL expression over the source's columns. */
	public const FIELDS = [
		'requests' => [ 'bucket', 'hour', 'day', 'server', 'url', 'status', 'status_class', 'method', 'country', 'ua', 'ja4', 'from', 'worker', 'profiled', 'error' ],
		'timings'  => [ 'bucket', 'hour', 'server', 'url', 'worker', 'category', 'entry', 'listener' ],
	];

	/** What each source measures over the rows a spec selects. */
	public const MEASURES = [
		'requests' => [ 'count', 'timed', 'sum_ms', 'avg_ms', 'max_ms', 'min_ms', 'p50_ms', 'p95_ms', 'sum_peak_mb', 'avg_peak_mb', 'max_peak_mb', 'errors', 'urls', 'last_seen', 'count_2xx', 'count_3xx', 'count_4xx', 'count_5xx', 'worker' ],
		'timings'  => [ 'ms', 'calls', 'requests', 'avg_ms', 'avg_calls' ],
	];

	/** Most rows one answer holds. */
	public const MAX_LIMIT = 1000;

	/** The farthest row a page may start at. */
	public const MAX_OFFSET = 10000;

	/** Fields holding integers, so a filter's digits bind as one. */
	private const INTEGER_FIELDS = [ 'bucket', 'hour', 'day', 'status', 'status_class', 'worker', 'profiled', 'listener' ];

	/** The fields `requests_hourly` answers exactly. */
	private const HOURLY_FIELDS = [ 'hour', 'day', 'server', 'status_class', 'method', 'worker', 'country' ];

	/** The measures `requests_hourly` answers exactly. */
	private const HOURLY_MEASURES = [ 'count', 'timed', 'sum_ms', 'avg_ms', 'max_ms', 'sum_peak_mb', 'avg_peak_mb', 'errors' ];

	/** Buckets an hour holds. */
	private const BUCKETS_PER_HOUR = Bucket::HOUR_SECONDS / Bucket::SECONDS;

	/** Buckets a UTC day holds. */
	private const BUCKETS_PER_DAY = 24 * self::BUCKETS_PER_HOUR;

	/** What an absent value answers. */
	private const UNKNOWN = 'Unknown';

	/** A status code the builder reads a status class from, as SQL. */
	private const KNOWN_STATUS = 't.status BETWEEN ' . Flame_Builder_Node::STATUS_MIN . ' AND ' . Flame_Builder_Node::STATUS_MAX;

	/** The measures reading each group's timed requests ranked by `ms`. */
	private const RANKED = [ 'p50_ms', 'p95_ms' ];

	/** The join a URL field, input or search needs. */
	private const URL_JOIN = 'JOIN %1$s.urls AS u ON u.id = t.url_id';

	/** A search: the arm's URL among those its path index matches. */
	private const SEARCH = 'u.id IN ( SELECT rowid FROM %1$s.urls_fts WHERE urls_fts MATCH ? )';

	/** The timings measures, over either timings table's arm inputs. */
	private const TIMING_MEASURES = [
		'ms'        => [ 'TOTAL( m_ms )', [ 'ms' ] ],
		'calls'     => [ 'COALESCE( SUM( m_calls ), 0 )', [ 'calls' ] ],
		'requests'  => [ 'COALESCE( SUM( m_requests ), 0 )', [ 'requests' ] ],
		'avg_ms'    => [ 'SUM( m_ms ) / NULLIF( SUM( m_requests ), 0 )', [ 'ms', 'requests' ] ],
		'avg_calls' => [ 'CAST( SUM( m_calls ) AS REAL ) / NULLIF( SUM( m_requests ), 0 )', [ 'calls', 'requests' ] ],
	];

	/** The timing inputs either timings table's arm projects. */
	private const TIMING_INPUTS = [
		'ms'       => 't.ms',
		'calls'    => 't.calls',
		'requests' => 't.requests',
	];

	/**
	 * Each table's SQL over its row `t`, the URL `u` and the agent `a`: the
	 * column its bucket ranges read and how many buckets one of its units
	 * holds; each field's expression; the join a field or input needs, by
	 * name; each input an arm projects as `m_<name>`; and each measure's
	 * aggregate over those inputs, with the inputs it reads, its
	 * `{error_kinds}` the builder's `ERROR_KINDS`.
	 */
	private const TABLES = [
		'requests'        => [
			'range'    => [ 't.bucket', 1 ],
			'fields'   => [
				'bucket'       => 't.bucket',
				'hour'         => 't.bucket / ' . self::BUCKETS_PER_HOUR,
				'day'          => 't.bucket / ' . self::BUCKETS_PER_DAY,
				'server'       => 't.server',
				'url'          => 'u.hash',
				'status'       => 'CASE WHEN ' . self::KNOWN_STATUS . " THEN t.status ELSE '" . self::UNKNOWN . "' END",
				'status_class' => 'CASE WHEN ' . self::KNOWN_STATUS . " THEN t.status / 100 ELSE '" . self::UNKNOWN . "' END",
				'method'       => 't.method',
				'country'      => "COALESCE( t.country, '" . self::UNKNOWN . "' )",
				'ua'           => "COALESCE( a.ua, '" . self::UNKNOWN . "' )",
				'ja4'          => "COALESCE( t.ja4, '" . self::UNKNOWN . "' )",
				'from'         => "COALESCE( t.from_header, '" . self::UNKNOWN . "' )",
				'worker'       => 't.worker',
				'profiled'     => 't.profiled',
				'error'        => "COALESCE( t.error, '-' )",
			],
			'joins'    => [
				'url' => self::URL_JOIN,
				'ua'  => 'LEFT JOIN %1$s.agents AS a ON a.id = t.ua_id',
			],
			'inputs'   => [
				'ms'          => 't.ms',
				'peak_mb'     => 't.peak_mb',
				'error'       => 't.error',
				'finished_at' => 't.finished_at',
				'url'         => 'u.hash',
				'class'       => 'CASE WHEN ' . self::KNOWN_STATUS . ' THEN t.status / 100 END',
				'worker'      => 't.worker',
			],
			'measures' => [
				'count'       => [ 'COUNT( * )', [] ],
				'timed'       => [ 'COUNT( m_ms )', [ 'ms' ] ],
				'sum_ms'      => [ 'TOTAL( m_ms )', [ 'ms' ] ],
				'avg_ms'      => [ 'AVG( m_ms )', [ 'ms' ] ],
				'max_ms'      => [ 'MAX( m_ms )', [ 'ms' ] ],
				'min_ms'      => [ 'MIN( m_ms )', [ 'ms' ] ],
				'p50_ms'      => [ 'MAX( CASE WHEN m_rank = ( m_ranked * 50 + 99 ) / 100 THEN m_ms END )', [ 'ms' ] ],
				'p95_ms'      => [ 'MAX( CASE WHEN m_rank = ( m_ranked * 95 + 99 ) / 100 THEN m_ms END )', [ 'ms' ] ],
				'sum_peak_mb' => [ 'TOTAL( m_peak_mb )', [ 'peak_mb' ] ],
				'avg_peak_mb' => [ 'AVG( m_peak_mb )', [ 'peak_mb' ] ],
				'max_peak_mb' => [ 'MAX( m_peak_mb )', [ 'peak_mb' ] ],
				'errors'      => [ 'COUNT( CASE WHEN m_error IN ( {error_kinds} ) THEN 1 END )', [ 'error' ] ],
				'urls'        => [ 'COUNT( DISTINCT m_url )', [ 'url' ] ],
				'last_seen'   => [ 'MAX( m_finished_at )', [ 'finished_at' ] ],
				'count_2xx'   => [ 'COUNT( CASE WHEN m_class = 2 THEN 1 END )', [ 'class' ] ],
				'count_3xx'   => [ 'COUNT( CASE WHEN m_class = 3 THEN 1 END )', [ 'class' ] ],
				'count_4xx'   => [ 'COUNT( CASE WHEN m_class = 4 THEN 1 END )', [ 'class' ] ],
				'count_5xx'   => [ 'COUNT( CASE WHEN m_class = 5 THEN 1 END )', [ 'class' ] ],
				'worker'      => [ 'MAX( m_worker )', [ 'worker' ] ],
			],
		],
		'requests_hourly' => [
			'range'    => [ 't.hour', self::BUCKETS_PER_HOUR ],
			'fields'   => [
				'hour'         => 't.hour',
				'day'          => 't.hour / 24',
				'server'       => 't.server',
				'status_class' => "CASE WHEN t.status_class = 0 THEN '" . self::UNKNOWN . "' ELSE t.status_class END",
				'method'       => 't.method',
				'worker'       => 't.worker',
				'country'      => "CASE WHEN t.country = '' THEN '" . self::UNKNOWN . "' ELSE t.country END",
			],
			'joins'    => [],
			'inputs'   => [
				'count'       => 't.count',
				'timed'       => 't.timed',
				'sum_ms'      => 't.sum_ms',
				'max_ms'      => 't.max_ms',
				'sum_peak_mb' => 't.sum_peak_mb',
				'errors'      => 't.errors',
			],
			'measures' => [
				'count'       => [ 'COALESCE( SUM( m_count ), 0 )', [ 'count' ] ],
				'timed'       => [ 'COALESCE( SUM( m_timed ), 0 )', [ 'timed' ] ],
				'sum_ms'      => [ 'TOTAL( m_sum_ms )', [ 'sum_ms' ] ],
				'avg_ms'      => [ 'SUM( m_sum_ms ) / NULLIF( SUM( m_timed ), 0 )', [ 'sum_ms', 'timed' ] ],
				'max_ms'      => [ 'CASE WHEN SUM( m_timed ) > 0 THEN MAX( m_max_ms ) END', [ 'max_ms', 'timed' ] ],
				'sum_peak_mb' => [ 'TOTAL( m_sum_peak_mb )', [ 'sum_peak_mb' ] ],
				'avg_peak_mb' => [ 'SUM( m_sum_peak_mb ) / NULLIF( SUM( m_count ), 0 )', [ 'sum_peak_mb', 'count' ] ],
				'errors'      => [ 'COALESCE( SUM( m_errors ), 0 )', [ 'errors' ] ],
			],
		],
		'timings'         => [
			'range'    => [ 't.bucket', 1 ],
			'fields'   => [
				'bucket'   => 't.bucket',
				'hour'     => 't.bucket / ' . self::BUCKETS_PER_HOUR,
				'server'   => 't.server',
				'url'      => 'u.hash',
				'category' => 't.category',
				'entry'    => 't.entry',
				'listener' => 't.listener',
			],
			'joins'    => [ 'url' => self::URL_JOIN ],
			'inputs'   => self::TIMING_INPUTS,
			'measures' => self::TIMING_MEASURES,
		],
		'timings_site'    => [
			'range'    => [ 't.bucket', 1 ],
			'fields'   => [
				'bucket'   => 't.bucket',
				'hour'     => 't.bucket / ' . self::BUCKETS_PER_HOUR,
				'server'   => 't.server',
				'worker'   => 't.worker',
				'category' => 't.category',
				'entry'    => 't.entry',
				'listener' => 't.listener',
			],
			'joins'    => [],
			'inputs'   => self::TIMING_INPUTS,
			'measures' => self::TIMING_MEASURES,
		],
	];

	/**
	 * Answer a spec over every partition's rows. A fleet whose stats file
	 * will not open says so once a window and answers no rows (decision 3).
	 *
	 * @param array{source:string, group:list<string>, filter:array<string,list<scalar|null>>, measure:list<string>,
	 *              buckets:list<array{0:int,1:int}>, sort?:string, order?:string, limit?:int, offset?:int, search?:string} $spec
	 * @return array{source:string, table:string, columns:list<string>, rows:list<list<mixed>>}
	 * @throws \InvalidArgumentException naming an unknown source, field, measure
	 *                                   or sort, or a field the table read lacks.
	 */
	public static function run( array $spec ): array {
		$spec  = self::checked( $spec );
		$table = self::table_for( $spec );
		foreach ( [ ...$spec['group'], ...\array_keys( $spec['filter'] ) ] as $field ) {
			if ( ! isset( self::TABLES[ $table ]['fields'][ $field ] ) ) {
				throw new \InvalidArgumentException( \esc_html( "a {$spec['source']} query naming these fields reads {$table}, which holds no {$field}" ) );
			}
		}
		$columns = self::columns( $spec );
		try {
			$rows = Row_Table_Node::read( Flame_Facts_Row_Schema::TABLE, static fn ( Row_Reader $reader ): array => self::rows( $reader, $spec, $table ) );
		} catch ( Table_Unavailable $e ) {
			Core::print_less_often( 'performance: stats rows did not open', ' — ' . $e->getMessage() );
			$rows = [];
		}
		return [
			'source'  => $spec['source'],
			'table'   => $table,
			'columns' => $columns,
			'rows'    => $rows,
		];
	}

	/**
	 * The spec's rows across the attached partitions, each `url` group's
	 * server and path joined into its `url_name`; none when no partition
	 * has written a file.
	 *
	 * @param Row_Reader $reader The attached files.
	 * @param Spec       $spec   The checked spec.
	 * @param string     $table  The table answering it.
	 * @return list<list<mixed>>
	 */
	private static function rows( Row_Reader $reader, array $spec, string $table ): array {
		$aliases = $reader->aliases();
		if ( [] === $aliases ) {
			return [];
		}
		[ $sql, $binds ] = self::statement( $spec, $table, $aliases );
		$rows            = $reader->rows( $sql, $binds );
		if ( ! \in_array( 'url', $spec['group'], true ) ) {
			return $rows;
		}
		return \array_map(
			static function ( array $row ) use ( $spec ): array {
				$named = [];
				foreach ( $spec['group'] as $field ) {
					$named[] = \array_shift( $row );
					if ( 'url' === $field ) {
						$named[] = Url_Parts::join_url( Core::as_string( \array_shift( $row ) ), Core::as_string( \array_shift( $row ) ) );
					}
				}
				return [ ...$named, ...$row ];
			},
			$rows
		);
	}

	/**
	 * One statement answering the spec over `$aliases`, and its binds in
	 * the order its placeholders appear.
	 *
	 * @param Spec         $spec    The checked spec.
	 * @param string       $table   The table answering it.
	 * @param list<string> $aliases The attached partitions.
	 * @return array{0:string, 1:list<scalar|null>}
	 */
	private static function statement( array $spec, string $table, array $aliases ): array {
		$def    = self::TABLES[ $table ];
		$fields = \array_values( \array_unique( [ ...$spec['group'], ...\array_keys( $spec['filter'] ) ] ) );
		$inputs = [];
		foreach ( $spec['measure'] as $name ) {
			\array_push( $inputs, ...$def['measures'][ $name ][1] );
		}
		$inputs = \array_values( \array_unique( $inputs ) );

		[ $arm, $arm_binds ] = self::arm( $spec, $table, $fields, $inputs );
		$from                = '( ' . \implode( ' UNION ALL ', \array_map( static fn ( string $alias ): string => \sprintf( $arm, $alias ), $aliases ) ) . ' )';
		$binds               = \array_merge( ...\array_fill( 0, \count( $aliases ), $arm_binds ) );

		$groups = [];
		$select = [];
		$sorts  = [];
		foreach ( $spec['group'] as $i => $field ) {
			$groups[]        = "f{$i}";
			$select[]        = "f{$i}";
			$sorts[ $field ] = [ "f{$i}" ];
			if ( 'url' === $field ) {
				\array_push( $select, "MIN( s{$i} )", "MIN( n{$i} )" );
				$sorts['url_name'] = [ "MIN( s{$i} )", "MIN( n{$i} )" ];
			}
		}
		if ( [] !== \array_intersect( $spec['measure'], self::RANKED ) ) {
			$over = [] === $groups ? '' : 'PARTITION BY ' . \implode( ', ', $groups ) . ' ';
			$from = "( SELECT *, ROW_NUMBER() OVER ( {$over}ORDER BY m_ms IS NULL, m_ms ) AS m_rank, COUNT( m_ms ) OVER ( {$over}) AS m_ranked FROM {$from} )";
		}
		$kinds = "'" . \implode( "', '", Flame_Builder_Node::ERROR_KINDS ) . "'";
		foreach ( $spec['measure'] as $j => $name ) {
			$select[]       = \str_replace( '{error_kinds}', $kinds, $def['measures'][ $name ][0] ) . " AS v{$j}";
			$sorts[ $name ] = [ "v{$j}" ];
		}
		$order = $groups;
		if ( '' !== $spec['sort'] ) {
			$sorted    = $sorts[ $spec['sort'] ] ?? throw new \LogicException( \esc_html( "sort {$spec['sort']} names no group or measure" ) );
			$direction = ' ' . \strtoupper( $spec['order'] ) . ' NULLS LAST';
			\array_unshift( $order, ...\array_map( static fn ( string $column ): string => $column . $direction, $sorted ) );
		}
		$sql = 'SELECT ' . \implode( ', ', $select ) . " FROM {$from}"
			. ( [] === $groups ? '' : ' GROUP BY ' . \implode( ', ', $groups ) )
			. ( [] === $order ? '' : ' ORDER BY ' . \implode( ', ', $order ) )
			. ' LIMIT ? OFFSET ?';
		return [ $sql, [ ...$binds, $spec['limit'], $spec['offset'] ] ];
	}

	/**
	 * One partition's arm, its alias a `%1$s` placeholder: each group field
	 * as `f<i>` (a URL's server and path beside it as `s<i>` and `n<i>`), each input as
	 * `m_<name>`, over the bucket ranges, the filters and the search.
	 *
	 * @param Spec         $spec   The checked spec.
	 * @param string       $table  The table answering it.
	 * @param list<string> $fields Every field the spec groups or filters.
	 * @param list<string> $inputs Every input its measures read.
	 * @return array{0:string, 1:list<scalar|null>}
	 */
	private static function arm( array $spec, string $table, array $fields, array $inputs ): array {
		$def    = self::TABLES[ $table ];
		$expr   = $def['fields'];
		$select = [];
		foreach ( $spec['group'] as $i => $field ) {
			$select[] = Core::as_string( $expr[ $field ] ) . " AS f{$i}";
			if ( 'url' === $field ) {
				\array_push( $select, "u.server AS s{$i}", "u.path AS n{$i}" );
			}
		}
		foreach ( $inputs as $input ) {
			$select[] = Core::as_string( Core::arr( $def['inputs'] )[ $input ] ) . " AS m_{$input}";
		}
		if ( [] === $select ) {
			$select[] = '1 AS m_row';
		}

		[ $column, $per ] = $def['range'];
		$where            = [];
		$binds            = [];
		foreach ( $spec['buckets'] as [ $first, $last ] ) {
			$where[] = "{$column} BETWEEN ? AND ?";
			\array_push( $binds, \intdiv( $first, $per ), \intdiv( $last + 1, $per ) - 1 );
		}
		$clauses = [ '( ' . \implode( ' OR ', $where ) . ' )' ];
		foreach ( $spec['filter'] as $field => $values ) {
			$clauses[] = Core::as_string( $expr[ $field ] ) . ' IN ( ' . \implode( ', ', \array_fill( 0, \count( $values ), '?' ) ) . ' )';
			\array_push( $binds, ...$values );
		}
		$joined = $fields;
		if ( '' !== $spec['match'] ) {
			$clauses[] = self::SEARCH;
			$binds[]   = $spec['match'];
			$joined[]  = 'url';
		}
		$joins = [];
		foreach ( Core::arr( $def['joins'] ) as $name => $join ) {
			if ( \in_array( $name, [ ...$joined, ...$inputs ], true ) ) {
				$joins[] = Core::as_string( $join );
			}
		}
		$sql = 'SELECT ' . \implode( ', ', $select ) . " FROM %1\$s.{$table} AS t "
			. \implode( ' ', $joins ) . ' WHERE ' . \implode( ' AND ', $clauses );
		return [ $sql, $binds ];
	}

	/**
	 * The answer's columns: each group field, a `url` followed by its
	 * `url_name`, then each measure.
	 *
	 * @param array{group:list<string>, measure:list<string>} $spec
	 * @return list<string>
	 */
	private static function columns( array $spec ): array {
		$columns = [];
		foreach ( $spec['group'] as $field ) {
			$columns[] = $field;
			if ( 'url' === $field ) {
				$columns[] = 'url_name';
			}
		}
		return [ ...$columns, ...$spec['measure'] ];
	}

	/**
	 * The table that answers $spec exactly at least cost: timings_site | timings | requests_hourly | requests.
	 *
	 * A timings spec reads `timings_site` unless a URL is grouped, filtered
	 * or searched; a requests spec reads `requests_hourly` when every field
	 * and measure it names is one the rollup keeps and every bucket range
	 * covers whole hours.
	 *
	 * @param array{source:string, group:list<string>, filter:array<string,mixed>, measure:list<string>, buckets:list<array{0:int,1:int}>, search?:string} $spec
	 */
	public static function table_for( array $spec ): string {
		$named  = [ ...$spec['group'], ...\array_keys( $spec['filter'] ) ];
		$by_url = \in_array( 'url', $named, true ) || '' !== ( $spec['search'] ?? '' );
		if ( 'timings' === $spec['source'] ) {
			return $by_url ? 'timings' : 'timings_site';
		}
		$hourly = ! $by_url
			&& [] === \array_diff( $named, self::HOURLY_FIELDS )
			&& [] === \array_diff( $spec['measure'], self::HOURLY_MEASURES )
			&& [] === \array_filter( $spec['buckets'], static fn ( array $range ): bool => 0 !== $range[0] % self::BUCKETS_PER_HOUR || 0 !== ( $range[1] + 1 ) % self::BUCKETS_PER_HOUR );
		return $hourly ? 'requests_hourly' : 'requests';
	}

	/**
	 * A spec with every key present, each name checked against the
	 * vocabulary, each integer field's digits read as integers, and its
	 * search as the FTS5 query an arm matches.
	 *
	 * @param array<string,mixed> $spec As `run()` takes it.
	 * @return Spec
	 * @throws \InvalidArgumentException Naming what it refuses.
	 */
	private static function checked( array $spec ): array {
		$source = Core::as_string( $spec['source'] ?? null );
		$fields = self::FIELDS[ $source ] ?? throw new \InvalidArgumentException( \esc_html( "unknown source {$source}: one of " . \implode( ', ', \array_keys( self::FIELDS ) ) ) );
		$group  = \array_values( \array_map( Core::as_string( ... ), Core::arr( $spec['group'] ?? null ) ) );
		$filter = [];
		foreach ( Core::arr( $spec['filter'] ?? null ) as $field => $values ) {
			$filter[ (string) $field ] = \array_values( \array_map( static fn ( mixed $value ): mixed => self::bindable( (string) $field, $value ), Core::arr( $values ) ) );
		}
		foreach ( [ ...$group, ...\array_keys( $filter ) ] as $field ) {
			if ( ! \in_array( $field, $fields, true ) ) {
				throw new \InvalidArgumentException( \esc_html( "unknown {$source} field {$field}: one of " . \implode( ', ', $fields ) ) );
			}
		}
		$measure = \array_values( \array_map( Core::as_string( ... ), Core::arr( $spec['measure'] ?? null ) ) );
		if ( [] === $measure ) {
			throw new \InvalidArgumentException( 'a query names at least one measure' );
		}
		foreach ( $measure as $name ) {
			if ( ! \in_array( $name, self::MEASURES[ $source ], true ) ) {
				throw new \InvalidArgumentException( \esc_html( "unknown {$source} measure {$name}: one of " . \implode( ', ', self::MEASURES[ $source ] ) ) );
			}
		}
		$checked = [
			'source'  => $source,
			'group'   => $group,
			'filter'  => $filter,
			'measure' => $measure,
			'buckets' => \array_values( \array_map( static fn ( mixed $range ): array => [ Core::as_int( Core::arr( $range )[0] ?? null ), Core::as_int( Core::arr( $range )[1] ?? null ) ], Core::arr( $spec['buckets'] ?? null ) ) ),
			'sort'    => Core::as_string( $spec['sort'] ?? '' ),
			'order'   => Core::as_string( $spec['order'] ?? 'asc' ),
			'limit'   => Core::as_int( $spec['limit'] ?? self::MAX_LIMIT ),
			'offset'  => Core::as_int( $spec['offset'] ?? 0 ),
			'search'  => Core::as_string( $spec['search'] ?? '' ),
		];
		$checked['match'] = '' === $checked['search'] ? '' : self::match( $checked['search'] );
		if ( [] === $checked['buckets'] ) {
			throw new \InvalidArgumentException( 'a query names at least one bucket range' );
		}
		foreach ( \array_intersect( $measure, $group ) as $name ) {
			throw new \InvalidArgumentException( \esc_html( "{$name} names both a group and a measure" ) );
		}
		$sortable = [ ...$group, ...( \in_array( 'url', $group, true ) ? [ 'url_name' ] : [] ), ...$measure ];
		if ( '' !== $checked['sort'] && ! \in_array( $checked['sort'], $sortable, true ) ) {
			throw new \InvalidArgumentException( \esc_html( "unknown sort {$checked['sort']}: one of the groups and measures, " . \implode( ', ', $sortable ) ) );
		}
		if ( 'asc' !== $checked['order'] && 'desc' !== $checked['order'] ) {
			throw new \InvalidArgumentException( \esc_html( "unknown order {$checked['order']}: asc or desc" ) );
		}
		if ( $checked['limit'] < 1 || $checked['limit'] > self::MAX_LIMIT ) {
			throw new \InvalidArgumentException( \esc_html( "limit {$checked['limit']} is outside 1.." . self::MAX_LIMIT ) );
		}
		if ( $checked['offset'] < 0 || $checked['offset'] > self::MAX_OFFSET ) {
			throw new \InvalidArgumentException( \esc_html( "offset {$checked['offset']} is outside 0.." . self::MAX_OFFSET ) );
		}
		return $checked;
	}

	/**
	 * A filter value as it binds: an integer field's digits as an integer,
	 * so it compares equal to the column; anything else as it came.
	 *
	 * @param string $field The field it filters.
	 * @param mixed  $value The value.
	 * @return scalar|null
	 */
	private static function bindable( string $field, mixed $value ): mixed {
		if ( \is_string( $value ) && \in_array( $field, self::INTEGER_FIELDS, true ) ) {
			return Core::canonical_decimal( $value ) ?? $value;
		}
		return \is_scalar( $value ) ? $value : null;
	}

	/**
	 * A search term as an FTS5 query: each word of it quoted and the words
	 * joined by spaces, so every word must match and no operator in the
	 * term acts as one.
	 *
	 * @param string $term The search term.
	 * @throws \InvalidArgumentException When the term holds no word.
	 */
	private static function match( string $term ): string {
		$words = \preg_split( '/[^\p{L}\p{N}]+/u', $term, -1, \PREG_SPLIT_NO_EMPTY ) ?: [];
		if ( [] === $words ) {
			throw new \InvalidArgumentException( \esc_html( "search {$term} holds no word" ) );
		}
		return \implode( ' ', \array_map( static fn ( string $word ): string => "\"{$word}\"", $words ) );
	}
}
