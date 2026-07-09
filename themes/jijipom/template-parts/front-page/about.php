<?php
/**
 * トップページ:会社紹介セクション(3ブロック)
 *
 * @package jijipom
 */

$jijipom_about_heading = get_theme_mod( 'jijipom_about_heading', __( '会社紹介', 'jijipom' ) );
?>
<section class="front-section front-about">
	<div class="front-inner">
		<?php if ( $jijipom_about_heading ) : ?>
			<h2 class="front-section__heading"><?php echo esc_html( $jijipom_about_heading ); ?></h2>
		<?php endif; ?>

		<div class="front-about__grid">
			<?php
			for ( $i = 1; $i <= 3; $i++ ) :
				$jijipom_about_image = get_theme_mod( "jijipom_about_{$i}_image", '' );
				$jijipom_about_title = get_theme_mod( "jijipom_about_{$i}_title", '' );
				$jijipom_about_text  = get_theme_mod( "jijipom_about_{$i}_text", '' );

				// すべて空のブロックは出力しない
				if ( ! $jijipom_about_image && ! $jijipom_about_title && ! $jijipom_about_text ) {
					continue;
				}
				?>
				<div class="about-block">
					<div class="about-block__thumb">
						<?php if ( $jijipom_about_image ) : ?>
							<img src="<?php echo esc_url( $jijipom_about_image ); ?>" alt="<?php echo esc_attr( $jijipom_about_title ); ?>" loading="lazy">
						<?php else : ?>
							<span class="front-placeholder" aria-hidden="true"></span>
						<?php endif; ?>
					</div>
					<?php if ( $jijipom_about_title ) : ?>
						<h3 class="about-block__title"><?php echo esc_html( $jijipom_about_title ); ?></h3>
					<?php endif; ?>
					<?php if ( $jijipom_about_text ) : ?>
						<p class="about-block__text"><?php echo nl2br( esc_html( $jijipom_about_text ) ); ?></p>
					<?php endif; ?>
				</div>
			<?php endfor; ?>
		</div>
	</div>
</section>
