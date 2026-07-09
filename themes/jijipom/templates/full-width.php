<?php
/**
 * Template Name: フルワイド(サイドバーなし)
 * Template Post Type: page
 *
 * サイドバーを表示せず、本文を全体幅まで広げるページテンプレート。
 * ランディングページやトップページ向け。
 * 「ページ編集 > ページ属性 > テンプレート」から選択できます。
 *
 * @package jijipom
 */

get_header();
?>

<div class="content-area content-area--full">
	<?php
	while ( have_posts() ) :
		the_post();
		?>

		<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
			<header class="entry-header">
				<?php the_title( '<h1 class="entry-title">', '</h1>' ); ?>
			</header>

			<?php if ( has_post_thumbnail() ) : ?>
				<figure class="featured-image">
					<?php the_post_thumbnail( 'full', array( 'fetchpriority' => 'high' ) ); ?>
				</figure>
			<?php endif; ?>

			<div class="entry-content">
				<?php
				the_content();

				wp_link_pages(
					array(
						'before' => '<div class="page-links">' . esc_html__( 'ページ:', 'jijipom' ),
						'after'  => '</div>',
					)
				);
				?>
			</div><!-- .entry-content -->
		</article>

		<?php
		if ( comments_open() || get_comments_number() ) {
			comments_template();
		}

	endwhile;
	?>
</div><!-- .content-area -->

<?php
// サイドバーは読み込まない(フルワイド)
get_footer();
