<?php
if (!defined('ABSPATH')) exit;

/**
 * Registers the `drwp_report` custom post type. Whether new
 * conversions land in this CPT or in WP's built-in `post` is
 * controlled by DRWP_Output::post_type() — this class only handles
 * registration so the type always exists for taxonomy / archive
 * lookups, regardless of the plugin's output target.
 */
class DRWP_CPT {
    const POST_TYPE = 'drwp_report';

    public static function init() {
        add_action('init', [__CLASS__, 'register']);
    }

    public static function register() {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name'               => __('日報', 'drwp-daily-reports'),
                'singular_name'      => __('日報', 'drwp-daily-reports'),
                'menu_name'          => __('日報投稿', 'drwp-daily-reports'),
                'all_items'          => __('日報投稿一覧', 'drwp-daily-reports'),
                'add_new'             => __('新規追加', 'drwp-daily-reports'),
                'add_new_item'        => __('日報投稿を追加', 'drwp-daily-reports'),
                'edit_item'           => __('日報投稿を編集', 'drwp-daily-reports'),
                'new_item'            => __('新規日報投稿', 'drwp-daily-reports'),
                'view_item'           => __('日報投稿を表示', 'drwp-daily-reports'),
                'search_items'        => __('日報投稿を検索', 'drwp-daily-reports'),
            ],
            'public'              => true,
            'show_ui'             => true,
            'show_in_rest'        => true,
            'show_in_menu'        => false, // listed under the plugin's own menu instead
            'menu_position'       => null,
            'has_archive'         => true,
            'rewrite'             => ['slug' => 'drwp-report'],
            'supports'            => ['title', 'editor', 'thumbnail', 'excerpt', 'comments', 'author', 'revisions'],
            'taxonomies'          => ['category', 'post_tag'],
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
        ]);
    }
}
