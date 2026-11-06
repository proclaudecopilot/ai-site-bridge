<?php
/**
 * Safe, read-only filesystem helpers.
 */

defined( 'ABSPATH' ) || exit;

class SB_Paths {

	const DENY_BASENAMES = array( 'wp-config.php', '.htpasswd', '.my.cnf' );
	const DENY_PATTERNS  = array( '/^\.env(\..*)?$/i', '/\.(pem|key|p12|pfx)$/i' );

	/** Directories the bridge may read, depending on the configured scope. */
	public static function roots(): array {
		$roots = array( self::norm( WP_CONTENT_DIR ) );
		if ( SB_Auth::setting( 'file_scope' ) === 'abspath' ) {
			array_unshift( $roots, self::norm( ABSPATH ) );
		}
		return array_values( array_unique( array_filter( $roots ) ) );
	}

	private static function norm( string $p ): string {
		$r = realpath( $p );
		return $r === false ? rtrim( str_replace( '\\', '/', $p ), '/' ) : rtrim( str_replace( '\\', '/', $r ), '/' );
	}

	/**
	 * Resolve a user-supplied path to an absolute path inside an allowed root.
	 *
	 * Accepts: "plugins/x/y.php", "wp-content/plugins/x/y.php", absolute paths.
	 *
	 * @return string|WP_Error
	 */
	public static function resolve( string $path ) {
		$path = trim( $path );
		if ( $path === '' || strpos( $path, "\0" ) !== false ) {
			return new WP_Error( 'sb_bad_path', 'Empty or invalid path.', array( 'status' => 400 ) );
		}
		$path       = str_replace( '\\', '/', $path );
		$candidates = array();
		if ( $path[0] === '/' || preg_match( '/^[A-Za-z]:\//', $path ) ) {
			$candidates[] = $path;
		} else {
			$candidates[] = self::norm( ABSPATH ) . '/' . ltrim( $path, '/' );
			$candidates[] = self::norm( WP_CONTENT_DIR ) . '/' . ltrim( $path, '/' );
			$candidates[] = self::norm( WP_PLUGIN_DIR ) . '/' . ltrim( $path, '/' );
		}
		foreach ( $candidates as $c ) {
			$real = realpath( $c );
			if ( $real === false ) {
				continue;
			}
			$real = str_replace( '\\', '/', $real );
			foreach ( self::roots() as $root ) {
				if ( $real === $root || strpos( $real, $root . '/' ) === 0 ) {
					$deny = self::denied( $real );
					if ( $deny ) {
						return $deny;
					}
					return $real;
				}
			}
		}
		return new WP_Error( 'sb_path_outside', 'Path does not exist or is outside the allowed directories (' . implode( ', ', self::roots() ) . ').', array( 'status' => 404 ) );
	}

	/**
	 * Resolve a path for writing. The file need not exist yet, but its parent
	 * directory must exist and sit inside an allowed root, and the basename must
	 * not be on the deny list.
	 *
	 * @return string|WP_Error  Absolute normalized target path.
	 */
	public static function resolve_writable( string $path ) {
		$path = trim( $path );
		if ( $path === '' || strpos( $path, "\0" ) !== false ) {
			return new WP_Error( 'sb_bad_path', 'Empty or invalid path.', array( 'status' => 400 ) );
		}
		$path = str_replace( '\\', '/', $path );
		if ( strpos( $path, '..' ) !== false ) {
			return new WP_Error( 'sb_bad_path', 'Path traversal ("..") is not allowed.', array( 'status' => 400 ) );
		}

		// Build the candidate absolute paths exactly like resolve() does.
		$candidates = array();
		if ( $path[0] === '/' || preg_match( '/^[A-Za-z]:\//', $path ) ) {
			$candidates[] = $path;
		} else {
			$candidates[] = self::norm( ABSPATH ) . '/' . ltrim( $path, '/' );
			$candidates[] = self::norm( WP_CONTENT_DIR ) . '/' . ltrim( $path, '/' );
			$candidates[] = self::norm( WP_PLUGIN_DIR ) . '/' . ltrim( $path, '/' );
		}

		foreach ( $candidates as $c ) {
			$parent     = dirname( $c );
			$parent_real = realpath( $parent );
			if ( $parent_real === false ) {
				continue; // Parent directory must already exist.
			}
			$parent_real = str_replace( '\\', '/', $parent_real );
			$target      = $parent_real . '/' . basename( $c );
			foreach ( self::roots() as $root ) {
				if ( $parent_real === $root || strpos( $parent_real, $root . '/' ) === 0 ) {
					$deny = self::denied( $target );
					if ( $deny ) {
						return $deny;
					}
					return $target;
				}
			}
		}
		return new WP_Error( 'sb_path_outside', 'The target directory does not exist or is outside the allowed directories (' . implode( ', ', self::roots() ) . ').', array( 'status' => 404 ) );
	}

	private static function denied( string $real ) {
		$base = basename( $real );
		if ( in_array( $base, self::DENY_BASENAMES, true ) ) {
			return new WP_Error( 'sb_path_denied', 'Reading this file is not allowed.', array( 'status' => 403 ) );
		}
		foreach ( self::DENY_PATTERNS as $re ) {
			if ( preg_match( $re, $base ) ) {
				return new WP_Error( 'sb_path_denied', 'Reading this file is not allowed.', array( 'status' => 403 ) );
			}
		}
		return null;
	}

	public static function relative( string $abs ): string {
		$abs  = str_replace( '\\', '/', $abs );
		$root = self::norm( ABSPATH ) . '/';
		if ( strpos( $abs, $root ) === 0 ) {
			return substr( $abs, strlen( $root ) );
		}
		$croot = dirname( self::norm( WP_CONTENT_DIR ) ) . '/';
		if ( strpos( $abs, $croot ) === 0 ) {
			return substr( $abs, strlen( $croot ) );
		}
		return $abs;
	}

	/** "plugin:slug", "mu-plugin:file", "theme:stylesheet", "core", or "other". */
	public static function source_of( string $file ): string {
		$file = str_replace( '\\', '/', $file );
		$pd   = self::norm( WP_PLUGIN_DIR ) . '/';
		if ( strpos( $file, $pd ) === 0 ) {
			$rest = substr( $file, strlen( $pd ) );
			return 'plugin:' . explode( '/', $rest )[0];
		}
		$mu = self::norm( WPMU_PLUGIN_DIR ) . '/';
		if ( strpos( $file, $mu ) === 0 ) {
			return 'mu-plugin:' . explode( '/', substr( $file, strlen( $mu ) ) )[0];
		}
		$themes = self::norm( get_theme_root() ) . '/';
		if ( strpos( $file, $themes ) === 0 ) {
			return 'theme:' . explode( '/', substr( $file, strlen( $themes ) ) )[0];
		}
		$abs = self::norm( ABSPATH ) . '/';
		if ( strpos( $file, $abs . 'wp-includes/' ) === 0 || strpos( $file, $abs . 'wp-admin/' ) === 0 ) {
			return 'core';
		}
		return 'other';
	}

	/** Last $lines lines of a file without loading it whole. */
	public static function tail( string $file, int $lines, int $max_bytes = 4194304 ): array {
		$size = filesize( $file );
		$fh   = fopen( $file, 'rb' );
		if ( ! $fh ) {
			return array();
		}
		$chunk = 65536;
		$pos   = $size;
		$data  = '';
		$count = 0;
		while ( $pos > 0 && $count <= $lines && strlen( $data ) < $max_bytes ) {
			$read = min( $chunk, $pos );
			$pos -= $read;
			fseek( $fh, $pos );
			$data  = fread( $fh, $read ) . $data;
			$count = substr_count( $data, "\n" );
		}
		fclose( $fh );
		$arr = explode( "\n", $data );
		if ( end( $arr ) === '' ) {
			array_pop( $arr );
		}
		return array_slice( $arr, -$lines );
	}

	/** Read a range of lines (1-based $from) from a file. */
	public static function read_lines( string $file, int $from, int $count ): array {
		$out = array();
		$fh  = fopen( $file, 'rb' );
		if ( ! $fh ) {
			return $out;
		}
		$n = 0;
		while ( ( $line = fgets( $fh ) ) !== false ) {
			$n++;
			if ( $n < $from ) {
				continue;
			}
			$out[] = rtrim( $line, "\r\n" );
			if ( count( $out ) >= $count ) {
				break;
			}
		}
		fclose( $fh );
		return $out;
	}

	public static function listing( string $dir, int $limit = 2000 ): array {
		$items = array();
		$h     = opendir( $dir );
		if ( ! $h ) {
			return $items;
		}
		while ( ( $name = readdir( $h ) ) !== false ) {
			if ( $name === '.' || $name === '..' ) {
				continue;
			}
			$full    = $dir . '/' . $name;
			$is_dir  = is_dir( $full );
			$items[] = array(
				'name'  => $name,
				'type'  => $is_dir ? 'dir' : ( is_link( $full ) ? 'link' : 'file' ),
				'size'  => $is_dir ? null : @filesize( $full ),
				'mtime' => @filemtime( $full ) ? gmdate( 'c', filemtime( $full ) ) : null,
			);
			if ( count( $items ) >= $limit ) {
				break;
			}
		}
		closedir( $h );
		usort(
			$items,
			function ( $a, $b ) {
				if ( $a['type'] !== $b['type'] ) {
					return $a['type'] === 'dir' ? -1 : 1;
				}
				return strcasecmp( $a['name'], $b['name'] );
			}
		);
		return $items;
	}

	public static function debug_log_path(): string {
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && WP_DEBUG_LOG !== '' ) {
			return WP_DEBUG_LOG;
		}
		return WP_CONTENT_DIR . '/debug.log';
	}
}
