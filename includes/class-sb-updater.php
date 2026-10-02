<?php
/**
 * Self-updater: pulls new versions straight from the GitHub repository's
 * main branch, so every site updates from Плъгини → Обновления like any
 * other plugin. A new version is detected when the "Version:" header in
 * site-bridge.php on GitHub is higher than the installed one.
 */

defined( 'ABSPATH' ) || exit;

class SB_Updater {

	const REPO      = 'proclaudecopilot/ai-site-bridge';
	const BRANCH    = 'main';
	const CACHE_KEY = 'site_bridge_remote_version';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	public static function boot(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder_name' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_update' ), 10, 2 );
	}

	public static function basename(): string {
		return plugin_basename( SITE_BRIDGE_FILE );
	}

	public static function raw_url( string $file ): string {
		return 'https://raw.githubusercontent.com/' . self::REPO . '/' . self::BRANCH . '/' . $file;
	}

	public static function package_url(): string {
		return 'https://github.com/' . self::REPO . '/archive/refs/heads/' . self::BRANCH . '.zip';
	}

	/** Remote version from the plugin header on GitHub (cached). */
	public static function remote_version( bool $force = false ) {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( ! $force && is_array( $cached ) && isset( $cached['version'] ) ) {
			return $cached;
		}
		$res = wp_remote_get(
			add_query_arg( 'sb', time(), self::raw_url( 'site-bridge.php' ) ),
			array(
				'timeout'    => 10,
				'user-agent' => 'SiteBridge/' . SITE_BRIDGE_VERSION . '; ' . home_url(),
			)
		);
		$info = array(
			'version' => null,
			'checked' => time(),
			'error'   => null,
		);
		if ( is_wp_error( $res ) ) {
			$info['error'] = $res->get_error_message();
		} elseif ( wp_remote_retrieve_response_code( $res ) !== 200 ) {
			$info['error'] = 'HTTP ' . wp_remote_retrieve_response_code( $res );
		} elseif ( preg_match( '/^\s*\*\s*Version:\s*([0-9][0-9A-Za-z.\-]*)/mi', (string) wp_remote_retrieve_body( $res ), $m ) ) {
			$info['version'] = trim( $m[1] );
		} else {
			$info['error'] = 'Version header not found.';
		}
		set_site_transient( self::CACHE_KEY, $info, $info['error'] ? 15 * MINUTE_IN_SECONDS : self::CACHE_TTL );
		return $info;
	}

	public static function has_update(): bool {
		$r = self::remote_version();
		return ! empty( $r['version'] ) && version_compare( $r['version'], SITE_BRIDGE_VERSION, '>' );
	}

	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$remote = self::remote_version();
		$base   = self::basename();
		if ( ! empty( $remote['version'] ) && version_compare( $remote['version'], SITE_BRIDGE_VERSION, '>' ) ) {
			$transient->response[ $base ] = (object) array(
				'id'          => 'github.com/' . self::REPO,
				'slug'        => 'site-bridge',
				'plugin'      => $base,
				'new_version' => $remote['version'],
				'url'         => 'https://github.com/' . self::REPO,
				'package'     => self::package_url(),
				'icons'       => array(),
				'banners'     => array(),
				'tested'      => '',
				'requires_php' => '7.4',
			);
			unset( $transient->no_update[ $base ] );
		} else {
			unset( $transient->response[ $base ] );
			$transient->no_update[ $base ] = (object) array(
				'id'          => 'github.com/' . self::REPO,
				'slug'        => 'site-bridge',
				'plugin'      => $base,
				'new_version' => SITE_BRIDGE_VERSION,
				'url'         => 'https://github.com/' . self::REPO,
				'package'     => '',
			);
		}
		return $transient;
	}

	/** "View details" popup. */
	public static function plugin_info( $result, $action, $args ) {
		if ( $action !== 'plugin_information' || empty( $args->slug ) || $args->slug !== 'site-bridge' ) {
			return $result;
		}
		$remote  = self::remote_version();
		$readme  = wp_remote_get( self::raw_url( 'README.md' ), array( 'timeout' => 10 ) );
		$body    = is_wp_error( $readme ) ? '' : (string) wp_remote_retrieve_body( $readme );
		$changes = '';
		if ( preg_match( '/^## Промени\s*$(.*?)(^## |\z)/msu', $body, $m ) ) {
			$changes = '<pre style="white-space:pre-wrap">' . esc_html( trim( $m[1] ) ) . '</pre>';
		}
		return (object) array(
			'name'          => 'Site Bridge',
			'slug'          => 'site-bridge',
			'version'       => $remote['version'] ?: SITE_BRIDGE_VERSION,
			'author'        => '<a href="https://github.com/' . self::REPO . '">proclaudecopilot</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => '5.8',
			'requires_php'  => '7.4',
			'download_link' => self::package_url(),
			'last_updated'  => gmdate( 'Y-m-d', (int) ( $remote['checked'] ?? time() ) ),
			'sections'      => array(
				'description' => '<p>Read-only REST API за AI асистент. Обновява се от GitHub: <code>' . esc_html( self::REPO ) . '</code>, клон <code>' . esc_html( self::BRANCH ) . '</code>.</p>',
				'changelog'   => $changes ?: '<p>Виж <a href="https://github.com/' . self::REPO . '/commits/' . self::BRANCH . '">commits в GitHub</a>.</p>',
			),
		);
	}

	/**
	 * GitHub zips extract to "ai-site-bridge-main/"; rename to the installed folder
	 * so the plugin keeps its path and stays active.
	 */
	public static function fix_folder_name( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== self::basename() ) {
			return $source;
		}
		global $wp_filesystem;
		$wanted = trailingslashit( $remote_source ) . dirname( self::basename() ) . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $wanted ) ) {
			return $source;
		}
		if ( $wp_filesystem->move( $source, $wanted, true ) ) {
			return $wanted;
		}
		return new WP_Error( 'sb_rename_failed', 'Site Bridge: could not rename the extracted update folder.' );
	}

	public static function after_update( $upgrader, $hook_extra ): void {
		if ( ( $hook_extra['type'] ?? '' ) === 'plugin' ) {
			delete_site_transient( self::CACHE_KEY );
		}
	}

	/** Force a fresh check (used by the settings screen button). */
	public static function check_now(): array {
		delete_site_transient( self::CACHE_KEY );
		$r = self::remote_version( true );
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( true );
		}
		wp_update_plugins();
		return $r;
	}
}
