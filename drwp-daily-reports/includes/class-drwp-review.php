<?php
if (!defined('ABSPATH')) exit;

/**
 * レビュー状態の定義。かつてここにあった admin-post ハンドラ
 * (drwp_review_report / drwp_add_comment) は、どの画面からも参照されない
 * 死にコードだったため撤去した。レビューとコメントは REST
 * (POST /reports/{id}/review, /reports/{id}/comments) に一本化されている。
 */
class DRWP_Review {
    const ALLOWED_STATUSES = ['pending', 'approved', 'needs_revision', 'edit_requested'];
}
