<?php
/**
 * Settings, token auth, IP allow-list, rate limit and request log.
 */

defined( 'ABSPATH' ) || exit;

class SB_Auth {

	const OPT     = 'site_bridge_settings';
	const LOG_OPT = 'site_bridge_log';
	const LOG_MAX = 100;

	const HEADER = 'X-Site-Bridge-Token';

	public static function defaults(): array {
		return array(
			'enabled'          => false,
			'token_hash'       => '',
			'token_hint'       => '',
			'token_created'    => 0,
			'token_last_used'  => 0,
			'ip_allowlist'     => '',
			'rate_limit'       => 120,
			'allow_files'      => true,
			'allow_sql'        => true,
			'allow_render'     => true,
			'capture_warnings' => false,
			'render_user'      => 0,
			'file_scope'       => 'content', // 'content' => wp-content only, 'abspath' => whole install.
			'log_requests'     => true,
		);
	}

	public static function settings(): array {
		$saved = get_option( self::OPT, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::defaults(), $saved );
	}

	public static function setting( string $key ) {
		$s = self::settings();
		return $s[ $key ] ?? null;
	}

	public static function update( array $changes ): void {
		$s = array_merge( self::settings(), $changes );
		update_option( self::OPT, $s, false );
	}

	public static function on_activate(): void {
		if ( get_option( self::OPT, null ) === null ) {
			add_option( self::OPT, self::defaults(), '', 'no' );
		}
	}

	public static function is_enabled(): bool {
		if ( defined( 'SITE_BRIDGE_DISABLED' ) && SITE_BRIDGE_DISABLED ) {
			return false;
		}
		return (bool) self::setting( 'enabled' );
	}

	public static function has_token(): bool {
		return self::setting( 'token_hash' ) !== '' || ( defined( 'SITE_BRIDGE_TOKEN' ) && SITE_BRIDGE_TOKEN !== '' );
	}

	/** Creates a new token, stores only its hash, returns the plain token once. */
	public static function generate_token(): string {
		$token = 'sb_' . bin2hex( random_bytes( 24 ) );
		self::update(
			array(
				'token_hash'      => self::hash( $token ),
				'token_hint'      => substr( $token, 0, 8 ),
				'token_created'   => time(),
				'token_last_used' => 0,
			)
		);
		return $token;
	}

	public static function revoke_token(): void {
		self::update(
			array(
				'token_hash'      => '',
				'token_hint'      => '',
				'token_created'   => 0,
				'token_last_used' => 0,
			)
		);
	}

	public static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	public static function extract_token( WP_REST_Request $request ): string {
		$t = (string) $request->get_header( 'x_site_bridge_token' );
		if ( $t === '' ) {
			$auth = (string) $request->get_header( 'authorization' );
			if ( $auth === '' && ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
				$auth = (string) $_SERVER['HTTP_AUTHORIZATION'];
			}
			if ( stripos( $auth, 'Bearer ' ) === 0 ) {
				$t = trim( substr( $auth, 7 ) );
			}
		}
		return trim( $t );
	}

	/**
	 * permission_callback for every route.
	 *
	 * @return true|WP_Error
	 */
	public static function check( WP_REST_Request $request ) {
		if ( ! self::is_enabled() ) {
			return new WP_Error( 'site_bridge_disabled', 'Site Bridge is disabled on this site.', array( 'status' => 403 ) );
		}

		$token = self::extract_token( $request );
		if ( $token === '' ) {
			return new WP_Error( 'site_bridge_no_token', 'Missing ' . self::HEADER . ' header.', array( 'status' => 401 ) );
		}

		$ok   = false;
		$hash = (string) self::setting( 'token_hash' );
		if ( $hash !== '' && hash_equals( $hash, self::hash( $token ) ) ) {
			$ok = true;
		}
		if ( ! $ok && defined( 'SITE_BRIDGE_TOKEN' ) && SITE_BRIDGE_TOKEN !== '' && hash_equals( (string) SITE_BRIDGE_TOKEN, $token ) ) {
			$ok = true;
		}
		if ( ! $ok ) {
			self::log( $request, 401 );
			return new WP_Error( 'site_bridge_bad_token', 'Invalid token.', array( 'status' => 401 ) );
		}

		if ( ! self::ip_allowed( self::client_ip() ) ) {
			self::log( $request, 403 );
			return new WP_Error( 'site_bridge_ip', 'Client IP is not in the allow-list.', array( 'status' => 403 ) );
		}

		if ( ! self::within_rate_limit( $token ) ) {
			self::log( $request, 429 );
			return new WP_Error( 'site_bridge_rate', 'Rate limit exceeded.', array( 'status' => 429 ) );
		}

		$last = (int) self::setting( 'token_last_used' );
		if ( time() - $last > 60 ) {
			self::update( array( 'token_last_used' => time() ) );
		}

		self::log( $request, 200 );
		return true;
	}

	public static function feature_check( string $feature ) {
		return function ( WP_REST_Request $request ) use ( $feature ) {
			$r = self::check( $request );
			if ( $r !== true ) {
				return $r;
			}
			if ( ! self::setting( 'allow_' . $feature ) ) {
				return new WP_Error( 'site_bridge_feature_off', sprintf( 'The "%s" feature is turned off in Site Bridge settings.', $feature ), array( 'status' => 403 ) );
			}
			return true;
		};
	}

	/* ---------- IP ---------- */

	public static function client_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	}

	public static function ip_allowed( string $ip ): bool {
		$list = trim( (string) self::setting( 'ip_allowlist' ) );
		if ( $list === '' ) {
			return true;
		}
		foreach ( preg_split( '/[\s,]+/', $list ) as $rule ) {
			$rule = trim( $rule );
			if ( $rule === '' || strpos( $rule, '#' ) === 0 ) {
				continue;
			}
			if ( self::ip_matches( $ip, $rule ) ) {
				return true;
			}
		}
		return false;
	}

	private static function ip_matches( string $ip, string $rule ): bool {
		if ( $ip === $rule ) {
			return true;
		}
		if ( strpos( $rule, '*' ) !== false ) {
			$re = '/^' . str_replace( '\*', '.*', preg_quote( $rule, '/' ) ) . '$/';
			return (bool) preg_match( $re, $ip );
		}
		if ( strpos( $rule, '/' ) !== false && filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			list( $net, $bits ) = explode( '/', $rule, 2 );
			$bits = (int) $bits;
			if ( ! filter_var( $net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) || $bits < 0 || $bits > 32 ) {
				return false;
			}
			$mask = $bits === 0 ? 0 : ( ~0 << ( 32 - $bits ) ) & 0xFFFFFFFF;
			return ( ip2long( $ip ) & $mask ) === ( ip2long( $net ) & $mask );
		}
		return false;
	}

	/* ---------- rate limit ---------- */

	private static function within_rate_limit( string $token ): bool {
		$limit = (int) self::setting( 'rate_limit' );
		if ( $limit <= 0 ) {
			return true;
		}
		$key   = 'sb_rl_' . substr( self::hash( $token ), 0, 16 ) . '_' . floor( time() / 60 );
		$count = (int) get_transient( $key );
		$count++;
		set_transient( $key, $count, 120 );
		return $count <= $limit;
	}

	/* ---------- request log ---------- */

	public static function log( WP_REST_Request $request, int $status ): void {
		if ( ! self::setting( 'log_requests' ) ) {
			return;
		}
		$log = get_option( self::LOG_OPT, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$params = $request->get_params();
		unset( $params['query'] ); // SQL text can be long; the route name is enough.
		$log[] = array(
			'time'   => time(),
			'ip'     => self::client_ip(),
			'method' => $request->get_method(),
			'route'  => $request->get_route(),
			'params' => wp_json_encode( SB_Redact::deep( $params ) ),
			'status' => $status,
		);
		if ( count( $log ) > self::LOG_MAX ) {
			$log = array_slice( $log, -self::LOG_MAX );
		}
		update_option( self::LOG_OPT, $log, false );
	}

	public static function get_log(): array {
		$log = get_option( self::LOG_OPT, array() );
		return is_array( $log ) ? array_reverse( $log ) : array();
	}

	public static function clear_log(): void {
		delete_option( self::LOG_OPT );
	}
}
