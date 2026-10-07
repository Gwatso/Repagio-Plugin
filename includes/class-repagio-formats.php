<?php
/**
 * Output formats and tones.
 *
 * One place that knows how the plugin's own format slugs, the labels shown to
 * the site owner, and the output_type values the API expects line up.
 *
 * @package Repagio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes what a post can be repurposed into, and how it should sound.
 *
 * The array keys are the slugs stored in the _repagio_converted post meta.
 * They are deliberately the same slugs the scanner already reads, so recording
 * a conversion needs no change to the scanner and no migration of existing
 * meta. The 'api' value is the vocabulary the service speaks, which differs.
 *
 * @since 0.3.0
 */
class Repagio_Formats {

	/**
	 * Tone used when none is chosen.
	 *
	 * @var string
	 */
	const DEFAULT_TONE = 'professional';

	/**
	 * Every output format, keyed by the slug stored in post meta.
	 *
	 * @since 0.3.0
	 *
	 * @return array[] {
	 *     @type string $api     output_type value the API expects.
	 *     @type string $label   Name shown in the format selector.
	 *     @type bool   $keyword Whether a target keyword applies.
	 * }
	 */
	public static function all() {
		return array(
			'seo_blog'      => array(
				'api'     => 'blog',
				'label'   => __( 'Blog Post', 'repagio' ),
				'keyword' => true,
			),
			'linkedin_post' => array(
				'api'     => 'linkedin',
				'label'   => __( 'LinkedIn Post', 'repagio' ),
				'keyword' => false,
			),
			'x_thread'      => array(
				'api'     => 'twitter',
				'label'   => __( 'X Thread', 'repagio' ),
				'keyword' => false,
			),
			'newsletter'    => array(
				'api'     => 'newsletter',
				'label'   => __( 'Newsletter', 'repagio' ),
				'keyword' => false,
			),
		);
	}

	/**
	 * Whether a slug names a format this plugin can request.
	 *
	 * @since 0.3.0
	 *
	 * @param string $format Format slug.
	 * @return bool
	 */
	public static function exists( $format ) {
		return array_key_exists( (string) $format, self::all() );
	}

	/**
	 * The output_type the API expects for a format slug.
	 *
	 * @since 0.3.0
	 *
	 * @param string $format Format slug.
	 * @return string Empty string when the slug is unknown.
	 */
	public static function api_type( $format ) {
		$formats = self::all();

		return isset( $formats[ $format ] ) ? $formats[ $format ]['api'] : '';
	}

	/**
	 * The label shown for a format slug.
	 *
	 * @since 0.3.0
	 *
	 * @param string $format Format slug.
	 * @return string
	 */
	public static function label( $format ) {
		$formats = self::all();

		return isset( $formats[ $format ] ) ? $formats[ $format ]['label'] : (string) $format;
	}

	/**
	 * Whether a format takes an optional target keyword.
	 *
	 * @since 0.3.0
	 *
	 * @param string $format Format slug.
	 * @return bool
	 */
	public static function takes_keyword( $format ) {
		$formats = self::all();

		return isset( $formats[ $format ] ) && ! empty( $formats[ $format ]['keyword'] );
	}

	/**
	 * The output_type values the API accepts.
	 *
	 * @since 0.3.0
	 *
	 * @return string[]
	 */
	public static function api_types() {
		return array_values( wp_list_pluck( self::all(), 'api' ) );
	}

	/**
	 * Every supported tone, keyed by the value sent to the API.
	 *
	 * @since 0.3.0
	 *
	 * @return string[] Tone slug => label.
	 */
	public static function tones() {
		return array(
			'professional'   => __( 'Professional', 'repagio' ),
			'conversational' => __( 'Conversational', 'repagio' ),
			'authoritative'  => __( 'Authoritative', 'repagio' ),
			'casual'         => __( 'Casual', 'repagio' ),
			'inspirational'  => __( 'Inspirational', 'repagio' ),
		);
	}

	/**
	 * Whether a slug names a supported tone.
	 *
	 * @since 0.3.0
	 *
	 * @param string $tone Tone slug.
	 * @return bool
	 */
	public static function tone_exists( $tone ) {
		return array_key_exists( (string) $tone, self::tones() );
	}
}
