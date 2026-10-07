<?php
/**
 * One-time migration of data stored under the plugin's former name.
 *
 * @package Repagio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Moves settings and post meta written by Repagify, as this plugin was called
 * up to 0.6.0, onto the Repagio keys.
 *
 * Without this, renaming the keys would silently discard every existing site's
 * API key and its record of which posts have been converted. The legacy names
 * below are the only place the old prefix may appear in the codebase.
 *
 * The migration runs at most once per site: it is triggered by the legacy
 * option, and deleting that option is its last step.
 *
 * @since 0.7.0
 */
class Repagio_Migration {

	/**
	 * Option that held every setting under the former name.
	 *
	 * @var string
	 */
	const LEGACY_OPTION = 'repagify_settings';

	/**
	 * Post meta key that marked converted posts under the former name.
	 *
	 * @var string
	 */
	const LEGACY_CONVERTED_META = '_repagify_converted';

	/**
	 * The default API base URL under the former name. Every site stored it
	 * explicitly, so it has to be swapped for the new default rather than
	 * copied, or migrated sites would keep calling the old domain.
	 *
	 * @var string
	 */
	const LEGACY_DEFAULT_API_URL = 'https://repagify.afriflare.com/api/v1';

	/**
	 * Caches written under the former name. Discarded, not copied: each one
	 * rebuilds itself on the next request that needs it.
	 *
	 * @var string[]
	 */
	const LEGACY_TRANSIENTS = array(
		'repagify_scan_cache',
		'repagify_account_cache',
		'repagify_account_failure',
		'repagify_latest_release',
		'repagify_release_failure',
	);

	/**
	 * Migrates legacy data if any is present.
	 *
	 * Must run before Repagio_Settings::seed_defaults(), so that a site's saved
	 * key is carried over rather than shadowed by a freshly seeded empty one.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public static function maybe_migrate() {
		$legacy = get_option( self::LEGACY_OPTION, false );

		if ( false === $legacy ) {
			return;
		}

		self::migrate_settings( $legacy );
		self::migrate_converted_meta();

		foreach ( self::LEGACY_TRANSIENTS as $transient ) {
			delete_transient( $transient );
		}

		// Last, so an interrupted run is retried on the next request.
		delete_option( self::LEGACY_OPTION );
	}

	/**
	 * Copies the legacy settings over the new option.
	 *
	 * A new option that already holds a key is left alone: that key was saved
	 * deliberately after the rename and is the one the site owner wants.
	 *
	 * @since 0.7.0
	 *
	 * @param mixed $legacy Value of the legacy option.
	 * @return void
	 */
	protected static function migrate_settings( $legacy ) {
		if ( ! is_array( $legacy ) ) {
			return;
		}

		$current = get_option( Repagio_Settings::OPTION_NAME, false );

		if ( is_array( $current ) && ! empty( $current['api_key'] ) ) {
			return;
		}

		// A custom URL, such as a development instance, is kept as it is.
		$api_url = isset( $legacy['api_url'] ) && is_scalar( $legacy['api_url'] )
			? untrailingslashit( trim( (string) $legacy['api_url'] ) )
			: '';

		if ( '' === $api_url || self::LEGACY_DEFAULT_API_URL === $api_url ) {
			$legacy['api_url'] = REPAGIO_DEFAULT_API_URL;
		}

		update_option( Repagio_Settings::OPTION_NAME, $legacy );
	}

	/**
	 * Renames the legacy converted-post meta key in place.
	 *
	 * One UPDATE rather than a read and rewrite per post, so the stored values,
	 * including the timestamps of each conversion, are untouched.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	protected static function migrate_converted_meta() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time rename of a meta key; no API renames a key, and the affected posts' caches are cleared below.
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::LEGACY_CONVERTED_META
			)
		);

		if ( empty( $post_ids ) ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- As above.
		$wpdb->update(
			$wpdb->postmeta,
			array( 'meta_key' => Repagio_Scanner::CONVERTED_META ),
			array( 'meta_key' => self::LEGACY_CONVERTED_META ),
			array( '%s' ),
			array( '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key

		foreach ( $post_ids as $post_id ) {
			wp_cache_delete( (int) $post_id, 'post_meta' );
		}
	}
}
