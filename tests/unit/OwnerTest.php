<?php
/**
 * Tests for Newspack_Event_Logger_Nodes\Owner.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Newspack_Event_Logger_Nodes\Flame_Tree;
use Newspack_Event_Logger_Nodes\Owner;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

#[CoversClass( Owner::class )]
class OwnerTest extends TestCase {

	/** An install laid out as WordPress lays one out, at a path no default names. */
	private const ROOTS = [
		'plugins'    => '/srv/kea-7746/wp-content/plugins',
		'mu_plugins' => '/srv/kea-7746/wp-content/mu-plugins',
		'themes'     => [ '/srv/kea-7746/wp-content/themes' ],
		'content'    => '/srv/kea-7746/wp-content',
		'abspath'    => '/srv/kea-7746/',
		'managed'    => '/srv/managed-6620',
	];

	protected function setUp(): void {
		parent::setUp();
		self::own_files_under( self::ROOTS );
	}

	/**
	 * Every row of the owner table, and the files that belong to no row.
	 *
	 * @return array<string,array{string,string}>
	 */
	public static function files(): array {
		$content = self::ROOTS['content'];
		return [
			'a plugin directory'             => [ "{$content}/plugins/weka-4410/includes/class-weka.php", 'weka-4410' ],
			'a single-file plugin'           => [ "{$content}/plugins/hello-tui.php", 'hello-tui' ],
			'a plugin file with no suffix'   => [ "{$content}/plugins/raw-segment", 'raw-segment' ],
			'a plugin release'               => [ "{$content}/plugin-releases/kereru-9182/20261008223021288837917-61651-v4.2.0/kereru-9182/includes/class-x.php", 'kereru-9182' ],
			'a release root without a plugin' => [ "{$content}/plugin-releases/kereru-9182/stray.php", '' ],
			'an mu-plugin directory'         => [ "{$content}/mu-plugins/ruru-3301/loader.php", 'mu:ruru-3301' ],
			'a single-file mu-plugin'        => [ "{$content}/mu-plugins/00-newspack-profiler.php", 'mu:00-newspack-profiler' ],
			'a theme'                        => [ "{$content}/themes/pukeko-2207/inc/template-tags.php", 'theme:pukeko-2207' ],
			'a file loose in the theme root' => [ "{$content}/themes/index.php", '' ],
			'a drop-in'                      => [ "{$content}/object-cache.php", 'dropin' ],
			'a drop-in core reads on error'  => [ "{$content}/fatal-error-handler.php", 'dropin' ],
			'a content file that is no drop-in' => [ "{$content}/kaka-5150.php", '' ],
			'wp-includes'                    => [ '/srv/kea-7746/wp-includes/class-wp-hook.php', 'core' ],
			'wp-admin'                       => [ '/srv/kea-7746/wp-admin/includes/plugin.php', 'core' ],
			'a root file'                    => [ '/srv/kea-7746/wp-settings.php', 'core' ],
			'uploads under the install'      => [ "{$content}/uploads/2026/10/kea.php", '' ],
			'vendor under the install'       => [ '/srv/kea-7746/vendor/autoload.php', '' ],
			'a sibling of the plugins root'  => [ "{$content}/plugins-old/takahe-8812/takahe.php", '' ],
			'a sibling of the mu-plugins root' => [ "{$content}/mu-plugins-old/takahe-8812.php", '' ],
			'a path outside every root'      => [ '/etc/kea-7746.php', '' ],
			'no file at all'                 => [ '', '' ],
			'a managed plugin'               => [ '/srv/managed-6620/plugins/boost-3311/4.2.1-b.7/app/class-boost.php', 'boost-3311' ],
			'a managed plugin without a version' => [ '/srv/managed-6620/plugins/boost-3311/stray.php', '' ],
			'a managed theme'                => [ '/srv/managed-6620/themes/kowhai-8840/2.28.1/inc/template-tags.php', 'theme:kowhai-8840' ],
			'a managed theme without a version' => [ '/srv/managed-6620/themes/kowhai-8840/style.php', '' ],
			'a managed root outside its kinds' => [ '/srv/managed-6620/languages/kowhai.php', '' ],
			'code eval() ran in a plugin'    => [ "{$content}/plugins/weka-4410/includes/class-weka.php(12) : eval()'d code", '' ],
		];
	}

	#[DataProvider( 'files' )]
	public function test_a_file_is_owned_by_the_root_it_sits_under( string $file, string $owner ): void {
		$this->assertSame( $owner, Owner::of( $file ) );
	}

	/** PHP names a file with every symlink resolved, the content dir's included. */
	public function test_a_release_through_a_symlinked_content_dir_is_its_plugin(): void {
		$real = \sys_get_temp_dir() . '/eln-owner-real-' . \getmypid();
		$link = \sys_get_temp_dir() . '/eln-owner-link-' . \getmypid();
		\mkdir( $real . '/plugin-releases', 0777, true );
		\symlink( $real, $link );
		try {
			self::own_files_under( [ 'content' => $link ] + self::ROOTS );

			$this->assertSame( 'tui-5520', Owner::of( \realpath( $real ) . '/plugin-releases/tui-5520/20261008223021288837917-61651-v1.9.0/tui-5520/tui-5520.php' ) );
		} finally {
			\unlink( $link );
			\exec( 'rm -rf ' . \escapeshellarg( $real ) );
		}
	}

	/**
	 * A dev install links each plugin, mu-plugin and theme in from a checkout
	 * elsewhere, and PHP names its files there; each answers its link's slug.
	 */
	public function test_a_linked_plugin_mu_plugin_or_theme_is_its_links(): void {
		$base  = \sys_get_temp_dir() . '/eln-owner-linked-' . \getmypid();
		$repos = $base . '/repos-4471';
		foreach ( [ 'wp-content/plugins', 'wp-content/mu-plugins', 'wp-content/themes', 'repos-4471/weka-src/includes', 'repos-4471/ruru-src', 'repos-4471/themes/pukeko-src/inc' ] as $dir ) {
			\mkdir( "{$base}/{$dir}", 0777, true );
		}
		\touch( "{$repos}/hello-tui-src.php" );
		\symlink( "{$repos}/weka-src", "{$base}/wp-content/plugins/weka-4410" );
		\symlink( "{$repos}/hello-tui-src.php", "{$base}/wp-content/plugins/hello-tui.php" );
		\symlink( "{$repos}/ruru-src", "{$base}/wp-content/mu-plugins/ruru-3301" );
		\symlink( "{$repos}/themes/pukeko-src", "{$base}/wp-content/themes/pukeko-2207" );
		try {
			self::own_files_under(
				[
					'plugins'    => "{$base}/wp-content/plugins",
					'mu_plugins' => "{$base}/wp-content/mu-plugins",
					'themes'     => [ "{$base}/wp-content/themes" ],
					'content'    => "{$base}/wp-content",
					'abspath'    => "{$base}/",
				] + self::ROOTS
			);
			$real = \realpath( $repos );

			$this->assertSame( 'weka-4410', Owner::of( "{$real}/weka-src/includes/class-weka.php" ) );
			$this->assertSame( 'hello-tui', Owner::of( "{$real}/hello-tui-src.php" ) );
			$this->assertSame( 'mu:ruru-3301', Owner::of( "{$real}/ruru-src/loader.php" ) );
			$this->assertSame( 'theme:pukeko-2207', Owner::of( "{$real}/themes/pukeko-src/inc/template-tags.php" ) );
			$this->assertSame( '', Owner::of( "{$real}/weka-src-old/weka.php" ) );
			$this->assertSame( '', Owner::of( "{$real}/hello-tui-src.php.orig" ) );
		} finally {
			\exec( 'rm -rf ' . \escapeshellarg( $base ) );
		}
	}

	/**
	 * A link whose target sits inside another link's owns its own files, and a
	 * file linked loose into a theme root is no theme, as a file there is not.
	 */
	public function test_the_innermost_link_owns_and_a_loose_theme_file_link_is_no_theme(): void {
		$base  = \sys_get_temp_dir() . '/eln-owner-nested-' . \getmypid();
		$repos = $base . '/repos-8813';
		foreach ( [ 'wp-content/plugins', 'wp-content/themes', 'repos-8813/kotare/kotare-child' ] as $dir ) {
			\mkdir( "{$base}/{$dir}", 0777, true );
		}
		\touch( "{$repos}/shim-src.php" );
		// The inner link first, so a directory listing in that order meets it first.
		\symlink( "{$repos}/kotare/kotare-child", "{$base}/wp-content/themes/kotare-child" );
		\symlink( "{$repos}/kotare", "{$base}/wp-content/themes/kotare" );
		\symlink( "{$repos}/shim-src.php", "{$base}/wp-content/themes/shim.php" );
		try {
			self::own_files_under(
				[
					'plugins'    => "{$base}/wp-content/plugins",
					'themes'     => [ "{$base}/wp-content/themes" ],
					'content'    => "{$base}/wp-content",
					'abspath'    => "{$base}/",
				] + self::ROOTS
			);
			$real = \realpath( $repos );

			$this->assertSame( 'theme:kotare-child', Owner::of( "{$real}/kotare/kotare-child/functions.php" ) );
			$this->assertSame( 'theme:kotare', Owner::of( "{$real}/kotare/style.php" ) );
			$this->assertSame( '', Owner::of( "{$real}/shim-src.php" ) );
		} finally {
			\exec( 'rm -rf ' . \escapeshellarg( $base ) );
		}
	}

	/**
	 * A link into the host's managed tree keeps the managed owner: the
	 * narrower roots answer before a link does.
	 */
	public function test_a_link_into_a_managed_root_keeps_its_managed_owner(): void {
		$base = \sys_get_temp_dir() . '/eln-owner-managed-' . \getmypid();
		foreach ( [ 'wp-content/mu-plugins', 'managed-4402/plugins/wpcomsh-7/2.6.1' ] as $dir ) {
			\mkdir( "{$base}/{$dir}", 0777, true );
		}
		\symlink( "{$base}/managed-4402/plugins/wpcomsh-7/2.6.1", "{$base}/wp-content/mu-plugins/wpcomsh-7" );
		try {
			self::own_files_under(
				[
					'mu_plugins' => "{$base}/wp-content/mu-plugins",
					'content'    => "{$base}/wp-content",
					'abspath'    => "{$base}/",
					'managed'    => \realpath( "{$base}/managed-4402" ),
				] + self::ROOTS
			);

			$this->assertSame( 'wpcomsh-7', Owner::of( \realpath( "{$base}/managed-4402" ) . '/plugins/wpcomsh-7/2.6.1/wpcomsh.php' ) );
		} finally {
			\exec( 'rm -rf ' . \escapeshellarg( $base ) );
		}
	}

	/**
	 * A root that can name no owner for a file does not end the search: a
	 * plugin deployed unversioned into the managed tree and linked into the
	 * plugins root answers its link's slug.
	 */
	public function test_a_root_that_names_no_owner_yields_to_a_later_one(): void {
		$base = \sys_get_temp_dir() . '/eln-owner-unversioned-' . \getmypid();
		foreach ( [ 'wp-content/plugins', 'managed-5531/plugins/nuc-5531' ] as $dir ) {
			\mkdir( "{$base}/{$dir}", 0777, true );
		}
		\symlink( "{$base}/managed-5531/plugins/nuc-5531", "{$base}/wp-content/plugins/nuc-5531" );
		try {
			self::own_files_under(
				[
					'plugins' => "{$base}/wp-content/plugins",
					'content' => "{$base}/wp-content",
					'abspath' => "{$base}/",
					'managed' => \realpath( "{$base}/managed-5531" ),
				] + self::ROOTS
			);
			$managed = \realpath( "{$base}/managed-5531" );

			$this->assertSame( 'nuc-5531', Owner::of( "{$managed}/plugins/nuc-5531/nuc-5531.php" ) );
			$this->assertSame( '', Owner::of( "{$managed}/plugins/stray-5531/stray.php" ) );
		} finally {
			\exec( 'rm -rf ' . \escapeshellarg( $base ) );
		}
	}

	/** A root's links are read once a process; a theme-only rebuild keeps them. */
	public function test_a_roots_links_are_read_once(): void {
		$base = \sys_get_temp_dir() . '/eln-owner-once-' . \getmypid();
		foreach ( [ 'wp-content/plugins', 'repos-3390/toroa-src' ] as $dir ) {
			\mkdir( "{$base}/{$dir}", 0777, true );
		}
		\symlink( "{$base}/repos-3390/toroa-src", "{$base}/wp-content/plugins/toroa-3390" );
		try {
			self::own_files_under(
				[
					'plugins' => "{$base}/wp-content/plugins",
					'themes'  => [],
					'content' => "{$base}/wp-content",
					'abspath' => "{$base}/",
				] + self::ROOTS
			);
			$real = \realpath( "{$base}/repos-3390" );
			$this->assertSame( 'toroa-3390', Owner::of( "{$real}/toroa-src/toroa.php" ) );

			\unlink( "{$base}/wp-content/plugins/toroa-3390" );
			$GLOBALS['wp_theme_directories'][] = "{$base}/extra-themes";

			$this->assertSame( 'toroa-3390', Owner::of( "{$real}/toroa-src/includes/class-toroa.php" ) );
		} finally {
			\exec( 'rm -rf ' . \escapeshellarg( $base ) );
		}
	}

	/**
	 * A managed host serves core from a versioned tree `ABSPATH` links to, so
	 * core's files classify through the resolved `ABSPATH`.
	 */
	public function test_core_through_a_symlinked_abspath_is_core(): void {
		$real = \sys_get_temp_dir() . '/eln-owner-core-' . \getmypid() . '/core/7.1.3';
		$link = \sys_get_temp_dir() . '/eln-owner-wp-' . \getmypid();
		\mkdir( $real . '/wp-includes', 0777, true );
		\symlink( $real, $link );
		try {
			self::own_files_under( [ 'abspath' => $link . '/' ] + self::ROOTS );

			$this->assertSame( 'core', Owner::of( \realpath( $real ) . '/wp-includes/class-wp-hook.php' ) );
		} finally {
			\unlink( $link );
			\exec( 'rm -rf ' . \escapeshellarg( \dirname( $real, 2 ) ) );
		}
	}

	/** A file is classified once a process: its second answer is the first. */
	public function test_a_file_keeps_the_owner_it_was_first_given(): void {
		$file = self::ROOTS['plugins'] . '/weka-4410/weka.php';
		$this->assertSame( 'weka-4410', Owner::of( $file ) );

		Owner::$roots = static fn (): array => [ 'plugins' => '/srv/elsewhere-3141/plugins' ] + self::seam_roots();
		( new \ReflectionProperty( Owner::class, 'table' ) )->setValue( null, null );

		$this->assertSame( 'weka-4410', Owner::of( $file ) );
		$this->assertSame( '', Owner::of( self::ROOTS['plugins'] . '/ruru-3301/ruru.php' ), 'an unseen file reads the new roots' );
	}

	/** The roots are asked for once, however many files are classified. */
	public function test_the_roots_are_read_once(): void {
		$asked        = 0;
		Owner::$roots = static function () use ( &$asked ): array {
			++$asked;
			return self::seam_roots();
		};

		Owner::of( self::ROOTS['plugins'] . '/weka-4410/a.php' );
		Owner::of( self::ROOTS['plugins'] . '/weka-4410/b.php' );

		$this->assertSame( 1, $asked );
	}

	/** Without the seam, the roots are WordPress's own. */
	public function test_the_default_roots_are_the_installs(): void {
		self::own_files_under( null );

		$this->assertSame( 'akismet', Owner::of( \WP_PLUGIN_DIR . '/akismet/akismet.php' ) );
		$this->assertSame( 'mu:kea-7746', Owner::of( \WPMU_PLUGIN_DIR . '/kea-7746.php' ) );
		$GLOBALS['wp_theme_directories'] = [ \WP_CONTENT_DIR . '/themes' ];
		$this->assertSame( 'theme:pukeko-2207', Owner::of( \WP_CONTENT_DIR . '/themes/pukeko-2207/functions.php' ) );
		$this->assertSame( 'dropin', Owner::of( \WP_CONTENT_DIR . '/advanced-cache.php' ) );
		$this->assertSame( 'jetpack', Owner::of( '/wordpress/plugins/jetpack/16.4-a.3/jetpack.php' ) );
		$this->assertSame( 'theme:newspack-theme', Owner::of( '/wordpress/themes/newspack-theme/2.28.1/functions.php' ) );
	}

	/** The roots map the seam answers: every root but the themes. */
	private static function seam_roots(): array {
		return \array_diff_key( self::ROOTS, [ 'themes' => true ] );
	}

	/** A classification asked for while the roots are read answers no owner. */
	public function test_a_classification_inside_the_roots_read_terminates(): void {
		$file         = self::ROOTS['plugins'] . '/weka-4410/inner.php';
		$inner        = null;
		Owner::$roots = static function () use ( $file, &$inner ): array {
			$inner = Owner::of( $file );
			return self::seam_roots();
		};

		$this->assertSame( 'weka-4410', Owner::of( self::ROOTS['plugins'] . '/weka-4410/outer.php' ) );
		$this->assertSame( '', $inner );
		$this->assertSame( 'weka-4410', Owner::of( $file ), 'the inner answer is not remembered' );
	}

	/** The theme root's filter is never run, so a listener on it cannot recurse. */
	public function test_classifying_runs_no_theme_root_filter(): void {
		self::own_files_under( null );
		$runs = 0;
		\add_filter(
			'theme_root',
			static function ( $root ) use ( &$runs ) {
				++$runs;
				Owner::of( \WP_PLUGIN_DIR . '/kea-7746/kea.php' );
				return $root;
			}
		);

		$this->assertSame( 'weka-4410', Owner::of( \WP_PLUGIN_DIR . '/weka-4410/weka.php' ) );
		$this->assertSame( 0, $runs );
	}

	/** A theme directory registered after the first classification is seen. */
	public function test_a_theme_directory_registered_later_is_seen(): void {
		self::own_files_under( [ 'themes' => [] ] + self::ROOTS );
		$this->assertSame( 'weka-4410', Owner::of( self::ROOTS['plugins'] . '/weka-4410/weka.php' ) );

		$GLOBALS['wp_theme_directories'][] = '/srv/kea-7746/extra-themes';

		$this->assertSame( 'theme:hoiho-1290', Owner::of( '/srv/kea-7746/extra-themes/hoiho-1290/functions.php' ) );
	}

	public function test_a_known_owner_prefixes_the_label(): void {
		$this->assertSame( '[mu:ruru-3301] Ruru_Loader->boot', Owner::label( 'mu:ruru-3301', 'Ruru_Loader->boot' ) );
	}

	public function test_an_unknown_owner_leaves_the_label_bare(): void {
		$this->assertSame( '{closure}:kea.php:12', Owner::label( '', '{closure}:kea.php:12' ) );
	}

	/** A file's label carries the owner of that file. */
	public function test_a_file_labels_with_its_owner(): void {
		$this->assertSame( '[mu:ruru-3301] ruru_boot', Owner::labelled( self::ROOTS['mu_plugins'] . '/ruru-3301/boot.php', 'ruru_boot' ) );
		$this->assertSame( 'kaka_boot', Owner::labelled( '/etc/kaka-5150.php', 'kaka_boot' ) );
	}

	/** Only a bare slug is a plugin; the other owners are something else. */
	public function test_a_plugin_owner_is_a_bare_slug(): void {
		$this->assertTrue( Owner::is_plugin( 'weka-4410' ) );
		foreach ( [ '', 'mu:ruru-3301', 'theme:pukeko-2207', 'dropin', 'core' ] as $owner ) {
			$this->assertFalse( Owner::is_plugin( $owner ), $owner );
		}
	}

	/** A name opening with `[` is a bare label, whatever `: ` its label holds. */
	public function test_a_bare_label_keeps_its_owner_past_a_colon(): void {
		$this->assertSame( 'mu:kea-8814', Owner::of_label( '[mu:kea-8814] Kea_Load::run: step' ) );
	}

	/** A frame named `<base>: <label>` has the owner its label opens with. */
	public function test_of_label_reads_the_owner_after_the_base(): void {
		$this->assertSame( 'theme:pukeko-2207', Owner::of_label( 'the_content hook: [theme:pukeko-2207] Pukeko->render' ) );
	}

	/** A frame that is only a label has the owner it opens with. */
	public function test_of_label_reads_a_bare_label(): void {
		$this->assertSame( 'mu:ruru-3301', Owner::of_label( '[mu:ruru-3301] {closure}:loader.php:42 @-5' ) );
	}

	/** A call-site frame has the owner its label opens with. */
	public function test_of_label_reads_a_call_site(): void {
		$this->assertSame( 'core', Owner::of_label( 'sql: [core] kea_7746_get_option' ) );
	}

	/** A plugin-load span belongs to the plugin it names. */
	public function test_a_plugin_load_span_is_its_plugins(): void {
		$this->assertSame( 'kea-7746', Owner::of_label( 'kea-7746' . Flame_Tree::PLUGIN_LOAD_SUFFIX ) );
	}

	/** A name whose label does not open with `[<owner>] ` has no owner. */
	public function test_a_name_with_no_owner_reads_empty(): void {
		$this->assertSame( '', Owner::of_label( 'init hook' ) );
		$this->assertSame( '', Owner::of_label( 'kea-7746 custom: [not-at-start' ) );
	}
}
