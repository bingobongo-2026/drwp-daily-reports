<?php
/**
 * Template Name: お問い合わせ
 * Template Post Type: page
 *
 * お問い合わせページ。各領域は「外観 > カスタマイズ > 固定ページ >
 * お問い合わせ ページ」で設定します。問い合わせフォームは Contact Form 7
 * などのショートコードを設定すると表示されます。
 *
 * @package jijipom
 */

get_header();

$jijipom_lead     = get_theme_mod( 'jijipom_contact_lead', '' );
$jijipom_tel      = get_theme_mod( 'jijipom_contact_tel', '' );
$jijipom_tel_note = get_theme_mod( 'jijipom_contact_tel_note', '' );
$jijipom_email    = get_theme_mod( 'jijipom_contact_email', '' );
$jijipom_hours    = get_theme_mod( 'jijipom_contact_hours', '' );
$jijipom_holiday  = get_theme_mod( 'jijipom_contact_holiday', '' );
$jijipom_area     = get_theme_mod( 'jijipom_contact_area', '' );
$jijipom_shortcode = get_theme_mod( 'jijipom_contact_form_shortcode', '' );
$jijipom_map_url  = get_theme_mod( 'jijipom_contact_map_url', '' );

$jijipom_has_contact = ( $jijipom_tel || $jijipom_email || $jijipom_hours || $jijipom_holiday || $jijipom_area );
?>

<div class="page-template page-contact">
	<?php
	while ( have_posts() ) :
		the_post();
		?>

		<header class="page-template__header front-inner">
			<?php the_title( '<h1 class="page-template__title">', '</h1>' ); ?>
			<?php if ( $jijipom_lead ) : ?>
				<p class="page-template__lead"><?php echo nl2br( esc_html( $jijipom_lead ) ); ?></p>
			<?php endif; ?>
		</header>

		<?php if ( $jijipom_has_contact ) : ?>
			<section class="front-section contact-info">
				<div class="front-inner">
					<div class="contact-info__grid">
						<?php if ( $jijipom_tel ) : ?>
							<div class="contact-card">
								<span class="contact-card__label"><?php esc_html_e( 'お電話', 'jijipom' ); ?></span>
								<a class="contact-card__tel" href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $jijipom_tel ) ); ?>"><?php echo esc_html( $jijipom_tel ); ?></a>
								<?php if ( $jijipom_tel_note ) : ?>
									<span class="contact-card__note"><?php echo esc_html( $jijipom_tel_note ); ?></span>
								<?php endif; ?>
							</div>
						<?php endif; ?>
						<?php if ( $jijipom_email ) : ?>
							<div class="contact-card">
								<span class="contact-card__label"><?php esc_html_e( 'メール', 'jijipom' ); ?></span>
								<a class="contact-card__email" href="mailto:<?php echo esc_attr( $jijipom_email ); ?>"><?php echo esc_html( $jijipom_email ); ?></a>
							</div>
						<?php endif; ?>
						<?php if ( $jijipom_hours || $jijipom_holiday ) : ?>
							<div class="contact-card">
								<span class="contact-card__label"><?php esc_html_e( '受付', 'jijipom' ); ?></span>
								<?php if ( $jijipom_hours ) : ?>
									<span class="contact-card__value"><?php echo esc_html( $jijipom_hours ); ?></span>
								<?php endif; ?>
								<?php if ( $jijipom_holiday ) : ?>
									<span class="contact-card__note"><?php echo esc_html( __( '定休日: ', 'jijipom' ) . $jijipom_holiday ); ?></span>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					</div>
					<?php if ( $jijipom_area ) : ?>
						<p class="contact-info__area"><?php echo nl2br( esc_html( $jijipom_area ) ); ?></p>
					<?php endif; ?>
				</div>
			</section>
		<?php endif; ?>

		<?php if ( trim( get_the_content() ) !== '' ) : ?>
			<section class="front-section">
				<div class="front-inner entry-content">
					<?php the_content(); ?>
				</div>
			</section>
		<?php endif; ?>

		<?php if ( $jijipom_shortcode ) : ?>
			<section class="front-section front-section--muted contact-form">
				<div class="front-inner">
					<h2 class="front-section__heading"><?php esc_html_e( 'お問い合わせフォーム', 'jijipom' ); ?></h2>
					<div class="contact-form__body">
						<?php echo do_shortcode( $jijipom_shortcode ); ?>
					</div>
				</div>
			</section>
		<?php endif; ?>

		<?php if ( $jijipom_map_url ) : ?>
			<section class="front-section contact-map">
				<div class="front-inner">
					<div class="contact-map__frame">
						<iframe src="<?php echo esc_url( $jijipom_map_url ); ?>" width="100%" height="360" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="<?php esc_attr_e( '地図', 'jijipom' ); ?>"></iframe>
					</div>
				</div>
			</section>
		<?php endif; ?>

	<?php endwhile; ?>
</div><!-- .page-contact -->

<?php
get_footer();
