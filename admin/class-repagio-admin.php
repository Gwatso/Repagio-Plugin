<?php
/**
 * Admin menu, assets and AJAX endpoints.
 *
 * @package Repagio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Repagio admin pages and handles their AJAX requests.
 *
 * @since 0.1.0
 */
class Repagio_Admin {

	/**
	 * Nonce action shared by every Repagio AJAX endpoint.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'repagio_ajax';

	/**
	 * Nonce action for the Rescan content form.
	 *
	 * @var string
	 */
	const RESCAN_ACTION = 'repagio_rescan';

	/**
	 * Slug of the opportunity dashboard, which is the plugin's first page.
	 *
	 * @var string
	 */
	const DASHBOARD_PAGE = 'repagio-dashboard';

	/**
	 * Opportunity rows shown per page.
	 *
	 * @var int
	 */
	const PER_PAGE = 20;

	/**
	 * Columns the opportunity table can be sorted by.
	 *
	 * @var string[]
	 */
	const SORTABLE = array( 'score', 'word_count', 'date', 'title' );

	/**
	 * Menu hook suffixes for the plugin's own screens.
	 *
	 * @var string[]
	 */
	protected $screens = array();

	/**
	 * Registers admin hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		$basename = plugin_basename( REPAGIO_FILE );

		add_filter( 'plugin_action_links_' . $basename, array( $this, 'add_action_links' ) );

		// On multisite the plugin can also be managed from the network screen,
		// where the same links are wanted but the surrounding markup differs.
		add_filter( 'network_admin_plugin_action_links_' . $basename, array( $this, 'add_network_action_links' ) );

		add_filter( 'plugin_row_meta', array( $this, 'add_row_meta' ), 10, 2 );

		add_action( 'wp_ajax_repagio_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_repagio_scan_batch', array( $this, 'ajax_scan_batch' ) );
		add_action( 'wp_ajax_repagio_prepare', array( $this, 'ajax_prepare' ) );
		add_action( 'wp_ajax_repagio_generate', array( $this, 'ajax_generate' ) );
		add_action( 'wp_ajax_repagio_post_status', array( $this, 'ajax_post_status' ) );
		add_action( 'wp_ajax_repagio_quota', array( $this, 'ajax_quota' ) );
	}

	/**
	 * Adds the top level Repagio menu, whose first page is the dashboard.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->screens['dashboard'] = add_menu_page(
			__( 'Repagio', 'repagio' ),
			__( 'Repagio', 'repagio' ),
			Repagio_Settings::capability(),
			self::DASHBOARD_PAGE,
			array( $this, 'render_dashboard_page' ),
			'dashicons-book-alt',
			58
		);

		// Names the auto-generated first submenu entry, which would otherwise
		// repeat the top level title.
		add_submenu_page(
			self::DASHBOARD_PAGE,
			__( 'Repagio dashboard', 'repagio' ),
			__( 'Dashboard', 'repagio' ),
			Repagio_Settings::capability(),
			self::DASHBOARD_PAGE,
			array( $this, 'render_dashboard_page' )
		);

		$this->screens['settings'] = add_submenu_page(
			self::DASHBOARD_PAGE,
			__( 'Repagio settings', 'repagio' ),
			__( 'Settings', 'repagio' ),
			Repagio_Settings::capability(),
			Repagio_Settings::PAGE,
			array( $this, 'render_settings_page' )
		);

		if ( $this->screens['dashboard'] ) {
			// Runs before any output, so the rescan handler can redirect.
			add_action( 'load-' . $this->screens['dashboard'], array( $this, 'maybe_rescan' ) );
		}
	}

	/**
	 * Adds Settings and Dashboard before Deactivate in the plugins list.
	 *
	 * WordPress convention puts a plugin's own links first and leaves the core
	 * Deactivate and Delete links at the end, so these are prepended.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function add_action_links( $links ) {
		if ( ! current_user_can( Repagio_Settings::capability() ) ) {
			return $links;
		}

		return array_merge( $this->own_action_links(), $links );
	}

	/**
	 * The same links on the network plugins screen.
	 *
	 * Both Repagio screens live on the site admin rather than the network
	 * admin, so the links point there. A network administrator following one
	 * lands on the main site's dashboard, which is where the settings for this
	 * plugin actually are.
	 *
	 * @since 0.6.0
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function add_network_action_links( $links ) {
		if ( ! current_user_can( 'manage_network_plugins' ) ) {
			return $links;
		}

		return array_merge( $this->own_action_links(), $links );
	}

	/**
	 * Builds the plugin's own action links, in the order they are shown.
	 *
	 * @since 0.6.0
	 *
	 * @return string[]
	 */
	protected function own_action_links() {
		return array(
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( Repagio_Settings::settings_url() ),
				esc_html__( 'Settings', 'repagio' )
			),
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::DASHBOARD_PAGE ) ),
				esc_html__( 'Dashboard', 'repagio' )
			),
		);
	}

	/**
	 * Adds documentation and support links under this plugin's row.
	 *
	 * Runs for every plugin on the screen, so the file is checked before
	 * anything is added. Adding rows to somebody else's plugin would be rude
	 * and is a review concern.
	 *
	 * @since 0.6.0
	 *
	 * @param string[] $meta        Existing meta row links.
	 * @param string   $plugin_file Plugin file the row belongs to.
	 * @return string[]
	 */
	public function add_row_meta( $meta, $plugin_file ) {
		if ( plugin_basename( REPAGIO_FILE ) !== $plugin_file ) {
			return $meta;
		}

		$links = array(
			'https://repagio.app/api-access' => __( 'Documentation', 'repagio' ),
			'https://repagio.app/help'       => __( 'Support', 'repagio' ),
			'https://github.com/Gwatso/Repagio-Plugin/issues' => __( 'Report an issue', 'repagio' ),
		);

		foreach ( $links as $url => $label ) {
			$meta[] = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( $url ),
				esc_html( $label )
			);
		}

		return $meta;
	}

	/**
	 * Loads the plugin's stylesheet and script on its own screens only.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, $this->screens, true ) ) {
			return;
		}

		wp_enqueue_style(
			'repagio-admin',
			REPAGIO_URL . 'admin/assets/admin.css',
			array(),
			REPAGIO_VERSION
		);

		wp_enqueue_script(
			'repagio-admin',
			REPAGIO_URL . 'admin/assets/admin.js',
			array(),
			REPAGIO_VERSION,
			true
		);

		wp_localize_script(
			'repagio-admin',
			'repagioAdmin',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( self::NONCE_ACTION ),
				'hasKey'      => Repagio_Settings::has_api_key(),
				'settingsUrl' => Repagio_Settings::settings_url(),
				'quota'       => $this->quota_payload(),
				'defaultTone' => Repagio_Formats::DEFAULT_TONE,
				'keywordFor'  => array_keys(
					array_filter(
						Repagio_Formats::all(),
						static function ( $format ) {
							return ! empty( $format['keyword'] );
						}
					)
				),
				'i18n'        => array(
					'testing'       => __( 'Testing…', 'repagio' ),
					'genericError'  => __( 'Something went wrong. Please try again.', 'repagio' ),
					'planLabel'     => __( 'Plan', 'repagio' ),
					'remaining'     => __( 'Conversions remaining', 'repagio' ),
					'used'          => __( 'Conversions used', 'repagio' ),
					/* translators: 1: posts scanned, 2: posts in total. */
					'scanProgress'  => __( 'Scanned %1$s of %2$s posts.', 'repagio' ),
					'scanDone'      => __( 'Scan complete. Loading your opportunities…', 'repagio' ),
					'scanError'     => __( 'The scan could not finish. Reload this page to try again.', 'repagio' ),
					'generating'    => __( 'Generating…', 'repagio' ),
					'generateWait'  => __( 'This usually takes 20 to 45 seconds. Leave this window open.', 'repagio' ),
					'preparing'     => __( 'Reading the post…', 'repagio' ),
					/* translators: %s: formatted word count. */
					'wordsToSend'   => __( '%s words will be sent to Repagio.', 'repagio' ),
					/* translators: 1: characters being sent, 2: characters in the post. */
					'truncated'     => __( 'This post is longer than Repagio can convert at once, so only the first %1$s characters of %2$s will be used. You may want to split it into parts and repurpose each one.', 'repagio' ),
					/* translators: %s: formatted word count. */
					'resultWords'   => __( '%s words generated.', 'repagio' ),
					'copied'        => __( 'Copied', 'repagio' ),
					'copyFailed'    => __( 'Could not copy automatically. Select the text and copy it.', 'repagio' ),
					'copy'          => __( 'Copy to clipboard', 'repagio' ),
					'closeLabel'    => __( 'Close', 'repagio' ),
					'noKey'         => __( 'Connect a Repagio account on the settings screen to generate content.', 'repagio' ),
				),
			)
		);
	}

	/**
	 * Clears the scan cache when the Rescan content form is submitted.
	 *
	 * Hooked to the dashboard's load action so the redirect happens before any
	 * output, which also stops a reload from re-running the scan.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function maybe_rescan() {
		if ( ! isset( $_POST['repagio_action'] ) ) {
			return;
		}

		if ( 'rescan' !== sanitize_key( wp_unslash( $_POST['repagio_action'] ) ) ) {
			return;
		}

		check_admin_referer( self::RESCAN_ACTION );

		if ( ! current_user_can( Repagio_Settings::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to rescan this site.', 'repagio' ) );
		}

		Repagio_Scanner::clear_cache();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'               => self::DASHBOARD_PAGE,
					'repagio-rescanned' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Renders the opportunity dashboard.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function render_dashboard_page() {
		if ( ! current_user_can( Repagio_Settings::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'repagio' ) );
		}

		require REPAGIO_PATH . 'admin/views/dashboard.php';
	}

	/**
	 * Renders the settings page.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( Repagio_Settings::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'repagio' ) );
		}

		require REPAGIO_PATH . 'admin/views/settings.php';
	}

	/**
	 * Reads the opportunity table's filter, sort and page state from the URL.
	 *
	 * Nonce verification does not apply: these are read-only display arguments
	 * on a GET request that changes nothing, exactly as core's own list tables
	 * treat them. Every value is validated against a known set before use.
	 *
	 * @since 0.2.0
	 *
	 * @return array Sanitized arguments.
	 */
	public static function read_table_args() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'score';

		if ( ! in_array( $orderby, self::SORTABLE, true ) ) {
			$orderby = 'score';
		}

		$order = isset( $_GET['order'] ) ? strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) : '';

		if ( 'asc' !== $order && 'desc' !== $order ) {
			$order = self::default_order( $orderby );
		}

		$post_type = isset( $_GET['repagio_post_type'] )
			? sanitize_key( wp_unslash( $_GET['repagio_post_type'] ) )
			: '';

		if ( '' !== $post_type && ! in_array( $post_type, Repagio_Scanner::scannable_post_types(), true ) ) {
			$post_type = '';
		}

		$args = array(
			'orderby'      => $orderby,
			'order'        => $order,
			'paged'        => isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1,
			'post_type'    => $post_type,
			'category'     => isset( $_GET['repagio_cat'] ) ? absint( wp_unslash( $_GET['repagio_cat'] ) ) : 0,
			'date_from'    => isset( $_GET['repagio_from'] ) ? self::sanitize_date( sanitize_text_field( wp_unslash( $_GET['repagio_from'] ) ) ) : '',
			'date_to'      => isset( $_GET['repagio_to'] ) ? self::sanitize_date( sanitize_text_field( wp_unslash( $_GET['repagio_to'] ) ) ) : '',
			'unrepurposed' => ! empty( $_GET['repagio_unrepurposed'] ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// A backwards range would silently return nothing, so swap it.
		if ( '' !== $args['date_from'] && '' !== $args['date_to'] && $args['date_from'] > $args['date_to'] ) {
			$swap              = $args['date_from'];
			$args['date_from'] = $args['date_to'];
			$args['date_to']   = $swap;
		}

		return $args;
	}

	/**
	 * Whether any filter is narrowing the table.
	 *
	 * @since 0.2.0
	 *
	 * @param array $args Table arguments.
	 * @return bool
	 */
	public static function has_active_filters( $args ) {
		return '' !== $args['post_type']
			|| $args['category'] > 0
			|| '' !== $args['date_from']
			|| '' !== $args['date_to']
			|| ! empty( $args['unrepurposed'] );
	}

	/**
	 * Builds the URL that sorts the table by a given column.
	 *
	 * Clicking the column already sorted flips the direction; any other column
	 * starts at its own natural direction and returns to page one.
	 *
	 * @since 0.2.0
	 *
	 * @param array  $args   Current table arguments.
	 * @param string $column Column to sort by.
	 * @return string
	 */
	public static function sort_url( $args, $column ) {
		if ( ! in_array( $column, self::SORTABLE, true ) ) {
			$column = 'score';
		}

		if ( $column === $args['orderby'] ) {
			$order = ( 'asc' === $args['order'] ) ? 'desc' : 'asc';
		} else {
			$order = self::default_order( $column );
		}

		$query            = self::filter_query_args( $args );
		$query['orderby'] = $column;
		$query['order']   = $order;

		return add_query_arg( $query, admin_url( 'admin.php' ) );
	}

	/**
	 * Renders the pagination links for the current selection.
	 *
	 * @since 0.2.0
	 *
	 * @param array $args  Current table arguments.
	 * @param int   $paged Current page number.
	 * @param int   $pages Total pages.
	 * @return string Markup, or an empty string when there is only one page.
	 */
	public static function pagination_links( $args, $paged, $pages ) {
		if ( (int) $pages < 2 ) {
			return '';
		}

		$query            = self::filter_query_args( $args );
		$query['orderby'] = $args['orderby'];
		$query['order']   = $args['order'];
		$query['paged']   = '%#%';

		$links = paginate_links(
			array(
				'base'      => add_query_arg( $query, admin_url( 'admin.php' ) ),
				'format'    => '',
				'prev_text' => '&lsaquo;',
				'next_text' => '&rsaquo;',
				'total'     => (int) $pages,
				'current'   => (int) $paged,
				'type'      => 'plain',
				'end_size'  => 1,
				'mid_size'  => 2,
			)
		);

		if ( ! $links ) {
			return '';
		}

		return '<span class="pagination-links">' . $links . '</span>';
	}

	/**
	 * Bucket a score falls into, used to colour its badge.
	 *
	 * @since 0.2.0
	 *
	 * @param int $score Score from 0 to 100.
	 * @return string 'high', 'medium' or 'low'.
	 */
	public static function score_band( $score ) {
		$score = (int) $score;

		if ( $score >= 70 ) {
			return 'high';
		}

		if ( $score >= 40 ) {
			return 'medium';
		}

		return 'low';
	}

	/**
	 * A post title safe to show in a table row.
	 *
	 * @since 0.2.0
	 *
	 * @param string $title Raw post title.
	 * @return string
	 */
	public static function row_title( $title ) {
		$title = trim( (string) $title );

		if ( '' === $title ) {
			return __( '(no title)', 'repagio' );
		}

		return $title;
	}

	/**
	 * The query arguments that carry the current filters, for link building.
	 *
	 * Empty filters are left out so URLs stay short and readable.
	 *
	 * @since 0.2.0
	 *
	 * @param array $args Table arguments.
	 * @return array
	 */
	protected static function filter_query_args( $args ) {
		$query = array( 'page' => self::DASHBOARD_PAGE );

		if ( '' !== $args['post_type'] ) {
			$query['repagio_post_type'] = $args['post_type'];
		}

		if ( $args['category'] > 0 ) {
			$query['repagio_cat'] = $args['category'];
		}

		if ( '' !== $args['date_from'] ) {
			$query['repagio_from'] = $args['date_from'];
		}

		if ( '' !== $args['date_to'] ) {
			$query['repagio_to'] = $args['date_to'];
		}

		if ( ! empty( $args['unrepurposed'] ) ) {
			$query['repagio_unrepurposed'] = 1;
		}

		return $query;
	}

	/**
	 * The direction a column sorts in when it is first clicked.
	 *
	 * @since 0.2.0
	 *
	 * @param string $column Column name.
	 * @return string 'asc' or 'desc'.
	 */
	protected static function default_order( $column ) {
		// Titles read best A to Z; everything else is most interesting highest
		// or newest first.
		return ( 'title' === $column ) ? 'asc' : 'desc';
	}

	/**
	 * Validates a Y-m-d date from the filter form.
	 *
	 * @since 0.2.0
	 *
	 * @param string $value Raw value.
	 * @return string A real Y-m-d date, or an empty string.
	 */
	protected static function sanitize_date( $value ) {
		$value = sanitize_text_field( (string) $value );

		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches ) ) {
			return '';
		}

		if ( ! wp_checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1], $value ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Scans one batch of posts and reports progress.
	 *
	 * Only used on archives too large to scan inside a single page load.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function ajax_scan_batch() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( Repagio_Settings::capability() ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to do that.', 'repagio' ) ),
				403
			);
		}

		$offset   = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		$progress = Repagio_Scanner::scan_step( $offset );

		wp_send_json_success( $progress );
	}

	/**
	 * Reports what would be sent for a post, before anything is generated.
	 *
	 * Lets the modal show a real word count and a truncation warning, and lets
	 * a post that is too short fail immediately instead of after a round trip.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function ajax_prepare() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$post = $this->request_post();

		if ( is_wp_error( $post ) ) {
			wp_send_json_error( $this->error_payload( $post ) );
		}

		$prepared = Repagio_Content::prepare( $post );

		if ( is_wp_error( $prepared ) ) {
			wp_send_json_error( $this->error_payload( $prepared ) );
		}

		wp_send_json_success(
			array(
				'wordCount'      => (int) $prepared['word_count'],
				'length'         => (int) $prepared['length'],
				'originalLength' => (int) $prepared['original_length'],
				'truncated'      => (bool) $prepared['truncated'],
			)
		);
	}

	/**
	 * Describes one post for the editor sidebar.
	 *
	 * The post's score and its explanation, what it has already been
	 * repurposed into, and what would be sent if it were generated now. All of
	 * it is computed here on the server, from the saved post, so the sidebar
	 * and the dashboard can never disagree.
	 *
	 * This never contacts the Repagio service. The sidebar can be pinned open,
	 * which makes this request part of every editor load for whoever pinned
	 * it, so the account's quota is left to ajax_quota(), which the sidebar
	 * calls only when someone opens its Repurpose panel.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function ajax_post_status() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$post = $this->request_post();

		if ( is_wp_error( $post ) ) {
			wp_send_json_error( $this->error_payload( $post ) );
		}

		$item      = Repagio_Scanner::build_item( $post );
		$converted = array();

		// Most recent first, which is the order anyone reading the list wants.
		arsort( $item['converted'] );

		foreach ( $item['converted'] as $format => $timestamp ) {
			$converted[] = array(
				'format' => $format,
				'label'  => Repagio_Formats::label( $format ),
				'date'   => $timestamp > 0 ? wp_date( get_option( 'date_format' ), $timestamp ) : '',
			);
		}

		$prepared = Repagio_Content::prepare( $post );

		if ( is_wp_error( $prepared ) ) {
			$source = array( 'error' => $prepared->get_error_message() );
		} else {
			$source = array(
				'wordCount'      => (int) $prepared['word_count'],
				'length'         => (int) $prepared['length'],
				'originalLength' => (int) $prepared['original_length'],
				'truncated'      => (bool) $prepared['truncated'],
			);
		}

		wp_send_json_success(
			array(
				'score'     => (int) $item['score'],
				'band'      => self::score_band( $item['score'] ),
				'reason'    => $item['reason'],
				'converted' => $converted,
				'source'    => $source,
			)
		);
	}

	/**
	 * Reports the account's quota for the editor sidebar.
	 *
	 * Called only when someone opens the sidebar's Repurpose panel, never as
	 * part of drawing the sidebar, so loading the editor does not contact the
	 * service even when the sidebar is pinned. Reads through the same five
	 * minute cache the dashboard uses.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function ajax_quota() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! Repagio_Settings::can_repurpose() ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to do that.', 'repagio' ) ),
				403
			);
		}

		wp_send_json_success( array( 'quota' => $this->quota_payload() ) );
	}

	/**
	 * Generates one output format for one post.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function ajax_generate() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$post = $this->request_post();

		if ( is_wp_error( $post ) ) {
			wp_send_json_error( $this->error_payload( $post ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
		$format = isset( $_POST['format'] ) ? sanitize_key( wp_unslash( $_POST['format'] ) ) : '';

		if ( ! Repagio_Formats::exists( $format ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Choose an output format.', 'repagio' ) )
			);
		}

		$tone = isset( $_POST['tone'] ) ? sanitize_key( wp_unslash( $_POST['tone'] ) ) : '';

		if ( ! Repagio_Formats::tone_exists( $tone ) ) {
			$tone = Repagio_Formats::DEFAULT_TONE;
		}

		$keyword = isset( $_POST['keyword'] )
			? sanitize_text_field( wp_unslash( $_POST['keyword'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// A keyword only means anything for the formats that accept one.
		if ( ! Repagio_Formats::takes_keyword( $format ) ) {
			$keyword = '';
		}

		$prepared = Repagio_Content::prepare( $post );

		if ( is_wp_error( $prepared ) ) {
			wp_send_json_error( $this->error_payload( $prepared ) );
		}

		$this->allow_slow_request();

		$api    = new Repagio_API();
		$result = $api->generate(
			$prepared['text'],
			Repagio_Formats::api_type( $format ),
			$tone,
			array( 'keyword' => $keyword )
		);

		if ( is_wp_error( $result ) ) {
			$payload = $this->error_payload( $result );

			// A spent allowance is the one failure with a useful next step, and
			// which step depends on the tier. The cached account is stale by
			// definition here, so it is re-read before the offer is built.
			if ( 'repagio_limit_reached' === $result->get_error_code() ) {
				$account            = Repagio_Quota::refresh();
				$payload['upgrade'] = Repagio_Quota::upgrade_offer( $account, $result->get_error_message() );
				$payload['quota']   = $this->quota_payload( $account );
			}

			wp_send_json_error( $payload );
		}

		$content = isset( $result['content'] ) && is_string( $result['content'] )
			? trim( $result['content'] )
			: '';

		if ( '' === $content ) {
			wp_send_json_error(
				array(
					'message' => __( 'Repagio replied without any content. Nothing was saved — try again.', 'repagio' ),
				)
			);
		}

		$this->record_conversion( $post->ID, $format );

		// Ask the service what is actually left rather than assuming. If that
		// call fails the generation still happened, so the cached figure is
		// stepped down locally instead of being left overstating the balance.
		$account = Repagio_Quota::refresh();

		if ( null === $account ) {
			$account = Repagio_Quota::decrement();
		}

		$words = ( isset( $result['word_count'] ) && is_numeric( $result['word_count'] ) )
			? (int) $result['word_count']
			: Repagio_Content::count_words( $content );

		wp_send_json_success(
			array(
				'quota'        => $this->quota_payload( $account ),
				'content'      => $content,
				'wordCount'    => $words,
				'format'       => $format,
				'formatLabel'  => Repagio_Formats::label( $format ),
				'tone'         => $tone,
				'truncated'    => (bool) $prepared['truncated'],
				'conversionId' => isset( $result['conversion_id'] ) && is_scalar( $result['conversion_id'] )
					? sanitize_text_field( (string) $result['conversion_id'] )
					: '',
			)
		);
	}

	/**
	 * The quota state the browser needs, for the pre-flight check and the strip.
	 *
	 * Sent with the page rather than fetched, so clicking Repurpose with an
	 * empty allowance costs nothing and answers instantly.
	 *
	 * @since 0.4.0
	 *
	 * @param array|null $account Account to describe. Read from cache when omitted.
	 * @return array
	 */
	protected function quota_payload( $account = null ) {
		if ( null === $account ) {
			$account = Repagio_Settings::has_api_key() ? Repagio_Quota::get() : null;
		}

		$known = is_array( $account );

		return array(
			'known'     => $known,
			'tier'      => $known ? $account['tier'] : '',
			'tierLabel' => $known ? $account['tier_label'] : '',
			'unlimited' => Repagio_Quota::is_unlimited( $account ),
			'remaining' => ( $known && null !== $account['remaining'] ) ? (int) $account['remaining'] : null,
			'limit'     => ( $known && null !== $account['limit'] ) ? (int) $account['limit'] : null,
			'hasQuota'  => Repagio_Quota::has_quota( $account ),
			'band'      => Repagio_Quota::band( $account ),
			'phrase'    => $known ? Repagio_Quota::quota_phrase( $account ) : '',
			'upgrade'   => Repagio_Quota::upgrade_offer( $account ),
		);
	}

	/**
	 * Gives this request long enough to wait for a generation.
	 *
	 * A generation measured at 23 to 41 seconds against the live service, and
	 * the client allows 60. Plenty of hosts cap max_execution_time at 30, which
	 * would kill PHP while the request was still in flight and surface as an
	 * empty reply rather than an error. Raising the limit for this one request
	 * avoids that. Hosts that disable the function keep their own limit, which
	 * is why the result is not relied upon.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	protected function allow_slow_request() {
		if ( ! function_exists( 'set_time_limit' ) ) {
			return;
		}

		$disabled = explode( ',', (string) ini_get( 'disable_functions' ) );

		if ( in_array( 'set_time_limit', array_map( 'trim', $disabled ), true ) ) {
			return;
		}

		$current = (int) ini_get( 'max_execution_time' );
		$needed  = Repagio_API::GENERATE_TIMEOUT + 30;

		// 0 means no limit, which is already enough.
		if ( 0 === $current || $current >= $needed ) {
			return;
		}

		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors -- Deliberate and guarded: a generation blocks for 20-45s and hosts capping max_execution_time at 30 would kill PHP mid-request. Availability is checked above, the limit is only ever raised, and this runs on one AJAX request rather than globally.
		set_time_limit( $needed );
	}

	/**
	 * Validates the post named by an AJAX request.
	 *
	 * @since 0.3.0
	 *
	 * @return WP_Post|WP_Error
	 */
	protected function request_post() {
		// Administrators from the dashboard, and whoever the editor sidebar
		// is open to. The post itself is checked against edit_post below.
		if ( ! Repagio_Settings::can_repurpose() ) {
			return new WP_Error(
				'repagio_forbidden',
				__( 'You do not have permission to do that.', 'repagio' )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by the caller.
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'repagio_no_post',
				__( 'That post could not be found.', 'repagio' )
			);
		}

		// Repurposing reads the whole post body, so require the right to read it.
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error(
				'repagio_forbidden',
				__( 'You do not have permission to repurpose that post.', 'repagio' )
			);
		}

		if ( 'publish' !== $post->post_status ) {
			return new WP_Error(
				'repagio_not_published',
				__( 'Only published posts can be repurposed.', 'repagio' )
			);
		}

		return $post;
	}

	/**
	 * Records that a post has been repurposed into a format.
	 *
	 * Writes the array shape the scanner reads — format slug => timestamp — and
	 * drops the scan cache so the dashboard shows the new status next time it
	 * is opened.
	 *
	 * @since 0.3.0
	 *
	 * @param int    $post_id Post that was repurposed.
	 * @param string $format  Format slug.
	 * @return void
	 */
	protected function record_conversion( $post_id, $format ) {
		$converted = get_post_meta( (int) $post_id, Repagio_Scanner::CONVERTED_META, true );

		if ( ! is_array( $converted ) ) {
			$converted = array();
		}

		$converted[ $format ] = time();

		update_post_meta( (int) $post_id, Repagio_Scanner::CONVERTED_META, $converted );

		Repagio_Scanner::clear_cache();
	}

	/**
	 * Flattens a WP_Error into the shape the admin script renders.
	 *
	 * @since 0.3.0
	 *
	 * @param WP_Error $error Error to describe.
	 * @return array
	 */
	protected function error_payload( $error ) {
		$data = $error->get_error_data();

		if ( ! is_array( $data ) ) {
			$data = array();
		}

		// Errors about the key point at the settings screen. Someone using the
		// editor sidebar without the settings capability cannot open it, so
		// the link would only lead to a permissions error.
		if (
			isset( $data['action_url'] )
			&& 0 === strpos( (string) $data['action_url'], admin_url() )
			&& ! current_user_can( Repagio_Settings::capability() )
		) {
			unset( $data['action_url'], $data['action_label'] );
		}

		return array(
			'message'     => $error->get_error_message(),
			'code'        => $error->get_error_code(),
			'status'      => isset( $data['status'] ) ? (int) $data['status'] : 0,
			'apiCode'     => isset( $data['api_code'] ) ? (string) $data['api_code'] : '',
			'retryAfter'  => isset( $data['retry_after'] ) ? (int) $data['retry_after'] : 0,
			'actionUrl'   => isset( $data['action_url'] ) ? esc_url_raw( $data['action_url'] ) : '',
			'actionLabel' => isset( $data['action_label'] ) ? (string) $data['action_label'] : '',
		);
	}

	/**
	 * Tests that the saved key and URL can reach the service.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function ajax_test_connection() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( Repagio_Settings::capability() ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to do that.', 'repagio' ) ),
				403
			);
		}

		$api      = new Repagio_API();
		$response = $api->get_me();

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $this->error_payload( $response ) );
		}

		// This call is a /me fetch, so the quota cache is filled from it rather
		// than left to provoke an identical request moments later.
		$account = Repagio_Quota::store( $response );

		wp_send_json_success(
			array(
				'message' => __( 'Connected to Repagio.', 'repagio' ),
				'account' => $this->summarise_account( $response ),
				'quota'   => $this->quota_payload( $account ),
			)
		);
	}

	/**
	 * Reduces an account payload to the few fields the page displays.
	 *
	 * The service may nest the account under a wrapper key and may name the
	 * usage fields more than one way, so each value is looked for in a few
	 * likely places and simply left out when it is not there.
	 *
	 * @since 0.1.0
	 *
	 * @param array $account Decoded /me response.
	 * @return array
	 */
	protected function summarise_account( $account ) {
		if ( ! is_array( $account ) ) {
			$account = array();
		}

		// Unwrap { data: {...} } or { account: {...} } if that is the shape.
		foreach ( array( 'data', 'account', 'user' ) as $wrapper ) {
			if ( isset( $account[ $wrapper ] ) && is_array( $account[ $wrapper ] ) ) {
				$account = array_merge( $account, $account[ $wrapper ] );
				break;
			}
		}

		$summary = array(
			'plan'      => $this->first_string( $account, array( 'plan', 'plan_tier', 'tier', 'plan_name' ) ),
			'used'      => $this->first_int( $account, array( 'conversions_used', 'used', 'usage', 'conversions_this_period' ) ),
			'remaining' => $this->first_int( $account, array( 'conversions_remaining', 'remaining', 'credits_remaining' ) ),
			'limit'     => $this->first_int( $account, array( 'conversion_limit', 'conversions_limit', 'limit', 'quota', 'monthly_limit' ) ),
		);

		// Any two of used, remaining and limit imply the third.
		if ( null === $summary['used'] && null !== $summary['limit'] && null !== $summary['remaining'] ) {
			$summary['used'] = max( 0, $summary['limit'] - $summary['remaining'] );
		}

		if ( null === $summary['remaining'] && null !== $summary['limit'] && null !== $summary['used'] ) {
			$summary['remaining'] = max( 0, $summary['limit'] - $summary['used'] );
		}

		// An unlimited plan reports no limit and no remaining count. Saying so
		// is more use than leaving the row out, which reads as missing data.
		$limit_type = $this->first_string( $account, array( 'limit_type' ) );

		if ( 'unlimited' === $limit_type && null === $summary['remaining'] ) {
			$summary['remaining'] = __( 'Unlimited', 'repagio' );
		}

		// Plan tiers come back as bare slugs such as "agency".
		if ( '' !== $summary['plan'] ) {
			$summary['plan'] = ucwords( str_replace( array( '_', '-' ), ' ', $summary['plan'] ) );
		}

		return $summary;
	}

	/**
	 * Returns the first key that holds a non-empty string.
	 *
	 * @since 0.3.0
	 *
	 * @param array    $source Array to read.
	 * @param string[] $keys   Keys to try, in order.
	 * @return string Empty string when none match.
	 */
	protected function first_string( $source, $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $source[ $key ] ) && is_scalar( $source[ $key ] ) && '' !== (string) $source[ $key ] ) {
				return sanitize_text_field( (string) $source[ $key ] );
			}
		}

		return '';
	}

	/**
	 * Returns the first key that holds a number.
	 *
	 * @since 0.3.0
	 *
	 * @param array    $source Array to read.
	 * @param string[] $keys   Keys to try, in order.
	 * @return int|null Null when none match.
	 */
	protected function first_int( $source, $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $source[ $key ] ) && is_numeric( $source[ $key ] ) ) {
				return (int) $source[ $key ];
			}
		}

		return null;
	}
}
