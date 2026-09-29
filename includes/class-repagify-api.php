<?php
/**
 * HTTP client for the Repagify service.
 *
 * Every outbound request in this plugin goes through this class. No other file
 * may call the HTTP API directly.
 *
 * @package Repagify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Talks to the Repagify REST API over the WordPress HTTP API.
 *
 * Every failure comes back as a WP_Error carrying a message fit to show an
 * administrator, plus data the admin layer uses to offer the right next step:
 * the HTTP status, the service's own error code, a Retry-After delay when the
 * service sets one, and a link where following one would help.
 *
 * @since 0.1.0
 */
class Repagify_API {

	/**
	 * Seconds to wait for a response before giving up.
	 *
	 * @var int
	 */
	const TIMEOUT = 30;

	/**
	 * Seconds to allow for a generation, which runs a model and is slow.
	 *
	 * @var int
	 */
	const GENERATE_TIMEOUT = 60;

	/**
	 * Seconds to allow for the account lookup behind the dashboard's quota
	 * strip. Short on purpose: a slow service must not hold up a page whose
	 * main content does not need the network at all.
	 *
	 * @var int
	 */
	const ACCOUNT_TIMEOUT = 10;

	/**
	 * API key used for Bearer authentication.
	 *
	 * @var string
	 */
	protected $api_key;

	/**
	 * API base URL, without a trailing slash.
	 *
	 * @var string
	 */
	protected $api_url;

	/**
	 * Builds a client.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $api_key Key to authenticate with. Defaults to the stored key.
	 * @param string|null $api_url Base URL to call. Defaults to the stored URL.
	 */
	public function __construct( $api_key = null, $api_url = null ) {
		$this->api_key = null === $api_key
			? Repagify_Settings::get_api_key()
			: (string) $api_key;

		$this->api_url = null === $api_url
			? Repagify_Settings::get_api_url()
			: untrailingslashit( (string) $api_url );
	}

	/**
	 * Fetches the account: plan tier, usage and conversions remaining.
	 *
	 * @since 0.1.0
	 *
	 * @param int $timeout Seconds to wait. Defaults to self::TIMEOUT.
	 * @return array|WP_Error Decoded account payload, or an error.
	 */
	public function get_me( $timeout = 0 ) {
		return $this->request( 'GET', '/me', array(), $timeout );
	}

	/**
	 * Asks the service to repurpose a piece of text.
	 *
	 * The arguments are validated here rather than relied on being right, so a
	 * malformed request fails locally with a clear message instead of spending
	 * a round trip to be told the same thing.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text        Source text, already stripped of markup.
	 * @param string $output_type API output type: blog, linkedin, twitter or newsletter.
	 * @param string $tone        Requested tone, e.g. 'professional'.
	 * @param array  $args        {
	 *     Optional extras.
	 *
	 *     @type string $keyword   Target keyword, for blog output.
	 *     @type string $source_id Saved source to attribute this to.
	 * }
	 * @return array|WP_Error Decoded generation payload, or an error.
	 */
	public function generate( $text, $output_type, $tone, $args = array() ) {
		$text   = trim( (string) $text );
		$length = Repagify_Content::length( $text );

		if ( $length < Repagify_Content::MIN_CHARS ) {
			return new WP_Error(
				'repagify_too_short',
				sprintf(
					/* translators: %s: minimum number of characters. */
					__( 'There is not enough text to repurpose. Repagify needs at least %s characters.', 'repagify' ),
					number_format_i18n( Repagify_Content::MIN_CHARS )
				)
			);
		}

		if ( $length > Repagify_Content::MAX_CHARS ) {
			return new WP_Error(
				'repagify_too_long',
				sprintf(
					/* translators: %s: maximum number of characters. */
					__( 'That is more than Repagify can convert at once. The limit is %s characters.', 'repagify' ),
					number_format_i18n( Repagify_Content::MAX_CHARS )
				)
			);
		}

		$output_type = (string) $output_type;

		if ( ! in_array( $output_type, Repagify_Formats::api_types(), true ) ) {
			return new WP_Error(
				'repagify_bad_output_type',
				__( 'That is not an output format Repagify can produce.', 'repagify' )
			);
		}

		$tone = (string) $tone;

		if ( ! Repagify_Formats::tone_exists( $tone ) ) {
			$tone = Repagify_Formats::DEFAULT_TONE;
		}

		$body = array(
			'text'        => $text,
			'output_type' => $output_type,
			'tone'        => $tone,
		);

		// Both extras are optional and are left out entirely when unused.
		if ( ! empty( $args['keyword'] ) ) {
			$body['keyword'] = (string) $args['keyword'];
		}

		if ( ! empty( $args['source_id'] ) ) {
			$body['source_id'] = (string) $args['source_id'];
		}

		return $this->request( 'POST', '/generate', $body, self::GENERATE_TIMEOUT );
	}

	/**
	 * Performs a request against the Repagify API.
	 *
	 * @since 0.1.0
	 *
	 * @param string $method   HTTP method, 'GET' or 'POST'.
	 * @param string $endpoint Path beginning with a slash, e.g. '/me'.
	 * @param array  $body     Payload, JSON encoded for POST requests.
	 * @param int    $timeout  Seconds to wait. Defaults to self::TIMEOUT.
	 * @return array|WP_Error Decoded response body, or an error.
	 */
	protected function request( $method, $endpoint, $body = array(), $timeout = 0 ) {
		if ( '' === $this->api_key ) {
			return new WP_Error(
				'repagify_no_api_key',
				__( 'Add your Repagify API key on the settings screen first.', 'repagify' ),
				array(
					'action_url'   => Repagify_Settings::settings_url(),
					'action_label' => __( 'Open settings', 'repagify' ),
				)
			);
		}

		$url = $this->api_url . $endpoint;

		$args = array(
			'timeout' => $timeout > 0 ? (int) $timeout : self::TIMEOUT,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Accept'        => 'application/json',
			),
		);

		if ( 'POST' === $method ) {
			$args['headers']['Content-Type'] = 'application/json; charset=utf-8';
			$args['body']                    = wp_json_encode( $body );

			$response = wp_remote_post( $url, $args );
		} else {
			$response = wp_remote_get( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			return $this->transport_error( $response, $args['timeout'] );
		}

		return $this->handle_response( $response, $endpoint );
	}

	/**
	 * Describes a request that never reached the service.
	 *
	 * Nothing here is the site owner's doing, so the message says so rather
	 * than asking them to check something they cannot have got wrong.
	 *
	 * @since 0.3.0
	 *
	 * @param WP_Error $error   Transport error.
	 * @param int      $timeout Seconds the request was allowed.
	 * @return WP_Error
	 */
	protected function transport_error( $error, $timeout ) {
		$raw       = $error->get_error_message();
		$timed_out = ( false !== stripos( $raw, 'timed out' ) || false !== stripos( $raw, 'timeout' ) );

		if ( $timed_out ) {
			return new WP_Error(
				'repagify_timeout',
				sprintf(
					/* translators: %s: number of seconds waited. */
					__( 'Repagify did not answer within %s seconds. The service may be busy — nothing on your site was changed, so it is safe to try again.', 'repagify' ),
					number_format_i18n( (int) $timeout )
				),
				array( 'timeout' => (int) $timeout )
			);
		}

		return new WP_Error(
			'repagify_unreachable',
			sprintf(
				/* translators: %s: transport level error message. */
				__( 'Could not reach Repagify: %s. This is a connection problem between your server and the service, not something wrong with your content.', 'repagify' ),
				$this->redact( $raw )
			)
		);
	}

	/**
	 * Turns a raw HTTP response into decoded data or a friendly WP_Error.
	 *
	 * @since 0.1.0
	 *
	 * @param array  $response Response from the HTTP API.
	 * @param string $endpoint Endpoint that was requested.
	 * @return array|WP_Error
	 */
	protected function handle_response( $response, $endpoint ) {
		$status  = (int) wp_remote_retrieve_response_code( $response );
		$raw     = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		$api_code = $this->error_code_from( $decoded );
		$api_text = $this->error_message_from( $decoded );

		if ( $status < 400 ) {
			// A wrong path can return the marketing site with HTTP 200, so a
			// success code alone is not enough to trust the body.
			if ( ! is_array( $decoded ) ) {
				return new WP_Error(
					'repagify_bad_response',
					__( 'Repagify returned a response this plugin could not read. Check the API base URL on the settings screen.', 'repagify' ),
					array(
						'status'       => $status,
						'action_url'   => Repagify_Settings::settings_url(),
						'action_label' => __( 'Open settings', 'repagify' ),
					)
				);
			}

			return $decoded;
		}

		switch ( true ) {
			case 400 === $status:
				return new WP_Error(
					'repagify_invalid_request',
					'' !== $api_text
						? $api_text
						: __( 'Repagify could not use this content. It may be too short, too long, or not text it can work with.', 'repagify' ),
					$this->error_data( $status, $api_code )
				);

			case 401 === $status || 403 === $status:
				return new WP_Error(
					'repagify_unauthorized',
					__( 'Repagify rejected the saved API key — it is invalid or has been revoked. Paste a current key on the settings screen and save.', 'repagify' ),
					$this->error_data(
						$status,
						$api_code,
						array(
							'action_url'   => Repagify_Settings::settings_url(),
							'action_label' => __( 'Open settings', 'repagify' ),
						)
					)
				);

			case 402 === $status:
				// The service's own message carries the plan detail and the
				// pricing link, so it is shown rather than replaced.
				return new WP_Error(
					'repagify_limit_reached',
					'' !== $api_text
						? $api_text
						: __( 'You have used every conversion on your Repagify plan.', 'repagify' ),
					$this->error_data( $status, $api_code, $this->link_action_from( $api_text ) )
				);

			case 404 === $status:
				return new WP_Error(
					'repagify_not_found',
					sprintf(
						/* translators: %s: API endpoint path, e.g. /generate. */
						__( 'Repagify does not recognise %s. Check the API base URL on the settings screen.', 'repagify' ),
						$endpoint
					),
					$this->error_data(
						$status,
						$api_code,
						array(
							'action_url'   => Repagify_Settings::settings_url(),
							'action_label' => __( 'Open settings', 'repagify' ),
						)
					)
				);

			case 429 === $status:
				$retry = $this->retry_after( $response );

				return new WP_Error(
					'repagify_rate_limited',
					$this->rate_limit_message( $retry, $api_text ),
					$this->error_data( $status, $api_code, array( 'retry_after' => $retry ) )
				);

			case $status >= 500:
				return new WP_Error(
					'repagify_server_error',
					sprintf(
						/* translators: %d: HTTP status code. */
						__( 'Repagify had a problem on its end (HTTP %d). Nothing on your site was changed — try again in a few minutes.', 'repagify' ),
						$status
					),
					$this->error_data( $status, $api_code )
				);
		}

		return new WP_Error(
			'repagify_request_failed',
			'' !== $api_text
				? $api_text
				: sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Repagify refused the request (HTTP %d).', 'repagify' ),
					$status
				),
			$this->error_data( $status, $api_code )
		);
	}

	/**
	 * Assembles the data carried on an error, for the admin layer to act on.
	 *
	 * @since 0.3.0
	 *
	 * @param int    $status   HTTP status code.
	 * @param string $api_code Error code the service returned.
	 * @param array  $extra    Additional keys to merge in.
	 * @return array
	 */
	protected function error_data( $status, $api_code, $extra = array() ) {
		return array_merge(
			array(
				'status'   => (int) $status,
				'api_code' => (string) $api_code,
			),
			$extra
		);
	}

	/**
	 * Reads the error code out of the service's error envelope.
	 *
	 * The documented shape is { error: { code, message } }.
	 *
	 * @since 0.3.0
	 *
	 * @param mixed $decoded Decoded response body.
	 * @return string Empty string when there is none.
	 */
	protected function error_code_from( $decoded ) {
		if ( isset( $decoded['error']['code'] ) && is_string( $decoded['error']['code'] ) ) {
			return sanitize_key( $decoded['error']['code'] );
		}

		if ( isset( $decoded['code'] ) && is_string( $decoded['code'] ) ) {
			return sanitize_key( $decoded['code'] );
		}

		return '';
	}

	/**
	 * Reads the human-readable message out of the service's error envelope.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $decoded Decoded response body.
	 * @return string Empty string when there is none.
	 */
	protected function error_message_from( $decoded ) {
		if ( ! is_array( $decoded ) ) {
			return '';
		}

		$message = '';

		if ( isset( $decoded['error']['message'] ) && is_string( $decoded['error']['message'] ) ) {
			$message = $decoded['error']['message'];
		} else {
			foreach ( array( 'message', 'error', 'detail' ) as $key ) {
				if ( isset( $decoded[ $key ] ) && is_string( $decoded[ $key ] ) ) {
					$message = $decoded[ $key ];
					break;
				}
			}
		}

		if ( '' === $message ) {
			return '';
		}

		return $this->redact( sanitize_text_field( $message ) );
	}

	/**
	 * Seconds to wait before retrying, from the Retry-After header.
	 *
	 * The header is either a number of seconds or an HTTP date; both are
	 * accepted.
	 *
	 * @since 0.3.0
	 *
	 * @param array $response Response from the HTTP API.
	 * @return int Seconds, or 0 when the header is absent or unusable.
	 */
	protected function retry_after( $response ) {
		$header = trim( (string) wp_remote_retrieve_header( $response, 'retry-after' ) );

		if ( '' === $header ) {
			return 0;
		}

		if ( is_numeric( $header ) ) {
			return max( 0, (int) $header );
		}

		$when = strtotime( $header );

		if ( false === $when ) {
			return 0;
		}

		return max( 0, $when - time() );
	}

	/**
	 * Wording for a rate limited response.
	 *
	 * @since 0.3.0
	 *
	 * @param int    $retry    Seconds to wait, 0 when unknown.
	 * @param string $api_text Message the service supplied.
	 * @return string
	 */
	protected function rate_limit_message( $retry, $api_text ) {
		if ( $retry > 0 ) {
			return sprintf(
				/* translators: %s: human-readable delay, such as "45 seconds" or "2 minutes". */
				__( 'Repagify is rate limiting this site. Try again in %s.', 'repagify' ),
				human_time_diff( time(), time() + $retry )
			);
		}

		if ( '' !== $api_text ) {
			return $api_text;
		}

		return __( 'Repagify is rate limiting this site. Wait a minute and try again.', 'repagify' );
	}

	/**
	 * Pulls a link out of a service message so it can be offered as a button.
	 *
	 * The plan limit message carries a pricing URL. Rendering it as a real link
	 * beside the notice keeps the message itself plain text, which is what the
	 * admin layer escapes it as.
	 *
	 * @since 0.3.0
	 *
	 * @param string $message Message from the service.
	 * @return array Action keys, or an empty array when there is no usable link.
	 */
	protected function link_action_from( $message ) {
		if ( ! preg_match( '#https?://[^\s<>"\']+#i', (string) $message, $matches ) ) {
			return array();
		}

		$url = esc_url_raw( rtrim( $matches[0], '.,);' ) );

		if ( '' === $url ) {
			return array();
		}

		return array(
			'action_url'   => $url,
			'action_label' => __( 'View plans', 'repagify' ),
		);
	}

	/**
	 * Removes the API key from a string before it can be surfaced anywhere.
	 *
	 * Transport errors occasionally echo request headers back. This guarantees
	 * the key cannot leak into an admin notice through that path.
	 *
	 * @since 0.1.0
	 *
	 * @param string $message Message that may contain the key.
	 * @return string
	 */
	protected function redact( $message ) {
		if ( '' === $this->api_key ) {
			return (string) $message;
		}

		return str_replace( $this->api_key, '[redacted]', (string) $message );
	}
}
