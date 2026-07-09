<?php
/**
 * 一覧表示用の投稿エントリー(アーカイブ・ホームなど)
 *
 * @package jijipom
 */
?>

<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
	<header class="entry-header">
		<?php
		if ( is_singular() ) :
			the_title( '<h1 class="entry-title">', '</h1>' );
		else :
			the_title( '<h2 class="entry-title"><a href="' . esc_url( get_permalink() ) . '" rel="bookmark">', '</a></h2>' );
		endif;

		if ( 'post' === get_post_type() ) :
			?>
			<div class="entry-meta">
				<?php
				jijipom_posted_on();
				jijipom_posted_by();
				?>
			</div>
		<?php endif; ?>
	</header>

	<?php if ( has_post_thumbnail() && ! is_singular() ) : ?>
		<figure class="featured-image">
			<a href="<?php the_permalink(); ?>" aria-hidden="true" tabindex="-1">
				<?php the_post_thumbnail( 'medium_large', array( 'loading' => 'lazy' ) ); ?>
			</a>
		</figure>
	<?php endif; ?>

	<div class="entry-summary">
		<?php the_excerpt(); ?>
		<a class="read-more" href="<?php the_permalink(); ?>">
			<?php esc_html_e( '続きを読む', 'jijipom' ); ?>
			<span class="screen-reader-text"><?php the_title(); ?></span>
		</a>
	</div>
</article>
