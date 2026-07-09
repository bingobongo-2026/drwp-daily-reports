<?php
/**
 * ヘッダーテンプレート
 *
 * @package jijipom
 */
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="profile" href="https://gmpg.org/xfn/11">
	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="skip-link screen-reader-text" href="#main"><?php esc_html_e( 'コンテンツへスキップ', 'jijipom' ); ?></a>

<header id="masthead" class="site-header">
	<div class="container">
		<div class="site-branding">
			<?php
			// サイトタイトル画像(ロゴ)。「外観 > カスタマイズ > サイト基本情報」から設定。
			if ( has_custom_logo() ) {
				the_custom_logo();
			}

			// 文字タイトルの表示可否。ロゴ未設定時は必ず表示(ヘッダーが空になるのを防ぐ)。
			$jijipom_show_title = get_theme_mod( 'jijipom_show_site_title', true ) || ! has_custom_logo();

			if ( $jijipom_show_title ) :
				if ( is_front_page() && is_home() ) :
					?>
					<h1 class="site-title"><a href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home"><?php bloginfo( 'name' ); ?></a></h1>
					<?php
				else :
					?>
					<p class="site-title"><a href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home"><?php bloginfo( 'name' ); ?></a></p>
					<?php
				endif;
			else :
				// 文字を隠す場合でも、機械可読なサイト名を残す(SEO・スクリーンリーダー対応)。
				?>
				<span class="screen-reader-text"><?php bloginfo( 'name' ); ?></span>
				<?php
			endif;

			$jijipom_description = get_bloginfo( 'description', 'display' );
			$jijipom_show_desc   = get_theme_mod( 'jijipom_show_site_description', true );
			if ( $jijipom_show_desc && ( $jijipom_description || is_customize_preview() ) ) :
				?>
				<p class="site-description"><?php echo $jijipom_description; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
			<?php endif; ?>
		</div><!-- .site-branding -->

		<div class="site-header__actions">
			<?php if ( has_nav_menu( 'primary' ) ) : ?>
				<button class="menu-toggle" aria-controls="primary-menu" aria-expanded="false">
					<span class="menu-toggle__box" aria-hidden="true"><span class="menu-toggle__bar"></span></span>
					<span class="screen-reader-text"><?php esc_html_e( 'メニュー', 'jijipom' ); ?></span>
				</button>
				<nav id="site-navigation" class="main-navigation" aria-label="<?php esc_attr_e( 'メインメニュー', 'jijipom' ); ?>">
					<?php
					wp_nav_menu(
						array(
							'theme_location' => 'primary',
							'menu_id'        => 'primary-menu',
							'container'      => false,
							'depth'          => 2,
						)
					);
					?>
				</nav>
			<?php endif; ?>

			<?php
			// ヘッダーCTAボタン(文言とURLの両方が設定されている場合のみ表示)
			$jijipom_cta_text = get_theme_mod( 'jijipom_header_cta_text', '' );
			$jijipom_cta_url  = get_theme_mod( 'jijipom_header_cta_url', '' );
			if ( $jijipom_cta_text && $jijipom_cta_url ) :
				?>
				<a class="header-cta" href="<?php echo esc_url( $jijipom_cta_url ); ?>"><?php echo esc_html( $jijipom_cta_text ); ?></a>
			<?php endif; ?>
		</div><!-- .site-header__actions -->
	</div><!-- .container -->
</header><!-- #masthead -->

<?php jijipom_breadcrumbs(); ?>

<main id="main" class="site-main">
	<div class="container">
		<div class="site-content">
