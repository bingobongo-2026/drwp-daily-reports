<?php
/**
 * 検索フォーム
 *
 * @package jijipom
 */
?>
<form role="search" method="get" class="search-form" action="<?php echo esc_url( home_url( '/' ) ); ?>">
	<label for="search-field-<?php echo esc_attr( uniqid() ); ?>" class="screen-reader-text"><?php esc_html_e( '検索:', 'jijipom' ); ?></label>
	<input type="search" class="search-field" placeholder="<?php esc_attr_e( 'キーワードで検索…', 'jijipom' ); ?>" value="<?php echo esc_attr( get_search_query() ); ?>" name="s" />
	<button type="submit" class="search-submit"><?php esc_html_e( '検索', 'jijipom' ); ?></button>
</form>
