# DRWP Daily Reports v1.8

v1.8 focuses on publication UX.

## Added in v1.8
- Category selection UI using WordPress categories instead of raw category IDs
- Bulk update for publication settings
- Post status control: draft / pending / future
- Scheduled publish datetime field
- Bulk re-sync to linked posts
- Existing report to post conversion keeps `linked_post_id`

## Key files
- `includes/class-drwp-admin.php`
- `includes/class-drwp-post-converter.php`
- `admin/views/report-edit.php`
- `admin/views/reports-list.php`

## Notes
This is still a prototype. The code is intended as a working scaffold, not a production-hardened plugin.


## v1.8
- 公開プレビュー画面を追加
- タグ対応を追加
- 一括公開設定更新にタグを追加
