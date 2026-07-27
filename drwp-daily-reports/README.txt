=== 日報マン ===
Contributors: nippoman
Tags: daily-reports, workflow, review, japanese, construction
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.48.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

現場日報のレビュー・写真添付・公開記事化を一体化したライセンス制プラグイン。ライセンスサーバと連動して書込・記事化を有効化します。

== Description ==

「日報マン」は、現場の作業日報を WordPress 上で運用するためのプラグインです。日報の作成・写真添付・レビュー（承認/差戻し）・コメント・公開記事化までを一連のワークフローで扱えます。

主な機能:

* 日報の作成・編集・写真添付（フロント / wp-admin 両対応）
* レビュー承認・差戻し・コメント
* 案件 / 顧客 / グループ / 予定 / 社員管理
* 承認済み日報を WordPress 投稿として公開（テンプレート選択可: 標準 / 案件レポート / Before/After）
* AI 機能（Pro プラン）: 次回訪問ブリーフィング、公開記事下書き生成、案件サマリ、対応必須アラート、振り返りアドバイス（OpenAI / Anthropic 対応）
* CSV 一括出力、操作履歴（自動保存期間設定可能）
* `wp-json/drwp/v1/*` REST API
* 退職社員のログイン遮断機能
* スタンドアロンのライセンスサーバと Ed25519 署名検証で連動

ライセンスについては「ライセンス」セクションを参照してください。

== ライセンス ==

このプラグインは GNU General Public License v2 以降の下で配布されます。本体ソースコードの改変・再配布は GPLv2 の条件に従えば自由に行えます。

ただし本プラグインの **書き込み機能・記事化機能・AI 機能** は、別途発行される「日報マン」ライセンスキーとライセンスサーバの応答（Ed25519 署名）を必要とします。ライセンスキーは商用提供されており、購入後に提供されるキーを「ライセンス」設定ページに入力することで有効化されます。

ライセンスキーは GPL ライセンスの一部ではなく、別途のライセンス契約に基づいて提供されます。本プラグインのソースコードを改変して上記の検証ロジックを取り除くことは GPL 上は許諾されていますが、その配布物に「日報マン」のサービス名や本商標を含めることはできません（商標権の留保）。

== Changelog ==

= 1.70.0 =
* 予定のCSV取り込みを追加（管理画面「予定の取り込み」）。サイボウズ Office で
  書き出したスケジュールのCSVを読み込み、「予定」として登録できる。
* 列名を決め打ちにせず、CSVのどの列を予定のどの項目に入れるかを画面で
  対応付ける方式。よくある列名は自動で推測して初期値に入る。
* 文字コードはシフトJIS・UTF-8（BOM有無）のいずれにも対応。日付は
  2026/7/1・2026-07-01・2026年7月1日・日時が1列にまとまった形式を解釈する。
* サイボウズの参加者名から担当者を自動照合（表示名・社員名・ログイン名・
  メールアドレス）。一致しない名前は画面で割り当て先を指定できる。
* 同じCSVを取り込み直しても重複しないよう、予定テーブルに
  external_source / external_id を追加し、既存行は更新する（upsert）。
* 取り込み結果は監査ログに記録する。取り込みは運用者権限
  （edit_others_posts）が必要。

= 1.8.0 =
* REST API at /wp-json/drwp/v1/* with the same capability gates as the
  admin pages.
* Cross-report audit log viewer with filters, pagination, and filtered
  CSV export.
* Dashboard widget surfacing today / pending / needs_revision /
  approved counts plus recent reports.
* CSV bulk import (UTF-8, BOM optional, max 5 MB) with auto-creation
  of unknown projects.
* License server signing-key rotation: previous keys are kept so old
  signatures keep validating until clients refresh.
* Plugin verifies signatures with libsodium against current+previous
  keys.

= 1.6.0 =
* Bulk publish-settings updates, category IDs for post conversion,
  and bulk sync to linked posts.

= 1.3.0 =
* Audit log table + screen, with entries for save, review, comments,
  post conversion, project changes, and license settings.

= 1.1.0 =
* Capability model split between site staff, reviewers, and
  publishers. Menus filter by capability.
