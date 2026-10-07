<?php
/**
 * Opportunity dashboard markup.
 *
 * Rendered by Repagio_Admin::render_dashboard_page(), which has already
 * checked the current user's capability. Everything on this page comes from
 * the local archive scan — no API key is required and no request leaves the
 * site.
 *
 * @package Repagio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$repagio_page       = Repagio_Admin::DASHBOARD_PAGE;
$repagio_base_url   = admin_url( 'admin.php?page=' . $repagio_page );
$repagio_post_types = Repagio_Scanner::scannable_post_types();
$repagio_total      = Repagio_Scanner::count_scannable( $repagio_post_types );
$repagio_cache      = Repagio_Scanner::get_cache();
$repagio_batching   = Repagio_Scanner::needs_batching( $repagio_total );
$repagio_scanning   = false;
$repagio_offset     = 0;

if ( $repagio_total > 0 ) {
	if ( is_array( $repagio_cache ) && $repagio_cache['complete'] ) {
		// Nothing to do: the cache is warm.
		$repagio_offset = (int) $repagio_cache['scanned'];
	} elseif ( $repagio_batching ) {
		// Too large for one request. Resume, or start, a batched scan over AJAX.
		$repagio_scanning = true;
		$repagio_offset   = is_array( $repagio_cache ) ? (int) $repagio_cache['scanned'] : 0;
	} else {
		$repagio_cache = Repagio_Scanner::scan();
	}
}

$repagio_items = ( is_array( $repagio_cache ) && isset( $repagio_cache['items'] ) )
	? $repagio_cache['items']
	: array();

$repagio_stats = Repagio_Scanner::get_stats(
	is_array( $repagio_cache ) ? $repagio_cache : array( 'items' => array() )
);

// Filter, sort and page state, all read from the query string.
$repagio_query = Repagio_Admin::read_table_args();

$repagio_rows  = Repagio_Scanner::filter_items( $repagio_items, $repagio_query );
$repagio_found = count( $repagio_rows );
$repagio_rows  = Repagio_Scanner::sort_items(
	$repagio_rows,
	$repagio_query['orderby'],
	$repagio_query['order']
);

$repagio_per_page = Repagio_Admin::PER_PAGE;
$repagio_pages    = max( 1, (int) ceil( $repagio_found / $repagio_per_page ) );
$repagio_paged    = min( max( 1, (int) $repagio_query['paged'] ), $repagio_pages );
$repagio_rows     = array_slice(
	$repagio_rows,
	( $repagio_paged - 1 ) * $repagio_per_page,
	$repagio_per_page
);

$repagio_filtered = Repagio_Admin::has_active_filters( $repagio_query );

// Scanning is entirely local; only generation needs a connected account.
$repagio_has_key = Repagio_Settings::has_api_key();

// Cached for five minutes, and never fetched at all without a key, so opening
// this page does not mean an HTTP request.
$repagio_account = $repagio_has_key ? Repagio_Quota::get() : null;
$repagio_band    = Repagio_Quota::band( $repagio_account );
$repagio_columns  = array(
	'title'      => __( 'Title', 'repagio' ),
	'word_count' => __( 'Word count', 'repagio' ),
	'date'       => __( 'Published', 'repagio' ),
	'score'      => __( 'Score', 'repagio' ),
	'status'     => __( 'Status', 'repagio' ),
	'action'     => __( 'Action', 'repagio' ),
);
$repagio_sortable = array( 'word_count', 'date', 'score' );
?>
<div class="wrap repagio-wrap repagio-dashboard">
	<h1 class="wp-heading-inline"><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php if ( $repagio_total > 0 && ! $repagio_scanning ) : ?>
		<form
			method="post"
			action="<?php echo esc_url( $repagio_base_url ); ?>"
			class="repagio-rescan-form"
		>
			<?php wp_nonce_field( Repagio_Admin::RESCAN_ACTION ); ?>
			<input type="hidden" name="repagio_action" value="rescan" />
			<button type="submit" class="page-title-action">
				<?php esc_html_e( 'Rescan content', 'repagio' ); ?>
			</button>
		</form>
	<?php endif; ?>

	<hr class="wp-header-end" />

	<?php if ( isset( $_GET['repagio-rescanned'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag, no action taken. ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Your archive has been rescanned.', 'repagio' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( ! $repagio_has_key && $repagio_total > 0 ) : ?>
		<div class="notice notice-info is-dismissible repagio-connect-notice">
			<p>
				<strong><?php esc_html_e( 'Everything on this page works without an account. Generation runs on the Repagio service.', 'repagio' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'The scan, the scores and the opportunities all run on your own server and need no connection. Turning a post into a blog post, LinkedIn post, X thread or newsletter is computation done by Repagio, a separate web service, so that step needs an account with them. Connecting one is optional.', 'repagio' ); ?>
			</p>
			<p>
				<a
					href="<?php echo esc_url( Repagio_Settings::signup_url() ); ?>"
					class="button button-primary"
					target="_blank"
					rel="noopener noreferrer"
				>
					<?php esc_html_e( 'Create a free account', 'repagio' ); ?>
				</a>
				<a href="<?php echo esc_url( Repagio_Settings::settings_url() ); ?>" class="button">
					<?php esc_html_e( 'Add your API key', 'repagio' ); ?>
				</a>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( 0 === $repagio_total ) : ?>

		<div class="repagio-empty">
			<span class="dashicons dashicons-book-alt" aria-hidden="true"></span>
			<h2><?php esc_html_e( 'Nothing to scan yet', 'repagio' ); ?></h2>
			<p>
				<?php esc_html_e( 'Repagio finds repurposing opportunities in content you have already published. There are no published posts on this site yet, so there is nothing for the scanner to read.', 'repagio' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'Publish a post — or hit publish on a draft you already have — then come back and rescan.', 'repagio' ); ?>
			</p>
			<p class="repagio-empty-actions">
				<a href="<?php echo esc_url( admin_url( 'post-new.php' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Write a post', 'repagio' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_status=draft&post_type=post' ) ); ?>" class="button">
					<?php esc_html_e( 'View drafts', 'repagio' ); ?>
				</a>
			</p>
		</div>

	<?php elseif ( $repagio_scanning ) : ?>

		<div
			class="repagio-scan-progress"
			id="repagio-scan-progress"
			data-total="<?php echo esc_attr( (string) $repagio_total ); ?>"
			data-offset="<?php echo esc_attr( (string) $repagio_offset ); ?>"
		>
			<h2><?php esc_html_e( 'Scanning your archive', 'repagio' ); ?></h2>
			<p class="repagio-scan-blurb">
				<?php
				printf(
					/* translators: %s: formatted number of published posts. */
					esc_html__( 'This site has %s published posts, so the scan runs in batches to stay well inside your server limits. It only has to run once an hour.', 'repagio' ),
					'<strong>' . esc_html( number_format_i18n( $repagio_total ) ) . '</strong>'
				);
				?>
			</p>

			<div
				class="repagio-progress-bar"
				role="progressbar"
				aria-valuemin="0"
				aria-valuemax="<?php echo esc_attr( (string) $repagio_total ); ?>"
				aria-valuenow="<?php echo esc_attr( (string) $repagio_offset ); ?>"
				aria-describedby="repagio-scan-status"
			>
				<span class="repagio-progress-fill" id="repagio-progress-fill"></span>
			</div>

			<p class="repagio-scan-status" id="repagio-scan-status" role="status" aria-live="polite">
				<?php
				printf(
					/* translators: 1: posts scanned, 2: posts in total. */
					esc_html__( 'Scanned %1$s of %2$s posts.', 'repagio' ),
					esc_html( number_format_i18n( $repagio_offset ) ),
					esc_html( number_format_i18n( $repagio_total ) )
				);
				?>
			</p>

			<noscript>
				<p class="repagio-hint">
					<?php esc_html_e( 'Batched scanning needs JavaScript. Enable it and reload this page.', 'repagio' ); ?>
				</p>
			</noscript>
		</div>

	<?php else : ?>

		<div class="repagio-hook">
			<p class="repagio-hook-lead">
				<?php
				printf(
					/* translators: %s: formatted number of published posts. */
					esc_html( _n( 'You have %s published post.', 'You have %s published posts.', $repagio_stats['total_posts'], 'repagio' ) ),
					'<strong>' . esc_html( number_format_i18n( $repagio_stats['total_posts'] ) ) . '</strong>'
				);
				?>
				<br />
				<?php
				printf(
					/* translators: %s: formatted number of never-repurposed posts. */
					esc_html( _n( '%s has never been repurposed.', '%s have never been repurposed.', $repagio_stats['never_repurposed'], 'repagio' ) ),
					'<strong>' . esc_html( number_format_i18n( $repagio_stats['never_repurposed'] ) ) . '</strong>'
				);
				?>
			</p>
			<p class="repagio-hook-sub">
				<?php
				printf(
					/* translators: %s: formatted word count. */
					esc_html__( 'That’s %s words of dormant content.', 'repagio' ),
					esc_html( number_format_i18n( $repagio_stats['dormant_words'] ) )
				);
				?>
			</p>
		</div>

		<div class="repagio-status repagio-status--<?php echo esc_attr( $repagio_has_key ? $repagio_band : 'offline' ); ?>">
			<?php if ( ! $repagio_has_key ) : ?>
				<span class="repagio-status-dot" aria-hidden="true"></span>
				<span class="repagio-status-text">
					<?php esc_html_e( 'Not connected — scanning and scoring work offline; connect a Repagio account to generate', 'repagio' ); ?>
				</span>
				<a href="<?php echo esc_url( Repagio_Settings::settings_url() ); ?>" class="repagio-status-link">
					<?php esc_html_e( 'Settings', 'repagio' ); ?>
				</a>
			<?php elseif ( null === $repagio_account ) : ?>
				<span class="repagio-status-dot" aria-hidden="true"></span>
				<span class="repagio-status-text">
					<?php esc_html_e( 'Connected · plan details could not be read just now', 'repagio' ); ?>
				</span>
			<?php else : ?>
				<span class="repagio-status-dot" aria-hidden="true"></span>
				<span class="repagio-status-text">
					<?php esc_html_e( 'Connected', 'repagio' ); ?>
					<span class="repagio-status-sep" aria-hidden="true">·</span>
					<?php
					printf(
						/* translators: %s: plan name, such as Creator. */
						esc_html__( '%s plan', 'repagio' ),
						esc_html( $repagio_account['tier_label'] )
					);
					?>
					<span class="repagio-status-sep" aria-hidden="true">·</span>
					<span class="repagio-status-quota">
						<?php echo esc_html( Repagio_Quota::quota_phrase( $repagio_account ) ); ?>
					</span>
				</span>
			<?php endif; ?>
		</div>

		<div class="repagio-stats">
			<?php
			$repagio_cards = array(
				array(
					'label' => __( 'Total posts', 'repagio' ),
					'value' => $repagio_stats['total_posts'],
					'note'  => sprintf(
						/* translators: %s: formatted average word count. */
						__( '%s words on average', 'repagio' ),
						number_format_i18n( $repagio_stats['average_words'] )
					),
					'tone'  => 'neutral',
				),
				array(
					'label' => __( 'Never repurposed', 'repagio' ),
					'value' => $repagio_stats['never_repurposed'],
					'note'  => __( 'Ready to work again', 'repagio' ),
					'tone'  => 'opportunity',
				),
				array(
					'label' => __( 'Total words', 'repagio' ),
					'value' => $repagio_stats['total_words'],
					'note'  => __( 'Across your whole archive', 'repagio' ),
					'tone'  => 'neutral',
				),
				array(
					'label' => __( 'Already repurposed', 'repagio' ),
					'value' => $repagio_stats['repurposed'],
					'note'  => __( 'At least one format saved', 'repagio' ),
					'tone'  => 'done',
				),
			);

			foreach ( $repagio_cards as $repagio_card ) :
				?>
				<div class="repagio-card repagio-card--<?php echo esc_attr( $repagio_card['tone'] ); ?>">
					<span class="repagio-card-label"><?php echo esc_html( $repagio_card['label'] ); ?></span>
					<span class="repagio-card-value"><?php echo esc_html( number_format_i18n( $repagio_card['value'] ) ); ?></span>
					<span class="repagio-card-note"><?php echo esc_html( $repagio_card['note'] ); ?></span>
				</div>
				<?php
			endforeach;
			?>
		</div>

		<h2 class="repagio-section-heading"><?php esc_html_e( 'Repurposing opportunities', 'repagio' ); ?></h2>

		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="repagio-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( $repagio_page ); ?>" />
			<input type="hidden" name="orderby" value="<?php echo esc_attr( $repagio_query['orderby'] ); ?>" />
			<input type="hidden" name="order" value="<?php echo esc_attr( $repagio_query['order'] ); ?>" />

			<?php if ( count( $repagio_post_types ) > 1 ) : ?>
				<span class="repagio-filter">
					<label class="screen-reader-text" for="repagio-filter-post-type">
						<?php esc_html_e( 'Filter by post type', 'repagio' ); ?>
					</label>
					<select name="repagio_post_type" id="repagio-filter-post-type">
						<option value=""><?php esc_html_e( 'All post types', 'repagio' ); ?></option>
						<?php
						foreach ( $repagio_post_types as $repagio_type ) :
							$repagio_type_object = get_post_type_object( $repagio_type );
							$repagio_type_label  = ( $repagio_type_object && isset( $repagio_type_object->labels->name ) )
								? $repagio_type_object->labels->name
								: $repagio_type;
							?>
							<option
								value="<?php echo esc_attr( $repagio_type ); ?>"
								<?php selected( $repagio_query['post_type'], $repagio_type ); ?>
							>
								<?php echo esc_html( $repagio_type_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</span>
			<?php else : ?>
				<input type="hidden" name="repagio_post_type" value="<?php echo esc_attr( $repagio_query['post_type'] ); ?>" />
			<?php endif; ?>

			<span class="repagio-filter">
				<label class="screen-reader-text" for="repagio-filter-category">
					<?php esc_html_e( 'Filter by category', 'repagio' ); ?>
				</label>
				<?php
				wp_dropdown_categories(
					array(
						'show_option_all' => __( 'All categories', 'repagio' ),
						'taxonomy'        => 'category',
						'name'            => 'repagio_cat',
						'id'              => 'repagio-filter-category',
						'selected'        => (int) $repagio_query['category'],
						'orderby'         => 'name',
						'hierarchical'    => true,
						'hide_empty'      => true,
						'value_field'     => 'term_id',
					)
				);
				?>
			</span>

			<span class="repagio-filter">
				<label for="repagio-filter-from"><?php esc_html_e( 'From', 'repagio' ); ?></label>
				<input
					type="date"
					name="repagio_from"
					id="repagio-filter-from"
					value="<?php echo esc_attr( $repagio_query['date_from'] ); ?>"
				/>
			</span>

			<span class="repagio-filter">
				<label for="repagio-filter-to"><?php esc_html_e( 'To', 'repagio' ); ?></label>
				<input
					type="date"
					name="repagio_to"
					id="repagio-filter-to"
					value="<?php echo esc_attr( $repagio_query['date_to'] ); ?>"
				/>
			</span>

			<span class="repagio-filter">
				<label for="repagio-filter-unrepurposed">
					<input
						type="checkbox"
						name="repagio_unrepurposed"
						id="repagio-filter-unrepurposed"
						value="1"
						<?php checked( $repagio_query['unrepurposed'] ); ?>
					/>
					<?php esc_html_e( 'Never repurposed only', 'repagio' ); ?>
				</label>
			</span>

			<?php submit_button( __( 'Filter', 'repagio' ), 'secondary', '', false ); ?>

			<?php if ( $repagio_filtered ) : ?>
				<a href="<?php echo esc_url( $repagio_base_url ); ?>" class="repagio-reset-filters">
					<?php esc_html_e( 'Reset', 'repagio' ); ?>
				</a>
			<?php endif; ?>
		</form>

		<div class="tablenav top">
			<div class="tablenav-pages<?php echo esc_attr( ( $repagio_pages < 2 ) ? ' one-page' : '' ); ?>">
				<span class="displaying-num">
					<?php
					printf(
						/* translators: %s: formatted number of matching posts. */
						esc_html( _n( '%s item', '%s items', $repagio_found, 'repagio' ) ),
						esc_html( number_format_i18n( $repagio_found ) )
					);
					?>
				</span>
				<?php echo wp_kses_post( Repagio_Admin::pagination_links( $repagio_query, $repagio_paged, $repagio_pages ) ); ?>
			</div>
			<br class="clear" />
		</div>

		<table class="wp-list-table widefat fixed striped table-view-list repagio-table">
			<caption class="screen-reader-text">
				<?php esc_html_e( 'Published posts ranked by repurposing potential.', 'repagio' ); ?>
			</caption>
			<thead>
				<tr>
					<?php
					foreach ( $repagio_columns as $repagio_key => $repagio_label ) :
						$repagio_classes = array( 'manage-column', 'column-' . $repagio_key );
						$repagio_is_sort = in_array( $repagio_key, $repagio_sortable, true );
						$repagio_current = ( $repagio_key === $repagio_query['orderby'] );

						if ( $repagio_is_sort ) {
							$repagio_classes[] = 'sortable';

							if ( $repagio_current ) {
								$repagio_classes[] = 'sorted';
								$repagio_classes[] = $repagio_query['order'];
							} else {
								// Which way a click will sort this column first.
								$repagio_classes[] = ( 'title' === $repagio_key ) ? 'asc' : 'desc';
							}
						}

						if ( 'title' === $repagio_key ) {
							$repagio_classes[] = 'column-primary';
						}
						?>
						<th
							scope="col"
							class="<?php echo esc_attr( implode( ' ', $repagio_classes ) ); ?>"
							<?php
							if ( $repagio_is_sort ) {
								printf(
									' aria-sort="%s"',
									$repagio_current
										? esc_attr( 'asc' === $repagio_query['order'] ? 'ascending' : 'descending' )
										: 'none'
								);
							}
							?>
						>
							<?php if ( $repagio_is_sort ) : ?>
								<a href="<?php echo esc_url( Repagio_Admin::sort_url( $repagio_query, $repagio_key ) ); ?>">
									<span><?php echo esc_html( $repagio_label ); ?></span>
									<span class="sorting-indicator" aria-hidden="true"></span>
								</a>
							<?php else : ?>
								<?php echo esc_html( $repagio_label ); ?>
							<?php endif; ?>
						</th>
					<?php endforeach; ?>
				</tr>
			</thead>

			<tbody>
				<?php if ( empty( $repagio_rows ) ) : ?>
					<tr class="no-items">
						<td class="colspanchange" colspan="<?php echo esc_attr( (string) count( $repagio_columns ) ); ?>">
							<?php if ( $repagio_filtered ) : ?>
								<?php esc_html_e( 'No posts match these filters. Try widening the date range or clearing the category.', 'repagio' ); ?>
								<a href="<?php echo esc_url( $repagio_base_url ); ?>"><?php esc_html_e( 'Reset filters', 'repagio' ); ?></a>
							<?php else : ?>
								<?php esc_html_e( 'No opportunities found.', 'repagio' ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php else : ?>
					<?php
					foreach ( $repagio_rows as $repagio_row ) :
						$repagio_labels = Repagio_Scanner::format_labels_for( $repagio_row['converted'] );
						$repagio_band   = Repagio_Admin::score_band( (int) $repagio_row['score'] );
						?>
						<tr>
							<td class="title column-title has-row-actions column-primary" data-colname="<?php echo esc_attr( $repagio_columns['title'] ); ?>">
								<strong>
									<?php if ( '' !== $repagio_row['edit_link'] ) : ?>
										<a class="row-title" href="<?php echo esc_url( $repagio_row['edit_link'] ); ?>">
											<?php echo esc_html( Repagio_Admin::row_title( $repagio_row['title'] ) ); ?>
										</a>
									<?php else : ?>
										<?php echo esc_html( Repagio_Admin::row_title( $repagio_row['title'] ) ); ?>
									<?php endif; ?>
								</strong>

								<?php if ( ! empty( $repagio_row['categories'] ) ) : ?>
									<div class="repagio-row-meta">
										<?php echo esc_html( implode( ', ', $repagio_row['categories'] ) ); ?>
									</div>
								<?php endif; ?>

								<div class="row-actions">
									<?php if ( '' !== $repagio_row['edit_link'] ) : ?>
										<span class="edit">
											<a href="<?php echo esc_url( $repagio_row['edit_link'] ); ?>"><?php esc_html_e( 'Edit', 'repagio' ); ?></a>
											<?php if ( '' !== $repagio_row['permalink'] ) : ?>
												 |
											<?php endif; ?>
										</span>
									<?php endif; ?>
									<?php if ( '' !== $repagio_row['permalink'] ) : ?>
										<span class="view">
											<a href="<?php echo esc_url( $repagio_row['permalink'] ); ?>" rel="bookmark"><?php esc_html_e( 'View', 'repagio' ); ?></a>
										</span>
									<?php endif; ?>
								</div>

								<button type="button" class="toggle-row">
									<span class="screen-reader-text"><?php esc_html_e( 'Show more details', 'repagio' ); ?></span>
								</button>
							</td>

							<td class="column-word_count" data-colname="<?php echo esc_attr( $repagio_columns['word_count'] ); ?>">
								<?php echo esc_html( number_format_i18n( $repagio_row['word_count'] ) ); ?>
								<?php if ( (int) $repagio_row['heading_count'] > 0 ) : ?>
									<span class="repagio-row-meta">
										<?php
										printf(
											/* translators: %s: number of h2 and h3 headings. */
											esc_html( _n( '%s heading', '%s headings', (int) $repagio_row['heading_count'], 'repagio' ) ),
											esc_html( number_format_i18n( $repagio_row['heading_count'] ) )
										);
										?>
									</span>
								<?php endif; ?>
							</td>

							<td class="column-date" data-colname="<?php echo esc_attr( $repagio_columns['date'] ); ?>">
								<?php echo esc_html( mysql2date( get_option( 'date_format' ), $repagio_row['date'] ) ); ?>
								<span class="repagio-row-meta">
									<?php
									printf(
										/* translators: %s: human-readable time difference, such as "2 years". */
										esc_html__( '%s ago', 'repagio' ),
										esc_html( human_time_diff( (int) $repagio_row['timestamp'], time() ) )
									);
									?>
								</span>
							</td>

							<td class="column-score" data-colname="<?php echo esc_attr( $repagio_columns['score'] ); ?>">
								<span class="repagio-score repagio-score--<?php echo esc_attr( $repagio_band ); ?>">
									<?php echo esc_html( number_format_i18n( $repagio_row['score'] ) ); ?>
								</span>
								<span class="repagio-score-track" aria-hidden="true">
									<?php // The width is this row's score, so it cannot live in the stylesheet. ?>
									<span
										class="repagio-score-fill"
										style="width:<?php echo esc_attr( (string) max( 0, min( 100, (int) $repagio_row['score'] ) ) ); ?>%"
									></span>
								</span>
								<span class="repagio-row-meta"><?php echo esc_html( $repagio_row['reason'] ); ?></span>
							</td>

							<td class="column-status" data-colname="<?php echo esc_attr( $repagio_columns['status'] ); ?>">
								<?php if ( empty( $repagio_row['repurposed'] ) ) : ?>
									<span class="repagio-badge repagio-badge--opportunity">
										<?php esc_html_e( 'Never repurposed', 'repagio' ); ?>
									</span>
								<?php else : ?>
									<span class="repagio-badge repagio-badge--done">
										<?php esc_html_e( 'Repurposed', 'repagio' ); ?>
									</span>
									<?php if ( ! empty( $repagio_labels ) ) : ?>
										<span class="repagio-row-meta">
											<?php
											printf(
												/* translators: %s: comma-separated list of output format names. */
												esc_html__( 'as %s', 'repagio' ),
												esc_html( implode( ', ', $repagio_labels ) )
											);
											?>
										</span>
									<?php endif; ?>
								<?php endif; ?>
							</td>

							<td class="column-action" data-colname="<?php echo esc_attr( $repagio_columns['action'] ); ?>">
								<?php if ( $repagio_has_key ) : ?>
									<button
										type="button"
										class="button button-small repagio-repurpose"
										data-post-id="<?php echo esc_attr( (string) $repagio_row['id'] ); ?>"
										data-post-title="<?php echo esc_attr( Repagio_Admin::row_title( $repagio_row['title'] ) ); ?>"
									>
										<?php esc_html_e( 'Repurpose', 'repagio' ); ?>
									</button>
								<?php else : ?>
									<span
										class="repagio-soon-wrap"
										title="<?php esc_attr_e( 'Generation is performed by the Repagio web service. Connect an account on the settings screen to use it.', 'repagio' ); ?>"
									>
										<button
											type="button"
											class="button button-small"
											aria-describedby="repagio-soon-note"
											disabled
										>
											<?php esc_html_e( 'Repurpose', 'repagio' ); ?>
										</button>
									</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( ! $repagio_has_key ) : ?>
			<p id="repagio-soon-note" class="repagio-hint">
				<?php esc_html_e( 'Generation is carried out by the Repagio web service, so it needs a connected account. Everything else on this page works without one.', 'repagio' ); ?>
			</p>
		<?php endif; ?>

		<div class="tablenav bottom">
			<div class="tablenav-pages<?php echo esc_attr( ( $repagio_pages < 2 ) ? ' one-page' : '' ); ?>">
				<?php echo wp_kses_post( Repagio_Admin::pagination_links( $repagio_query, $repagio_paged, $repagio_pages ) ); ?>
			</div>
			<br class="clear" />
		</div>

		<?php if ( is_array( $repagio_cache ) && $repagio_cache['generated'] > 0 ) : ?>
			<p class="repagio-hint">
				<?php
				printf(
					/* translators: %s: human-readable time difference, such as "5 mins". */
					esc_html__( 'Scanned %s ago. Results are cached for an hour; use Rescan content to refresh them now.', 'repagio' ),
					esc_html( human_time_diff( (int) $repagio_cache['generated'], time() ) )
				);
				?>
			</p>
		<?php endif; ?>

	<?php endif; ?>

	<?php if ( $repagio_has_key && $repagio_total > 0 ) : ?>
		<div class="repagio-modal" id="repagio-modal" hidden>
			<div class="repagio-modal-backdrop" data-repagio-close="1"></div>

			<div
				class="repagio-modal-panel"
				role="dialog"
				aria-modal="true"
				aria-labelledby="repagio-modal-title"
			>
				<div class="repagio-modal-head">
					<h2 id="repagio-modal-title"><?php esc_html_e( 'Repurpose this post', 'repagio' ); ?></h2>
					<button type="button" class="repagio-modal-close" data-repagio-close="1">
						<span class="screen-reader-text"><?php esc_html_e( 'Close', 'repagio' ); ?></span>
						<span aria-hidden="true">&times;</span>
					</button>
				</div>

				<p class="repagio-modal-post" id="repagio-modal-post"></p>

				<div class="repagio-modal-body">

					<form class="repagio-generate-form" id="repagio-generate-form">
						<input type="hidden" name="post_id" id="repagio-field-post" value="" />

						<p class="repagio-field">
							<label for="repagio-field-format"><?php esc_html_e( 'Output format', 'repagio' ); ?></label>
							<select id="repagio-field-format" name="format">
								<?php foreach ( Repagio_Formats::all() as $repagio_slug => $repagio_format ) : ?>
									<option value="<?php echo esc_attr( $repagio_slug ); ?>">
										<?php echo esc_html( $repagio_format['label'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</p>

						<p class="repagio-field">
							<label for="repagio-field-tone"><?php esc_html_e( 'Tone', 'repagio' ); ?></label>
							<select id="repagio-field-tone" name="tone">
								<?php foreach ( Repagio_Formats::tones() as $repagio_tone => $repagio_tone_label ) : ?>
									<option
										value="<?php echo esc_attr( $repagio_tone ); ?>"
										<?php selected( $repagio_tone, Repagio_Formats::DEFAULT_TONE ); ?>
									>
										<?php echo esc_html( $repagio_tone_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</p>

						<p class="repagio-field" id="repagio-field-keyword-row">
							<label for="repagio-field-keyword">
								<?php esc_html_e( 'Target keyword', 'repagio' ); ?>
								<span class="repagio-optional"><?php esc_html_e( '(optional)', 'repagio' ); ?></span>
							</label>
							<input
								type="text"
								id="repagio-field-keyword"
								name="keyword"
								class="regular-text"
								autocomplete="off"
								placeholder="<?php esc_attr_e( 'e.g. content repurposing', 'repagio' ); ?>"
							/>
						</p>

						<p class="repagio-source-note" id="repagio-source-note" role="status" aria-live="polite"></p>

						<p class="repagio-modal-quota" id="repagio-modal-quota"><?php
							// What this generation will cost, so the choice is informed.
							if ( is_array( $repagio_account ) && ! Repagio_Quota::is_unlimited( $repagio_account ) ) {
								echo esc_html( Repagio_Quota::quota_phrase( $repagio_account ) );
							}
						?></p>

						<div class="repagio-modal-actions">
							<button type="submit" class="button button-primary" id="repagio-generate-button">
								<?php esc_html_e( 'Generate', 'repagio' ); ?>
							</button>
							<span class="spinner" id="repagio-generate-spinner"></span>
							<button type="button" class="button button-link" data-repagio-close="1">
								<?php esc_html_e( 'Cancel', 'repagio' ); ?>
							</button>
						</div>

						<p class="repagio-generate-wait" id="repagio-generate-wait" hidden>
							<?php esc_html_e( 'This usually takes 20 to 45 seconds. Leave this window open.', 'repagio' ); ?>
						</p>
					</form>

					<div class="repagio-result-panel" id="repagio-result-panel" hidden>
						<p class="repagio-result-meta" id="repagio-result-meta"></p>

						<pre class="repagio-result-content" id="repagio-result-content" tabindex="0"></pre>

						<div class="repagio-modal-actions">
							<button type="button" class="button button-primary" id="repagio-copy-button">
								<?php esc_html_e( 'Copy to clipboard', 'repagio' ); ?>
							</button>
							<button type="button" class="button" id="repagio-again-button">
								<?php esc_html_e( 'Generate another format', 'repagio' ); ?>
							</button>
							<button type="button" class="button button-link" data-repagio-close="1">
								<?php esc_html_e( 'Close', 'repagio' ); ?>
							</button>
						</div>
					</div>

					<div class="repagio-upgrade-panel" id="repagio-upgrade-panel" hidden>
						<p class="repagio-upgrade-title" id="repagio-upgrade-title"></p>
						<p class="repagio-upgrade-message" id="repagio-upgrade-message"></p>
						<p class="repagio-upgrade-note" id="repagio-upgrade-note"></p>

						<div class="repagio-modal-actions">
							<a
								href="#"
								class="button button-primary"
								id="repagio-upgrade-button"
								target="_blank"
								rel="noopener noreferrer"
								hidden
							></a>
							<button type="button" class="button button-link" data-repagio-close="1">
								<?php esc_html_e( 'Close', 'repagio' ); ?>
							</button>
						</div>
					</div>

					<div class="repagio-modal-notice" id="repagio-modal-notice" role="alert" aria-live="assertive"></div>

				</div>
			</div>
		</div>
	<?php endif; ?>
</div>
