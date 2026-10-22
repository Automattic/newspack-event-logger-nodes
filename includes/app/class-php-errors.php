<?php
/**
 * PHP errors — the handler and WordPress listeners behind `log_php_errors`.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Owner;
use Newspack_Nodes\Core as RuntimeCore;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What `App\Core` binds on a scope whose rule says `log_php_errors`: an error
 * handler for PHP's warnings, notices and deprecations, and listeners on the
 * actions WordPress fires for its own deprecations and misuse whatever
 * `WP_DEBUG` says. Each records into `Log_Manager::record_php_error()`.
 *
 * The handler goes on top of whatever handler is installed and calls it with
 * the same arguments, whatever recording threw, so Query Monitor's panel and
 * any other collector keep every error; it answers what that handler
 * answered, or false when there is none, so PHP's own `error_log` line is
 * written as it would be without it.
 *
 * Under `WP_DEBUG` one misuse reaches three of these: the helper's action,
 * then `wp_trigger_error()`'s action, then the error it raises. The helper's
 * action alone records it; see `wordpress_error()` and `handle()`.
 */
final class Php_Errors {

	/**
	 * The levels the handler registers for, Query Monitor's: every one but
	 * the fatals. The profiler drop-in's `NEWSPACK_PROFILER_PHP_ERROR_MASK`
	 * is the same value, which it cannot read from here.
	 */
	public const MASK = \E_ALL & ~( \E_ERROR | \E_PARSE | \E_COMPILE_ERROR | \E_USER_ERROR | \E_RECOVERABLE_ERROR );

	/**
	 * WordPress's helpers that report a deprecation or a misuse through an
	 * action and, under `WP_DEBUG`, through `wp_trigger_error()` as well.
	 */
	private const HELPERS = [
		'_deprecated_function',
		'_deprecated_constructor',
		'_deprecated_class',
		'_deprecated_file',
		'_deprecated_argument',
		'_deprecated_hook',
		'_doing_it_wrong',
	];

	/**
	 * Frames walked to find a helper and the code outward of it. Nine reach
	 * the helper — this class's three at most, a wrapped listener's two,
	 * `WP_Hook`'s two, `do_action()` and the helper — and the rest walk out
	 * through core to the code that misused it.
	 */
	private const HELPER_DEPTH = 24;

	/** The file whose `wp_trigger_error()` raises the error its actions report. */
	private const TRIGGER_FILE = '/wp-includes/functions.php';

	/** @var \Closure The handler, one object, so the stack can be asked for it. */
	private \Closure $handler;

	/** @var callable|null The handler beneath this one, which it calls. */
	private $previous = null;

	/** @var bool Whether the handler sits in the stack. */
	private bool $installed = false;

	/** Build the one handler object `install()` puts on the stack. */
	public function __construct() {
		$this->handler = $this->handle( ... );
	}

	/**
	 * Take the handler off the stack while it is still on top. One that
	 * something installed over stays, and records nothing under a rule
	 * without the switch, since `Log_Manager` reads the rule of each record.
	 */
	public function uninstall(): void {
		if ( $this->installed && self::pop_if_top( $this->handler ) ) {
			$this->installed = false;
		}
	}

	/**
	 * Pop a handler when it is the one on top of the stack, the only one
	 * `restore_error_handler()` can remove.
	 *
	 * @param callable $handler The handler to remove.
	 * @return bool Whether it was on top, and is gone.
	 */
	public static function pop_if_top( callable $handler ): bool {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- reads the top, which PHP offers no getter for.
		$top = \set_error_handler( static fn (): bool => false );
		\restore_error_handler();
		if ( $top !== $handler ) {
			return false;
		}
		\restore_error_handler();
		return true;
	}

	/**
	 * The error handler: record a PHP warning, notice or deprecation, then
	 * call the handler beneath with the same arguments, whatever recording
	 * threw; a throw then propagates.
	 *
	 * `silenced` is set when `error_reporting` leaves the error out, as `@`
	 * does. A `wp_trigger_error()` error is skipped: the action WordPress
	 * fired ahead of it recorded it already.
	 *
	 * @param int    $errno   The error's level.
	 * @param string $errstr  Its message.
	 * @param string $errfile The file it was raised in.
	 * @param int    $errline The line.
	 * @return bool What the handler beneath answered; false with none.
	 */
	public function handle( int $errno, string $errstr, string $errfile = '', int $errline = 0 ): bool {
		try {
			$level = Log_Manager::PHP_ERROR_LEVELS[ $errno ] ?? null;
			if ( null !== $level && ! self::raised_by_wp_trigger_error( $errno, $errfile ) ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- reads the level; sets nothing.
				Log_Manager::started_instance()?->record_php_error( $level, $errstr, $errfile, $errline, 0 === ( \error_reporting() & $errno ) );
			}
		} finally {
			$answer = null !== $this->previous && false !== ( $this->previous )( $errno, $errstr, $errfile, $errline );
		}
		return $answer;
	}

	/**
	 * Whether WordPress's own `wp_trigger_error()` raised this error.
	 *
	 * @param int    $errno   The error's level.
	 * @param string $errfile The file it was raised in.
	 */
	private static function raised_by_wp_trigger_error( int $errno, string $errfile ): bool {
		return \in_array( $errno, [ \E_USER_WARNING, \E_USER_NOTICE, \E_USER_DEPRECATED ], true )
			&& \str_ends_with( $errfile, self::TRIGGER_FILE )
			&& Owner::CORE === Owner::of( $errfile );
	}

	/**
	 * `deprecated_function_run`.
	 *
	 * @param mixed $function_name The deprecated function.
	 * @param mixed $replacement   What to call instead.
	 * @param mixed $version       The version that deprecated it.
	 */
	public function deprecated_function( mixed $function_name, mixed $replacement, mixed $version ): void {
		$this->wordpress_error( '_deprecated_function', 'deprecated', self::deprecation( 'deprecated_function', self::text( $function_name ), $version, $replacement ) );
	}

	/**
	 * `deprecated_constructor_run`.
	 *
	 * @param mixed $class_name   The class whose PHP4-style constructor ran.
	 * @param mixed $version      The version that deprecated it.
	 * @param mixed $parent_class The class that called it.
	 */
	public function deprecated_constructor( mixed $class_name, mixed $version, mixed $parent_class ): void {
		$in = '' === self::text( $parent_class ) ? '' : ' in ' . self::text( $parent_class );
		$this->wordpress_error( '_deprecated_constructor', 'deprecated', self::deprecation( 'deprecated_constructor', self::text( $class_name ) . $in, $version ) );
	}

	/**
	 * `deprecated_class_run`.
	 *
	 * @param mixed $class_name  The deprecated class.
	 * @param mixed $replacement What to use instead.
	 * @param mixed $version     The version that deprecated it.
	 */
	public function deprecated_class( mixed $class_name, mixed $replacement, mixed $version ): void {
		$this->wordpress_error( '_deprecated_class', 'deprecated', self::deprecation( 'deprecated_class', self::text( $class_name ), $version, $replacement ) );
	}

	/**
	 * `deprecated_file_included`.
	 *
	 * @param mixed $file        The deprecated file.
	 * @param mixed $replacement What to include instead.
	 * @param mixed $version     The version that deprecated it.
	 * @param mixed $message     WordPress's word on the change.
	 */
	public function deprecated_file( mixed $file, mixed $replacement, mixed $version, mixed $message ): void {
		$this->wordpress_error( '_deprecated_file', 'deprecated', self::deprecation( 'deprecated_file', self::text( $file ), $version, $replacement, $message ) );
	}

	/**
	 * `deprecated_argument_run`.
	 *
	 * @param mixed $function_name The function given the deprecated argument.
	 * @param mixed $message       What changed.
	 * @param mixed $version       The version that deprecated it.
	 */
	public function deprecated_argument( mixed $function_name, mixed $message, mixed $version ): void {
		$this->wordpress_error( '_deprecated_argument', 'deprecated', self::deprecation( 'deprecated_argument', self::text( $function_name ), $version, '', $message ) );
	}

	/**
	 * `deprecated_hook_run`.
	 *
	 * @param mixed $hook        The deprecated hook.
	 * @param mixed $replacement The hook to use instead.
	 * @param mixed $version     The version that deprecated it.
	 * @param mixed $message     WordPress's word on the change.
	 */
	public function deprecated_hook( mixed $hook, mixed $replacement, mixed $version, mixed $message ): void {
		$this->wordpress_error( '_deprecated_hook', 'deprecated', self::deprecation( 'deprecated_hook', self::text( $hook ), $version, $replacement, $message ) );
	}

	/**
	 * A deprecation in the actions' own terms:
	 * `<kind> <subject> since <version>, use <replacement>: <message>`, each
	 * clause left out when WordPress passed nothing for it.
	 *
	 * @param string $kind        The action, less its `_run` or `_included`.
	 * @param string $subject     What is deprecated.
	 * @param mixed  $version     The version that deprecated it.
	 * @param mixed  $replacement What to use instead.
	 * @param mixed  $message     WordPress's word on the change.
	 */
	private static function deprecation( string $kind, string $subject, mixed $version, mixed $replacement = '', mixed $message = '' ): string {
		$version     = self::text( $version );
		$replacement = self::text( $replacement );
		$message     = self::text( $message );
		return "{$kind} {$subject}"
			. ( '' === $version ? '' : " since {$version}" )
			. ( '' === $replacement ? '' : ", use {$replacement}" )
			. ( '' === $message ? '' : ": {$message}" );
	}

	/**
	 * `doing_it_wrong_run`, as `doing_it_wrong <function>: <message> (<version>)`.
	 *
	 * @param mixed $function_name The function called incorrectly.
	 * @param mixed $message       How.
	 * @param mixed $version       The version that added the message.
	 */
	public function doing_it_wrong( mixed $function_name, mixed $message, mixed $version ): void {
		$version = self::text( $version );
		$this->wordpress_error( '_doing_it_wrong', 'notice', 'doing_it_wrong ' . self::text( $function_name ) . ': ' . self::text( $message ) . ( '' === $version ? '' : " ({$version})" ) );
	}

	/**
	 * `wp_trigger_error_always_run`, or `wp_trigger_error_run` before 7.0,
	 * as `wp_trigger_error <function>: <message>`.
	 *
	 * @param mixed $function_name The function that raised it, '' for none.
	 * @param mixed $message       What it raised.
	 * @param mixed $error_level   The `E_USER_*` level.
	 */
	public function triggered_error( mixed $function_name, mixed $message, mixed $error_level ): void {
		$level = Log_Manager::PHP_ERROR_LEVELS[ RuntimeCore::num_int( $error_level ) ] ?? null;
		if ( null !== $level ) {
			$function_name = self::text( $function_name );
			$this->wordpress_error( 'wp_trigger_error', $level, 'wp_trigger_error' . ( '' === $function_name ? '' : " {$function_name}" ) . ': ' . self::text( $message ) );
		}
	}

	/**
	 * Record one WordPress error at the nearest code outward of its helper
	 * that neither core nor an unknown file owns, as `Core::owned_frame()`
	 * walks it, or at the helper's own call when there is none. A
	 * `wp_trigger_error()` a helper made is skipped, its action having
	 * recorded it already. `silenced` is set when `WP_DEBUG` is off, as
	 * WordPress then raises nothing.
	 *
	 * @param string $helper  The WordPress function that fired the action.
	 * @param string $level   `warning`, `notice` or `deprecated`.
	 * @param string $message What WordPress reports.
	 */
	private function wordpress_error( string $helper, string $level, string $message ): void {
		$lm = Log_Manager::started_instance();
		if ( null === $lm ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- locates the misuse.
		$frames = \debug_backtrace( \DEBUG_BACKTRACE_IGNORE_ARGS, self::HELPER_DEPTH );
		$at     = null;
		foreach ( $frames as $i => $frame ) {
			if ( $helper === $frame['function'] && ! isset( $frame['class'] ) ) {
				$at = $i;
				break;
			}
		}
		$site = [];
		if ( null !== $at ) {
			if ( 'wp_trigger_error' === $helper && \in_array( $frames[ $at + 1 ]['function'] ?? '', self::HELPERS, true ) ) {
				return;
			}
			$site = $frames[ Core::owned_frame( $frames, $at ) ?? $at ];
		}
		$lm->record_php_error( $level, $message, $site['file'] ?? '', $site['line'] ?? 0, ! ( \defined( 'WP_DEBUG' ) && \WP_DEBUG ) );
	}

	/**
	 * An action argument as text; one that is not a scalar reads as ''.
	 *
	 * @param mixed $value What the action passed.
	 */
	private static function text( mixed $value ): string {
		return RuntimeCore::as_string( $value, '' );
	}

	/**
	 * The listeners `App\Core` binds beside the handler. A WordPress older
	 * than 7.0 has no `wp_trigger_error_always_run`, and only there is the
	 * debug action bound, so a newer one never hears a misuse twice.
	 *
	 * @return list<array{0: string, 1: callable, 2: int, 3: int}> Hook, callback, priority, accepted args.
	 */
	public function listeners(): array {
		return [
			[ 'deprecated_function_run', [ $this, 'deprecated_function' ], 10, 3 ],
			[ 'deprecated_constructor_run', [ $this, 'deprecated_constructor' ], 10, 3 ],
			[ 'deprecated_class_run', [ $this, 'deprecated_class' ], 10, 3 ],
			[ 'deprecated_file_included', [ $this, 'deprecated_file' ], 10, 4 ],
			[ 'deprecated_argument_run', [ $this, 'deprecated_argument' ], 10, 3 ],
			[ 'deprecated_hook_run', [ $this, 'deprecated_hook' ], 10, 4 ],
			[ 'doing_it_wrong_run', [ $this, 'doing_it_wrong' ], 10, 3 ],
			[ \version_compare( \get_bloginfo( 'version' ), '7.0', '<' ) ? 'wp_trigger_error_run' : 'wp_trigger_error_always_run', [ $this, 'triggered_error' ], 10, 3 ],
		];
	}

	/**
	 * Put the handler on top of the stack, unless it already sits in it.
	 */
	public function install(): void {
		if ( $this->installed ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- the switch's whole point.
		$this->previous  = \set_error_handler( $this->handler, self::MASK );
		$this->installed = true;
	}
}
