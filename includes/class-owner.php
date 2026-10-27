<?php
/**
 * Owner
 *
 * Who owns a PHP file: a plugin, an mu-plugin, a theme, a drop-in, the host's
 * platform or core.
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
 * drop-in core loads by name from `WP_CONTENT_DIR`, or the host's copy in
 * `/wordpress/drop-ins/` it links to; `core` for `wp-admin`, `wp-includes`
 * and the files at `ABSPATH`; `platform` for the rest of the host's managed
 * tree, its own mu-plugins among them, and for its scripts directory, which
 * holds the object cache a drop-in loads; and `''` for anything else,
 * `eval()`'d code included.
 * PHP names a file with every symlink resolved, so each root matches as
 * written and as resolved, and each carries a trailing slash, so a root never
 * matches a sibling that merely begins with its name. A plugin, mu-plugin or
 * theme linked into its root from elsewhere, as a dev install links its
 * checkouts, matches at its resolved target and answers its link's slug.
 */
final class Owner {

	/** The owner of WordPress's own files. */
	public const CORE = 'core';

	/** The owner of a drop-in. */
	public const DROPIN = 'dropin';

	/** The owner of the host's own code, in its managed tree or its scripts. */
	public const PLATFORM = 'platform';

	/** The bucket for time no owner claims. */
	public const UNOWNED = '(unowned)';

	/** The bucket for time no frame accounts for. */
	public const UNMEASURED = '(unmeasured)';

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

	/** The owners that name no file's plugin, though none carries a prefix. */
	private const NOT_PLUGINS = [
		self::CORE     => true,
		self::DROPIN   => true,
		self::PLATFORM => true,
	];

	/** The root kinds whose entries may be links to a checkout elsewhere. */
	private const LINKABLE = [
		'plugin' => true,
		'mu'     => true,
		'theme'  => true,
	];

	/** The root kinds that hold the others, which a link answers ahead of. */
	private const BROAD = [
		'content'  => true,
		'core'     => true,
		'platform' => true,
	];

	/**
	 * Roots seam. Replaces the WordPress constants that locate each root —
	 * `WP_PLUGIN_DIR`, `WPMU_PLUGIN_DIR`, `WP_CONTENT_DIR`, `ABSPATH` — and the
	 * host's managed tree and scripts directory, `/wordpress` and `/scripts`
	 * on WP Cloud, which are read once a process. The theme directories are
	 * not among them: they are read from the `$wp_theme_directories` global
	 * `register_theme_directory()` fills, which runs no filter, and re-read
	 * whenever it changes. Tests reassign the seam to classify against a tree
	 * of their own.
	 * Signature: `function (): array{plugins: string, mu_plugins: string, content: string, abspath: string, managed: string, scripts: string}`
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
	 * @var array{roots: array{plugins: string, mu_plugins: string, content: string, abspath: string, managed: string, scripts: string}, themes: array<mixed>, dirs: list<array{0:string,1:string,2:?string}>}|null
	 */
	private static ?array $table = null;

	/** Whether the table is being built, when a classification answers ''. */
	private static bool $building = false;

	/**
	 * Each linkable root's links, read once a process, keyed by kind and root.
	 *
	 * @var array<string,list<array{0:string,1:string,2:?string}>>
	 */
	private static array $links = [];

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
	 * The owner the first root holding the file gives it, a root that names
	 * none yielding to the next: an unversioned copy in the managed tree is
	 * named by the link to it.
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
		foreach ( self::$table['dirs'] as [ $dir, $kind, $owner ] ) {
			if ( ! ( \str_ends_with( $dir, '/' ) ? \str_starts_with( $file, $dir ) : $file === $dir ) ) {
				continue;
			}
			$found = $owner ?? self::owner_in( $kind, \explode( '/', \substr( $file, \strlen( $dir ) ) ) );
			if ( '' !== $found ) {
				return $found;
			}
		}
		return '';
	}

	/**
	 * The roots the seam answers, or the running install's.
	 *
	 * @return array{plugins: string, mu_plugins: string, content: string, abspath: string, managed: string, scripts: string}
	 */
	private static function read_roots(): array {
		/** @var array{plugins: string, mu_plugins: string, content: string, abspath: string, managed: string, scripts: string} $roots As the seam's signature declares. */
		$roots = ( self::$roots ?? self::wordpress_roots( ... ) )();
		return $roots;
	}

	/**
	 * The roots of the running install.
	 *
	 * @return array{plugins: string, mu_plugins: string, content: string, abspath: string, managed: string, scripts: string}
	 */
	private static function wordpress_roots(): array {
		return [
			'plugins'    => WP_PLUGIN_DIR,
			'mu_plugins' => WPMU_PLUGIN_DIR,
			'content'    => WP_CONTENT_DIR,
			'abspath'    => ABSPATH,
			'managed'    => '/wordpress',
			'scripts'    => '/scripts',
		];
	}

	/**
	 * Every root, as written and as resolved, in the order a file tries them:
	 * the narrower roots, then the links into them, then the content dir and
	 * `ABSPATH` a link's target may sit under, and last the managed tree,
	 * whose core a resolved `ABSPATH` has already claimed. A link carries its
	 * owner; a root's is read off the path below it.
	 *
	 * @param array{plugins: string, mu_plugins: string, content: string, abspath: string, managed: string, scripts: string} $roots  The roots.
	 * @param array<mixed> $themes The theme directories.
	 * @return array{roots: array{plugins: string, mu_plugins: string, content: string, abspath: string, managed: string, scripts: string}, themes: array<mixed>, dirs: list<array{0:string,1:string,2:?string}>}
	 */
	private static function table( array $roots, array $themes ): array {
		$kinds  = [
			[ 'plugin', $roots['plugins'] ],
			[ 'release', $roots['content'] . '/plugin-releases' ],
			[ 'managed_plugin', $roots['managed'] . '/plugins' ],
			[ 'managed_theme', $roots['managed'] . '/themes' ],
			[ 'managed_dropin', $roots['managed'] . '/drop-ins' ],
			[ 'mu', $roots['mu_plugins'] ],
			...\array_map( static fn ( mixed $dir ): array => [ 'theme', \is_string( $dir ) ? $dir : '' ], $themes ),
			[ 'content', $roots['content'] ],
			[ 'core', $roots['abspath'] ],
			[ 'platform', $roots['managed'] ],
			[ 'platform', $roots['scripts'] ],
		];
		$narrow = [];
		$linked = [];
		$broad  = [];
		foreach ( $kinds as [ $kind, $root ] ) {
			foreach ( '' === $root ? [] : \array_unique( [ $root, \realpath( $root ) ] ) as $dir ) {
				if ( false === $dir ) {
					continue;
				}
				if ( isset( self::BROAD[ $kind ] ) ) {
					$broad[] = [ \rtrim( $dir, '/' ) . '/', $kind, null ];
				} else {
					$narrow[] = [ \rtrim( $dir, '/' ) . '/', $kind, null ];
				}
			}
			if ( '' !== $root && isset( self::LINKABLE[ $kind ] ) ) {
				\array_push( $linked, ...( self::$links[ "{$kind}\0{$root}" ] ??= self::linked( $kind, $root ) ) );
			}
		}
		// Innermost target first: a link inside another owns its files.
		\usort( $linked, static fn ( array $a, array $b ): int => \strlen( $b[0] ) <=> \strlen( $a[0] ) );
		return [
			'roots'  => $roots,
			'themes' => $themes,
			'dirs'   => [ ...$narrow, ...$linked, ...$broad ],
		];
	}

	/**
	 * Each entry of a root that is a symlink, at its resolved target: a
	 * directory slash-terminated, a file as itself, which matches only itself.
	 * Its owner is the one a file inside the linked directory, or the linked
	 * file itself, would have in the root.
	 *
	 * @param string $kind A `LINKABLE` kind.
	 * @param string $root The root.
	 * @return list<array{0:string,1:string,2:?string}>
	 */
	private static function linked( string $kind, string $root ): array {
		$dirs = [];
		foreach ( \glob( \rtrim( $root, '/' ) . '/*', GLOB_NOSORT ) ?: [] as $entry ) {
			$real = \is_link( $entry ) ? \realpath( $entry ) : false;
			if ( false === $real ) {
				continue;
			}
			$dirs[] = \is_dir( $real )
				? [ $real . '/', $kind, self::owner_in( $kind, [ \basename( $entry ), '' ] ) ]
				: [ $real, $kind, self::owner_in( $kind, [ \basename( $entry ) ] ) ];
		}
		return $dirs;
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
			'content', 'managed_dropin' => 1 === $count && isset( self::DROPINS[ $segments[0] ] ) ? self::DROPIN : '',
			'platform'       => self::PLATFORM,
			default          => 1 === $count || isset( self::CORE_DIRS[ $segments[0] ] ) ? self::CORE : '',
		};
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
		return '' !== $owner && ! isset( self::NOT_PLUGINS[ $owner ] ) && ! \str_contains( $owner, ':' );
	}

	/**
	 * The owner a flame frame's name carries.
	 *
	 * A frame is `<base>: <label>` or a bare label, and `label()` put the owner
	 * in front of the label. A plugin-load span is the plugin's own.
	 *
	 * @param string $name A frame's name.
	 * @return string The owner, or '' when the name carries none.
	 */
	public static function of_label( string $name ): string {
		if ( Flame_Tree::is_plugin_load_span( $name ) ) {
			return \substr( Flame_Tree::base_name( $name ), 0, -\strlen( Flame_Tree::PLUGIN_LOAD_SUFFIX ) );
		}
		$at    = \strpos( $name, ': ' );
		$label = false === $at || \str_starts_with( $name, '[' ) ? $name : \substr( $name, $at + 2 );
		$end   = \strpos( $label, '] ' );
		return '[' === ( $label[0] ?? '' ) && false !== $end ? \substr( $label, 1, $end - 1 ) : '';
	}
}
