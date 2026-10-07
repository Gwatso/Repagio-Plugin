<?php
/**
 * Options storage and Settings API registration.
 *
 * @package Repagio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and reads the Repagio settings.
 *
 * The API key is stored in wp_options but is never rendered in full. The
 * settings form only ever shows a mask, and the input that writes the key is
 * always submitted empty unless the site owner is deliberately replacing it.
 *
 * @since 0.1.0
 */
class Repagio_Settings {

	/**
	 * Option name holding every plugin setting.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'repagio_settings';

	/**
	 * Settings API group name.
	 *
	 * @var string
	 */
	const GROUP = 'repagio_settings_group';

	/**
	 * Slug of the settings page the sections are attached to.
	 *
	 * @var string
	 */
	const PAGE = 'repagio-settings';

	/**
	 * Whether to warn that API keys are not yet available on every plan.
	 *
	 * TEMPORARY. Repagio is opening API keys to Free and Creator accounts,
	 * with per-plan quotas doing the gating instead of a tier wall. The moment
	 * that ships, set this to false — or delete this constant and
	 * key_tier_notice() together, which are the only two places this claim is
	 * made anywhere in the plugin.
	 *
	 * @var bool
	 */
	const KEY_TIER_NOTICE_ENABLED = true;

	/**
	 * Hooks the Settings API registration.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_init', array( __CLASS__, 'seed_defaults' ) );

		// A different key means a different account, so the cached plan and
		// quota must not survive the change.
		add_action( 'update_option_' . self::OPTION_NAME, array( __CLASS__, 'forget_account' ), 10, 2 );
		add_action( 'add_option_' . self::OPTION_NAME, array( __CLASS__, 'forget_account' ) );
	}

	/**
	 * Returns the default value for every setting.
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'api_key' => '',
			'api_url' => REPAGIO_DEFAULT_API_URL,
		);
	}

	/**
	 * Returns every setting, merged over the defaults.
	 *
	 * Never assumes the option row exists or is well formed. A site whose
	 * activation hook never ran — the plugin dropped in over FTP, or activated
	 * before the hook was added — has no row at all, and a half-written option
	 * can hold nulls or nested arrays. Both cases resolve to the defaults here
	 * rather than surfacing as notices further up.
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	public static function all() {
		$defaults = self::defaults();
		$stored   = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$clean = array();

		foreach ( $defaults as $key => $default ) {
			$clean[ $key ] = ( isset( $stored[ $key ] ) && is_scalar( $stored[ $key ] ) )
				? (string) $stored[ $key ]
				: $default;
		}

		return $clean;
	}

	/**
	 * Returns the stored API key.
	 *
	 * Callers must never render, log or transmit this value anywhere other than
	 * the Authorization header built by Repagio_API.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function get_api_key() {
		$settings = self::all();

		return (string) $settings['api_key'];
	}

	/**
	 * Whether an API key has been saved.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public static function has_api_key() {
		return '' !== self::get_api_key();
	}

	/**
	 * Returns a masked preview of the saved key, safe to print.
	 *
	 * Everything but the last four characters is replaced with bullets, so the
	 * site owner can tell which key is saved without the key itself reaching the
	 * page. Short keys are masked completely.
	 *
	 * @since 0.1.0
	 *
	 * @return string Empty string when no key is saved.
	 */
	public static function masked_api_key() {
		$key = self::get_api_key();

		if ( '' === $key ) {
			return '';
		}

		$length = strlen( $key );

		if ( $length < 12 ) {
			return str_repeat( "\xE2\x80\xA2", 12 );
		}

		return str_repeat( "\xE2\x80\xA2", 12 ) . substr( $key, -4 );
	}

	/**
	 * Returns the API base URL, without a trailing slash.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function get_api_url() {
		$settings = self::all();
		$url      = untrailingslashit( trim( (string) $settings['api_url'] ) );

		return '' === $url ? REPAGIO_DEFAULT_API_URL : $url;
	}

	/**
	 * Writes the default options if the plugin has never stored any.
	 *
	 * Runs from the activation hook and again on every admin_init. The second
	 * call is the safety net: activation hooks only fire at the moment a plugin
	 * is switched on, so a site that gained the plugin any other way would
	 * otherwise never get a row. add_option() is a no-op once one exists, so
	 * this cannot overwrite a saved key.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function seed_defaults() {
		if ( false === get_option( self::OPTION_NAME, false ) ) {
			add_option( self::OPTION_NAME, self::defaults() );
		}
	}

	/**
	 * Clears the cached account after the settings change.
	 *
	 * @since 0.4.0
	 *
	 * @param mixed $old Previous option value. Unused for an add.
	 * @param mixed $new New option value.
	 * @return void
	 */
	public static function forget_account( $old = null, $new = null ) {
		unset( $old, $new );

		if ( class_exists( 'Repagio_Quota' ) ) {
			Repagio_Quota::clear();
		}
	}

	/**
	 * The note explaining which plans can currently issue an API key.
	 *
	 * The single source of this claim. See KEY_TIER_NOTICE_ENABLED.
	 *
	 * @since 0.4.0
	 *
	 * @return string Empty string once keys are available on every plan.
	 */
	public static function key_tier_notice() {
		if ( ! self::KEY_TIER_NOTICE_ENABLED ) {
			return '';
		}

		return __( 'Repagio currently issues API keys on its Pro and Agency plans, with Free and Creator support on the way. That is the service’s own policy about its API. The plugin’s scanner and dashboard work with no key at all.', 'repagio' );
	}

	/**
	 * URL of the Repagio signup page.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public static function signup_url() {
		return REPAGIO_SIGNUP_URL;
	}

	/**
	 * URL of this site's Repagio settings screen.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public static function settings_url() {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/**
	 * Registers the setting, its section and its fields.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);

		add_settings_section(
			'repagio_section_connection',
			__( 'Connection', 'repagio' ),
			array( __CLASS__, 'render_connection_section' ),
			self::PAGE
		);

		add_settings_field(
			'repagio_field_api_key',
			__( 'API key', 'repagio' ),
			array( __CLASS__, 'render_api_key_field' ),
			self::PAGE,
			'repagio_section_connection',
			array( 'label_for' => 'repagio_field_api_key' )
		);

		add_settings_field(
			'repagio_field_api_url',
			__( 'API base URL', 'repagio' ),
			array( __CLASS__, 'render_api_url_field' ),
			self::PAGE,
			'repagio_section_connection',
			array( 'label_for' => 'repagio_field_api_url' )
		);
	}

	/**
	 * Sanitizes the option array before it is written.
	 *
	 * An empty API key field means "keep the saved key", so the key never has to
	 * be printed into the form in order to survive a save.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$current = self::all();
		$clean   = self::defaults();

		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$clean['api_key'] = $current['api_key'];

		// Each field is forced to a scalar before anything is done with it: a
		// crafted post can submit repagio_settings[api_url][] as an array, and
		// PHP 8 makes passing that to a string function fatal.
		$submitted_key = trim( sanitize_text_field( self::scalar( $input, 'api_key' ) ) );

		if ( '' !== $submitted_key ) {
			$clean['api_key'] = $submitted_key;
		}

		if ( ! empty( $input['remove_api_key'] ) ) {
			$clean['api_key'] = '';
		}

		$submitted_url = esc_url_raw( trim( self::scalar( $input, 'api_url' ) ) );

		if ( '' === $submitted_url ) {
			$clean['api_url'] = REPAGIO_DEFAULT_API_URL;
		} else {
			$clean['api_url'] = untrailingslashit( $submitted_url );
		}

		return $clean;
	}

	/**
	 * Reads one submitted field as an unslashed string.
	 *
	 * Anything that is not a scalar — an array, an object, null — reads as an
	 * empty string rather than being handed on to a string function.
	 *
	 * @since 0.3.0
	 *
	 * @param array  $input Submitted values.
	 * @param string $key   Field to read.
	 * @return string
	 */
	protected static function scalar( $input, $key ) {
		if ( ! isset( $input[ $key ] ) || ! is_scalar( $input[ $key ] ) ) {
			return '';
		}

		return (string) wp_unslash( $input[ $key ] );
	}

	/**
	 * Describes the connection section.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function render_connection_section() {
		echo '<p>' . esc_html__( 'Paste the API key from your Repagio account. Once saved, the key is only ever shown as a mask.', 'repagio' ) . '</p>';
	}

	/**
	 * Renders the API key field.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function render_api_key_field() {
		$has_key = self::has_api_key();
		?>
		<?php if ( $has_key ) : ?>
			<p class="repagio-key-preview">
				<code><?php echo esc_html( self::masked_api_key() ); ?></code>
				<span class="repagio-badge repagio-badge--saved"><?php esc_html_e( 'Saved', 'repagio' ); ?></span>
			</p>
		<?php endif; ?>

		<input
			type="password"
			id="repagio_field_api_key"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[api_key]"
			value=""
			class="regular-text"
			autocomplete="off"
			spellcheck="false"
			placeholder="<?php echo $has_key ? esc_attr__( 'Enter a new key to replace the saved one', 'repagio' ) : esc_attr__( 'Paste your Repagio API key', 'repagio' ); ?>"
		/>

		<p class="description">
			<?php if ( $has_key ) : ?>
				<?php esc_html_e( 'Leave this blank to keep the saved key.', 'repagio' ); ?>
				<label for="repagio_field_remove_api_key" class="repagio-inline-label">
					<input
						type="checkbox"
						id="repagio_field_remove_api_key"
						name="<?php echo esc_attr( self::OPTION_NAME ); ?>[remove_api_key]"
						value="1"
					/>
					<?php esc_html_e( 'Delete the saved key', 'repagio' ); ?>
				</label>
			<?php else : ?>
				<?php esc_html_e( 'Find your key in your Repagio account under Settings.', 'repagio' ); ?>
			<?php endif; ?>
		</p>

		<?php $tier_notice = self::key_tier_notice(); ?>

		<?php if ( '' !== $tier_notice ) : ?>
			<p class="description repagio-key-tier-note">
				<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
				<?php echo esc_html( $tier_notice ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Renders the API base URL field.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function render_api_url_field() {
		?>
		<input
			type="url"
			id="repagio_field_api_url"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[api_url]"
			value="<?php echo esc_attr( self::get_api_url() ); ?>"
			class="regular-text code"
			spellcheck="false"
		/>
		<p class="description">
			<?php
			printf(
				/* translators: %s: the default API base URL. */
				esc_html__( 'Leave as %s unless you are pointing this site at a development instance.', 'repagio' ),
				'<code>' . esc_html( REPAGIO_DEFAULT_API_URL ) . '</code>'
			);
			?>
		</p>
		<?php
	}
}
