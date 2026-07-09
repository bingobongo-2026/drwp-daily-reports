<?php
/**
 * トップページ:メインビジュアル(ヒーロー)
 * 画像を最大3枚まで設定可能。2枚以上でスライドショー(自動切り替え)。
 *
 * @package jijipom
 */

// 設定済みの画像を最大3枚まで集める
$jijipom_hero_images = array();
foreach ( array( 'jijipom_hero_image', 'jijipom_hero_image_2', 'jijipom_hero_image_3' ) as $jijipom_key ) {
	$jijipom_url = get_theme_mod( $jijipom_key, '' );
	if ( $jijipom_url ) {
		$jijipom_hero_images[] = $jijipom_url;
	}
}

$jijipom_hero_interval = absint( get_theme_mod( 'jijipom_hero_interval', 5 ) );
if ( $jijipom_hero_interval < 2 ) {
	$jijipom_hero_interval = 5;
}

$jijipom_hero_title    = get_theme_mod( 'jijipom_hero_title', __( 'キャッチコピー', 'jijipom' ) );
$jijipom_hero_subtitle = get_theme_mod( 'jijipom_hero_subtitle', '' );
$jijipom_hero_btn_text = get_theme_mod( 'jijipom_hero_button_text', '' );
$jijipom_hero_btn_url  = get_theme_mod( 'jijipom_hero_button_url', '' );

$jijipom_has_image  = ! empty( $jijipom_hero_images );
$jijipom_hero_class = 'front-hero' . ( $jijipom_has_image ? ' has-image' : '' );
?>
<section class="<?php echo esc_attr( $jijipom_hero_class ); ?>"<?php if ( count( $jijipom_hero_images ) > 1 ) : ?> data-hero-interval="<?php echo esc_attr( $jijipom_hero_interval * 1000 ); ?>"<?php endif; ?>>
	<?php if ( $jijipom_has_image ) : ?>
		<div class="front-hero__slides" aria-hidden="true">
			<?php foreach ( $jijipom_hero_images as $jijipom_i => $jijipom_img ) : ?>
				<div class="front-hero__slide<?php echo 0 === $jijipom_i ? ' is-active' : ''; ?>" style="background-image:url(<?php echo esc_url( $jijipom_img ); ?>)"></div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<div class="front-inner front-hero__inner">
		<?php if ( $jijipom_hero_title ) : ?>
			<h2 class="front-hero__title"><?php echo nl2br( esc_html( $jijipom_hero_title ) ); ?></h2>
		<?php endif; ?>

		<?php if ( $jijipom_hero_subtitle ) : ?>
			<p class="front-hero__subtitle"><?php echo nl2br( esc_html( $jijipom_hero_subtitle ) ); ?></p>
		<?php endif; ?>

		<?php if ( $jijipom_hero_btn_text && $jijipom_hero_btn_url ) : ?>
			<a class="button front-hero__button" href="<?php echo esc_url( $jijipom_hero_btn_url ); ?>"><?php echo esc_html( $jijipom_hero_btn_text ); ?></a>
		<?php endif; ?>
	</div>
</section>
