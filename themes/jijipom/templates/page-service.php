<?php
/**
 * Template Name: サービス
 * Template Post Type: page
 *
 * サービス紹介ページ。各領域は「外観 > カスタマイズ > 固定ページ >
 * サービス ページ」で設定します。ページ本文(ブロックエディタ)は
 * サービス一覧の下に補足として表示されます。
 *
 * @package jijipom
 */

get_header();

$jijipom_lead          = get_theme_mod( 'jijipom_svc_lead', '' );
$jijipom_items_heading = get_theme_mod( 'jijipom_svc_items_heading', __( '提供サービス', 'jijipom' ) );
$jijipom_feat_heading  = get_theme_mod( 'jijipom_svc_feature_heading', __( '私たちの強み', 'jijipom' ) );
$jijipom_feat_text     = get_theme_mod( 'jijipom_svc_feature_text', '' );
$jijipom_cta_heading   = get_theme_mod( 'jijipom_svc_cta_heading', '' );
$jijipom_cta_text      = get_theme_mod( 'jijipom_svc_cta_text', '' );
$jijipom_cta_btn_text  = get_theme_mod( 'jijipom_svc_cta_button_text', __( 'お問い合わせはこちら', 'jijipom' ) );
$jijipom_cta_btn_url   = get_theme_mod( 'jijipom_svc_cta_button_url', '' );

// 入力済みのサービス項目だけ集める。
$jijipom_items = array();
for ( $i = 1; $i <= 4; $i++ ) {
	$title = get_theme_mod( "jijipom_svc_item{$i}_title", '' );
	$text  = get_theme_mod( "jijipom_svc_item{$i}_text", '' );
	$image = get_theme_mod( "jijipom_svc_item{$i}_image", '' );
	if ( '' !== $title || '' !== $text || '' !== $image ) {
		$jijipom_items[] = array(
			'title' => $title,
			'text'  => $text,
			'image' => $image,
		);
	}
}
?>

<div class="page-template page-service">
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

		<?php if ( ! empty( $jijipom_items ) ) : ?>
			<section class="front-section front-section--muted svc-items">
				<div class="front-inner">
					<?php if ( $jijipom_items_heading ) : ?>
						<h2 class="front-section__heading"><?php echo esc_html( $jijipom_items_heading ); ?></h2>
					<?php endif; ?>
					<div class="svc-items__grid">
						<?php foreach ( $jijipom_items as $item ) : ?>
							<article class="svc-card">
								<?php if ( $item['image'] ) : ?>
									<div class="svc-card__media">
										<img src="<?php echo esc_url( $item['image'] ); ?>" alt="<?php echo esc_attr( $item['title'] ); ?>" loading="lazy">
									</div>
								<?php endif; ?>
								<div class="svc-card__body">
									<?php if ( $item['title'] ) : ?>
										<h3 class="svc-card__title"><?php echo esc_html( $item['title'] ); ?></h3>
									<?php endif; ?>
									<?php if ( $item['text'] ) : ?>
										<p class="svc-card__text"><?php echo nl2br( esc_html( $item['text'] ) ); ?></p>
									<?php endif; ?>
								</div>
							</article>
						<?php endforeach; ?>
					</div>
				</div>
			</section>
		<?php endif; ?>

		<?php if ( $jijipom_feat_text ) : ?>
			<section class="front-section svc-feature">
				<div class="front-inner">
					<?php if ( $jijipom_feat_heading ) : ?>
						<h2 class="front-section__heading"><?php echo esc_html( $jijipom_feat_heading ); ?></h2>
					<?php endif; ?>
					<p class="svc-feature__text"><?php echo nl2br( esc_html( $jijipom_feat_text ) ); ?></p>
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

		<?php if ( $jijipom_cta_heading || ( $jijipom_cta_btn_text && $jijipom_cta_btn_url ) ) : ?>
			<section class="front-section front-section--muted svc-cta">
				<div class="front-inner svc-cta__inner">
					<?php if ( $jijipom_cta_heading ) : ?>
						<h2 class="svc-cta__heading"><?php echo esc_html( $jijipom_cta_heading ); ?></h2>
					<?php endif; ?>
					<?php if ( $jijipom_cta_text ) : ?>
						<p class="svc-cta__text"><?php echo nl2br( esc_html( $jijipom_cta_text ) ); ?></p>
					<?php endif; ?>
					<?php if ( $jijipom_cta_btn_text && $jijipom_cta_btn_url ) : ?>
						<a class="button" href="<?php echo esc_url( $jijipom_cta_btn_url ); ?>"><?php echo esc_html( $jijipom_cta_btn_text ); ?></a>
					<?php endif; ?>
				</div>
			</section>
		<?php endif; ?>

	<?php endwhile; ?>
</div><!-- .page-service -->

<?php
get_footer();
