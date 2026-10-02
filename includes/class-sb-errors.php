<?php
/**
 * Captures fatal errors (and optionally warnings/notices) into a small ring buffer
 * so the bridge can report them even when debug.log is not readable.
 */

defined( 'ABSPATH' ) || exit;

class SB_Errors {

	const OPT = 'site_bridge_errors';
	const MAX = 50;

	private static $buffer       = array();
	private static $prev_handler = null;
	private static $booted       = false;

	const FATAL = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );
		if ( SB_Auth::setting( 'capture_warnings' ) ) {
			self::$prev_handler = set_error_handler( array( __CLASS__, 'on_error' ) );
		}
	}

	public static function on_error( $errno, $errstr, $errfile = '', $errline = 0 ) {
		if ( ( error_reporting() & $errno ) && count( self::$buffer ) < 20 ) {
			self::$buffer[] = self::entry( $errno, (string) $errstr, (string) $errfile, (int) $errline, false );
		}
		if ( self::$prev_handler ) {
			return call_user_func( self::$prev_handler, $errno, $errstr, $errfile, $errline );
		}
		return false; // Let PHP's own handler log it as usual.
	}

	public static function on_shutdown(): void {
		$e = error_get_last();
		if ( $e && in_array( (int) $e['type'], self::FATAL, true ) ) {
			self::$buffer[] = self::entry( (int) $e['type'], (string) $e['message'], (string) $e['file'], (int) $e['line'], true );
		}
		if ( self::$buffer ) {
			self::persist();
		}
	}

	private static function persist(): void {
		if ( ! function_exists( 'get_option' ) || empty( $GLOBALS['wpdb'] ) ) {
			return;
		}
		try {
			$list = get_option( self::OPT, array() );
			if ( ! is_array( $list ) ) {
				$list = array();
			}
			$list = array_merge( $list, self::$buffer );
			if ( count( $list ) > self::MAX ) {
				$list = array_slice( $list, -self::MAX );
			}
			update_option( self::OPT, $list, false );
		} catch ( \Throwable $t ) { // phpcs:ignore
			// Never make a dying request worse.
		}
		self::$buffer = array();
	}

	private static function entry( int $type, string $msg, string $file, int $line, bool $fatal ): array {
		return array(
			'time'    => time(),
			'fatal'   => $fatal,
			'type'    => self::type_name( $type ),
			'message' => mb_substr( $msg, 0, 2000 ),
			'file'    => SB_Paths::relative( $file ),
			'line'    => $line,
			'source'  => SB_Paths::source_of( $file ),
			'url'     => isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '',
			'method'  => isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : ( PHP_SAPI === 'cli' ? 'CLI' : '' ),
			'is_rest' => defined( 'REST_REQUEST' ) && REST_REQUEST,
			'is_ajax' => function_exists( 'wp_doing_ajax' ) && wp_doing_ajax(),
			'is_cron' => function_exists( 'wp_doing_cron' ) && wp_doing_cron(),
		);
	}

	public static function type_name( int $type ): string {
		$map = array(
			E_ERROR             => 'E_ERROR',
			E_WARNING           => 'E_WARNING',
			E_PARSE             => 'E_PARSE',
			E_NOTICE            => 'E_NOTICE',
			E_CORE_ERROR        => 'E_CORE_ERROR',
			E_CORE_WARNING      => 'E_CORE_WARNING',
			E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
			E_COMPILE_WARNING   => 'E_COMPILE_WARNING',
			E_USER_ERROR        => 'E_USER_ERROR',
			E_USER_WARNING      => 'E_USER_WARNING',
			E_USER_NOTICE       => 'E_USER_NOTICE',
			E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
			E_DEPRECATED        => 'E_DEPRECATED',
			E_USER_DEPRECATED   => 'E_USER_DEPRECATED',
		);
		return $map[ $type ] ?? (string) $type;
	}

	public static function all(): array {
		$list = get_option( self::OPT, array() );
		return is_array( $list ) ? array_reverse( $list ) : array();
	}

	public static function clear(): void {
		delete_option( self::OPT );
	}
}
