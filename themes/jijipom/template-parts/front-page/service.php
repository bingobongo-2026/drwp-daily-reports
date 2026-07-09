<?php
/**
 * トップページ:サービスセクション
 *
 * @package jijipom
 */

$jijipom_svc_heading  = get_theme_mod( 'jijipom_service_heading', __( 'サービス', 'jijipom' ) );
$jijipom_svc_image    = get_theme_mod( 'jijipom_service_image', '' );
$jijipom_svc_text     = get_theme_mod( 'jijipom_service_text', __( 'ここにサービスの説明文が入ります。カスタマイザーから編集できます。', 'jijipom' ) );
$jijipom_svc_btn_text = get_theme_mod( 'jijipom_service_button_text', __( 'サービスを見る', 'jijipom' ) );
$jijipom_svc_btn_url  = get_theme_mod( 'jijipom_service_button_url', '' );
?>
<section class="front-section front-service">
	<div class="front-inner">
		<?php if ( $jijipom_svc_heading ) : ?>
			<h2 class="front-section__heading"><?php echo esc_html( $jijipom_svc_heading ); ?></h2>
		<?php endif; ?>

		<div class="front-service__grid">
			<div class="front-service__media">
				<?php if ( $jijipom_svc_image ) : ?>
					<img src="<?php echo esc_url( $jijipom_svc_image ); ?>" alt="<?php echo esc_attr( $jijipom_svc_heading ); ?>" loading="lazy">
				<?php else : ?>
					<div class="front-placeholder" aria-hidden="true"></div>
				<?php endif; ?>
			</div>

			<div class="front-service__body">
				<?php if ( $jijipom_svc_text ) : ?>
					<p><?php echo nl2br( esc_html( $jijipom_svc_text ) ); ?></p>
				<?php endif; ?>

				<?php if ( $jijipom_svc_btn_text && $jijipom_svc_btn_url ) : ?>
					<a class="button" href="<?php echo esc_url( $jijipom_svc_btn_url ); ?>"><?php echo esc_html( $jijipom_svc_btn_text ); ?></a>
				<?php endif; ?>
			</div>
		</div>
	</div>
</section>
