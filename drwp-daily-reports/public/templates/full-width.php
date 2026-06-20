<?php
/**
 * 日報マン フルワイドテンプレート
 *
 * テーマの header.php / footer.php / sidebar.php を呼ばずに、
 * 最小限の HTML 骨格で `the_content()` を全幅描画する。
 * - `wp_head()` / `wp_footer()` は呼ぶ (プラグイン + WP のスクリプ
 *   ト・スタイルが効くように)
 * - 管理バーが出るとき (ログイン状態) は body padding で潰れない
 *   よう領域を確保
 * - 背景色やフォントは「日報マン」管理画面の見た目に近い
 *   ニュートラルなトーンで揃える (テーマの装飾は全く乗らない)
 *
 * このファイルはテーマフォルダではなくプラグインの
 * `public/templates/` に置き、`DRWP_Page_Template::use_template`
 * が `template_include` フィルタで指し示す。
 */
if (!defined('ABSPATH')) exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php wp_title('|', true, 'right'); echo esc_html(get_bloginfo('name')); ?></title>
  <link rel="profile" href="https://gmpg.org/xfn/11">
  <?php wp_head(); ?>
  <style id="drwp-fullwidth-base">
    /* テーマの装飾を一切被せない、薄いニュートラル下地。 */
    html, body { margin: 0; padding: 0; background: #f6f7f9; color: #1f2937;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI",
                   "Hiragino Sans", "Yu Gothic", "Noto Sans JP", sans-serif;
      line-height: 1.5;
    }
    a { color: #2563eb; }
    a:hover { color: #1d4ed8; }
    img { max-width: 100%; height: auto; }

    /* 1800px までの可変ラッパー。中央寄せで両端 24px のパディング。
       日報カレンダーを縦長に積んだ時に、両側の白余白が目立たない
       ぐらい広めに取る。 */
    .drwp-fullwidth-wrap { max-width: 1800px; margin: 0 auto; padding: 24px 24px; box-sizing: border-box; }
    .drwp-fullwidth-content { width: 100%; }

    /* admin bar が出る (ログイン状態) ときは上から 32/46px ぶん
       下げる。WP 標準と同じ既定値 (デスクトップ 32px, モバイル 46px)。 */
    .admin-bar .drwp-fullwidth-wrap { padding-top: 56px; }
    @media (max-width: 782px) {
      .admin-bar .drwp-fullwidth-wrap { padding-top: 70px; }
    }
  </style>
</head>
<body <?php body_class('drwp-fullwidth-template'); ?>>
  <?php if (function_exists('wp_body_open')) wp_body_open(); ?>
  <div class="drwp-fullwidth-wrap">
    <main class="drwp-fullwidth-content">
      <?php
      if (have_posts()) {
          while (have_posts()) {
              the_post();
              the_content();
          }
      }
      ?>
    </main>
  </div>
  <?php wp_footer(); ?>
</body>
</html>
