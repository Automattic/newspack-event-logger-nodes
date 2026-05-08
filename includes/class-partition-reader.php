<?php
/**
 * Partition_Reader: streaming reader for `Newspack_Nodes\Partition`.
 *
 * Lift-adapt of the legacy `Newspack_Event_Logger\FirehoseReader`. Provides
 * line-buffered reads with rotation handling and an `is_caught_up()` probe
 * so SSE loops can sleep instead of busy-poll when the tail is current.
 *
 * Position tracking is the caller's responsibility — this class does not
 * persist offsets. SSE controllers ship `{segment_id, offset}` to the client
 * on each batch and accept it back as a resume position.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

\defined( 'ABSPATH' ) || exit;

use Newspack_Nodes\Partition;

class Partition_Reader {

	/**
	 * 20MB cap on the internal line accumulator. Without a cap, a malformed
	 * file with no newlines could OOM the reader.
	 */
	private const MAX_LINE_BUFFER_SIZE = 20971520;

	private Partition $partition;
	private int $segment_id  = 0;
	private int $offset      = 0;
	/** @var array{id:int,size:int}|null */
	private ?array $current_segment = null;
	/** @var array<int,array{id:int,size:int}> */
	private array $segments    = [];
	/** @var resource|null */
	private $fh                = null;
	private string $line_buffer = '';
	private bool $at_eof        = true;

	/**
	 * @param Partition $partition       Source partition.
	 * @param string    $default_offset  'start', 'recent', or 'end'.
	 */
	public function __construct( Partition $partition, string $default_offset = 'start' ) {
		$this->partition = $partition;
		$this->next_offset( $default_offset );
	}

	public function __destruct() {
		$this->close();
	}

	/**
	 * Set the next read position.
	 *
	 * @param string|array{segment_id:int,offset:int} $position 'start' / 'recent' /
	 *                                                          'end' / explicit position.
	 */
	public function next_offset( string|array $position ): void {
		$this->close();
		$this->current_segment = null;
		$this->line_buffer     = '';
		$this->at_eof          = false;

		if ( \is_array( $position ) ) {
			$this->segment_id = (int) ( $position['segment_id'] ?? 0 );
			$this->offset     = (int) ( $position['offset'] ?? 0 );
			if ( $this->offset < -1 ) {
				$this->offset = -1;
			}
			return;
		}

		$this->refresh_segments();

		switch ( $position ) {
			case 'end':
				if ( ! empty( $this->segments ) ) {
					$newest           = \end( $this->segments );
					$this->segment_id = $newest['id'];
					$this->offset     = $newest['size'];
				}
				break;
			case 'recent':
				if ( ! empty( $this->segments ) ) {
					$count = \count( $this->segments );
					$this->segment_id = $count >= 2
						? $this->segments[ $count - 2 ]['id']
						: $this->segments[0]['id'];
					$this->offset     = 0;
				}
				break;
			case 'start':
			default:
				$this->segment_id = 0;
				$this->offset     = 0;
		}
	}

	public function update_offset(): void {
		if ( \is_resource( $this->fh ) ) {
			$pos = \ftell( $this->fh );
			if ( false !== $pos ) {
				$this->offset = $pos;
			}
		}
	}

	public function get_segment_id(): int {
		return $this->segment_id;
	}

	/**
	 * @return array{segment_id:int,offset:int}
	 */
	public function get_position(): array {
		return [
			'segment_id' => $this->segment_id,
			'offset'     => $this->offset,
		];
	}

	public function refresh_segments(): void {
		$this->segments = $this->partition->get_segments();
	}

	private function find_segment_by_id( int $segment_id ): ?array {
		foreach ( $this->segments as $seg ) {
			if ( $seg['id'] === $segment_id ) {
				return $seg;
			}
		}
		return null;
	}

	/**
	 * Open / reopen the file handle on the current segment.
	 *
	 * @return resource|null
	 */
	public function open() {
		$this->refresh_segments();
		if ( empty( $this->segments ) ) {
			return null;
		}
		$segment = $this->find_segment_by_id( $this->segment_id );
		if ( null === $segment ) {
			$segment          = $this->segments[0];
			$this->segment_id = $segment['id'];
			$this->offset     = 0;
		}
		if ( null !== $this->current_segment && $this->current_segment['id'] === $segment['id'] && \is_resource( $this->fh ) ) {
			return $this->fh;
		}
		$this->close();
		$path = $this->partition->get_segment_path( $segment['id'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fopen
		$fh = @\fopen( $path, 'r' );
		if ( false === $fh ) {
			return null;
		}
		$this->fh              = $fh;
		$this->current_segment = $segment;
		if ( $this->offset > 0 ) {
			\fseek( $fh, $this->offset );
		}
		return $this->fh;
	}

	public function mark_eof(): void {
		$this->at_eof = true;
	}

	public function is_caught_up(): bool {
		if ( ! $this->at_eof ) {
			return false;
		}
		$this->refresh_segments();
		if ( empty( $this->segments ) ) {
			return true;
		}
		$newest = \end( $this->segments );
		return $this->segment_id >= $newest['id'];
	}

	/**
	 * Move forward to the next segment if one exists. Returns the new file
	 * handle, or the existing one if no rotation happened.
	 *
	 * @return resource|null
	 */
	public function next_segment() {
		$this->refresh_segments();
		if ( empty( $this->segments ) ) {
			return null;
		}
		$next_id = $this->segment_id + 1;
		$segment = $this->find_segment_by_id( $next_id );

		// Reset detected: current segment is gone AND there's no successor.
		if ( null === $segment && null === $this->find_segment_by_id( $this->segment_id ) ) {
			$this->close();
			$oldest                = $this->segments[0];
			$this->current_segment = $oldest;
			$this->segment_id      = $oldest['id'];
			$this->offset          = 0;
			$this->line_buffer     = '';
			$this->at_eof          = false;
			$path                  = $this->partition->get_segment_path( $oldest['id'] );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fopen
			$fh = @\fopen( $path, 'r' );
			$this->fh = false === $fh ? null : $fh;
			return $this->fh;
		}

		if ( null !== $segment && null !== $this->current_segment && \is_resource( $this->fh ) ) {
			$current_path = $this->partition->get_segment_path( $this->current_segment['id'] );
			\clearstatcache( true, $current_path );
			$file_size  = @\filesize( $current_path );
			$read_pos   = \ftell( $this->fh );
			$mtime      = @\filemtime( $current_path );
			$stale_secs = $mtime ? ( \time() - $mtime ) : PHP_INT_MAX;
			if ( $stale_secs < 1 ) {
				$segment = null;
			} elseif ( false !== $file_size && false !== $read_pos && $read_pos < $file_size && $stale_secs < 5 ) {
				$segment = null;
			}
		}

		if ( null === $segment ) {
			if ( \is_resource( $this->fh ) ) {
				\fseek( $this->fh, 0, SEEK_CUR );
			}
			return $this->fh;
		}

		$this->close();
		$this->current_segment = $segment;
		$this->segment_id      = $next_id;
		$this->offset          = 0;
		$this->line_buffer     = '';
		$this->at_eof          = false;
		$path                  = $this->partition->get_segment_path( $segment['id'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fopen
		$fh = @\fopen( $path, 'r' );
		$this->fh = false === $fh ? null : $fh;
		return $this->fh;
	}

	/**
	 * Read a complete line (with trailing newline). Returns null when no
	 * complete line is currently available.
	 */
	public function read_line(): ?string {
		if ( ! \is_resource( $this->fh ) ) {
			return null;
		}
		$nl = \strpos( $this->line_buffer, "\n" );
		if ( false !== $nl ) {
			$line              = \substr( $this->line_buffer, 0, $nl + 1 );
			$this->line_buffer = \substr( $this->line_buffer, $nl + 1 );
			$this->offset      = \ftell( $this->fh ) - \strlen( $this->line_buffer );
			return $line;
		}
		\fseek( $this->fh, 0, SEEK_CUR );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$data = \fread( $this->fh, 65536 );
		if ( false === $data || '' === $data ) {
			$this->at_eof = true;
			return null;
		}
		$this->at_eof = false;
		if ( \strlen( $this->line_buffer ) + \strlen( $data ) > self::MAX_LINE_BUFFER_SIZE ) {
			// Discard buffer; resync to next newline boundary.
			$this->offset    += \strlen( $this->line_buffer );
			$this->line_buffer = '';
			$nl = \strpos( $data, "\n" );
			if ( false !== $nl ) {
				$this->offset     += $nl + 1;
				$this->line_buffer = \substr( $data, $nl + 1 );
			} else {
				$this->offset += \strlen( $data );
			}
			return null;
		}
		$this->line_buffer .= $data;
		$nl = \strpos( $this->line_buffer, "\n" );
		if ( false !== $nl ) {
			$line              = \substr( $this->line_buffer, 0, $nl + 1 );
			$this->line_buffer = \substr( $this->line_buffer, $nl + 1 );
			$this->offset      = \ftell( $this->fh ) - \strlen( $this->line_buffer );
			return $line;
		}
		return null;
	}

	public function close(): void {
		if ( \is_resource( $this->fh ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			\fclose( $this->fh );
			$this->fh = null;
		}
	}
}
