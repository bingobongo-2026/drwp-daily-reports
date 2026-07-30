<?php
/**
 * 検索フォーム
 *
 * @package jijipom
 */

// label の for と input の id を一致させる (従来は for だけIDを振っていて
// input 側に id が無く、ラベルが関連付いていなかった)。1ページに複数
// フォームがあっても衝突しないよう連番を振る。
global $jijipom_search_form_count;
$jijipom_search_form_count = (int) $jijipom_search_form_count + 1;
$jijipom_search_field_id   = 'search-field-' . $jijipom_search_form_count;
?>
<form role="search" method="get" class="search-form" action="<?php echo esc_url( home_url( '/' ) ); ?>">
	<label for="<?php echo esc_attr( $jijipom_search_field_id ); ?>" class="screen-reader-text"><?php esc_html_e( '検索:', 'jijipom' ); ?></label>
	<input type="search" id="<?php echo esc_attr( $jijipom_search_field_id ); ?>" class="search-field" placeholder="<?php esc_attr_e( 'キーワードで検索…', 'jijipom' ); ?>" value="<?php echo esc_attr( get_search_query() ); ?>" name="s" />
	<button type="submit" class="search-submit"><?php esc_html_e( '検索', 'jijipom' ); ?></button>
</form>
