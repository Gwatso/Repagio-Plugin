<?php
/**
 * Turns post content into the plain text the generation endpoint accepts.
 *
 * @package Repagify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extracts, measures and trims the text sent to the API.
 *
 * @since 0.3.0
 */
class Repagify_Content {

	/**
	 * Shortest text the API will accept, in characters.
	 *
	 * @var int
	 */
	const MIN_CHARS = 100;

	/**
	 * Longest text the API will accept, in characters.
	 *
	 * @var int
	 */
	const MAX_CHARS = 50000;

	/**
	 * Prepares a post for generation.
	 *
	 * @since 0.3.0
	 *
	 * @param int|WP_Post $post Post to read.
	 * @return array|WP_Error {
	 *     Extracted text and what had to be done to it.
	 *
	 *     @type string $text            Text to send.
	 *     @type int    $length          Characters being sent.
	 *     @type int    $original_length Characters before any truncation.
	 *     @type int    $word_count      Words being sent.
	 *     @type bool   $truncated       Whether the post had to be cut short.
	 * }
	 */
	public static function prepare( $post ) {
		$post = get_post( $post );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'repagify_no_post',
				__( 'That post could not be found.', 'repagify' )
			);
		}

		$text     = self::extract_text( $post->post_content );
		$original = self::length( $text );

		if ( $original < self::MIN_CHARS ) {
			return new WP_Error(
				'repagify_too_short',
				sprintf(
					/* translators: 1: characters found, 2: minimum characters required. */
					__( 'This post is too short to repurpose. It has %1$s characters of text and Repagify needs at least %2$s. Add more to the post, or pick a longer one.', 'repagify' ),
					number_format_i18n( $original ),
					number_format_i18n( self::MIN_CHARS )
				),
				array(
					'length'  => $original,
					'minimum' => self::MIN_CHARS,
				)
			);
		}

		$truncated = false;

		if ( $original > self::MAX_CHARS ) {
			$text      = self::truncate( $text, self::MAX_CHARS );
			$truncated = true;
		}

		return array(
			'text'            => $text,
			'length'          => self::length( $text ),
			'original_length' => $original,
			'word_count'      => self::count_words( $text ),
			'truncated'       => $truncated,
		);
	}

	/**
	 * Reduces post content to plain text, keeping paragraph breaks.
	 *
	 * Block level tags become newlines before the tags are stripped, so
	 * paragraphs survive as blank lines instead of running together into one
	 * wall of text that the model would read as a single thought.
	 *
	 * @since 0.3.0
	 *
	 * @param string $content Raw post_content.
	 * @return string
	 */
	public static function extract_text( $content ) {
		$content = (string) $content;

		if ( '' === $content ) {
			return '';
		}

		$text = strip_shortcodes( $content );

		// Script and style bodies would otherwise survive as text.
		$text = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $text );

		// Mark the breaks that matter before the tags carrying them are lost.
		$text = preg_replace( '#<br\s*/?>#i', "\n", (string) $text );
		$text = preg_replace(
			'#</(p|div|section|article|h[1-6]|li|tr|blockquote|pre|figure|ul|ol|table)\s*>#i',
			"\n\n",
			(string) $text
		);

		// strip_tags(), which this wraps, also removes HTML comments, so block
		// delimiters go with it.
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES, get_bloginfo( 'charset' ) );

		return self::normalise( $text );
	}

	/**
	 * Collapses runs of whitespace without losing paragraph breaks.
	 *
	 * @since 0.3.0
	 *
	 * @param string $text Text to tidy.
	 * @return string
	 */
	protected static function normalise( $text ) {
		// Non-breaking spaces are not matched by \s in every build.
		$text = str_replace( array( "\xC2\xA0", "\r\n", "\r" ), array( ' ', "\n", "\n" ), (string) $text );

		// Spaces and tabs collapse; newlines are handled separately.
		$text = preg_replace( '/[^\S\n]+/u', ' ', $text );

		// Trailing and leading spaces on each line.
		$text = preg_replace( '/ *\n */u', "\n", (string) $text );

		// Three or more newlines is still just a paragraph break.
		$text = preg_replace( '/\n{3,}/u', "\n\n", (string) $text );

		return trim( (string) $text );
	}

	/**
	 * Cuts text to a character budget without splitting a word.
	 *
	 * @since 0.3.0
	 *
	 * @param string $text  Text to cut.
	 * @param int    $limit Maximum characters.
	 * @return string
	 */
	protected static function truncate( $text, $limit ) {
		$limit = (int) $limit;

		if ( self::length( $text ) <= $limit ) {
			return $text;
		}

		$cut = self::substr( $text, 0, $limit );

		// Step back to the last whitespace so the final word stays whole.
		if ( preg_match( '/^(.*)\s\S*$/su', $cut, $matches ) ) {
			$trimmed = rtrim( $matches[1] );

			// Only honour the word boundary if it does not throw most of the
			// text away, which a single enormous "word" would cause.
			if ( self::length( $trimmed ) >= (int) ( $limit * 0.9 ) ) {
				$cut = $trimmed;
			}
		}

		return rtrim( $cut );
	}

	/**
	 * Counts words in extracted text.
	 *
	 * @since 0.3.0
	 *
	 * @param string $text Extracted text.
	 * @return int
	 */
	public static function count_words( $text ) {
		$text = trim( (string) $text );

		if ( '' === $text ) {
			return 0;
		}

		$words = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $words ) ? count( $words ) : 0;
	}

	/**
	 * Character length, multibyte aware.
	 *
	 * @since 0.3.0
	 *
	 * @param string $text Text to measure.
	 * @return int
	 */
	public static function length( $text ) {
		return function_exists( 'mb_strlen' )
			? (int) mb_strlen( (string) $text, 'UTF-8' )
			: strlen( (string) $text );
	}

	/**
	 * Substring, multibyte aware.
	 *
	 * @since 0.3.0
	 *
	 * @param string $text   Text to cut.
	 * @param int    $start  Start offset.
	 * @param int    $length Characters to take.
	 * @return string
	 */
	protected static function substr( $text, $start, $length ) {
		return function_exists( 'mb_substr' )
			? (string) mb_substr( (string) $text, $start, $length, 'UTF-8' )
			: substr( (string) $text, $start, $length );
	}
}
