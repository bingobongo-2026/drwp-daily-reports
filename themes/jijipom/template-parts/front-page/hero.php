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

// 文字・ボタンの配置 (left / center / right)。中央が既定。
$jijipom_hero_align = get_theme_mod( 'jijipom_hero_align', 'center' );
if ( ! in_array( $jijipom_hero_align, array( 'left', 'center', 'right' ), true ) ) {
	$jijipom_hero_align = 'center';
}
$jijipom_inner_class = 'front-inner front-hero__inner is-align-' . $jijipom_hero_align;

// ボタンの色 (未設定ならテーマ標準色のまま)。
$jijipom_hero_btn_bg    = get_theme_mod( 'jijipom_hero_button_bg', '' );
$jijipom_hero_btn_color = get_theme_mod( 'jijipom_hero_button_color', '' );
$jijipom_btn_style      = '';
if ( $jijipom_hero_btn_bg ) {
	$jijipom_btn_style .= 'background-color:' . $jijipom_hero_btn_bg . ';border-color:' . $jijipom_hero_btn_bg . ';';
}
if ( $jijipom_hero_btn_color ) {
	$jijipom_btn_style .= 'color:' . $jijipom_hero_btn_color . ';';
}

// 背景の種類 (image / video)。動画が選ばれていて動画URLがあれば動画優先。
$jijipom_hero_type   = get_theme_mod( 'jijipom_hero_type', 'image' );
$jijipom_hero_video  = get_theme_mod( 'jijipom_hero_video', '' );
$jijipom_hero_poster = get_theme_mod( 'jijipom_hero_video_poster', '' );
$jijipom_use_video   = ( 'video' === $jijipom_hero_type && $jijipom_hero_video );

$jijipom_has_image = ! $jijipom_use_video && ! empty( $jijipom_hero_images );
// 背景メディアがあるとき (画像 or 動画) は暗いオーバーレイ + 白文字にする。
$jijipom_hero_class = 'front-hero'
	. ( $jijipom_has_image ? ' has-image' : '' )
	. ( $jijipom_use_video ? ' has-video' : '' );
?>
<section class="<?php echo esc_attr( $jijipom_hero_class ); ?>"<?php if ( ! $jijipom_use_video && count( $jijipom_hero_images ) > 1 ) : ?> data-hero-interval="<?php echo esc_attr( $jijipom_hero_interval * 1000 ); ?>"<?php endif; ?>>
	<?php if ( $jijipom_use_video ) : ?>
		<video class="front-hero__video" autoplay muted loop playsinline<?php echo $jijipom_hero_poster ? ' poster="' . esc_url( $jijipom_hero_poster ) . '"' : ''; ?> aria-hidden="true">
			<source src="<?php echo esc_url( $jijipom_hero_video ); ?>" type="video/mp4">
		</video>
	<?php elseif ( $jijipom_has_image ) : ?>
		<div class="front-hero__slides" aria-hidden="true">
			<?php foreach ( $jijipom_hero_images as $jijipom_i => $jijipom_img ) : ?>
				<div class="front-hero__slide<?php echo 0 === $jijipom_i ? ' is-active' : ''; ?>" style="background-image:url(<?php echo esc_url( $jijipom_img ); ?>)"></div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<div class="<?php echo esc_attr( $jijipom_inner_class ); ?>">
		<?php if ( $jijipom_hero_title ) : ?>
			<h2 class="front-hero__title"><?php echo nl2br( esc_html( $jijipom_hero_title ) ); ?></h2>
		<?php endif; ?>

		<?php if ( $jijipom_hero_subtitle ) : ?>
			<p class="front-hero__subtitle"><?php echo nl2br( esc_html( $jijipom_hero_subtitle ) ); ?></p>
		<?php endif; ?>

		<?php if ( $jijipom_hero_btn_text && $jijipom_hero_btn_url ) : ?>
			<a class="button front-hero__button" href="<?php echo esc_url( $jijipom_hero_btn_url ); ?>"<?php echo $jijipom_btn_style ? ' style="' . esc_attr( $jijipom_btn_style ) . '"' : ''; ?>><?php echo esc_html( $jijipom_hero_btn_text ); ?></a>
		<?php endif; ?>
	</div>
</section>
