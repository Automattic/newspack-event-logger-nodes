<?php
/**
 * Owner
 *
 * Who owns a PHP file: a plugin, an mu-plugin, a theme, a drop-in or core.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

\defined( 'ABSPATH' ) || exit;

/**
 * Every label that names code carries its owner as a `[<owner>] ` prefix, and
 * a fatal names the plugin it died in. Both read the one classifier here.
 *
 * An owner is `<slug>` for a plugin, under `WP_PLUGIN_DIR`, a release the
 * deploy swaps in at `WP_CONTENT_DIR/plugin-releases/<slug>/<release>/<slug>/`
 * or a host-managed copy at `/wordpress/plugins/<slug>/<version>/`;
 * `mu:<slug>` for an mu-plugin; `theme:<slug>` for a theme, under a theme
 * directory or at `/wordpress/themes/<slug>/<version>/`; `dropin` for a
 * drop-in core loads by name from `WP_CONTENT_DIR`; `core` for `wp-admin`,
 * `wp-includes` and the files at `ABSPATH`; and `''` for anything else,
 * `eval()`'d code included.
 * PHP names a file with every symlink resolved, so each root matches as
 * written and as resolved, and each carries a trailing slash, so a root never
 * matches a sibling that merely begins with its name.
 */
final class Owner {

	/** The owner of WordPress's own files. */
	public const CORE = 'core';

	/** The owner of a drop-in. */
	public const DROPIN = 'dropin';

	/** What an mu-plugin's slug follows. */
	private const MU_PREFIX = 'mu:';

	/** What a theme's slug follows. */
	private const THEME_PREFIX = 'theme:';

	/**
	 * The drop-ins `_get_dropins()` names, multisite's included. That function
	 * lives in wp-admin, which a front-end request never loads.
	 */
	private const DROPINS = [
		'advanced-cache.php'      => true,
		'db.php'                  => true,
		'db-error.php'            => true,
		'install.php'             => true,
		'maintenance.php'         => true,
		'object-cache.php'        => true,
		'php-error.php'           => true,
		'fatal-error-handler.php' => true,
		'sunrise.php'             => true,
		'blog-deleted.php'        => true,
		'blog-inactive.php'       => true,
		'blog-suspended.php'      => true,
	];

	/** The directories under `ABSPATH` that hold core. */
	private const CORE_DIRS = [
		'wp-admin'    => true,
		'wp-includes' => true,
	];

	/**
	 * Roots seam. Replaces the WordPress constants that locate each root —
	 * `WP_PLUGIN_DIR`, `WPMU_PLUGIN_DIR`, `WP_CONTENT_DIR`, `ABSPATH` — and the
	 * host's managed tree, `/wordpress` on WP Cloud, which are read once a
	 * process. The theme directories are not among them: they are read from
	 * the `$wp_theme_directories` global `register_theme_directory()` fills,
	 * which runs no filter, and re-read whenever it changes. Tests reassign
	 * the seam to classify against a tree of their own.
	 * Signature: `function (): array{plugins: string, mu_plugins: string, content: string, abspath: string, managed: string}`
	 *
	 * @var \Closure|null
	 */
	public static ?\Closure $roots = null;

	/**
	 * Each file's owner, once classified, for the process.
	 *
	 * @var array<string,string>
	 */
	private static array $owners = [];

	/**
	 * The roots, the theme directories they were joined with, and each one's
	 * directories, slash-terminated, in the order they are tried, with the
	 * kind of owner a file under one has.
	 *
	 * @var array{roots: array{plugins: string, mu_plugins: string, content: string, abspath: string, managed: string}, themes: array<mixed>, dirs: list<array{string,string}>}|null
	 */
	private static ?array $table = null;

	/** Whether the table is being built, when a classification answers ''. */
	private static bool $building = false;

	/**
	 * A label prefixed with the owner of a file.
	 *
	 * @param string $file  A path as PHP names it.
	 * @param string $label The label.
	 */
	public static function labelled( string $file, string $label ): string {
		return self::label( self::of( $file ), $label );
	}

	/**
	 * The owner of a file.
	 *
	 * @param string $file A path as PHP names it.
	 * @return string The owner, or '' when no root holds the file.
	 */
	public static function of( string $file ): string {
		if ( isset( self::$owners[ $file ] ) ) {
			return self::$owners[ $file ];
		}
		// A root read that classifies, as a listener on it would, re-enters.
		if ( self::$building ) {
			return '';
		}
		return self::$owners[ $file ] = self::classify( $file );
	}

	/**
	 * The owner the first root holding the file gives it.
	 *
	 * @param string $file A path as PHP names it.
	 */
	private static function classify( string $file ): string {
		if ( \str_ends_with( $file, "eval()'d code" ) ) {
			return '';
		}
		$themes = \is_array( $GLOBALS['wp_theme_directories'] ?? null ) ? $GLOBALS['wp_theme_directories'] : [];
		if ( null === self::$table || $themes !== self::$table['themes'] ) {
			self::$building = true;
			try {
				self::$table = self::table( self::$table['roots'] ?? self::read_roots(), $themes );
			} finally {
				self::$building = false;
			}
		}
		foreach ( self::$table['dirs'] as [ $dir, $kind ] ) {
			if ( \str_starts_with( $file, $dir ) ) {
				return self::owner_in( $kind, \explode( '/', \substr( $file, \strlen( $dir ) ) ) );
			}
		}
		return '';
	}

	/**
	 * The owner of a file under a root of `$kind`, from its path below it.
	 *
	 * @param string                 $kind     The root's kind.
	 * @param non-empty-list<string> $segments The path below the root, split on `/`.
	 */
	private static function owner_in( string $kind, array $segments ): string {
		$count = \count( $segments );
		$slug  = \str_ends_with( $segments[0], '.php' ) ? \substr( $segments[0], 0, -4 ) : $segments[0];
		return match ( $kind ) {
			'plugin'         => $slug,
			// A release's own root holds no plugin file.
			'release'        => $count > 3 ? $segments[2] : '',
			// A managed copy sits in a directory named for its version.
			'managed_plugin' => $count > 2 ? $segments[0] : '',
			'managed_theme'  => $count > 2 ? self::THEME_PREFIX . $segments[0] : '',
			'mu'             => self::MU_PREFIX . $slug,
			'theme'          => $count > 1 ? self::THEME_PREFIX . $segments[0] : '',
			'content'        => 1 === $count && isset( self::DROPINS[ $segments[0] ] ) ? self::DROPIN : '',
			default          => 1 === $count || isset( self::CORE_DIRS[ $segments[0] ] ) ? self::CORE : '',
		};
	}

	/**
	 * The roots the seam answers, or the running install's.
	 *
	 * @return array{plugins: string, mu_plugins: string, content: string, abspath: string, managed: string}
	 */
	private static function read_roots(): array {
		/** @var array{plugins: string, mu_plugins: string, content: string, abspath: string, managed: string} $roots As the seam's signature declares. */
		$roots = ( self::$roots ?? self::wordpress_roots( ... ) )();
		return $roots;
	}

	/**
	 * The roots of the running install.
	 *
	 * @return array{plugins: string, mu_plugins: string, content: string, abspath: string, managed: string}
	 */
	private static function wordpress_roots(): array {
		return [
			'plugins'    => WP_PLUGIN_DIR,
			'mu_plugins' => WPMU_PLUGIN_DIR,
			'content'    => WP_CONTENT_DIR,
			'abspath'    => ABSPATH,
			'managed'    => '/wordpress',
		];
	}

	/**
	 * Every root, as written and as resolved, in the order a file tries them:
	 * the narrower roots ahead of the content dir and `ABSPATH` holding them.
	 *
	 * @param array{plugins: string, mu_plugins: string, content: string, abspath: string, managed: string} $roots  The roots.
	 * @param array<mixed> $themes The theme directories.
	 * @return array{roots: array{plugins: string, mu_plugins: string, content: string, abspath: string, managed: string}, themes: array<mixed>, dirs: list<array{string,string}>}
	 */
	private static function table( array $roots, array $themes ): array {
		$kinds = [
			[ 'plugin', $roots['plugins'] ],
			[ 'release', $roots['content'] . '/plugin-releases' ],
			[ 'managed_plugin', $roots['managed'] . '/plugins' ],
			[ 'managed_theme', $roots['managed'] . '/themes' ],
			[ 'mu', $roots['mu_plugins'] ],
			...\array_map( static fn ( mixed $dir ): array => [ 'theme', \is_string( $dir ) ? $dir : '' ], $themes ),
			[ 'content', $roots['content'] ],
			[ 'core', $roots['abspath'] ],
		];
		$dirs  = [];
		foreach ( $kinds as [ $kind, $root ] ) {
			foreach ( '' === $root ? [] : \array_unique( [ $root, \realpath( $root ) ] ) as $dir ) {
				if ( false !== $dir ) {
					$dirs[] = [ \rtrim( $dir, '/' ) . '/', $kind ];
				}
			}
		}
		return [
			'roots'  => $roots,
			'themes' => $themes,
			'dirs'   => $dirs,
		];
	}

	/**
	 * A label prefixed with its owner, or bare when the owner is unknown.
	 *
	 * @param string $owner An owner `of()` answered.
	 * @param string $label The label.
	 */
	public static function label( string $owner, string $label ): string {
		return '' === $owner ? $label : "[{$owner}] {$label}";
	}

	/**
	 * Whether an owner is a plugin: a bare slug, which no other owner is.
	 *
	 * @param string $owner An owner `of()` answered.
	 */
	public static function is_plugin( string $owner ): bool {
		return '' !== $owner && self::CORE !== $owner && self::DROPIN !== $owner && ! \str_contains( $owner, ':' );
	}
}
