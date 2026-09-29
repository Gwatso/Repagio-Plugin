<?php
/**
 * Settings page markup.
 *
 * Rendered by Repagify_Admin::render_settings_page(), which has already
 * checked the current user's capability.
 *
 * @package Repagify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div class="wrap repagify-wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php settings_errors(); ?>

	<p class="repagify-intro">
		<?php esc_html_e( 'Repagify turns posts you have already published into SEO blog posts, LinkedIn posts, X threads and newsletters. Connect your account below to get started.', 'repagify' ); ?>
	</p>

	<form action="options.php" method="post">
		<?php
		settings_fields( Repagify_Settings::GROUP );
		do_settings_sections( Repagify_Settings::PAGE );
		submit_button();
		?>
	</form>

	<hr />

	<h2><?php esc_html_e( 'Connection test', 'repagify' ); ?></h2>

	<p class="description">
		<?php esc_html_e( 'Checks that the saved key can reach the Repagify API and reads back your plan. Save your settings first.', 'repagify' ); ?>
	</p>

	<p>
		<button
			type="button"
			class="button button-secondary"
			id="repagify-test-connection"
			data-default-label="<?php esc_attr_e( 'Test connection', 'repagify' ); ?>"
			<?php disabled( ! Repagify_Settings::has_api_key() ); ?>
		>
			<?php esc_html_e( 'Test connection', 'repagify' ); ?>
		</button>
		<span class="spinner" id="repagify-test-spinner"></span>
	</p>

	<?php if ( ! Repagify_Settings::has_api_key() ) : ?>
		<p class="repagify-hint"><?php esc_html_e( 'Save an API key to enable this test.', 'repagify' ); ?></p>
	<?php endif; ?>

	<div id="repagify-test-result" class="repagify-result" role="status" aria-live="polite"></div>
</div>
