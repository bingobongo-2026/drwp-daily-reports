<?php
/**
 * サイドバー(ウィジェットエリア)
 *
 * @package jijipom
 */

if ( ! is_active_sidebar( 'sidebar-1' ) ) {
	return;
}
?>

<aside id="secondary" class="widget-area" aria-label="<?php esc_attr_e( 'サイドバー', 'jijipom' ); ?>">
	<?php dynamic_sidebar( 'sidebar-1' ); ?>
</aside>
