<?php
/**
 * A small, defensive readme.txt reader.
 *
 * Exists so the View details modal shows the same copy as readme.txt rather
 * than a second, drifting version of it kept in PHP. Deliberately modest: it
 * understands the handful of constructs this plugin's readme actually uses and
 * degrades to an empty string for anything it does not, because a details
 * modal is not worth an error.
 *
 * @package Repagio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns readme.txt into the fields plugins_api expects.
 *
 * @since 0.6.0
 */
class Repagio_Readme {

	/**
	 * Sections offered in the details modal, mapped to their readme headings.
	 *
	 * @var array
	 */
	const SECTIONS = array(
		'description'  => 'Description',
		'installation' => 'Installation',
		'faq'          => 'Frequently Asked Questions',
		'changelog'    => 'Changelog',
	);

	/**
	 * Parses a readme.txt.
	 *
	 * Always returns the full shape, whatever the file contains or whether it
	 * can be read at all.
	 *
	 * @since 0.6.0
	 *
	 * @param string $path Absolute path to readme.txt.
	 * @return array {
	 *     @type string $requires          Requires at least.
	 *     @type string $tested            Tested up to.
	 *     @type string $requires_php      Requires PHP.
	 *     @type string $stable_tag        Stable tag.
	 *     @type string $short_description The line under the headers.
	 *     @type array  $sections          Section slug => HTML.
	 * }
	 */
	public static function parse( $path ) {
		$empty = array(
			'requires'          => '',
			'tested'            => '',
			'requires_php'      => '',
			'stable_tag'        => '',
			'short_description' => '',
			'sections'          => array(),
		);

		if ( ! is_readable( $path ) ) {
			return self::with_fallback( $empty );
		}

		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a file shipped inside this plugin, not a remote resource.

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return self::with_fallback( $empty );
		}

		$raw = str_replace( array( "\r\n", "\r" ), "\n", $raw );

		$parsed = array(
			'requires'          => self::read_header( $raw, 'Requires at least' ),
			'tested'            => self::read_header( $raw, 'Tested up to' ),
			'requires_php'      => self::read_header( $raw, 'Requires PHP' ),
			'stable_tag'        => self::read_header( $raw, 'Stable tag' ),
			'short_description' => self::short_description( $raw ),
			'sections'          => self::sections( $raw ),
		);

		return self::with_fallback( $parsed );
	}

	/**
	 * Guarantees the modal has something worth showing.
	 *
	 * @since 0.6.0
	 *
	 * @param array $parsed Parsed fields.
	 * @return array
	 */
	protected static function with_fallback( $parsed ) {
		if ( '' === $parsed['short_description'] ) {
			$parsed['short_description'] = __( 'Find the dormant posts in your archive worth reusing, then turn the best of them into blog posts, LinkedIn posts, X threads and newsletters.', 'repagio' );
		}

		if ( empty( $parsed['sections'] ) || empty( $parsed['sections']['description'] ) ) {
			$parsed['sections']['description'] = wpautop(
				esc_html__( 'Repagio scans your published archive entirely on your own server, scores every post for repurposing potential, and turns the ones worth reusing into blog posts, LinkedIn posts, X threads and newsletters. Scanning needs no account; generating needs a free Repagio account.', 'repagio' )
			);
		}

		return $parsed;
	}

	/**
	 * Reads one colon-delimited header from the top of the file.
	 *
	 * Named read_header rather than header so that a security audit grepping
	 * for PHP's header() does not have to stop and check this one.
	 *
	 * @since 0.6.0
	 *
	 * @param string $raw   Readme contents.
	 * @param string $label Header label.
	 * @return string
	 */
	protected static function read_header( $raw, $label ) {
		if ( ! preg_match( '/^' . preg_quote( $label, '/' ) . ':\s*(.+)$/mi', $raw, $matches ) ) {
			return '';
		}

		return sanitize_text_field( trim( $matches[1] ) );
	}

	/**
	 * The short description: the first prose line after the header block.
	 *
	 * @since 0.6.0
	 *
	 * @param string $raw Readme contents.
	 * @return string
	 */
	protected static function short_description( $raw ) {
		// Everything before the first section heading.
		$head = preg_split( '/^==\s*[^=]+\s*==/m', $raw );

		if ( ! is_array( $head ) || ! isset( $head[0] ) ) {
			return '';
		}

		$lines = explode( "\n", $head[0] );
		$seen  = false;

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			// Skip the === Title === line and every Header: value line.
			if ( preg_match( '/^===.*===$/', $line ) ) {
				continue;
			}

			if ( preg_match( '/^[A-Z][A-Za-z ]+:\s/', $line ) ) {
				$seen = true;
				continue;
			}

			if ( $seen ) {
				return sanitize_text_field( $line );
			}
		}

		return '';
	}

	/**
	 * Splits the file into the sections the modal shows, as HTML.
	 *
	 * @since 0.6.0
	 *
	 * @param string $raw Readme contents.
	 * @return array Section slug => HTML.
	 */
	protected static function sections( $raw ) {
		$sections = array();

		if ( ! preg_match_all( '/^==\s*(.+?)\s*==$/m', $raw, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $sections;
		}

		$count = count( $matches[0] );

		for ( $i = 0; $i < $count; $i++ ) {
			$heading = trim( $matches[1][ $i ][0] );
			$start   = $matches[0][ $i ][1] + strlen( $matches[0][ $i ][0] );
			$end     = ( $i + 1 < $count ) ? $matches[0][ $i + 1 ][1] : strlen( $raw );
			$body    = trim( substr( $raw, $start, $end - $start ) );

			$slug = array_search( $heading, self::SECTIONS, true );

			if ( false === $slug ) {
				continue;
			}

			$sections[ $slug ] = self::to_html( $body );
		}

		return $sections;
	}

	/**
	 * Converts a readme section to the limited HTML the modal renders.
	 *
	 * Handles the four constructs this readme uses: = subheadings =, * bullets,
	 * 1. numbered items, and paragraphs. Everything is escaped before any tag
	 * is added, so nothing in readme.txt can inject markup into the modal.
	 *
	 * @since 0.6.0
	 *
	 * @param string $body Section body.
	 * @return string
	 */
	protected static function to_html( $body ) {
		$out     = '';
		$list    = '';
		$ordered = false;

		/**
		 * Closes any open list.
		 */
		$close = function () use ( &$out, &$list, &$ordered ) {
			if ( '' === $list ) {
				return;
			}

			$tag  = $ordered ? 'ol' : 'ul';
			$out .= '<' . $tag . '>' . $list . '</' . $tag . '>';
			$list = '';
		};

		$paragraph = '';

		/**
		 * Flushes any buffered paragraph text.
		 */
		$flush = function () use ( &$out, &$paragraph ) {
			$paragraph = trim( $paragraph );

			if ( '' === $paragraph ) {
				return;
			}

			$out      .= '<p>' . self::inline( $paragraph ) . '</p>';
			$paragraph = '';
		};

		foreach ( explode( "\n", $body ) as $line ) {
			$trimmed = trim( $line );

			if ( '' === $trimmed ) {
				$flush();
				$close();
				continue;
			}

			// = Subheading =
			if ( preg_match( '/^=\s*(.+?)\s*=$/', $trimmed, $m ) ) {
				$flush();
				$close();
				$out .= '<h4>' . esc_html( $m[1] ) . '</h4>';
				continue;
			}

			// * bullet
			if ( preg_match( '/^\*\s+(.+)$/', $trimmed, $m ) ) {
				$flush();

				if ( $ordered ) {
					$close();
				}

				$ordered = false;
				$list   .= '<li>' . self::inline( $m[1] ) . '</li>';
				continue;
			}

			// 1. numbered item
			if ( preg_match( '/^\d+\.\s+(.+)$/', $trimmed, $m ) ) {
				$flush();

				if ( ! $ordered && '' !== $list ) {
					$close();
				}

				$ordered = true;
				$list   .= '<li>' . self::inline( $m[1] ) . '</li>';
				continue;
			}

			$close();
			$paragraph .= ' ' . $trimmed;
		}

		$flush();
		$close();

		return $out;
	}

	/**
	 * Escapes a line, then restores the inline markup the readme format allows.
	 *
	 * Escaping first means a stray angle bracket in the readme cannot become a
	 * tag. Only links, bold and code are put back, and link targets are run
	 * through esc_url().
	 *
	 * @since 0.6.0
	 *
	 * @param string $text Raw line.
	 * @return string
	 */
	protected static function inline( $text ) {
		$text = esc_html( trim( $text ) );

		// Markdown links, written as a label in square brackets then the URL in parentheses.
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\((https?:[^)\s]+)\)/',
			static function ( $m ) {
				return '<a href="' . esc_url( html_entity_decode( $m[2] ) ) . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>';
			},
			$text
		);

		// Bold, written between double asterisks.
		$text = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );

		// Inline code, written between backticks.
		$text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );

		return $text;
	}
}
