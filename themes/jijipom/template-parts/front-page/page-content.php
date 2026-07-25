<?php
/**
 * トップページ:固定ページ本文セクション
 *
 * フロントに指定した固定ページ(または「トップページ」テンプレートを割り当てた
 * ページ)の本文(ブロックエディタで書いた内容)を、カスタマイザーの各セクションの
 * 下に表示する。本文が空なら何も出力しない。
 *
 * @package jijipom
 */

if ( have_posts() ) :
	while ( have_posts() ) :
		the_post();
		if ( '' !== trim( get_the_content() ) ) :
			?>
			<section class="front-section front-section--content">
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
				</div>
			</section>
			<?php
		endif;
	endwhile;
endif;
