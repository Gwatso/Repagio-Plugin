<?php
/**
 * Archive scanner.
 *
 * Walks the published archive with WP_Query, collects the handful of fields
 * the opportunity dashboard needs, and scores each post for repurposing
 * potential. Makes no HTTP requests: the whole scanner works with no API key
 * saved.
 *
 * @package Repagio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scans published content and ranks it by repurposing potential.
 *
 * @since 0.2.0
 */
class Repagio_Scanner {

	/**
	 * Transient holding the last completed (or in-progress) scan.
	 *
	 * @var string
	 */
	const TRANSIENT = 'repagio_scan_cache';

	/**
	 * How long a completed scan stays cached, in seconds.
	 *
	 * @var int
	 */
	const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Post meta key recording which formats a post has been repurposed into.
	 *
	 * Stored as an array of format slug => Unix timestamp.
	 *
	 * @var string
	 */
	const CONVERTED_META = '_repagio_converted';

	/**
	 * Archives at or below this many posts are scanned in one request.
	 *
	 * Larger archives are scanned in batches over AJAX so no single request
	 * runs long enough to hit max_execution_time.
	 *
	 * @var int
	 */
	const SYNC_LIMIT = 200;

	/**
	 * Posts handled per AJAX batch.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 50;

	/**
	 * Posts pulled per WP_Query page during a synchronous scan.
	 *
	 * @var int
	 */
	const QUERY_CHUNK = 50;

	/**
	 * Characters the generation endpoint accepts in a single conversion.
	 *
	 * Anything longer has to be split before it can be repurposed, which costs
	 * a post a few points.
	 *
	 * @var int
	 */
	const MAX_CONVERSION_CHARS = 50000;

	/**
	 * Word count below which a post has too little to say to repurpose.
	 *
	 * @var int
	 */
	const THIN_WORDS = 300;

	/**
	 * Ceiling applied to posts below THIN_WORDS.
	 *
	 * Without it, age and heading bonuses lift a 280 word post into the middle
	 * of the range, which reads as an opportunity it is not: there is simply
	 * not enough there to turn into a thread or a newsletter. The cap keeps
	 * every thin post below every substantial one however old or tidy it is.
	 *
	 * @var int
	 */
	const THIN_SCORE_CAP = 15;

	/**
	 * Shape version, so a cache written by an older release is discarded rather
	 * than read with missing keys.
	 *
	 * @var int
	 */
	const CACHE_VERSION = 1;

	/**
	 * Post types the scanner looks at.
	 *
	 * @since 0.2.0
	 *
	 * @return string[] Registered post type names.
	 */
	public static function scannable_post_types() {
		/**
		 * Filters the post types the archive scanner walks.
		 *
		 * @since 0.2.0
		 *
		 * @param string[] $post_types Post type names. Defaults to array( 'post' ).
		 */
		$post_types = apply_filters( 'repagio_scannable_post_types', array( 'post' ) );

		if ( ! is_array( $post_types ) ) {
			$post_types = array( $post_types );
		}

		$clean = array();

		foreach ( $post_types as $post_type ) {
			if ( ! is_string( $post_type ) ) {
				continue;
			}

			$post_type = sanitize_key( $post_type );

			if ( '' === $post_type || ! post_type_exists( $post_type ) ) {
				continue;
			}

			$clean[ $post_type ] = $post_type;
		}

		if ( empty( $clean ) ) {
			$clean = array( 'post' => 'post' );
		}

		return array_values( $clean );
	}

	/**
	 * Counts published posts across the scannable post types.
	 *
	 * Uses wp_count_posts(), which is cached, rather than a COUNT query of its
	 * own.
	 *
	 * @since 0.2.0
	 *
	 * @param string[]|null $post_types Post types to count. Defaults to the scannable set.
	 * @return int
	 */
	public static function count_scannable( $post_types = null ) {
		if ( null === $post_types ) {
			$post_types = self::scannable_post_types();
		}

		$total = 0;

		foreach ( $post_types as $post_type ) {
			$counts = wp_count_posts( $post_type );

			if ( isset( $counts->publish ) ) {
				$total += (int) $counts->publish;
			}
		}

		return $total;
	}

	/**
	 * Whether this archive is large enough to need batching over AJAX.
	 *
	 * @since 0.2.0
	 *
	 * @param int|null $total Known post count, to avoid counting twice.
	 * @return bool
	 */
	public static function needs_batching( $total = null ) {
		if ( null === $total ) {
			$total = self::count_scannable();
		}

		return (int) $total > self::SYNC_LIMIT;
	}

	/**
	 * Returns the cached scan, completed or partial.
	 *
	 * @since 0.2.0
	 *
	 * @return array|null Cache array, or null when there is nothing usable stored.
	 */
	public static function get_cache() {
		$cache = get_transient( self::TRANSIENT );

		if ( ! is_array( $cache ) ) {
			return null;
		}

		if ( ! isset( $cache['version'] ) || self::CACHE_VERSION !== (int) $cache['version'] ) {
			return null;
		}

		if ( ! isset( $cache['items'] ) || ! is_array( $cache['items'] ) ) {
			return null;
		}

		$cache['post_types'] = ( isset( $cache['post_types'] ) && is_array( $cache['post_types'] ) )
			? $cache['post_types']
			: array();

		// A changed filter invalidates the cache rather than showing stale rows.
		if ( self::scannable_post_types() !== $cache['post_types'] ) {
			return null;
		}

		$cache['complete']  = ! empty( $cache['complete'] );
		$cache['total']     = isset( $cache['total'] ) ? (int) $cache['total'] : 0;
		$cache['scanned']   = isset( $cache['scanned'] ) ? (int) $cache['scanned'] : 0;
		$cache['generated'] = isset( $cache['generated'] ) ? (int) $cache['generated'] : 0;

		return $cache;
	}

	/**
	 * Returns a completed scan, running one if the cache is cold.
	 *
	 * Only ever scans synchronously. Callers handling a large archive should
	 * check needs_batching() first and drive scan_step() over AJAX instead; the
	 * returned array reports 'complete' => false in that case.
	 *
	 * @since 0.2.0
	 *
	 * @return array Cache array.
	 */
	public static function get_results() {
		$cache = self::get_cache();

		if ( is_array( $cache ) && $cache['complete'] ) {
			return $cache;
		}

		$post_types = self::scannable_post_types();
		$total      = self::count_scannable( $post_types );

		if ( self::needs_batching( $total ) ) {
			// Leave any partial cache in place; the dashboard resumes it.
			if ( is_array( $cache ) ) {
				return $cache;
			}

			return self::empty_cache( $post_types, $total );
		}

		return self::scan();
	}

	/**
	 * Scans the whole archive in one request.
	 *
	 * @since 0.2.0
	 *
	 * @return array Completed cache array.
	 */
	public static function scan() {
		$post_types = self::scannable_post_types();
		$total      = self::count_scannable( $post_types );
		$cache      = self::empty_cache( $post_types, $total );
		$offset     = 0;

		do {
			$batch = self::scan_batch( $offset, self::QUERY_CHUNK, $post_types );
			$found = count( $batch );

			if ( $found > 0 ) {
				$cache['items'] = array_merge( $cache['items'], $batch );
			}

			$offset += $found;
		} while ( self::QUERY_CHUNK === $found );

		$cache['scanned']   = count( $cache['items'] );
		$cache['total']     = max( $cache['scanned'], $total );
		$cache['complete']  = true;
		$cache['generated'] = time();

		set_transient( self::TRANSIENT, $cache, self::CACHE_TTL );

		return $cache;
	}

	/**
	 * Scans one batch and folds it into the cached partial result.
	 *
	 * Used by the AJAX handler on archives too large to scan in one request.
	 * When the stored partial has gone missing the scan restarts from zero
	 * rather than returning a result with a hole in it.
	 *
	 * @since 0.2.0
	 *
	 * @param int $offset Posts already scanned.
	 * @return array {
	 *     Progress report.
	 *
	 *     @type int  $scanned     Posts scanned so far.
	 *     @type int  $total       Posts to scan in total.
	 *     @type int  $next_offset Offset to pass to the next call.
	 *     @type bool $complete    Whether the scan has finished.
	 *     @type bool $restarted   Whether the partial result had to be rebuilt.
	 * }
	 */
	public static function scan_step( $offset ) {
		$offset     = max( 0, (int) $offset );
		$post_types = self::scannable_post_types();
		$total      = self::count_scannable( $post_types );
		$restarted  = false;
		$cache      = null;

		if ( $offset > 0 ) {
			$cache = self::get_cache();

			if ( ! is_array( $cache ) || count( $cache['items'] ) !== $offset ) {
				$cache     = null;
				$offset    = 0;
				$restarted = true;
			}
		}

		if ( null === $cache ) {
			$cache = self::empty_cache( $post_types, $total );
		}

		$cache['total'] = $total;

		$batch = self::scan_batch( $offset, self::BATCH_SIZE, $post_types );
		$found = count( $batch );

		if ( $found > 0 ) {
			$cache['items'] = array_merge( $cache['items'], $batch );
		}

		$cache['scanned']  = count( $cache['items'] );
		$cache['complete'] = $found < self::BATCH_SIZE || $cache['scanned'] >= $total;

		if ( $cache['complete'] ) {
			$cache['total']     = max( $cache['scanned'], $total );
			$cache['generated'] = time();
		}

		set_transient( self::TRANSIENT, $cache, self::CACHE_TTL );

		return array(
			'scanned'     => (int) $cache['scanned'],
			'total'       => (int) max( $cache['total'], $cache['scanned'] ),
			'next_offset' => (int) $cache['scanned'],
			'complete'    => (bool) $cache['complete'],
			'restarted'   => $restarted,
		);
	}

	/**
	 * Reads one page of published posts and reduces each to a scored item.
	 *
	 * @since 0.2.0
	 *
	 * @param int           $offset     Posts to skip.
	 * @param int           $limit      Posts to read.
	 * @param string[]|null $post_types Post types to read.
	 * @return array[] Scored items, lowest ID first.
	 */
	protected static function scan_batch( $offset, $limit, $post_types = null ) {
		if ( null === $post_types ) {
			$post_types = self::scannable_post_types();
		}

		$query = new WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'posts_per_page'         => (int) $limit,
				'offset'                 => (int) $offset,
				// A stable order keeps batch boundaries from overlapping.
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				// The total comes from wp_count_posts(), so skip the COUNT query.
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				// Categories and the converted meta are read for every post, so
				// priming both caches in one go beats a query per post.
				'update_post_term_cache' => true,
				'update_post_meta_cache' => true,
				'lazy_load_term_meta'    => false,
			)
		);

		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = self::build_item( $post );
		}

		// Nothing holds a reference to the post objects once the batch is built.
		unset( $query );

		return $items;
	}

	/**
	 * Reduces a post to the fields the dashboard needs, with its score.
	 *
	 * Public since 0.8.0 so the editor sidebar can score the one post it is
	 * showing exactly as the dashboard would, without a scan.
	 *
	 * @since 0.2.0
	 *
	 * @param WP_Post $post Post to describe.
	 * @return array
	 */
	public static function build_item( $post ) {
		$content   = isset( $post->post_content ) ? (string) $post->post_content : '';
		$plain     = self::plain_text( $content );
		$timestamp = (int) get_post_time( 'U', true, $post );

		if ( $timestamp <= 0 ) {
			$timestamp = (int) mysql2date( 'U', $post->post_date, false );
		}

		$categories = self::post_categories( $post );
		$edit_link  = get_edit_post_link( $post->ID, 'raw' );

		$item = array(
			'id'            => (int) $post->ID,
			'post_type'     => (string) $post->post_type,
			'title'         => (string) $post->post_title,
			'edit_link'     => is_string( $edit_link ) ? $edit_link : '',
			'permalink'     => (string) get_permalink( $post ),
			'date'          => (string) $post->post_date,
			'timestamp'     => $timestamp,
			'word_count'    => self::count_words( $plain ),
			'char_count'    => self::count_chars( $plain ),
			'heading_count' => self::count_headings( $content ),
			'category_ids'  => $categories['ids'],
			'categories'    => $categories['names'],
			'converted'     => self::converted_formats( $post->ID ),
		);

		$item['repurposed'] = ! empty( $item['converted'] );

		$score = self::score_post( $item );

		$item['score']  = $score['score'];
		$item['reason'] = $score['reason'];

		return $item;
	}

	/**
	 * Scores a scanned item from 0 to 100 for repurposing potential.
	 *
	 * Word count is the primary signal, worth over half the score: very short
	 * posts have nothing to repurpose, the 800 to 3,000 word band converts
	 * best, and anything past the conversion character limit loses a little
	 * because it has to be split first. Never having been repurposed is a large
	 * bonus, headings a smaller one, and age a smaller one again — dormant
	 * content is the opportunity.
	 *
	 * @since 0.2.0
	 *
	 * @param array $item Item as collected by the scan. Missing keys count as empty.
	 * @return array {
	 *     @type int    $score  0 to 100.
	 *     @type string $reason Short human-readable explanation.
	 * }
	 */
	public static function score_post( $item ) {
		$item = wp_parse_args(
			is_array( $item ) ? $item : array(),
			array(
				'word_count'    => 0,
				'char_count'    => 0,
				'heading_count' => 0,
				'timestamp'     => 0,
				'converted'     => array(),
				'repurposed'    => null,
			)
		);

		$words      = max( 0, (int) $item['word_count'] );
		$headings   = max( 0, (int) $item['heading_count'] );
		$chars      = max( 0, (int) $item['char_count'] );
		$converted  = is_array( $item['converted'] ) ? $item['converted'] : array();
		$repurposed = ( null === $item['repurposed'] ) ? ! empty( $converted ) : (bool) $item['repurposed'];
		$oversized  = $words > 5000 || $chars > self::MAX_CONVERSION_CHARS;

		// Length, 0-55. The dominant signal.
		if ( $words < self::THIN_WORDS ) {
			$score = 5;
		} elseif ( $words < 500 ) {
			$score = 20;
		} elseif ( $words < 800 ) {
			$score = 35;
		} elseif ( $words <= 3000 ) {
			$score = 55;
		} elseif ( $words <= 5000 ) {
			$score = 45;
		} else {
			// Past the conversion limit, so it needs splitting first.
			$score = 35;
		}

		// Never repurposed, 0 or 20.
		if ( ! $repurposed ) {
			$score += 20;
		}

		// Structure, 0-13.
		if ( $headings >= 6 ) {
			$score += 13;
		} elseif ( $headings >= 3 ) {
			$score += 9;
		} elseif ( $headings >= 1 ) {
			$score += 4;
		}

		// Age, 0-12.
		$score += self::age_points( (int) $item['timestamp'] );

		$score = (int) max( 0, min( 100, $score ) );

		// A thin post stays at the bottom whatever its bonuses came to.
		if ( $words < self::THIN_WORDS ) {
			$score = min( $score, self::THIN_SCORE_CAP );
		}

		return array(
			'score'  => $score,
			'reason' => self::build_reason( $words, $headings, $repurposed, $converted, $oversized ),
		);
	}

	/**
	 * Points awarded for how long a post has been sitting there.
	 *
	 * @since 0.2.0
	 *
	 * @param int $timestamp Publish time as a GMT Unix timestamp.
	 * @return int 0 to 12.
	 */
	protected static function age_points( $timestamp ) {
		if ( $timestamp <= 0 ) {
			return 0;
		}

		$age = time() - $timestamp;

		if ( $age <= 0 ) {
			return 0;
		}

		$months = $age / ( 30 * DAY_IN_SECONDS );

		if ( $months >= 24 ) {
			return 12;
		}

		if ( $months >= 12 ) {
			return 9;
		}

		if ( $months >= 6 ) {
			return 6;
		}

		if ( $months >= 3 ) {
			return 3;
		}

		return 0;
	}

	/**
	 * Builds the short explanation shown next to a score.
	 *
	 * @since 0.2.0
	 *
	 * @param int   $words      Word count.
	 * @param int   $headings   Heading count.
	 * @param bool  $repurposed Whether the post has been repurposed before.
	 * @param array $converted  Format slug => timestamp map.
	 * @param bool  $oversized  Whether the post exceeds the conversion limit.
	 * @return string
	 */
	protected static function build_reason( $words, $headings, $repurposed, $converted, $oversized ) {
		$parts = array();

		if ( $words < self::THIN_WORDS ) {
			// Structure is beside the point when there is this little to work
			// with, so the explanation says the one thing that matters.
			$parts[] = sprintf(
				/* translators: %s: formatted word count. */
				__( 'only %s words', 'repagio' ),
				number_format_i18n( $words )
			);
			$parts[] = __( 'too short to repurpose well', 'repagio' );
		} else {
			$parts[] = sprintf(
				/* translators: %s: formatted word count. */
				__( '%s words', 'repagio' ),
				number_format_i18n( $words )
			);

			if ( $headings >= 6 ) {
				$parts[] = __( 'well structured', 'repagio' );
			} elseif ( $headings >= 3 ) {
				$parts[] = __( 'clearly structured', 'repagio' );
			} elseif ( $headings >= 1 ) {
				$parts[] = __( 'few headings', 'repagio' );
			} else {
				$parts[] = __( 'no headings', 'repagio' );
			}

			if ( $oversized ) {
				$parts[] = __( 'needs splitting to convert', 'repagio' );
			}
		}

		if ( $repurposed ) {
			$labels = self::format_labels_for( $converted );

			if ( ! empty( $labels ) ) {
				$parts[] = sprintf(
					/* translators: %s: comma-separated list of output format names. */
					__( 'already repurposed as %s', 'repagio' ),
					implode( ', ', $labels )
				);
			} else {
				$parts[] = __( 'already repurposed', 'repagio' );
			}
		} else {
			$parts[] = __( 'never repurposed', 'repagio' );
		}

		/* translators: separator between the clauses of a score explanation. */
		return implode( __( ', ', 'repagio' ), $parts );
	}

	/**
	 * Totals across a completed scan.
	 *
	 * @since 0.2.0
	 *
	 * @param array|null $cache Scan cache. Defaults to the current results.
	 * @return array {
	 *     @type int $total_posts      Published posts scanned.
	 *     @type int $never_repurposed Posts with no recorded conversion.
	 *     @type int $repurposed       Posts with at least one conversion.
	 *     @type int $total_words      Words across every scanned post.
	 *     @type int $average_words    Mean word count, rounded.
	 *     @type int $dormant_words    Words held in never-repurposed posts.
	 * }
	 */
	public static function get_stats( $cache = null ) {
		if ( null === $cache ) {
			$cache = self::get_results();
		}

		$items = ( is_array( $cache ) && isset( $cache['items'] ) && is_array( $cache['items'] ) )
			? $cache['items']
			: array();

		$stats = array(
			'total_posts'      => count( $items ),
			'never_repurposed' => 0,
			'repurposed'       => 0,
			'total_words'      => 0,
			'average_words'    => 0,
			'dormant_words'    => 0,
		);

		foreach ( $items as $item ) {
			$words = isset( $item['word_count'] ) ? (int) $item['word_count'] : 0;

			$stats['total_words'] += $words;

			if ( empty( $item['repurposed'] ) ) {
				++$stats['never_repurposed'];
				$stats['dormant_words'] += $words;
			} else {
				++$stats['repurposed'];
			}
		}

		if ( $stats['total_posts'] > 0 ) {
			$stats['average_words'] = (int) round( $stats['total_words'] / $stats['total_posts'] );
		}

		return $stats;
	}

	/**
	 * Drops the cached scan.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public static function clear_cache() {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Filters scanned items down to the dashboard's current selection.
	 *
	 * @since 0.2.0
	 *
	 * @param array[] $items Scanned items.
	 * @param array   $args  {
	 *     @type string $post_type    Post type to keep, or '' for all.
	 *     @type int    $category     Category term ID to keep, or 0 for all.
	 *     @type string $date_from    Y-m-d lower bound, or ''.
	 *     @type string $date_to      Y-m-d upper bound, or ''.
	 *     @type bool   $unrepurposed Keep only never-repurposed posts.
	 * }
	 * @return array[]
	 */
	public static function filter_items( $items, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'post_type'    => '',
				'category'     => 0,
				'date_from'    => '',
				'date_to'      => '',
				'unrepurposed' => false,
			)
		);

		$from = ( '' !== $args['date_from'] ) ? strtotime( $args['date_from'] . ' 00:00:00' ) : false;
		$to   = ( '' !== $args['date_to'] ) ? strtotime( $args['date_to'] . ' 23:59:59' ) : false;

		$filtered = array();

		foreach ( $items as $item ) {
			if ( '' !== $args['post_type'] && $args['post_type'] !== $item['post_type'] ) {
				continue;
			}

			if ( (int) $args['category'] > 0 ) {
				$ids = ( isset( $item['category_ids'] ) && is_array( $item['category_ids'] ) )
					? array_map( 'intval', $item['category_ids'] )
					: array();

				if ( ! in_array( (int) $args['category'], $ids, true ) ) {
					continue;
				}
			}

			$published = isset( $item['timestamp'] ) ? (int) $item['timestamp'] : 0;

			if ( false !== $from && $published < $from ) {
				continue;
			}

			if ( false !== $to && $published > $to ) {
				continue;
			}

			if ( $args['unrepurposed'] && ! empty( $item['repurposed'] ) ) {
				continue;
			}

			$filtered[] = $item;
		}

		return $filtered;
	}

	/**
	 * Sorts scanned items for display.
	 *
	 * @since 0.2.0
	 *
	 * @param array[] $items   Scanned items.
	 * @param string  $orderby One of 'score', 'word_count', 'date' or 'title'.
	 * @param string  $order   'asc' or 'desc'.
	 * @return array[]
	 */
	public static function sort_items( $items, $orderby = 'score', $order = 'desc' ) {
		$direction = ( 'asc' === strtolower( (string) $order ) ) ? 1 : -1;

		usort(
			$items,
			static function ( $a, $b ) use ( $orderby, $direction ) {
				switch ( $orderby ) {
					case 'word_count':
						$result = (int) $a['word_count'] - (int) $b['word_count'];
						break;

					case 'date':
						$result = (int) $a['timestamp'] - (int) $b['timestamp'];
						break;

					case 'title':
						$result = strnatcasecmp( (string) $a['title'], (string) $b['title'] );
						break;

					case 'score':
					default:
						$result = (int) $a['score'] - (int) $b['score'];
						break;
				}

				if ( 0 === $result ) {
					// A stable tiebreak so paging never repeats or drops a row.
					return (int) $a['id'] - (int) $b['id'];
				}

				return $result * $direction;
			}
		);

		return $items;
	}

	/**
	 * Human-readable names for the output formats.
	 *
	 * Read from Repagio_Formats since 0.8.0, so the score explanation names a
	 * format exactly as the format selector beside it does.
	 *
	 * @since 0.2.0
	 *
	 * @return array Format slug => label.
	 */
	public static function format_labels() {
		return wp_list_pluck( Repagio_Formats::all(), 'label' );
	}

	/**
	 * Labels for a converted map, falling back to a tidied slug.
	 *
	 * @since 0.2.0
	 *
	 * @param array $converted Format slug => timestamp.
	 * @return string[]
	 */
	public static function format_labels_for( $converted ) {
		if ( ! is_array( $converted ) ) {
			return array();
		}

		$known  = self::format_labels();
		$labels = array();

		foreach ( array_keys( $converted ) as $slug ) {
			$slug = sanitize_key( (string) $slug );

			if ( '' === $slug ) {
				continue;
			}

			$labels[] = isset( $known[ $slug ] )
				? $known[ $slug ]
				: ucfirst( str_replace( array( '_', '-' ), ' ', $slug ) );
		}

		return $labels;
	}

	/**
	 * Reads the recorded conversions for a post.
	 *
	 * @since 0.2.0
	 *
	 * @param int $post_id Post ID.
	 * @return array Format slug => Unix timestamp.
	 */
	protected static function converted_formats( $post_id ) {
		$meta = get_post_meta( (int) $post_id, self::CONVERTED_META, true );

		if ( ! is_array( $meta ) ) {
			return array();
		}

		$clean = array();

		foreach ( $meta as $format => $timestamp ) {
			if ( ! is_string( $format ) && ! is_int( $format ) ) {
				continue;
			}

			$format = sanitize_key( (string) $format );

			if ( '' === $format ) {
				continue;
			}

			$clean[ $format ] = is_numeric( $timestamp ) ? (int) $timestamp : 0;
		}

		return $clean;
	}

	/**
	 * Category names and IDs for a post, if it uses categories at all.
	 *
	 * @since 0.2.0
	 *
	 * @param WP_Post $post Post to read.
	 * @return array {
	 *     @type int[]    $ids   Term IDs.
	 *     @type string[] $names Term names.
	 * }
	 */
	protected static function post_categories( $post ) {
		$result = array(
			'ids'   => array(),
			'names' => array(),
		);

		if ( ! is_object_in_taxonomy( $post->post_type, 'category' ) ) {
			return $result;
		}

		// Reads the term cache WP_Query has already primed.
		$terms = get_the_terms( $post, 'category' );

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return $result;
		}

		foreach ( $terms as $term ) {
			$result['ids'][]   = (int) $term->term_id;
			$result['names'][] = (string) $term->name;
		}

		return $result;
	}

	/**
	 * Reduces post content to countable plain text.
	 *
	 * Shortcodes, block delimiters, script and style bodies and every HTML tag
	 * come out first, so none of them are counted as words.
	 *
	 * @since 0.2.0
	 *
	 * @param string $content Raw post_content.
	 * @return string
	 */
	protected static function plain_text( $content ) {
		if ( '' === $content ) {
			return '';
		}

		$text = strip_shortcodes( $content );

		// Block delimiters and any other HTML comments.
		$text = preg_replace( '/<!--.*?-->/s', ' ', $text );

		// Script and style bodies, which wp_strip_all_tags would otherwise keep.
		$text = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', (string) $text );

		// Tags become spaces so that <p>one</p><p>two</p> counts as two words.
		$text = preg_replace( '/<[^>]*>/', ' ', (string) $text );
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES, get_bloginfo( 'charset' ) );

		// Non-breaking spaces, which \s does not match.
		$text = str_replace( "\xC2\xA0", ' ', $text );
		$text = preg_replace( '/\s+/u', ' ', $text );

		return trim( (string) $text );
	}

	/**
	 * Counts words in plain text.
	 *
	 * @since 0.2.0
	 *
	 * @param string $text Plain text.
	 * @return int
	 */
	protected static function count_words( $text ) {
		if ( '' === $text ) {
			return 0;
		}

		$words = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $words ) ? count( $words ) : 0;
	}

	/**
	 * Counts characters in plain text.
	 *
	 * @since 0.2.0
	 *
	 * @param string $text Plain text.
	 * @return int
	 */
	protected static function count_chars( $text ) {
		if ( '' === $text ) {
			return 0;
		}

		return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $text ) : strlen( $text );
	}

	/**
	 * Counts h2 and h3 headings in post content.
	 *
	 * @since 0.2.0
	 *
	 * @param string $content Raw post_content.
	 * @return int
	 */
	protected static function count_headings( $content ) {
		if ( '' === $content ) {
			return 0;
		}

		return (int) preg_match_all( '#<h[23](\s[^>]*)?>#i', $content );
	}

	/**
	 * An empty cache array, ready to have items folded into it.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $post_types Post types this scan covers.
	 * @param int      $total      Posts the scan expects to read.
	 * @return array
	 */
	protected static function empty_cache( $post_types, $total ) {
		return array(
			'version'    => self::CACHE_VERSION,
			'generated'  => 0,
			'post_types' => $post_types,
			'total'      => (int) $total,
			'scanned'    => 0,
			'complete'   => false,
			'items'      => array(),
		);
	}
}
