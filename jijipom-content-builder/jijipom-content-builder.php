<?php
/**
 * Plugin Name: jijipom コンテンツビルダー
 * Plugin URI: https://nippoman.example.com/
 * Description: jijipom テーマ向けのコンテンツを画面上で組み立て、ZIP でエクスポートします。管理画面のほか、ショートコード [jijipom_builder] でフロントページにも設置できます。書き出した ZIP は「外観 > コンテンツ取込」(jijipom テーマ) から取り込みます。
 * Version: 1.12.0
 * Author: jijipom
 * Text Domain: jijipom-content-builder
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 *
 * ビルダー UI 自体は assets/builder.html (自己完結・iframe 読み込み) で、
 * 入力内容は ZIP(jijipom-content.json) にエクスポートされます。iframe は
 * 中身の高さに自動リサイズするため、入れ子のスクロールバーは出ません。
 * 取り込み(反映)は jijipom テーマ側の「外観 > コンテンツ取込」で行います。
 */

if (!defined('ABSPATH')) exit;

define('JCB_VERSION', '1.12.0');
define('JCB_PATH', plugin_dir_path(__FILE__));
define('JCB_URL', plugin_dir_url(__FILE__));

class JCB_Plugin {

    const CAP = 'edit_theme_options';
    const MENU_SLUG = 'jijipom-content-builder';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_shortcode('jijipom_builder', [__CLASS__, 'shortcode']);
    }

    public static function menu() {
        add_menu_page(
            __('jijipom コンテンツ', 'jijipom-content-builder'),
            __('jijipom コンテンツ', 'jijipom-content-builder'),
            self::CAP,
            self::MENU_SLUG,
            [__CLASS__, 'render_page'],
            'dashicons-layout',
            59
        );
    }

    public static function render_page() {
        if (!current_user_can(self::CAP)) wp_die(esc_html__('権限がありません', 'jijipom-content-builder'));
        include JCB_PATH . 'admin/page.php';
    }

    /**
     * ビルダーを埋め込む iframe(高さ自動リサイズ付き)を返す。
     * builder.html が postMessage で高さを通知し、親側で iframe の
     * height を合わせるので、iframe 自体のスクロールバーは出ない。
     */
    public static function builder_iframe($min_height = 600) {
        $src = JCB_URL . 'assets/builder.html?v=' . JCB_VERSION;
        $id  = 'jcb-frame-' . wp_rand(1000, 999999);
        ob_start();
        ?>
        <iframe id="<?php echo esc_attr($id); ?>" src="<?php echo esc_url($src); ?>" title="jijipom builder"
                scrolling="no" loading="lazy"
                style="width:100%;border:0;display:block;min-height:<?php echo (int) $min_height; ?>px;overflow:hidden;"></iframe>
        <script>
        (function () {
            var f = document.getElementById('<?php echo esc_js($id); ?>');
            if (!f) return;
            window.addEventListener('message', function (e) {
                if (f.contentWindow && e.source === f.contentWindow && e.data && e.data.jijipomBuilderHeight) {
                    var h = parseInt(e.data.jijipomBuilderHeight, 10);
                    // 実際に高さが変わった時だけ反映(2px 未満の揺れは無視)し、
                    // 余白は足さない。加算するとリサイズが際限なく繰り返される。
                    if (h > 0 && Math.abs(h - (f._jcbH || 0)) > 1) {
                        f._jcbH = h;
                        f.style.height = h + 'px';
                    }
                }
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * ショートコード [jijipom_builder] — フロントページにビルダーを設置。
     * 入力/エクスポートはブラウザ内で完結(サーバーには保存しません)。
     *
     * 属性:
     *   width="full" … 本文の最大幅(テーマの --wide-width)を無視して画面幅
     *                  いっぱいに広げる(フルブリード)。フルワイドのページ向け。
     *   min_height   … iframe の初期高さ(px)。
     */
    public static function shortcode($atts) {
        $atts = shortcode_atts(['min_height' => 600, 'width' => ''], $atts, 'jijipom_builder');
        $full = in_array(strtolower((string) $atts['width']), ['full', 'wide', 'fullwidth', '100%'], true);
        $class = 'jijipom-builder-embed' . ($full ? ' alignfull' : '');
        // フルブリード: 親の最大幅に関係なく画面幅いっぱいにする。
        $style = $full ? ' style="width:100vw;max-width:100vw;margin-left:calc(50% - 50vw);"' : '';
        return '<div class="' . esc_attr($class) . '"' . $style . '>' . self::builder_iframe((int) $atts['min_height']) . '</div>';
    }
}

JCB_Plugin::init();
