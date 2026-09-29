<?php
/**
 * Plan tier and generation quota.
 *
 * Reads GET /me, normalises the four plan tiers into one shape, and answers
 * the questions the dashboard and the generate flow need: how many generations
 * are left, whether the account is out, and what to offer when it is.
 *
 * @package Repagify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Caches and interprets the account's plan and remaining quota.
 *
 * @since 0.4.0
 */
class Repagify_Quota {

	/**
	 * Transient holding the last successful /me response.
	 *
	 * @var string
	 */
	const TRANSIENT = 'repagify_account_cache';

	/**
	 * Transient marking a recent failed lookup.
	 *
	 * Without it, an unreachable service would be retried on every dashboard
	 * load, and every load would wait for the timeout before rendering.
	 *
	 * @var string
	 */
	const FAILURE_TRANSIENT = 'repagify_account_failure';

	/**
	 * How long a successful lookup is trusted, in seconds.
	 *
	 * @var int
	 */
	const CACHE_TTL = 300;

	/**
	 * How long a failed lookup suppresses retries, in seconds.
	 *
	 * @var int
	 */
	const FAILURE_TTL = 60;

	/**
	 * Plan tiers.
	 *
	 * @var string
	 */
	const TIER_FREE    = 'free';
	const TIER_CREATOR = 'creator';
	const TIER_PRO     = 'pro';
	const TIER_AGENCY  = 'agency';

	/**
	 * Quota shapes the service reports.
	 *
	 * @var string
	 */
	const LIMIT_LIFETIME  = 'lifetime';
	const LIMIT_MONTHLY   = 'monthly';
	const LIMIT_UNLIMITED = 'unlimited';

	/**
	 * Pricing page, whose anchors the upgrade buttons point at.
	 *
	 * @var string
	 */
	const PRICING_URL = 'https://repagify.afriflare.com/pricing';

	/**
	 * Returns the account, from cache when it is warm.
	 *
	 * Never calls out when no key is saved, and never more than once per
	 * CACHE_TTL, so opening the dashboard does not mean an HTTP request.
	 *
	 * @since 0.4.0
	 *
	 * @param bool $force Skip the cache and ask the service again.
	 * @return array|null Normalised account, or null when it cannot be read.
	 */
	public static function get( $force = false ) {
		if ( ! Repagify_Settings::has_api_key() ) {
			return null;
		}

		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT );

			if ( is_array( $cached ) ) {
				return $cached;
			}

			// A recent failure means do not try again yet.
			if ( get_transient( self::FAILURE_TRANSIENT ) ) {
				return null;
			}
		}

		$api      = new Repagify_API();
		$response = $api->get_me( Repagify_API::ACCOUNT_TIMEOUT );

		if ( is_wp_error( $response ) ) {
			set_transient( self::FAILURE_TRANSIENT, $response->get_error_code(), self::FAILURE_TTL );

			return null;
		}

		$account = self::normalise( $response );

		delete_transient( self::FAILURE_TRANSIENT );
		set_transient( self::TRANSIENT, $account, self::CACHE_TTL );

		return $account;
	}

	/**
	 * Re-reads the account now, ignoring the cache.
	 *
	 * Called straight after a generation so the displayed count is the real
	 * one rather than the pre-generation figure.
	 *
	 * @since 0.4.0
	 *
	 * @return array|null
	 */
	public static function refresh() {
		return self::get( true );
	}

	/**
	 * Caches an account payload the caller already has.
	 *
	 * Lets the Test Connection button, which has just fetched /me, populate the
	 * cache instead of provoking a second identical request.
	 *
	 * @since 0.4.0
	 *
	 * @param array $payload Raw /me response.
	 * @return array The normalised account.
	 */
	public static function store( $payload ) {
		$account = self::normalise( $payload );

		delete_transient( self::FAILURE_TRANSIENT );
		set_transient( self::TRANSIENT, $account, self::CACHE_TTL );

		return $account;
	}

	/**
	 * Drops the cached account.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public static function clear() {
		delete_transient( self::TRANSIENT );
		delete_transient( self::FAILURE_TRANSIENT );
	}

	/**
	 * Reduces one generation from the cached count without calling out.
	 *
	 * The fallback for when the post-generation refresh fails: the generation
	 * definitely happened, so showing the old count would overstate what is
	 * left. Never drops below zero and never touches an unlimited plan.
	 *
	 * @since 0.4.0
	 *
	 * @return array|null The adjusted account.
	 */
	public static function decrement() {
		$account = get_transient( self::TRANSIENT );

		if ( ! is_array( $account ) || self::is_unlimited( $account ) ) {
			return is_array( $account ) ? $account : null;
		}

		$account['used'] = (int) $account['used'] + 1;

		if ( null !== $account['remaining'] ) {
			$account['remaining'] = max( 0, (int) $account['remaining'] - 1 );
		}

		set_transient( self::TRANSIENT, $account, self::CACHE_TTL );

		return $account;
	}

	/**
	 * Turns a raw /me payload into the shape the rest of the plugin uses.
	 *
	 * Tolerant about where values live and what they are called, because the
	 * service is still changing shape around this feature.
	 *
	 * @since 0.4.0
	 *
	 * @param array $payload Decoded /me response.
	 * @return array {
	 *     @type string   $tier       free, creator, pro or agency.
	 *     @type string   $tier_label Readable tier name.
	 *     @type string   $limit_type lifetime, monthly or unlimited.
	 *     @type int      $used       Generations used.
	 *     @type int|null $limit      Cap, or null when unlimited.
	 *     @type int|null $remaining  Left, or null when unlimited.
	 *     @type string   $resets_at  Reset date, or '' when not supplied.
	 * }
	 */
	public static function normalise( $payload ) {
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		// Unwrap { data: {...} } style envelopes.
		foreach ( array( 'data', 'account', 'user' ) as $wrapper ) {
			if ( isset( $payload[ $wrapper ] ) && is_array( $payload[ $wrapper ] ) ) {
				$payload = array_merge( $payload, $payload[ $wrapper ] );
				break;
			}
		}

		$tier       = self::read_tier( $payload );
		$used       = self::read_int( $payload, array( 'conversions_used', 'used', 'usage' ) );
		$limit      = self::read_int( $payload, array( 'conversion_limit', 'conversions_limit', 'limit', 'quota' ) );
		$remaining  = self::read_int( $payload, array( 'conversions_remaining', 'remaining', 'credits_remaining' ) );
		$limit_type = self::read_limit_type( $payload, $limit );

		if ( self::LIMIT_UNLIMITED === $limit_type ) {
			$limit     = null;
			$remaining = null;
		} elseif ( null === $remaining && null !== $limit ) {
			// Derive what the service did not state.
			$remaining = max( 0, $limit - (int) $used );
		}

		return array(
			'tier'       => $tier,
			'tier_label' => self::tier_label( $tier ),
			'limit_type' => $limit_type,
			'used'       => (int) $used,
			'limit'      => $limit,
			'remaining'  => $remaining,
			'resets_at'  => self::read_reset( $payload ),
		);
	}

	/**
	 * Whether this account has no generation cap.
	 *
	 * @since 0.4.0
	 *
	 * @param array|null $account Normalised account.
	 * @return bool
	 */
	public static function is_unlimited( $account ) {
		if ( ! is_array( $account ) ) {
			return false;
		}

		return self::LIMIT_UNLIMITED === $account['limit_type'] || null === $account['remaining'];
	}

	/**
	 * Whether a generation can be attempted.
	 *
	 * Unknown accounts return true: a failed lookup must not lock someone out
	 * of a feature they are entitled to. The API stays the authority.
	 *
	 * @since 0.4.0
	 *
	 * @param array|null $account Normalised account.
	 * @return bool
	 */
	public static function has_quota( $account ) {
		if ( ! is_array( $account ) ) {
			return true;
		}

		if ( self::is_unlimited( $account ) ) {
			return true;
		}

		return (int) $account['remaining'] > 0;
	}

	/**
	 * Which band the remaining count falls into, for colouring.
	 *
	 * @since 0.4.0
	 *
	 * @param array|null $account Normalised account.
	 * @return string neutral, normal, low or empty.
	 */
	public static function band( $account ) {
		if ( ! is_array( $account ) || self::is_unlimited( $account ) ) {
			return 'neutral';
		}

		$remaining = (int) $account['remaining'];

		if ( $remaining <= 0 ) {
			return 'empty';
		}

		$limit = (int) $account['limit'];

		if ( $limit <= 0 ) {
			return 'normal';
		}

		return ( $remaining / $limit ) <= 0.25 ? 'low' : 'normal';
	}

	/**
	 * The quota sentence shown in the dashboard status strip.
	 *
	 * @since 0.4.0
	 *
	 * @param array|null $account Normalised account.
	 * @return string
	 */
	public static function quota_phrase( $account ) {
		if ( ! is_array( $account ) ) {
			return __( 'Plan details unavailable', 'repagify' );
		}

		if ( self::is_unlimited( $account ) ) {
			return __( 'Unlimited generations', 'repagify' );
		}

		$remaining = (int) $account['remaining'];
		$limit     = (int) $account['limit'];

		if ( $remaining <= 0 ) {
			return sprintf(
				/* translators: %s: the account's generation allowance. */
				__( '0 of %s generations remaining', 'repagify' ),
				number_format_i18n( $limit )
			);
		}

		if ( self::LIMIT_MONTHLY === $account['limit_type'] ) {
			return sprintf(
				/* translators: 1: generations left, 2: the plan's monthly cap. */
				__( '%1$s of %2$s generations left this month', 'repagify' ),
				number_format_i18n( $remaining ),
				number_format_i18n( $limit )
			);
		}

		return sprintf(
			/* translators: 1: generations left, 2: the account's lifetime cap. */
			__( '%1$s of %2$s free generations left', 'repagify' ),
			number_format_i18n( $remaining ),
			number_format_i18n( $limit )
		);
	}

	/**
	 * What to offer someone who has run out, by tier.
	 *
	 * Pro and Agency are unlimited, so a limit there is a fault rather than a
	 * sales opportunity: they are pointed at support, never at an upgrade.
	 *
	 * @since 0.4.0
	 *
	 * @param array|null $account       Normalised account.
	 * @param string     $service_message Message the API supplied, if any.
	 * @return array {
	 *     @type string $title    Heading.
	 *     @type string $message  Body.
	 *     @type string $note     Secondary line, may be empty.
	 *     @type string $url      Button target, empty when there is no button.
	 *     @type string $label    Button text, empty when there is no button.
	 * }
	 */
	public static function upgrade_offer( $account, $service_message = '' ) {
		$tier = is_array( $account ) ? $account['tier'] : '';

		if ( self::TIER_PRO === $tier || self::TIER_AGENCY === $tier ) {
			return array(
				'title'   => __( 'Generation could not be completed', 'repagify' ),
				'message' => '' !== $service_message
					? $service_message
					: __( 'Your Repagify plan includes unlimited generations, so this limit should not apply to your account. Nothing was charged and nothing on your site changed.', 'repagify' ),
				'note'    => __( 'If this keeps happening, contact Repagify support with the time it occurred.', 'repagify' ),
				'url'     => '',
				'label'   => '',
			);
		}

		if ( self::TIER_CREATOR === $tier ) {
			return array(
				'title'   => __( 'Your Repagify account has no generations left this month', 'repagify' ),
				'message' => __( 'This account has used all 20 generations its Repagify Creator plan includes this month. The service’s Pro plan includes unlimited generations, a more capable model, and brand voice matching for $19.', 'repagify' ),
				'note'    => self::reset_note( $account ),
				'url'     => self::PRICING_URL . '#pro',
				'label'   => __( 'View Repagify Pro plan', 'repagify' ),
			);
		}

		// Free, and anything unrecognised, gets the entry-level offer.
		return array(
			'title'   => __( 'Your Repagify account has no generations left', 'repagify' ),
			'message' => __( 'This account has used both of the free generations its Repagify plan includes. Generation is performed by the Repagify web service, whose Creator plan includes 20 generations a month for $9.', 'repagify' ),
			'note'    => self::TIER_FREE === $tier ? '' : self::reset_note( $account ),
			'url'     => self::PRICING_URL . '#creator',
			'label'   => __( 'View Repagify Creator plan', 'repagify' ),
		);
	}

	/**
	 * When the allowance comes back, if that is knowable.
	 *
	 * @since 0.4.0
	 *
	 * @param array|null $account Normalised account.
	 * @return string
	 */
	protected static function reset_note( $account ) {
		$resets = is_array( $account ) ? $account['resets_at'] : '';

		if ( '' !== $resets ) {
			$timestamp = strtotime( $resets );

			if ( false !== $timestamp ) {
				return sprintf(
					/* translators: %s: date the generation allowance resets. */
					__( 'Your Repagify allowance resets on %s.', 'repagify' ),
					date_i18n( get_option( 'date_format' ), $timestamp )
				);
			}
		}

		return __( 'Your Repagify allowance resets at the start of next month.', 'repagify' );
	}

	/**
	 * Readable name for a tier slug.
	 *
	 * @since 0.4.0
	 *
	 * @param string $tier Tier slug.
	 * @return string
	 */
	public static function tier_label( $tier ) {
		$labels = array(
			self::TIER_FREE    => __( 'Free', 'repagify' ),
			self::TIER_CREATOR => __( 'Creator', 'repagify' ),
			self::TIER_PRO     => __( 'Pro', 'repagify' ),
			self::TIER_AGENCY  => __( 'Agency', 'repagify' ),
		);

		if ( isset( $labels[ $tier ] ) ) {
			return $labels[ $tier ];
		}

		if ( '' === $tier ) {
			return __( 'Unknown', 'repagify' );
		}

		return ucwords( str_replace( array( '_', '-' ), ' ', $tier ) );
	}

	/**
	 * Reads the plan tier slug.
	 *
	 * @since 0.4.0
	 *
	 * @param array $payload Decoded /me response.
	 * @return string
	 */
	protected static function read_tier( $payload ) {
		foreach ( array( 'plan_tier', 'plan', 'tier', 'plan_name' ) as $key ) {
			if ( isset( $payload[ $key ] ) && is_scalar( $payload[ $key ] ) && '' !== (string) $payload[ $key ] ) {
				return sanitize_key( (string) $payload[ $key ] );
			}
		}

		return '';
	}

	/**
	 * Reads the quota shape, inferring it when the service does not say.
	 *
	 * @since 0.4.0
	 *
	 * @param array    $payload Decoded /me response.
	 * @param int|null $limit   Cap already read from the payload.
	 * @return string
	 */
	protected static function read_limit_type( $payload, $limit ) {
		$allowed = array( self::LIMIT_LIFETIME, self::LIMIT_MONTHLY, self::LIMIT_UNLIMITED );

		foreach ( array( 'limit_type', 'quota_type', 'period' ) as $key ) {
			if ( ! isset( $payload[ $key ] ) || ! is_scalar( $payload[ $key ] ) ) {
				continue;
			}

			$value = sanitize_key( (string) $payload[ $key ] );

			if ( in_array( $value, $allowed, true ) ) {
				return $value;
			}
		}

		// No cap stated at all reads as unlimited; a cap with no period reads
		// as monthly, which is the common case.
		return null === $limit ? self::LIMIT_UNLIMITED : self::LIMIT_MONTHLY;
	}

	/**
	 * Reads the reset date, if the service supplies one.
	 *
	 * @since 0.4.0
	 *
	 * @param array $payload Decoded /me response.
	 * @return string Empty string when absent.
	 */
	protected static function read_reset( $payload ) {
		foreach ( array( 'resets_at', 'reset_at', 'period_end', 'current_period_end', 'renews_at' ) as $key ) {
			if ( isset( $payload[ $key ] ) && is_scalar( $payload[ $key ] ) && '' !== (string) $payload[ $key ] ) {
				return sanitize_text_field( (string) $payload[ $key ] );
			}
		}

		return '';
	}

	/**
	 * Reads the first key holding a number.
	 *
	 * @since 0.4.0
	 *
	 * @param array    $payload Decoded /me response.
	 * @param string[] $keys    Keys to try, in order.
	 * @return int|null
	 */
	protected static function read_int( $payload, $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $payload[ $key ] ) && is_numeric( $payload[ $key ] ) ) {
				return (int) $payload[ $key ];
			}
		}

		return null;
	}
}
