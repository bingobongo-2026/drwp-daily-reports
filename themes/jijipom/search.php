<?php
/**
 * 検索結果テンプレート
 *
 * @package jijipom
 */

get_header();
?>

<div class="content-area">
	<?php if ( have_posts() ) : ?>

		<header class="page-header">
			<h1 class="page-title">
				<?php
				/* translators: %s: 検索語 */
				printf( esc_html__( '「%s」の検索結果', 'jijipom' ), '<span>' . esc_html( get_search_query() ) . '</span>' );
				?>
			</h1>
		</header>

		<div class="post-list">
			<?php
			while ( have_posts() ) :
				the_post();
				get_template_part( 'template-parts/content', 'search' );
			endwhile;
			?>
		</div>

		<?php jijipom_pagination(); ?>

	<?php else : ?>

		<?php get_template_part( 'template-parts/content', 'none' ); ?>

	<?php endif; ?>
</div><!-- .content-area -->

<?php
get_sidebar();
get_footer();
