<?php
/**
 * Uninstall cleanup.
 *
 * Runs when the site owner deletes the plugin from the Plugins screen.
 *
 * @package Repagio
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Removes the plugin's options, caches and post meta from the current site.
 *
 * @since 0.1.0
 *
 * @return void
 */
function repagio_uninstall_site() {
	delete_option( 'repagio_settings' );
	delete_transient( 'repagio_scan_cache' );
	delete_transient( 'repagio_account_cache' );
	delete_transient( 'repagio_account_failure' );
	delete_transient( 'repagio_latest_release' );
	delete_transient( 'repagio_release_failure' );
	delete_post_meta_by_key( '_repagio_converted' );

	// Data left under the plugin's former name, in case the migration in
	// Repagio_Migration never got the chance to run.
	delete_option( 'repagify_settings' );
	delete_post_meta_by_key( '_repagify_converted' );
}

if ( is_multisite() ) {
	$repagio_site_ids = get_sites(
		array(
			'fields'                 => 'ids',
			'number'                 => 0,
			'update_site_meta_cache' => false,
		)
	);

	foreach ( $repagio_site_ids as $repagio_site_id ) {
		switch_to_blog( $repagio_site_id );
		repagio_uninstall_site();
		restore_current_blog();
	}

	unset( $repagio_site_ids, $repagio_site_id );
} else {
	repagio_uninstall_site();
}
