<?php
/**
 * Update source, until the plugin is hosted on WordPress.org.
 *
 * WordPress only offers the "Enable auto-updates" control for a plugin it has
 * somewhere to check. Until this plugin is in the directory, that somewhere is
 * its GitHub releases.
 *
 * This is the one place outside class-repagio-api.php that makes an outbound
 * request, and deliberately so: it talks to GitHub, not to Repagio, and has
 * nothing to do with the site owner's API key. Routing it through the Repagio
 * client — which exists to attach a Bearer token to every call — would be
 * wrong. It still uses the WordPress HTTP API, never cURL.
 *
 * @package Repagio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves plugin updates and the View details modal from GitHub releases.
 *
 * @since 0.6.0
 */
class Repagio_Updater {

	/**
	 * Owner and repository the releases are read from.
	 *
	 * @var string
	 */
	const REPO = 'Gwatso/Repagio-Plugin';

	/**
	 * Hostname of the Update URI header, which names the filter WordPress calls.
	 *
	 * @var string
	 */
	const HOST = 'github.com';

	/**
	 * Directory slug this plugin installs into.
	 *
	 * @var string
	 */
	const SLUG = 'repagio';

	/**
	 * Transient holding the last successful release lookup.
	 *
	 * @var string
	 */
	const TRANSIENT = 'repagio_latest_release';

	/**
	 * Transient marking a recent failed lookup.
	 *
	 * @var string
	 */
	const FAILURE_TRANSIENT = 'repagio_release_failure';

	/**
	 * How long a successful lookup is trusted, in seconds.
	 *
	 * @var int
	 */
	const CACHE_TTL = 43200;

	/**
	 * How long a failure suppresses retries, in seconds.
	 *
	 * @var int
	 */
	const FAILURE_TTL = 3600;

	/**
	 * Seconds to wait on GitHub before giving up.
	 *
	 * @var int
	 */
	const TIMEOUT = 10;

	/**
	 * Registers the update hooks.
	 *
	 * Called from the admin branch of the bootstrap only, so nothing here is
	 * ever attached on a front-end request.
	 *
	 * @since 0.6.0
	 *
	 * @return void
	 */
	public static function init() {
		// WordPress 5.8+ calls this filter for plugins whose Update URI host
		// matches, instead of asking WordPress.org. Removing the Update URI
		// header makes this filter name unreachable and the class inert.
		add_filter( 'update_plugins_' . self::HOST, array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_information' ), 10, 3 );

		// A new release may have shipped since the last check.
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 0 );
	}

	/**
	 * Answers WordPress's update check for this plugin.
	 *
	 * Returns data whether or not an update exists: WordPress files an
	 * up-to-date answer under no_update, and it is that entry which makes the
	 * auto-update toggle appear. Returning false would hide it.
	 *
	 * Never throws and never returns junk. Any failure returns the incoming
	 * value untouched, because a broken updater must not break the plugins
	 * screen.
	 *
	 * @since 0.6.0
	 *
	 * @param array|false $update      Update data, false when nothing has claimed it.
	 * @param array       $plugin_data Headers of the plugin being checked.
	 * @param string      $plugin_file Plugin file, relative to the plugins directory.
	 * @return array|false
	 */
	public static function check( $update, $plugin_data, $plugin_file ) {
		// This filter fires for every plugin whose Update URI points at GitHub,
		// which on some sites means several. Only answer for our own.
		if ( self::SLUG . '/' . self::SLUG . '.php' !== $plugin_file ) {
			return $update;
		}

		$release = self::latest_release();

		if ( ! is_array( $release ) || '' === $release['version'] ) {
			return $update;
		}

		$installed = isset( $plugin_data['Version'] ) ? (string) $plugin_data['Version'] : REPAGIO_VERSION;

		$response = array(
			'slug'         => self::SLUG,
			'version'      => $release['version'],
			'url'          => 'https://github.com/' . self::REPO,
			'requires'     => isset( $plugin_data['RequiresWP'] ) ? $plugin_data['RequiresWP'] : '',
			'requires_php' => isset( $plugin_data['RequiresPHP'] ) ? $plugin_data['RequiresPHP'] : '',
			'tested'       => $release['tested'],
		);

		// Only offer a package when there is actually something newer. Handing
		// WordPress a download URL for a version already installed invites a
		// pointless reinstall.
		if ( version_compare( $release['version'], $installed, '>' ) && '' !== $release['package'] ) {
			$response['package'] = $release['package'];
		}

		return $response;
	}

	/**
	 * Fills the View details modal.
	 *
	 * @since 0.6.0
	 *
	 * @param false|object|array $result The result object or array. Default false.
	 * @param string             $action The type of information being requested.
	 * @param object             $args   Arguments for the API call.
	 * @return false|object|array
	 */
	public static function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$release = self::latest_release();
		$readme  = Repagio_Readme::parse( REPAGIO_PATH . 'readme.txt' );

		$information = array(
			'name'          => 'Repagio',
			'slug'          => self::SLUG,
			'version'       => ( is_array( $release ) && '' !== $release['version'] )
				? $release['version']
				: REPAGIO_VERSION,
			'author'        => '<a href="https://repagio.app">Afriflare</a>',
			'author_profile' => 'https://repagio.app',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => '' !== $readme['requires'] ? $readme['requires'] : '6.0',
			'tested'        => '' !== $readme['tested'] ? $readme['tested'] : '',
			'requires_php'  => '' !== $readme['requires_php'] ? $readme['requires_php'] : '7.4',
			'short_description' => $readme['short_description'],
			'sections'      => $readme['sections'],
			// No banners or icons are declared here on purpose. Pointing them at
			// a remote host counts as offloading assets, which WordPress.org
			// disallows, and once the plugin is in the directory those images
			// are served from its own CDN from the repository's assets folder.
		);

		if ( is_array( $release ) ) {
			if ( '' !== $release['package'] ) {
				$information['download_link'] = $release['package'];
			}

			if ( '' !== $release['published'] ) {
				$information['last_updated'] = $release['published'];
			}
		}

		return (object) $information;
	}

	/**
	 * Drops the cached release, so the next check asks GitHub again.
	 *
	 * @since 0.6.0
	 *
	 * @return void
	 */
	public static function flush() {
		delete_transient( self::TRANSIENT );
		delete_transient( self::FAILURE_TRANSIENT );
	}

	/**
	 * The latest release, from cache when it is warm.
	 *
	 * @since 0.6.0
	 *
	 * @return array|null {
	 *     @type string $version   Tag with any leading "v" stripped.
	 *     @type string $package   Zip URL, preferring an attached asset.
	 *     @type string $published ISO 8601 publication date.
	 *     @type string $tested    "Tested up to" from the release body, if given.
	 * }
	 */
	public static function latest_release() {
		$cached = get_transient( self::TRANSIENT );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		// A recent failure means do not try again yet. Without this, a GitHub
		// outage would add a ten second wait to every plugins screen load.
		if ( get_transient( self::FAILURE_TRANSIENT ) ) {
			return null;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Accept' => 'application/vnd.github+json',
					// GitHub rejects requests without one.
					'User-Agent' => 'Repagio-WordPress-Plugin/' . REPAGIO_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			set_transient( self::FAILURE_TRANSIENT, $response->get_error_code(), self::FAILURE_TTL );

			return null;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		// 404 means no release has been published yet, which is normal early on
		// and not worth retrying quickly.
		if ( 200 !== $status ) {
			set_transient( self::FAILURE_TRANSIENT, 'http_' . $status, self::FAILURE_TTL );

			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || ! isset( $body['tag_name'] ) ) {
			set_transient( self::FAILURE_TRANSIENT, 'unreadable', self::FAILURE_TTL );

			return null;
		}

		$release = self::describe( $body );

		if ( '' === $release['version'] ) {
			set_transient( self::FAILURE_TRANSIENT, 'no_version', self::FAILURE_TTL );

			return null;
		}

		delete_transient( self::FAILURE_TRANSIENT );
		set_transient( self::TRANSIENT, $release, self::CACHE_TTL );

		return $release;
	}

	/**
	 * Reduces a GitHub release payload to the few fields an update needs.
	 *
	 * @since 0.6.0
	 *
	 * @param array $body Decoded release payload.
	 * @return array
	 */
	protected static function describe( $body ) {
		$tag = isset( $body['tag_name'] ) ? sanitize_text_field( (string) $body['tag_name'] ) : '';

		// Tags are conventionally v1.2.3; the version is 1.2.3.
		$version = ltrim( $tag, 'vV' );

		if ( ! preg_match( '/^\d+(\.\d+)*/', $version ) ) {
			$version = '';
		}

		return array(
			'version'   => $version,
			'package'   => self::package_url( $body ),
			'published' => isset( $body['published_at'] ) ? sanitize_text_field( (string) $body['published_at'] ) : '',
			'tested'    => self::tested_from_body( isset( $body['body'] ) ? (string) $body['body'] : '' ),
		);
	}

	/**
	 * The zip WordPress should install.
	 *
	 * An attached .zip asset is strongly preferred: it is built from
	 * .distignore and unpacks to a correctly named folder. GitHub's generated
	 * zipball is the fallback, and unpacks to a folder named after the repo and
	 * commit, which installs to the wrong directory. It is offered only so a
	 * release without an asset is not completely dead.
	 *
	 * @since 0.6.0
	 *
	 * @param array $body Decoded release payload.
	 * @return string Empty string when there is nothing downloadable.
	 */
	protected static function package_url( $body ) {
		if ( isset( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( ! isset( $asset['browser_download_url'] ) ) {
					continue;
				}

				$url = (string) $asset['browser_download_url'];

				if ( preg_match( '/\.zip$/i', $url ) ) {
					return esc_url_raw( $url );
				}
			}
		}

		if ( isset( $body['zipball_url'] ) ) {
			return esc_url_raw( (string) $body['zipball_url'] );
		}

		return '';
	}

	/**
	 * Reads a "Tested up to" line out of a release body, if the notes carry one.
	 *
	 * @since 0.6.0
	 *
	 * @param string $body Release notes.
	 * @return string Empty string when absent.
	 */
	protected static function tested_from_body( $body ) {
		if ( preg_match( '/Tested up to:\s*([\d.]+)/i', $body, $matches ) ) {
			return sanitize_text_field( $matches[1] );
		}

		return '';
	}
}
