<?php
/**
 * Plugin Name: Site Bridge
 * Plugin URI:  https://github.com/proclaudecopilot/ai-site-bridge
 * Description: Read-only REST API that lets an AI assistant inspect this site (info, options, posts & meta, Oxygen data, logs, files, SELECT queries, rendered admin pages) while you work on other plugins. Nothing is ever written through it.
 * Version:     1.0.0
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * Author:      proclaudecopilot
 * License:     GPL-2.0-or-later
 * Text Domain: site-bridge
 * GitHub Plugin URI: proclaudecopilot/ai-site-bridge
 * Primary Branch: main
 * Update URI: https://github.com/proclaudecopilot/ai-site-bridge
 */

defined( 'ABSPATH' ) || exit;

define( 'SITE_BRIDGE_VERSION', '1.0.0' );
define( 'SITE_BRIDGE_FILE', __FILE__ );
define( 'SITE_BRIDGE_DIR', plugin_dir_path( __FILE__ ) );
define( 'SITE_BRIDGE_NS', 'site-bridge/v1' );

require_once SITE_BRIDGE_DIR . 'includes/class-sb-redact.php';
require_once SITE_BRIDGE_DIR . 'includes/class-sb-auth.php';
require_once SITE_BRIDGE_DIR . 'includes/class-sb-errors.php';
require_once SITE_BRIDGE_DIR . 'includes/class-sb-paths.php';
require_once SITE_BRIDGE_DIR . 'includes/class-sb-oxygen.php';
require_once SITE_BRIDGE_DIR . 'includes/class-sb-rest.php';
require_once SITE_BRIDGE_DIR . 'includes/class-sb-admin.php';
require_once SITE_BRIDGE_DIR . 'includes/class-sb-updater.php';

// Error capture must start as early as possible.
SB_Errors::boot();

add_action( 'rest_api_init', array( 'SB_REST', 'register_routes' ) );

if ( is_admin() ) {
	SB_Admin::boot();
}

SB_Updater::boot();

register_activation_hook( __FILE__, array( 'SB_Auth', 'on_activate' ) );
