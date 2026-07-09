		</div><!-- .site-content -->
	</div><!-- .container -->
</main><!-- #main -->

<footer id="colophon" class="site-footer">
	<div class="container">
		<div class="footer-main">
			<div class="footer-brand">
				<?php if ( has_custom_logo() ) : ?>
					<?php the_custom_logo(); ?>
				<?php else : ?>
					<span class="footer-sitename"><?php bloginfo( 'name' ); ?></span>
				<?php endif; ?>

				<?php
				$jijipom_footer_address = get_theme_mod( 'jijipom_footer_address', '' );
				$jijipom_footer_tel     = get_theme_mod( 'jijipom_footer_tel', '' );
				if ( $jijipom_footer_address || $jijipom_footer_tel ) :
					?>
					<address class="footer-info">
						<?php
						if ( $jijipom_footer_address ) {
							echo nl2br( esc_html( $jijipom_footer_address ) );
						}
						if ( $jijipom_footer_tel ) {
							echo ( $jijipom_footer_address ? '<br>' : '' ) . 'TEL ' . esc_html( $jijipom_footer_tel );
						}
						?>
					</address>
				<?php endif; ?>
			</div>

			<?php if ( has_nav_menu( 'footer' ) ) : ?>
				<nav class="footer-navigation" aria-label="<?php esc_attr_e( 'フッターメニュー', 'jijipom' ); ?>">
					<?php
					wp_nav_menu(
						array(
							'theme_location' => 'footer',
							'container'      => false,
							'depth'          => 1,
						)
					);
					?>
				</nav>
			<?php endif; ?>
		</div><!-- .footer-main -->

		<div class="footer-bottom">
			<p>&copy; <?php echo esc_html( date_i18n( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?></p>
		</div>
	</div><!-- .container -->
</footer><!-- #colophon -->

<?php wp_footer(); ?>
</body>
</html>
