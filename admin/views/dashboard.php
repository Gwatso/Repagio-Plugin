<?php
/**
 * Opportunity dashboard markup.
 *
 * Rendered by Repagify_Admin::render_dashboard_page(), which has already
 * checked the current user's capability. Everything on this page comes from
 * the local archive scan — no API key is required and no request leaves the
 * site.
 *
 * @package Repagify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$repagify_page       = Repagify_Admin::DASHBOARD_PAGE;
$repagify_base_url   = admin_url( 'admin.php?page=' . $repagify_page );
$repagify_post_types = Repagify_Scanner::scannable_post_types();
$repagify_total      = Repagify_Scanner::count_scannable( $repagify_post_types );
$repagify_cache      = Repagify_Scanner::get_cache();
$repagify_batching   = Repagify_Scanner::needs_batching( $repagify_total );
$repagify_scanning   = false;
$repagify_offset     = 0;

if ( $repagify_total > 0 ) {
	if ( is_array( $repagify_cache ) && $repagify_cache['complete'] ) {
		// Nothing to do: the cache is warm.
		$repagify_offset = (int) $repagify_cache['scanned'];
	} elseif ( $repagify_batching ) {
		// Too large for one request. Resume, or start, a batched scan over AJAX.
		$repagify_scanning = true;
		$repagify_offset   = is_array( $repagify_cache ) ? (int) $repagify_cache['scanned'] : 0;
	} else {
		$repagify_cache = Repagify_Scanner::scan();
	}
}

$repagify_items = ( is_array( $repagify_cache ) && isset( $repagify_cache['items'] ) )
	? $repagify_cache['items']
	: array();

$repagify_stats = Repagify_Scanner::get_stats(
	is_array( $repagify_cache ) ? $repagify_cache : array( 'items' => array() )
);

// Filter, sort and page state, all read from the query string.
$repagify_query = Repagify_Admin::read_table_args();

$repagify_rows  = Repagify_Scanner::filter_items( $repagify_items, $repagify_query );
$repagify_found = count( $repagify_rows );
$repagify_rows  = Repagify_Scanner::sort_items(
	$repagify_rows,
	$repagify_query['orderby'],
	$repagify_query['order']
);

$repagify_per_page = Repagify_Admin::PER_PAGE;
$repagify_pages    = max( 1, (int) ceil( $repagify_found / $repagify_per_page ) );
$repagify_paged    = min( max( 1, (int) $repagify_query['paged'] ), $repagify_pages );
$repagify_rows     = array_slice(
	$repagify_rows,
	( $repagify_paged - 1 ) * $repagify_per_page,
	$repagify_per_page
);

$repagify_filtered = Repagify_Admin::has_active_filters( $repagify_query );

// Scanning is entirely local; only generation needs a connected account.
$repagify_has_key = Repagify_Settings::has_api_key();

// Cached for five minutes, and never fetched at all without a key, so opening
// this page does not mean an HTTP request.
$repagify_account = $repagify_has_key ? Repagify_Quota::get() : null;
$repagify_band    = Repagify_Quota::band( $repagify_account );
$repagify_columns  = array(
	'title'      => __( 'Title', 'repagify' ),
	'word_count' => __( 'Word count', 'repagify' ),
	'date'       => __( 'Published', 'repagify' ),
	'score'      => __( 'Score', 'repagify' ),
	'status'     => __( 'Status', 'repagify' ),
	'action'     => __( 'Action', 'repagify' ),
);
$repagify_sortable = array( 'word_count', 'date', 'score' );
?>
<div class="wrap repagify-wrap repagify-dashboard">
	<h1 class="wp-heading-inline"><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php if ( $repagify_total > 0 && ! $repagify_scanning ) : ?>
		<form
			method="post"
			action="<?php echo esc_url( $repagify_base_url ); ?>"
			class="repagify-rescan-form"
		>
			<?php wp_nonce_field( Repagify_Admin::RESCAN_ACTION ); ?>
			<input type="hidden" name="repagify_action" value="rescan" />
			<button type="submit" class="page-title-action">
				<?php esc_html_e( 'Rescan content', 'repagify' ); ?>
			</button>
		</form>
	<?php endif; ?>

	<hr class="wp-header-end" />

	<?php if ( isset( $_GET['repagify-rescanned'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag, no action taken. ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Your archive has been rescanned.', 'repagify' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( ! $repagify_has_key && $repagify_total > 0 ) : ?>
		<div class="notice notice-info is-dismissible repagify-connect-notice">
			<p>
				<strong><?php esc_html_e( 'Everything on this page works without an account. Generation runs on the Repagify service.', 'repagify' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'The scan, the scores and the opportunities all run on your own server and need no connection. Turning a post into a blog post, LinkedIn post, X thread or newsletter is computation done by Repagify, a separate web service, so that step needs an account with them. Connecting one is optional.', 'repagify' ); ?>
			</p>
			<p>
				<a
					href="<?php echo esc_url( Repagify_Settings::signup_url() ); ?>"
					class="button button-primary"
					target="_blank"
					rel="noopener noreferrer"
				>
					<?php esc_html_e( 'Create a free account', 'repagify' ); ?>
				</a>
				<a href="<?php echo esc_url( Repagify_Settings::settings_url() ); ?>" class="button">
					<?php esc_html_e( 'Add your API key', 'repagify' ); ?>
				</a>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( 0 === $repagify_total ) : ?>

		<div class="repagify-empty">
			<span class="dashicons dashicons-book-alt" aria-hidden="true"></span>
			<h2><?php esc_html_e( 'Nothing to scan yet', 'repagify' ); ?></h2>
			<p>
				<?php esc_html_e( 'Repagify finds repurposing opportunities in content you have already published. There are no published posts on this site yet, so there is nothing for the scanner to read.', 'repagify' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'Publish a post — or hit publish on a draft you already have — then come back and rescan.', 'repagify' ); ?>
			</p>
			<p class="repagify-empty-actions">
				<a href="<?php echo esc_url( admin_url( 'post-new.php' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Write a post', 'repagify' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_status=draft&post_type=post' ) ); ?>" class="button">
					<?php esc_html_e( 'View drafts', 'repagify' ); ?>
				</a>
			</p>
		</div>

	<?php elseif ( $repagify_scanning ) : ?>

		<div
			class="repagify-scan-progress"
			id="repagify-scan-progress"
			data-total="<?php echo esc_attr( (string) $repagify_total ); ?>"
			data-offset="<?php echo esc_attr( (string) $repagify_offset ); ?>"
		>
			<h2><?php esc_html_e( 'Scanning your archive', 'repagify' ); ?></h2>
			<p class="repagify-scan-blurb">
				<?php
				printf(
					/* translators: %s: formatted number of published posts. */
					esc_html__( 'This site has %s published posts, so the scan runs in batches to stay well inside your server limits. It only has to run once an hour.', 'repagify' ),
					'<strong>' . esc_html( number_format_i18n( $repagify_total ) ) . '</strong>'
				);
				?>
			</p>

			<div
				class="repagify-progress-bar"
				role="progressbar"
				aria-valuemin="0"
				aria-valuemax="<?php echo esc_attr( (string) $repagify_total ); ?>"
				aria-valuenow="<?php echo esc_attr( (string) $repagify_offset ); ?>"
				aria-describedby="repagify-scan-status"
			>
				<span class="repagify-progress-fill" id="repagify-progress-fill"></span>
			</div>

			<p class="repagify-scan-status" id="repagify-scan-status" role="status" aria-live="polite">
				<?php
				printf(
					/* translators: 1: posts scanned, 2: posts in total. */
					esc_html__( 'Scanned %1$s of %2$s posts.', 'repagify' ),
					esc_html( number_format_i18n( $repagify_offset ) ),
					esc_html( number_format_i18n( $repagify_total ) )
				);
				?>
			</p>

			<noscript>
				<p class="repagify-hint">
					<?php esc_html_e( 'Batched scanning needs JavaScript. Enable it and reload this page.', 'repagify' ); ?>
				</p>
			</noscript>
		</div>

	<?php else : ?>

		<div class="repagify-hook">
			<p class="repagify-hook-lead">
				<?php
				printf(
					/* translators: %s: formatted number of published posts. */
					esc_html( _n( 'You have %s published post.', 'You have %s published posts.', $repagify_stats['total_posts'], 'repagify' ) ),
					'<strong>' . esc_html( number_format_i18n( $repagify_stats['total_posts'] ) ) . '</strong>'
				);
				?>
				<br />
				<?php
				printf(
					/* translators: %s: formatted number of never-repurposed posts. */
					esc_html( _n( '%s has never been repurposed.', '%s have never been repurposed.', $repagify_stats['never_repurposed'], 'repagify' ) ),
					'<strong>' . esc_html( number_format_i18n( $repagify_stats['never_repurposed'] ) ) . '</strong>'
				);
				?>
			</p>
			<p class="repagify-hook-sub">
				<?php
				printf(
					/* translators: %s: formatted word count. */
					esc_html__( 'That’s %s words of dormant content.', 'repagify' ),
					esc_html( number_format_i18n( $repagify_stats['dormant_words'] ) )
				);
				?>
			</p>
		</div>

		<div class="repagify-status repagify-status--<?php echo esc_attr( $repagify_has_key ? $repagify_band : 'offline' ); ?>">
			<?php if ( ! $repagify_has_key ) : ?>
				<span class="repagify-status-dot" aria-hidden="true"></span>
				<span class="repagify-status-text">
					<?php esc_html_e( 'Not connected — scanning and scoring work offline; connect a Repagify account to generate', 'repagify' ); ?>
				</span>
				<a href="<?php echo esc_url( Repagify_Settings::settings_url() ); ?>" class="repagify-status-link">
					<?php esc_html_e( 'Settings', 'repagify' ); ?>
				</a>
			<?php elseif ( null === $repagify_account ) : ?>
				<span class="repagify-status-dot" aria-hidden="true"></span>
				<span class="repagify-status-text">
					<?php esc_html_e( 'Connected · plan details could not be read just now', 'repagify' ); ?>
				</span>
			<?php else : ?>
				<span class="repagify-status-dot" aria-hidden="true"></span>
				<span class="repagify-status-text">
					<?php esc_html_e( 'Connected', 'repagify' ); ?>
					<span class="repagify-status-sep" aria-hidden="true">·</span>
					<?php
					printf(
						/* translators: %s: plan name, such as Creator. */
						esc_html__( '%s plan', 'repagify' ),
						esc_html( $repagify_account['tier_label'] )
					);
					?>
					<span class="repagify-status-sep" aria-hidden="true">·</span>
					<span class="repagify-status-quota">
						<?php echo esc_html( Repagify_Quota::quota_phrase( $repagify_account ) ); ?>
					</span>
				</span>
			<?php endif; ?>
		</div>

		<div class="repagify-stats">
			<?php
			$repagify_cards = array(
				array(
					'label' => __( 'Total posts', 'repagify' ),
					'value' => $repagify_stats['total_posts'],
					'note'  => sprintf(
						/* translators: %s: formatted average word count. */
						__( '%s words on average', 'repagify' ),
						number_format_i18n( $repagify_stats['average_words'] )
					),
					'tone'  => 'neutral',
				),
				array(
					'label' => __( 'Never repurposed', 'repagify' ),
					'value' => $repagify_stats['never_repurposed'],
					'note'  => __( 'Ready to work again', 'repagify' ),
					'tone'  => 'opportunity',
				),
				array(
					'label' => __( 'Total words', 'repagify' ),
					'value' => $repagify_stats['total_words'],
					'note'  => __( 'Across your whole archive', 'repagify' ),
					'tone'  => 'neutral',
				),
				array(
					'label' => __( 'Already repurposed', 'repagify' ),
					'value' => $repagify_stats['repurposed'],
					'note'  => __( 'At least one format saved', 'repagify' ),
					'tone'  => 'done',
				),
			);

			foreach ( $repagify_cards as $repagify_card ) :
				?>
				<div class="repagify-card repagify-card--<?php echo esc_attr( $repagify_card['tone'] ); ?>">
					<span class="repagify-card-label"><?php echo esc_html( $repagify_card['label'] ); ?></span>
					<span class="repagify-card-value"><?php echo esc_html( number_format_i18n( $repagify_card['value'] ) ); ?></span>
					<span class="repagify-card-note"><?php echo esc_html( $repagify_card['note'] ); ?></span>
				</div>
				<?php
			endforeach;
			?>
		</div>

		<h2 class="repagify-section-heading"><?php esc_html_e( 'Repurposing opportunities', 'repagify' ); ?></h2>

		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="repagify-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( $repagify_page ); ?>" />
			<input type="hidden" name="orderby" value="<?php echo esc_attr( $repagify_query['orderby'] ); ?>" />
			<input type="hidden" name="order" value="<?php echo esc_attr( $repagify_query['order'] ); ?>" />

			<?php if ( count( $repagify_post_types ) > 1 ) : ?>
				<span class="repagify-filter">
					<label class="screen-reader-text" for="repagify-filter-post-type">
						<?php esc_html_e( 'Filter by post type', 'repagify' ); ?>
					</label>
					<select name="repagify_post_type" id="repagify-filter-post-type">
						<option value=""><?php esc_html_e( 'All post types', 'repagify' ); ?></option>
						<?php
						foreach ( $repagify_post_types as $repagify_type ) :
							$repagify_type_object = get_post_type_object( $repagify_type );
							$repagify_type_label  = ( $repagify_type_object && isset( $repagify_type_object->labels->name ) )
								? $repagify_type_object->labels->name
								: $repagify_type;
							?>
							<option
								value="<?php echo esc_attr( $repagify_type ); ?>"
								<?php selected( $repagify_query['post_type'], $repagify_type ); ?>
							>
								<?php echo esc_html( $repagify_type_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</span>
			<?php else : ?>
				<input type="hidden" name="repagify_post_type" value="<?php echo esc_attr( $repagify_query['post_type'] ); ?>" />
			<?php endif; ?>

			<span class="repagify-filter">
				<label class="screen-reader-text" for="repagify-filter-category">
					<?php esc_html_e( 'Filter by category', 'repagify' ); ?>
				</label>
				<?php
				wp_dropdown_categories(
					array(
						'show_option_all' => __( 'All categories', 'repagify' ),
						'taxonomy'        => 'category',
						'name'            => 'repagify_cat',
						'id'              => 'repagify-filter-category',
						'selected'        => (int) $repagify_query['category'],
						'orderby'         => 'name',
						'hierarchical'    => true,
						'hide_empty'      => true,
						'value_field'     => 'term_id',
					)
				);
				?>
			</span>

			<span class="repagify-filter">
				<label for="repagify-filter-from"><?php esc_html_e( 'From', 'repagify' ); ?></label>
				<input
					type="date"
					name="repagify_from"
					id="repagify-filter-from"
					value="<?php echo esc_attr( $repagify_query['date_from'] ); ?>"
				/>
			</span>

			<span class="repagify-filter">
				<label for="repagify-filter-to"><?php esc_html_e( 'To', 'repagify' ); ?></label>
				<input
					type="date"
					name="repagify_to"
					id="repagify-filter-to"
					value="<?php echo esc_attr( $repagify_query['date_to'] ); ?>"
				/>
			</span>

			<span class="repagify-filter">
				<label for="repagify-filter-unrepurposed">
					<input
						type="checkbox"
						name="repagify_unrepurposed"
						id="repagify-filter-unrepurposed"
						value="1"
						<?php checked( $repagify_query['unrepurposed'] ); ?>
					/>
					<?php esc_html_e( 'Never repurposed only', 'repagify' ); ?>
				</label>
			</span>

			<?php submit_button( __( 'Filter', 'repagify' ), 'secondary', '', false ); ?>

			<?php if ( $repagify_filtered ) : ?>
				<a href="<?php echo esc_url( $repagify_base_url ); ?>" class="repagify-reset-filters">
					<?php esc_html_e( 'Reset', 'repagify' ); ?>
				</a>
			<?php endif; ?>
		</form>

		<div class="tablenav top">
			<div class="tablenav-pages<?php echo esc_attr( ( $repagify_pages < 2 ) ? ' one-page' : '' ); ?>">
				<span class="displaying-num">
					<?php
					printf(
						/* translators: %s: formatted number of matching posts. */
						esc_html( _n( '%s item', '%s items', $repagify_found, 'repagify' ) ),
						esc_html( number_format_i18n( $repagify_found ) )
					);
					?>
				</span>
				<?php echo wp_kses_post( Repagify_Admin::pagination_links( $repagify_query, $repagify_paged, $repagify_pages ) ); ?>
			</div>
			<br class="clear" />
		</div>

		<table class="wp-list-table widefat fixed striped table-view-list repagify-table">
			<caption class="screen-reader-text">
				<?php esc_html_e( 'Published posts ranked by repurposing potential.', 'repagify' ); ?>
			</caption>
			<thead>
				<tr>
					<?php
					foreach ( $repagify_columns as $repagify_key => $repagify_label ) :
						$repagify_classes = array( 'manage-column', 'column-' . $repagify_key );
						$repagify_is_sort = in_array( $repagify_key, $repagify_sortable, true );
						$repagify_current = ( $repagify_key === $repagify_query['orderby'] );

						if ( $repagify_is_sort ) {
							$repagify_classes[] = 'sortable';

							if ( $repagify_current ) {
								$repagify_classes[] = 'sorted';
								$repagify_classes[] = $repagify_query['order'];
							} else {
								// Which way a click will sort this column first.
								$repagify_classes[] = ( 'title' === $repagify_key ) ? 'asc' : 'desc';
							}
						}

						if ( 'title' === $repagify_key ) {
							$repagify_classes[] = 'column-primary';
						}
						?>
						<th
							scope="col"
							class="<?php echo esc_attr( implode( ' ', $repagify_classes ) ); ?>"
							<?php
							if ( $repagify_is_sort ) {
								printf(
									' aria-sort="%s"',
									$repagify_current
										? esc_attr( 'asc' === $repagify_query['order'] ? 'ascending' : 'descending' )
										: 'none'
								);
							}
							?>
						>
							<?php if ( $repagify_is_sort ) : ?>
								<a href="<?php echo esc_url( Repagify_Admin::sort_url( $repagify_query, $repagify_key ) ); ?>">
									<span><?php echo esc_html( $repagify_label ); ?></span>
									<span class="sorting-indicator" aria-hidden="true"></span>
								</a>
							<?php else : ?>
								<?php echo esc_html( $repagify_label ); ?>
							<?php endif; ?>
						</th>
					<?php endforeach; ?>
				</tr>
			</thead>

			<tbody>
				<?php if ( empty( $repagify_rows ) ) : ?>
					<tr class="no-items">
						<td class="colspanchange" colspan="<?php echo esc_attr( (string) count( $repagify_columns ) ); ?>">
							<?php if ( $repagify_filtered ) : ?>
								<?php esc_html_e( 'No posts match these filters. Try widening the date range or clearing the category.', 'repagify' ); ?>
								<a href="<?php echo esc_url( $repagify_base_url ); ?>"><?php esc_html_e( 'Reset filters', 'repagify' ); ?></a>
							<?php else : ?>
								<?php esc_html_e( 'No opportunities found.', 'repagify' ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php else : ?>
					<?php
					foreach ( $repagify_rows as $repagify_row ) :
						$repagify_labels = Repagify_Scanner::format_labels_for( $repagify_row['converted'] );
						$repagify_band   = Repagify_Admin::score_band( (int) $repagify_row['score'] );
						?>
						<tr>
							<td class="title column-title has-row-actions column-primary" data-colname="<?php echo esc_attr( $repagify_columns['title'] ); ?>">
								<strong>
									<?php if ( '' !== $repagify_row['edit_link'] ) : ?>
										<a class="row-title" href="<?php echo esc_url( $repagify_row['edit_link'] ); ?>">
											<?php echo esc_html( Repagify_Admin::row_title( $repagify_row['title'] ) ); ?>
										</a>
									<?php else : ?>
										<?php echo esc_html( Repagify_Admin::row_title( $repagify_row['title'] ) ); ?>
									<?php endif; ?>
								</strong>

								<?php if ( ! empty( $repagify_row['categories'] ) ) : ?>
									<div class="repagify-row-meta">
										<?php echo esc_html( implode( ', ', $repagify_row['categories'] ) ); ?>
									</div>
								<?php endif; ?>

								<div class="row-actions">
									<?php if ( '' !== $repagify_row['edit_link'] ) : ?>
										<span class="edit">
											<a href="<?php echo esc_url( $repagify_row['edit_link'] ); ?>"><?php esc_html_e( 'Edit', 'repagify' ); ?></a>
											<?php if ( '' !== $repagify_row['permalink'] ) : ?>
												 |
											<?php endif; ?>
										</span>
									<?php endif; ?>
									<?php if ( '' !== $repagify_row['permalink'] ) : ?>
										<span class="view">
											<a href="<?php echo esc_url( $repagify_row['permalink'] ); ?>" rel="bookmark"><?php esc_html_e( 'View', 'repagify' ); ?></a>
										</span>
									<?php endif; ?>
								</div>

								<button type="button" class="toggle-row">
									<span class="screen-reader-text"><?php esc_html_e( 'Show more details', 'repagify' ); ?></span>
								</button>
							</td>

							<td class="column-word_count" data-colname="<?php echo esc_attr( $repagify_columns['word_count'] ); ?>">
								<?php echo esc_html( number_format_i18n( $repagify_row['word_count'] ) ); ?>
								<?php if ( (int) $repagify_row['heading_count'] > 0 ) : ?>
									<span class="repagify-row-meta">
										<?php
										printf(
											/* translators: %s: number of h2 and h3 headings. */
											esc_html( _n( '%s heading', '%s headings', (int) $repagify_row['heading_count'], 'repagify' ) ),
											esc_html( number_format_i18n( $repagify_row['heading_count'] ) )
										);
										?>
									</span>
								<?php endif; ?>
							</td>

							<td class="column-date" data-colname="<?php echo esc_attr( $repagify_columns['date'] ); ?>">
								<?php echo esc_html( mysql2date( get_option( 'date_format' ), $repagify_row['date'] ) ); ?>
								<span class="repagify-row-meta">
									<?php
									printf(
										/* translators: %s: human-readable time difference, such as "2 years". */
										esc_html__( '%s ago', 'repagify' ),
										esc_html( human_time_diff( (int) $repagify_row['timestamp'], time() ) )
									);
									?>
								</span>
							</td>

							<td class="column-score" data-colname="<?php echo esc_attr( $repagify_columns['score'] ); ?>">
								<span class="repagify-score repagify-score--<?php echo esc_attr( $repagify_band ); ?>">
									<?php echo esc_html( number_format_i18n( $repagify_row['score'] ) ); ?>
								</span>
								<span class="repagify-score-track" aria-hidden="true">
									<?php // The width is this row's score, so it cannot live in the stylesheet. ?>
									<span
										class="repagify-score-fill"
										style="width:<?php echo esc_attr( (string) max( 0, min( 100, (int) $repagify_row['score'] ) ) ); ?>%"
									></span>
								</span>
								<span class="repagify-row-meta"><?php echo esc_html( $repagify_row['reason'] ); ?></span>
							</td>

							<td class="column-status" data-colname="<?php echo esc_attr( $repagify_columns['status'] ); ?>">
								<?php if ( empty( $repagify_row['repurposed'] ) ) : ?>
									<span class="repagify-badge repagify-badge--opportunity">
										<?php esc_html_e( 'Never repurposed', 'repagify' ); ?>
									</span>
								<?php else : ?>
									<span class="repagify-badge repagify-badge--done">
										<?php esc_html_e( 'Repurposed', 'repagify' ); ?>
									</span>
									<?php if ( ! empty( $repagify_labels ) ) : ?>
										<span class="repagify-row-meta">
											<?php
											printf(
												/* translators: %s: comma-separated list of output format names. */
												esc_html__( 'as %s', 'repagify' ),
												esc_html( implode( ', ', $repagify_labels ) )
											);
											?>
										</span>
									<?php endif; ?>
								<?php endif; ?>
							</td>

							<td class="column-action" data-colname="<?php echo esc_attr( $repagify_columns['action'] ); ?>">
								<?php if ( $repagify_has_key ) : ?>
									<button
										type="button"
										class="button button-small repagify-repurpose"
										data-post-id="<?php echo esc_attr( (string) $repagify_row['id'] ); ?>"
										data-post-title="<?php echo esc_attr( Repagify_Admin::row_title( $repagify_row['title'] ) ); ?>"
									>
										<?php esc_html_e( 'Repurpose', 'repagify' ); ?>
									</button>
								<?php else : ?>
									<span
										class="repagify-soon-wrap"
										title="<?php esc_attr_e( 'Generation is performed by the Repagify web service. Connect an account on the settings screen to use it.', 'repagify' ); ?>"
									>
										<button
											type="button"
											class="button button-small"
											aria-describedby="repagify-soon-note"
											disabled
										>
											<?php esc_html_e( 'Repurpose', 'repagify' ); ?>
										</button>
									</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( ! $repagify_has_key ) : ?>
			<p id="repagify-soon-note" class="repagify-hint">
				<?php esc_html_e( 'Generation is carried out by the Repagify web service, so it needs a connected account. Everything else on this page works without one.', 'repagify' ); ?>
			</p>
		<?php endif; ?>

		<div class="tablenav bottom">
			<div class="tablenav-pages<?php echo esc_attr( ( $repagify_pages < 2 ) ? ' one-page' : '' ); ?>">
				<?php echo wp_kses_post( Repagify_Admin::pagination_links( $repagify_query, $repagify_paged, $repagify_pages ) ); ?>
			</div>
			<br class="clear" />
		</div>

		<?php if ( is_array( $repagify_cache ) && $repagify_cache['generated'] > 0 ) : ?>
			<p class="repagify-hint">
				<?php
				printf(
					/* translators: %s: human-readable time difference, such as "5 mins". */
					esc_html__( 'Scanned %s ago. Results are cached for an hour; use Rescan content to refresh them now.', 'repagify' ),
					esc_html( human_time_diff( (int) $repagify_cache['generated'], time() ) )
				);
				?>
			</p>
		<?php endif; ?>

	<?php endif; ?>

	<?php if ( $repagify_has_key && $repagify_total > 0 ) : ?>
		<div class="repagify-modal" id="repagify-modal" hidden>
			<div class="repagify-modal-backdrop" data-repagify-close="1"></div>

			<div
				class="repagify-modal-panel"
				role="dialog"
				aria-modal="true"
				aria-labelledby="repagify-modal-title"
			>
				<div class="repagify-modal-head">
					<h2 id="repagify-modal-title"><?php esc_html_e( 'Repurpose this post', 'repagify' ); ?></h2>
					<button type="button" class="repagify-modal-close" data-repagify-close="1">
						<span class="screen-reader-text"><?php esc_html_e( 'Close', 'repagify' ); ?></span>
						<span aria-hidden="true">&times;</span>
					</button>
				</div>

				<p class="repagify-modal-post" id="repagify-modal-post"></p>

				<div class="repagify-modal-body">

					<form class="repagify-generate-form" id="repagify-generate-form">
						<input type="hidden" name="post_id" id="repagify-field-post" value="" />

						<p class="repagify-field">
							<label for="repagify-field-format"><?php esc_html_e( 'Output format', 'repagify' ); ?></label>
							<select id="repagify-field-format" name="format">
								<?php foreach ( Repagify_Formats::all() as $repagify_slug => $repagify_format ) : ?>
									<option value="<?php echo esc_attr( $repagify_slug ); ?>">
										<?php echo esc_html( $repagify_format['label'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</p>

						<p class="repagify-field">
							<label for="repagify-field-tone"><?php esc_html_e( 'Tone', 'repagify' ); ?></label>
							<select id="repagify-field-tone" name="tone">
								<?php foreach ( Repagify_Formats::tones() as $repagify_tone => $repagify_tone_label ) : ?>
									<option
										value="<?php echo esc_attr( $repagify_tone ); ?>"
										<?php selected( $repagify_tone, Repagify_Formats::DEFAULT_TONE ); ?>
									>
										<?php echo esc_html( $repagify_tone_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</p>

						<p class="repagify-field" id="repagify-field-keyword-row">
							<label for="repagify-field-keyword">
								<?php esc_html_e( 'Target keyword', 'repagify' ); ?>
								<span class="repagify-optional"><?php esc_html_e( '(optional)', 'repagify' ); ?></span>
							</label>
							<input
								type="text"
								id="repagify-field-keyword"
								name="keyword"
								class="regular-text"
								autocomplete="off"
								placeholder="<?php esc_attr_e( 'e.g. content repurposing', 'repagify' ); ?>"
							/>
						</p>

						<p class="repagify-source-note" id="repagify-source-note" role="status" aria-live="polite"></p>

						<p class="repagify-modal-quota" id="repagify-modal-quota"><?php
							// What this generation will cost, so the choice is informed.
							if ( is_array( $repagify_account ) && ! Repagify_Quota::is_unlimited( $repagify_account ) ) {
								echo esc_html( Repagify_Quota::quota_phrase( $repagify_account ) );
							}
						?></p>

						<div class="repagify-modal-actions">
							<button type="submit" class="button button-primary" id="repagify-generate-button">
								<?php esc_html_e( 'Generate', 'repagify' ); ?>
							</button>
							<span class="spinner" id="repagify-generate-spinner"></span>
							<button type="button" class="button button-link" data-repagify-close="1">
								<?php esc_html_e( 'Cancel', 'repagify' ); ?>
							</button>
						</div>

						<p class="repagify-generate-wait" id="repagify-generate-wait" hidden>
							<?php esc_html_e( 'This usually takes 20 to 45 seconds. Leave this window open.', 'repagify' ); ?>
						</p>
					</form>

					<div class="repagify-result-panel" id="repagify-result-panel" hidden>
						<p class="repagify-result-meta" id="repagify-result-meta"></p>

						<pre class="repagify-result-content" id="repagify-result-content" tabindex="0"></pre>

						<div class="repagify-modal-actions">
							<button type="button" class="button button-primary" id="repagify-copy-button">
								<?php esc_html_e( 'Copy to clipboard', 'repagify' ); ?>
							</button>
							<button type="button" class="button" id="repagify-again-button">
								<?php esc_html_e( 'Generate another format', 'repagify' ); ?>
							</button>
							<button type="button" class="button button-link" data-repagify-close="1">
								<?php esc_html_e( 'Close', 'repagify' ); ?>
							</button>
						</div>
					</div>

					<div class="repagify-upgrade-panel" id="repagify-upgrade-panel" hidden>
						<p class="repagify-upgrade-title" id="repagify-upgrade-title"></p>
						<p class="repagify-upgrade-message" id="repagify-upgrade-message"></p>
						<p class="repagify-upgrade-note" id="repagify-upgrade-note"></p>

						<div class="repagify-modal-actions">
							<a
								href="#"
								class="button button-primary"
								id="repagify-upgrade-button"
								target="_blank"
								rel="noopener noreferrer"
								hidden
							></a>
							<button type="button" class="button button-link" data-repagify-close="1">
								<?php esc_html_e( 'Close', 'repagify' ); ?>
							</button>
						</div>
					</div>

					<div class="repagify-modal-notice" id="repagify-modal-notice" role="alert" aria-live="assertive"></div>

				</div>
			</div>
		</div>
	<?php endif; ?>
</div>
