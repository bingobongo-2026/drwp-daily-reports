<?php
if (!defined('ABSPATH')) exit;

/**
 * Tiny settings reader for the "公開設定" page. Keeps two knobs:
 *   - post_type:        'post' (default, backwards-compatible) or 'drwp_report'
 *   - auto_thumbnail:   if true, sync_post sets the report's first
 *                       photo as the post thumbnail when none is set
 */
class DRWP_Output {
    const OPT_POST_TYPE      = 'drwp_output_post_type';
    const OPT_AUTO_THUMBNAIL = 'drwp_output_auto_thumbnail';

    const ALLOWED_TYPES = ['post', DRWP_CPT::POST_TYPE];

    public static function post_type() {
        $value = (string) get_option(self::OPT_POST_TYPE, 'post');
        return in_array($value, self::ALLOWED_TYPES, true) ? $value : 'post';
    }

    public static function auto_thumbnail() {
        $v = get_option(self::OPT_AUTO_THUMBNAIL, '1');
        return $v === '1' || $v === 1 || $v === true;
    }

    public static function settings() {
        return [
            'post_type'      => self::post_type(),
            'auto_thumbnail' => self::auto_thumbnail(),
        ];
    }

    public static function save_settings(array $values) {
        $type = (string) ($values['post_type'] ?? 'post');
        update_option(
            self::OPT_POST_TYPE,
            in_array($type, self::ALLOWED_TYPES, true) ? $type : 'post'
        );
        update_option(
            self::OPT_AUTO_THUMBNAIL,
            !empty($values['auto_thumbnail']) ? '1' : '0'
        );
    }
}
