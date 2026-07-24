<?php
/**
 * Plugin Name: jijipom コンテンツビルダー
 * Plugin URI: https://nippoman.example.com/
 * Description: jijipom テーマ向けのコンテンツを画面上で組み立て、ZIP でエクスポートします。書き出した ZIP は「外観 > コンテンツ取込」(jijipom テーマ) から取り込むと、各ページの内容と固定ページに反映されます。
 * Version: 1.1.0
 * Author: jijipom
 * Text Domain: jijipom-content-builder
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 *
 * ビルダー UI 自体は assets/builder.html (自己完結・iframe 読み込み) で、
 * 入力内容は ZIP(jijipom-content.json) にエクスポートされます。取り込み
 * (反映) は jijipom テーマ側の「外観 > コンテンツ取込」で行います。
 */

if (!defined('ABSPATH')) exit;

define('JCB_VERSION', '1.1.0');
define('JCB_PATH', plugin_dir_path(__FILE__));
define('JCB_URL', plugin_dir_url(__FILE__));

class JCB_Plugin {

    const CAP = 'edit_theme_options';
    const MENU_SLUG = 'jijipom-content-builder';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
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
}

JCB_Plugin::init();
