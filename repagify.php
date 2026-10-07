<?php
/**
 * Plugin Name:       Repagify
 * Plugin URI:        https://github.com/Gwatso/Repagio-Plugin
 * Description:       Finds the dormant posts in your archive worth reusing, then turns the best of them into blog posts, LinkedIn posts, X threads and newsletters.
 * Version:           0.6.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Afriflare
 * Author URI:        https://repagify.afriflare.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       repagify
 * Domain Path:       /languages
 * Update URI:        https://github.com/Gwatso/Repagio-Plugin
 *
 * ---------------------------------------------------------------------------
 * REMOVE THE "Update URI" HEADER ABOVE ONCE THIS PLUGIN IS ACCEPTED INTO THE
 * WORDPRESS.ORG DIRECTORY.
 *
 * While the header is present, WordPress routes update checks to
 * update_plugins_github.com (see includes/class-repagify-updater.php) and will
 * NOT accept updates from WordPress.org for this plugin. That is exactly what
 * the header is for: it stops the directory serving updates for a plugin whose
 * slug it does not own. The moment the plugin is hosted on WordPress.org, the
 * directory becomes the correct and canonical update source, and leaving this
 * header in place would permanently block every official update.
 *
 * Removing the header is sufficient on its own — Repagify_Updater only ever
 * hooks a filter keyed on the header's hostname, so it becomes inert. Deleting
 * the updater class as well is tidier but not required.
 * ---------------------------------------------------------------------------
 *
 * @package Repagify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'REPAGIFY_VERSION', '0.6.0' );
define( 'REPAGIFY_FILE', __FILE__ );
define( 'REPAGIFY_PATH', plugin_dir_path( __FILE__ ) );
define( 'REPAGIFY_URL', plugin_dir_url( __FILE__ ) );

/**
 * Fallback API base URL, used until the site owner overrides it in settings.
 */
define( 'REPAGIFY_DEFAULT_API_URL', 'https://repagify.afriflare.com/api/v1' );

/**
 * Where site owners without an account are sent to create a free one.
 */
define( 'REPAGIFY_SIGNUP_URL', 'https://repagify.afriflare.com/signup' );

require_once REPAGIFY_PATH . 'includes/class-repagify-settings.php';
require_once REPAGIFY_PATH . 'includes/class-repagify-formats.php';
require_once REPAGIFY_PATH . 'includes/class-repagify-content.php';
require_once REPAGIFY_PATH . 'includes/class-repagify-api.php';
require_once REPAGIFY_PATH . 'includes/class-repagify-quota.php';
require_once REPAGIFY_PATH . 'includes/class-repagify-scanner.php';
require_once REPAGIFY_PATH . 'includes/class-repagify-readme.php';
require_once REPAGIFY_PATH . 'includes/class-repagify-updater.php';

if ( is_admin() ) {
	require_once REPAGIFY_PATH . 'admin/class-repagify-admin.php';
}

/**
 * Boots the plugin once WordPress has loaded every plugin.
 *
 * @since 0.1.0
 *
 * @return void
 */
function repagify_bootstrap() {
	Repagify_Settings::init();

	if ( is_admin() ) {
		$admin = new Repagify_Admin();
		$admin->init();

		// Update checks belong to the admin only. The class hooks nothing at
		// all on a front-end request.
		Repagify_Updater::init();
	}
}
add_action( 'plugins_loaded', 'repagify_bootstrap' );

/*
 * Translations are loaded by WordPress itself.
 *
 * Since WordPress 4.6 a plugin distributed through the directory has its
 * translations loaded automatically from the Text Domain header, and calling
 * load_plugin_textdomain() is both redundant and flagged by Plugin Check. The
 * Text Domain and Domain Path headers at the top of this file are all that is
 * required.
 */


/**
 * Writes the default options the first time the plugin is activated.
 *
 * @since 0.1.0
 *
 * @return void
 */
function repagify_activate() {
	Repagify_Settings::seed_defaults();
}
register_activation_hook( __FILE__, 'repagify_activate' );
