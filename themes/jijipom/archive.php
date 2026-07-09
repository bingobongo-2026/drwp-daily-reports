<?php
/**
 * アーカイブテンプレート(カテゴリー・タグ・日付・著者など)
 *
 * @package jijipom
 */

get_header();
?>

<div class="content-area">
	<?php if ( have_posts() ) : ?>

		<header class="page-header">
			<?php
			the_archive_title( '<h1 class="page-title">', '</h1>' );
			the_archive_description( '<div class="archive-description">', '</div>' );
			?>
		</header>

		<div class="post-list">
			<?php
			while ( have_posts() ) :
				the_post();
				get_template_part( 'template-parts/content', get_post_type() );
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
