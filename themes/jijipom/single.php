<?php
/**
 * 投稿ページテンプレート
 *
 * @package jijipom
 */

get_header();
?>

<div class="content-area">
	<?php
	while ( have_posts() ) :
		the_post();
		?>

		<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
			<header class="entry-header">
				<?php the_title( '<h1 class="entry-title">', '</h1>' ); ?>

				<?php if ( 'post' === get_post_type() ) : ?>
					<div class="entry-meta">
						<?php
						jijipom_posted_on();
						jijipom_posted_by();
						?>
					</div>
				<?php endif; ?>
			</header>

			<?php if ( has_post_thumbnail() ) : ?>
				<figure class="featured-image">
					<?php the_post_thumbnail( 'large', array( 'fetchpriority' => 'high' ) ); ?>
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

			<footer class="entry-footer">
				<?php jijipom_entry_taxonomies(); ?>
			</footer>
		</article>

		<?php
		// 前後の記事ナビゲーション
		the_post_navigation(
			array(
				'prev_text' => '<span class="nav-subtitle">' . esc_html__( '前の記事', 'jijipom' ) . '</span> <span class="nav-title">%title</span>',
				'next_text' => '<span class="nav-subtitle">' . esc_html__( '次の記事', 'jijipom' ) . '</span> <span class="nav-title">%title</span>',
			)
		);

		// コメント
		if ( comments_open() || get_comments_number() ) {
			comments_template();
		}

	endwhile;
	?>
</div><!-- .content-area -->

<?php
get_sidebar();
get_footer();
