<?php
/**
 * Settings page markup.
 *
 * Rendered by Repagio_Admin::render_settings_page(), which has already
 * checked the current user's capability.
 *
 * @package Repagio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div class="wrap repagio-wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php settings_errors(); ?>

	<p class="repagio-intro">
		<?php esc_html_e( 'Repagio turns posts you have already published into SEO blog posts, LinkedIn posts, X threads and newsletters. Connect your account below to get started.', 'repagio' ); ?>
	</p>

	<form action="options.php" method="post">
		<?php
		settings_fields( Repagio_Settings::GROUP );
		do_settings_sections( Repagio_Settings::PAGE );
		submit_button();
		?>
	</form>

	<hr />

	<h2><?php esc_html_e( 'Connection test', 'repagio' ); ?></h2>

	<p class="description">
		<?php esc_html_e( 'Checks that the saved key can reach the Repagio API and reads back your plan. Save your settings first.', 'repagio' ); ?>
	</p>

	<p>
		<button
			type="button"
			class="button button-secondary"
			id="repagio-test-connection"
			data-default-label="<?php esc_attr_e( 'Test connection', 'repagio' ); ?>"
			<?php disabled( ! Repagio_Settings::has_api_key() ); ?>
		>
			<?php esc_html_e( 'Test connection', 'repagio' ); ?>
		</button>
		<span class="spinner" id="repagio-test-spinner"></span>
	</p>

	<?php if ( ! Repagio_Settings::has_api_key() ) : ?>
		<p class="repagio-hint"><?php esc_html_e( 'Save an API key to enable this test.', 'repagio' ); ?></p>
	<?php endif; ?>

	<div id="repagio-test-result" class="repagio-result" role="status" aria-live="polite"></div>
</div>
