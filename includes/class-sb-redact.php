<?php
/**
 * Redaction of secrets in anything the bridge returns.
 */

defined( 'ABSPATH' ) || exit;

class SB_Redact {

	const SENSITIVE_KEY_RE = '/(password|passwd|pwd|secret|token|api[_\-]?key|apikey|private[_\-]?key|salt|licen[cs]e|client[_\-]?secret|access[_\-]?key|auth[_\-]?key|bearer|credential|user_pass|activation_key|consumer_key|signing)/i';

	/** Keys that match the pattern but are harmless. */
	const SAFE_KEYS = array(
		'default_password_nag',
		'users_can_register',
		'show_password_fields',
		'password_reset',
		'tokens', // UI label-ish
	);

	const SENSITIVE_CONSTANTS = array(
		'DB_PASSWORD',
		'AUTH_KEY',
		'SECURE_AUTH_KEY',
		'LOGGED_IN_KEY',
		'NONCE_KEY',
		'AUTH_SALT',
		'SECURE_AUTH_SALT',
		'LOGGED_IN_SALT',
		'NONCE_SALT',
		'FTP_PASS',
		'SITE_BRIDGE_TOKEN',
	);

	public static function key_is_sensitive( $key ): bool {
		if ( ! is_string( $key ) ) {
			return false;
		}
		if ( in_array( strtolower( $key ), self::SAFE_KEYS, true ) ) {
			return false;
		}
		if ( in_array( strtoupper( $key ), self::SENSITIVE_CONSTANTS, true ) ) {
			return true;
		}
		return (bool) preg_match( self::SENSITIVE_KEY_RE, $key );
	}

	/** Placeholder that keeps the shape (type + length) of the hidden value. */
	public static function placeholder( $value ): string {
		if ( is_string( $value ) ) {
			return '[redacted string, ' . strlen( $value ) . ' chars]';
		}
		if ( is_array( $value ) ) {
			return '[redacted array, ' . count( $value ) . ' items]';
		}
		return '[redacted ' . gettype( $value ) . ']';
	}

	/**
	 * Redact a value given the key it was stored under; recurses into arrays/objects.
	 */
	public static function value( $key, $value, int $depth = 0 ) {
		if ( self::key_is_sensitive( $key ) && ! self::looks_harmless( $value ) ) {
			return self::placeholder( $value );
		}
		return self::deep( $value, $depth );
	}

	/** Recurse into arrays/objects redacting sensitive keys. */
	public static function deep( $value, int $depth = 0 ) {
		if ( $depth > 20 ) {
			return '[max depth]';
		}
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ $k ] = self::value( $k, $v, $depth + 1 );
			}
			return $out;
		}
		return $value;
	}

	/** Empty strings, booleans, numbers under a sensitive key reveal nothing. */
	private static function looks_harmless( $value ): bool {
		if ( $value === '' || $value === null || is_bool( $value ) ) {
			return true;
		}
		if ( is_numeric( $value ) && strlen( (string) $value ) <= 3 ) {
			return true;
		}
		return false;
	}

	/** Unserialize PHP-serialized data recursively, decode JSON strings when asked. */
	public static function normalize( $value, bool $decode_json = true, int $depth = 0 ) {
		if ( $depth > 10 ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			if ( is_serialized( $value ) ) {
				$un = @unserialize( $value, array( 'allowed_classes' => false ) );
				if ( $un !== false || $value === 'b:0;' ) {
					return self::normalize( $un, $decode_json, $depth + 1 );
				}
			}
			if ( $decode_json ) {
				$t = ltrim( $value );
				if ( $t !== '' && ( $t[0] === '{' || $t[0] === '[' ) ) {
					$j = json_decode( $value, true );
					if ( json_last_error() === JSON_ERROR_NONE && is_array( $j ) ) {
						return $j;
					}
				}
			}
			return $value;
		}
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::normalize( $v, $decode_json, $depth + 1 );
			}
		}
		return $value;
	}

	/**
	 * Redact a DB result row (array of columns). Knows the WordPress schema.
	 */
	public static function row( array $row ): array {
		foreach ( $row as $col => $val ) {
			if ( in_array( $col, array( 'user_pass', 'user_activation_key' ), true ) ) {
				$row[ $col ] = self::placeholder( $val );
				continue;
			}
			if ( $col === 'option_value' && isset( $row['option_name'] ) && self::key_is_sensitive( $row['option_name'] ) ) {
				$row[ $col ] = self::placeholder( $val );
				continue;
			}
			if ( $col === 'meta_value' && isset( $row['meta_key'] ) && self::key_is_sensitive( $row['meta_key'] ) ) {
				$row[ $col ] = self::placeholder( $val );
				continue;
			}
			if ( self::key_is_sensitive( $col ) ) {
				$row[ $col ] = self::placeholder( $val );
			}
		}
		return $row;
	}

	/** Mask the middle of a string (for showing token prefixes etc.). */
	public static function mask( string $s, int $keep = 4 ): string {
		if ( strlen( $s ) <= $keep * 2 ) {
			return str_repeat( '*', strlen( $s ) );
		}
		return substr( $s, 0, $keep ) . str_repeat( '*', max( 4, strlen( $s ) - $keep * 2 ) ) . substr( $s, -$keep );
	}
}
