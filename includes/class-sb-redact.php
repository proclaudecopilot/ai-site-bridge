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
	 * 2.0.1: known secret formats inside ANY string — API keys, tokens, private keys — regardless of the key they sit under
	 * (a Meta access token inside a plugin's serialized backup option, a token pasted in a log line, a key in a file).
	 */
	const SECRET_PATTERNS = array(
		'/-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]*?-----END [A-Z ]*PRIVATE KEY-----/',      // PEM private keys
		'/\bEAA[A-Za-z0-9]{40,}/',                                                                 // Meta / Facebook access token
		'/\b(?:gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{40,})/',                       // GitHub tokens
		'/\bglpat-[A-Za-z0-9_\-]{20,}/',                                                          // GitLab
		'/\b(?:sk|rk)_(?:live|test)_[A-Za-z0-9]{16,}/',                                            // Stripe secret / restricted keys
		'/\bsk-[A-Za-z0-9_\-]{20,}/',                                                             // OpenAI / Anthropic style keys
		'/\bAKIA[0-9A-Z]{16}\b/',                                                                 // AWS access key id
		'/\bAIza[0-9A-Za-z_\-]{35}\b/',                                                          // Google API key
		'/\bxox[baprs]-[A-Za-z0-9\-]{10,}/',                                                      // Slack
		'/\bSG\.[A-Za-z0-9_\-]{22}\.[A-Za-z0-9_\-]{43}/',                                      // SendGrid
		'/\bxkeysib-[a-f0-9]{64}-[A-Za-z0-9]{16}/',                                                // Brevo
		'/\bsb_[0-9a-f]{48}\b/',                                                                  // Site Bridge token
		'/\beyJ[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}/',                // JWT
		'/\b(?:bearer)\s+[A-Za-z0-9._\-]{20,}/i',                                                // Authorization: Bearer …
	);

	/** Replace every known secret format in a string with a placeholder that keeps its length. */
	public static function scrub_string( $s ) {
		if ( ! is_string( $s ) || strlen( $s ) < 16 ) {
			return $s;
		}
		foreach ( self::SECRET_PATTERNS as $re ) {
			$s = (string) preg_replace_callback( $re, function ( $m ) { return '[redacted secret, ' . strlen( $m[0] ) . ' chars]'; }, $s );
		}
		return $s;
	}

	/**
	 * 2.0.1: a string that is PHP-serialized or JSON (an option / meta value) is opened and redacted by key, so a token under a
	 * sensitive key inside it is hidden even when the outer key looks harmless (e.g. a plugin's backup of another plugin's settings).
	 * Returns the decoded, redacted structure; plain strings are only scrubbed for known secret formats.
	 */
	public static function scrub_scalar( $value, int $depth = 0 ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		$t = ltrim( $value );
		if ( $t !== '' && ( is_serialized( $value ) || $t[0] === '{' || $t[0] === '[' ) ) {
			$n = self::normalize( $value );
			if ( is_array( $n ) ) {
				return self::deep( $n, $depth + 1 );
			}
		}
		return self::scrub_string( $value );
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
		return self::scrub_scalar( $value, $depth ); // 2.0.1: strings — serialized/JSON opened and redacted by key, known token formats hidden
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
				continue;
			}
			$row[ $col ] = self::scrub_scalar( $val ); // 2.0.1: option_value / meta_value / any text — secrets inside serialized data and known token formats
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
