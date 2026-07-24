<?php
/**
 * Template Name: プライバシーポリシー
 * Template Post Type: page
 *
 * プライバシーポリシーページ。前文・事業者名・制定日は
 * 「外観 > カスタマイズ > 固定ページ > プライバシーポリシー ページ」で、
 * 各条項の本文はページ本文(ブロックエディタ)で記載します。
 *
 * @package jijipom
 */

get_header();

$jijipom_intro       = get_theme_mod( 'jijipom_privacy_intro', '' );
$jijipom_operator    = get_theme_mod( 'jijipom_privacy_operator', '' );
$jijipom_established = get_theme_mod( 'jijipom_privacy_established', '' );
?>

<div class="page-template page-privacy">
	<?php
	while ( have_posts() ) :
		the_post();
		?>

		<article class="front-inner privacy-article">
			<header class="page-template__header">
				<?php the_title( '<h1 class="page-template__title">', '</h1>' ); ?>
				<?php if ( $jijipom_established ) : ?>
					<p class="privacy-article__meta"><?php echo esc_html( $jijipom_established ); ?></p>
				<?php endif; ?>
			</header>

			<?php if ( $jijipom_intro ) : ?>
				<p class="privacy-article__intro"><?php echo nl2br( esc_html( $jijipom_intro ) ); ?></p>
			<?php endif; ?>

			<div class="entry-content privacy-article__body">
				<?php the_content(); ?>
			</div>

			<?php if ( $jijipom_operator ) : ?>
				<p class="privacy-article__operator"><?php echo esc_html( $jijipom_operator ); ?></p>
			<?php endif; ?>
		</article>

	<?php endwhile; ?>
</div><!-- .page-privacy -->

<?php
get_footer();
