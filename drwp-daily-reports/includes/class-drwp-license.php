<?php
if (!defined('ABSPATH')) exit;

class DRWP_License {
    public static function status() {
        return get_option('drwp_license_status', 'active');
    }

    public static function can_write() {
        return in_array(self::status(), ['active', 'grace'], true);
    }

    public static function can_convert() {
        return self::status() === 'active';
    }
}
