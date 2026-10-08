<?php
/**
 * Block editor sidebar.
 *
 * @package Repagio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the Repagio sidebar to the block editor.
 *
 * The sidebar is plain JavaScript written against the wp.* globals the editor
 * already loads, so it needs no build step. It talks to the same AJAX
 * endpoints as the dashboard, which keeps permission checks, scoring and
 * generation in exactly one place.
 *
 * Loading the editor never contacts the Repagio service, even with the
 * sidebar pinned open. Drawing the sidebar asks this site for the post's
 * score, which is computed locally; the account's quota is fetched only when
 * someone opens the Repurpose panel, and generation only when they press
 * Generate.
 *
 * The sidebar is open to editors as well as administrators, through
 * Repagio_Settings::editor_capability(). The settings and the API key stay
 * behind Repagio_Settings::capability().
 *
 * @since 0.8.0
 */
class Repagio_Editor {

	/**
	 * Script and style handle.
	 *
	 * @var string
	 */
	const HANDLE = 'repagio-editor';

	/**
	 * Registers editor hooks.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Whether the sidebar belongs on the current screen.
	 *
	 * enqueue_block_editor_assets also fires in the site editor and the widgets
	 * screen, neither of which has a post to repurpose, so the screen is checked
	 * rather than assumed. Only post types the scanner covers get the sidebar,
	 * so it appears exactly where the dashboard would list the post.
	 *
	 * @since 0.8.0
	 *
	 * @return bool
	 */
	protected function is_supported_screen() {
		// The same test the sidebar's endpoints apply, so nobody is shown a
		// sidebar whose requests would all be refused.
		if ( ! Repagio_Settings::can_repurpose() ) {
			return false;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'post' !== $screen->base ) {
			return false;
		}

		return in_array( $screen->post_type, Repagio_Scanner::scannable_post_types(), true );
	}

	/**
	 * Loads the sidebar script and its stylesheet.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! $this->is_supported_screen() ) {
			return;
		}

		wp_enqueue_style(
			self::HANDLE,
			REPAGIO_URL . 'admin/assets/editor.css',
			array( 'wp-components' ),
			REPAGIO_VERSION
		);

		wp_enqueue_script(
			self::HANDLE,
			REPAGIO_URL . 'admin/assets/editor.js',
			array(
				'wp-components',
				'wp-compose',
				'wp-data',
				'wp-editor',
				'wp-element',
				'wp-plugins',
			),
			REPAGIO_VERSION,
			true
		);

		wp_localize_script( self::HANDLE, 'repagioEditor', $this->script_data() );
	}

	/**
	 * Everything the sidebar needs that does not change while it is open.
	 *
	 * @since 0.8.0
	 *
	 * @return array
	 */
	protected function script_data() {
		$formats = array();

		foreach ( Repagio_Formats::all() as $slug => $format ) {
			$formats[] = array(
				'value' => $slug,
				'label' => $format['label'],
			);
		}

		$tones = array();

		foreach ( Repagio_Formats::tones() as $slug => $label ) {
			$tones[] = array(
				'value' => $slug,
				'label' => $label,
			);
		}

		return array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( Repagio_Admin::NONCE_ACTION ),
			'hasKey'       => Repagio_Settings::has_api_key(),
			// Editors cannot open the settings or the dashboard, so they are
			// not offered links to either.
			'canManage'    => current_user_can( Repagio_Settings::capability() ),
			'settingsUrl'  => Repagio_Settings::settings_url(),
			'dashboardUrl' => admin_url( 'admin.php?page=' . Repagio_Admin::DASHBOARD_PAGE ),
			'formats'      => $formats,
			'tones'        => $tones,
			'defaultTone'  => Repagio_Formats::DEFAULT_TONE,
			'keywordFor'   => array_keys(
				array_filter(
					Repagio_Formats::all(),
					static function ( $format ) {
						return ! empty( $format['keyword'] );
					}
				)
			),
			'i18n'         => array(
				'title'          => __( 'Repagio', 'repagio' ),
				'opportunity'    => __( 'Opportunity', 'repagio' ),
				'repurpose'      => __( 'Repurpose this post', 'repagio' ),
				/* translators: %s: score out of 100. */
				'scoreLabel'     => __( 'Repurposing score: %s out of 100', 'repagio' ),
				'repurposedAs'   => __( 'Already repurposed as', 'repagio' ),
				'openDashboard'  => __( 'Open the Repagio dashboard', 'repagio' ),
				'notPublished'   => __( 'Publish this post to score and repurpose it. Repagio works from the published version.', 'repagio' ),
				'unsaved'        => __( 'You have unsaved changes. Repagio reads the last saved version of this post, so save first if you want them included.', 'repagio' ),
				'loading'        => __( 'Reading the post…', 'repagio' ),
				'retry'          => __( 'Try again', 'repagio' ),
				'format'         => __( 'Output format', 'repagio' ),
				'tone'           => __( 'Tone', 'repagio' ),
				'keyword'        => __( 'Target keyword (optional)', 'repagio' ),
				'keywordHint'    => __( 'e.g. content repurposing', 'repagio' ),
				'generate'       => __( 'Generate', 'repagio' ),
				'generating'     => __( 'Generating…', 'repagio' ),
				'generateWait'   => __( 'This usually takes 20 to 45 seconds. You can keep editing, or close this sidebar; the result will be here when you come back.', 'repagio' ),
				/* translators: %s: formatted word count. */
				'wordsToSend'    => __( '%s words will be sent to Repagio.', 'repagio' ),
				/* translators: 1: characters being sent, 2: characters in the post. */
				'truncated'      => __( 'This post is longer than Repagio can convert at once, so only the first %1$s characters of %2$s will be used. You may want to split it into parts and repurpose each one.', 'repagio' ),
				'result'         => __( 'Generated content', 'repagio' ),
				/* translators: 1: output format name, 2: formatted word count. */
				'resultMeta'     => __( '%1$s — %2$s words', 'repagio' ),
				'copy'           => __( 'Copy to clipboard', 'repagio' ),
				'copied'         => __( 'Copied', 'repagio' ),
				'again'          => __( 'Generate another format', 'repagio' ),
				'noKey'          => __( 'Generation is carried out by the Repagio web service, so it needs a connected account. The score above works without one.', 'repagio' ),
				'connect'        => __( 'Connect an account', 'repagio' ),
				'askAdmin'       => __( 'Ask a site administrator to connect a Repagio account in the plugin settings.', 'repagio' ),
				'genericError'   => __( 'Something went wrong. Please try again.', 'repagio' ),
			),
		);
	}
}
