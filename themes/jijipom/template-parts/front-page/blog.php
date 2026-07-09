<?php
/**
 * トップページ:ブログセクション(最新記事を自動表示)
 *
 * @package jijipom
 */

$jijipom_blog_heading = get_theme_mod( 'jijipom_blog_heading', __( 'ブログ', 'jijipom' ) );
$jijipom_blog_count   = absint( get_theme_mod( 'jijipom_blog_count', 4 ) );
if ( $jijipom_blog_count < 1 ) {
	$jijipom_blog_count = 4;
}

$jijipom_blog_query = new WP_Query(
	array(
		'post_type'           => 'post',
		'posts_per_page'      => $jijipom_blog_count,
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
	)
);

if ( $jijipom_blog_query->have_posts() ) :
	?>
	<section class="front-section front-section--muted front-blog">
		<div class="front-inner">
			<?php if ( $jijipom_blog_heading ) : ?>
				<h2 class="front-section__heading"><?php echo esc_html( $jijipom_blog_heading ); ?></h2>
			<?php endif; ?>

			<div class="front-blog__grid">
				<?php
				while ( $jijipom_blog_query->have_posts() ) :
					$jijipom_blog_query->the_post();
					?>
					<article class="blog-card">
						<a class="blog-card__thumb" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
							<?php
							if ( has_post_thumbnail() ) {
								the_post_thumbnail( 'medium_large', array( 'loading' => 'lazy' ) );
							} else {
								echo '<span class="front-placeholder"></span>';
							}
							?>
						</a>
						<p class="blog-card__date"><?php echo esc_html( get_the_date() ); ?></p>
						<h3 class="blog-card__title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
					</article>
					<?php
				endwhile;
				?>
			</div>
		</div>
	</section>
	<?php
	wp_reset_postdata();
endif;
