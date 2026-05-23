# DRWP Daily Reports — 仕様書

| 項目 | 値 |
| --- | --- |
| プラグイン名 | DRWP Daily Reports |
| バージョン | 1.10.0 |
| Text Domain | `drwp-daily-reports` |
| 対象 | WordPress 上で日報の入力 → レビュー → 公開記事化までを一気通貫で扱う業務プラグイン |

本文書は 2026 年 5 月時点の `main` ブランチに基づく。コード上の事実が正で、本文書はそれを記述したスナップショットである。

---

## 1. 概要

現場作業員(フィールドワーカー)が **スマートフォンで日報を投稿**し、事務所側の編集者が **管理画面でレビュー**して、**ブログ記事として公開**するまでを一つのプラグインに集約している。1 日に複数現場を回る案件にも対応するため、**1 日報 × N 現場エントリ** のデータモデルを持つ。

### 想定ユーザー

| 役割 | 主な WP ロール | 主な操作 |
| --- | --- | --- |
| 現場作業員 | Author / Contributor (`edit_posts`) | スマホで日報送信、自分の日報を編集 |
| 事務所編集者 | Editor (`edit_others_posts`) | 全日報のレビュー・承認・差戻し、公開タイトル/本文の作成 |
| サイト管理者 | Administrator (`manage_options`) | プロジェクト管理、CSV インポート、通知設定、公開設定、ライセンス、監査ログ |

### 用語

- **日報 (report)**: 1 日 = 1 レポート。日付・作業者で識別。
- **現場エントリ (entry)**: 同一日報内の現場ごとの作業記録。1 日報に 0 件以上(従来型フラット報告は 0 件、複数現場日報は N 件)。
- **記事化 (post conversion)**: 日報・エントリを WordPress の投稿(post)に変換する処理。
- **公開項目**: 顧客向け記事の見出しと本文 (`public_title`, `public_body` 等)。作業員が書いた内部メモ (`work_description`) とは別管理。

---

## 2. システム構成

```
[現場作業員のスマホ]
   ↓ [drwp_login_form] でログイン
   ↓ [drwp_report_form] で日報入力
   ↓ POST /wp-json/drwp/v1/reports (写真は事前に /upload-photo)
[WordPress + drwp プラグイン]
   ↓ レビュー待ちキューに入る
[事務所編集者の管理画面]
   ↓ 日報編集ページで現場ごとの公開タイトル/本文を仕上げる
   ↓ 一括操作「記事化」
[WordPress 投稿として公開]
```

データ書込・公開可能性はすべて **ライセンス状態** (`DRWP_License::can_write()`) でゲートされる。

---

## 3. データモデル

スキーマは `DRWP_DB::maybe_upgrade()` が `plugins_loaded` で `dbDelta` を実行する。`drwp_schema_version` オプションが `DRWP_VERSION` 未満のとき更新が走る。

テーブル接頭辞は WP の `$wpdb->prefix`。以降は接頭辞抜きで記載。

### 3.1 `drwp_projects` — 現場

| カラム | 型 | 説明 |
| --- | --- | --- |
| `id` | BIGINT PK auto_inc | |
| `name` | VARCHAR(255) NOT NULL | 現場名 |
| `status` | VARCHAR(32) default `active` | `active` / `archived` |
| `created_at` / `updated_at` | DATETIME | |

### 3.2 `drwp_reports` — 日報

| カラム | 型 | 説明 |
| --- | --- | --- |
| `id` | BIGINT PK auto_inc | |
| `project_id` | BIGINT NULL | レガシー(フラット報告)時の現場。マルチエントリでは NULL のまま |
| `user_id` | BIGINT NOT NULL | 投稿した作業員の WP ユーザー ID |
| `report_date` | DATE NOT NULL | 日報の対象日 |
| `work_description` / `issues` / `next_plan` | LONGTEXT NULL | レガシー: 作業内容 / 問題点 / 次回予定 |
| `review_status` | VARCHAR(32) default `pending` | `pending` / `approved` / `revision_requested` |
| `public_title` / `public_intro` / `public_body` / `public_next_plan` | VARCHAR(255) / LONGTEXT NULL | レガシー時の公開項目(事務所側で記入) |
| `post_template` | VARCHAR(64) default `standard` | `standard` / `site_report` / `before_after` |
| `post_category_id` | BIGINT NULL | 投稿時に付与するカテゴリ |
| `post_tags` | TEXT NULL | カンマ・読点・改行区切りのタグ文字列 |
| `post_status` | VARCHAR(32) default `draft` | WordPress 投稿ステータス |
| `scheduled_at` | DATETIME NULL | 予約公開時刻 |
| `linked_post_id` | BIGINT NULL | 記事化された WP 投稿の ID(1 日報 = 1 投稿時) |
| `created_at` / `updated_at` | DATETIME | |

インデックス: `report_date`, `review_status`, `linked_post_id`

### 3.3 `drwp_report_entries` — 現場エントリ

| カラム | 型 | 説明 |
| --- | --- | --- |
| `id` | BIGINT PK auto_inc | |
| `report_id` | BIGINT NOT NULL | 親日報 |
| `sort_order` | INT UNSIGNED default 0 | 表示順 |
| `project_id` | BIGINT NULL | エントリ単位の現場 |
| `started_at` / `ended_at` | TIME NULL | エントリ単位の作業時刻 |
| `work_description` / `issues` / `next_plan` | LONGTEXT NULL | エントリ単位の内部メモ |
| `public_title` | VARCHAR(255) NULL | エントリ単位の公開タイトル(事務所側) |
| `public_body` | LONGTEXT NULL | エントリ単位の公開本文(事務所側) |
| `linked_post_id` | BIGINT NULL | 記事化されたエントリ単位の WP 投稿 ID |
| `created_at` / `updated_at` | DATETIME | |

インデックス: `report_id`, `project_id`, `linked_post_id`

### 3.4 `drwp_report_photos` — 写真

| カラム | 型 | 説明 |
| --- | --- | --- |
| `id` | BIGINT PK auto_inc | |
| `report_id` | BIGINT NOT NULL | |
| `entry_id` | BIGINT NULL | エントリ単位なら設定、レガシー(報告単位)なら NULL |
| `attachment_id` | BIGINT NOT NULL | WP メディアの attachment_id |
| `caption` | VARCHAR(255) NULL | |
| `sort_order` | INT UNSIGNED | |
| `created_at` | DATETIME | |

### 3.5 `drwp_comments` — レビューコメント

| カラム | 型 | 説明 |
| --- | --- | --- |
| `id` | BIGINT PK auto_inc | |
| `report_id` | BIGINT NOT NULL | |
| `user_id` | BIGINT NOT NULL | 投稿者 |
| `body` | LONGTEXT NOT NULL | |
| `created_at` | DATETIME | |

### 3.6 `drwp_audit_logs` — 監査ログ

| カラム | 型 | 説明 |
| --- | --- | --- |
| `id` | BIGINT PK auto_inc | |
| `report_id` | BIGINT NULL | イベントが日報に紐づく場合 |
| `user_id` | BIGINT default 0 | 操作者(`0` はシステム) |
| `event` | VARCHAR(64) NOT NULL | `report_created` / `report_updated` / `entries_updated` / `report_imported` / `photos_updated` / `review_*` 等 |
| `message` | VARCHAR(255) NULL | 日本語の説明文 |
| `meta_json` | LONGTEXT NULL | 追加メタ情報の JSON |
| `created_at` | DATETIME | |

---

## 4. 役割と権限

`includes/class-drwp-admin.php` の `CAP_*` 定数。

| 定数 | 実 WP capability | 想定ロール | 用途 |
| --- | --- | --- | --- |
| `CAP_EDIT` | `edit_posts` | Author 以上(Contributor 可) | 日報の作成・自分の日報の編集、ショートコード経由の投稿、CSV インポート |
| `CAP_REVIEW` | `edit_others_posts` | Editor 以上 | 他人の日報のレビュー、承認/差戻し、全日報の閲覧・編集 |
| `CAP_CONVERT` | `publish_posts` | Editor 以上 | 一括「記事化」操作 |

加えて、設定系の管理画面サブメニューと REST の license / settings 系は `manage_options` (Administrator) を要求する。

### 「自分の日報か他人の日報か」判定

`DRWP_Admin::current_user_can_edit_report($report)` が次のルールで決定する:

1. `CAP_REVIEW` を持っていれば常に編集可
2. `CAP_EDIT` かつ `report.user_id === get_current_user_id()` なら編集可
3. それ以外は不可

REST 側も同等のロジック (`can_view_one` / `can_edit_one`) を持つ。

---

## 5. 管理画面 (Admin)

トップメニューは `drwp_reports`(ラベル: 「日報管理」、icon: `dashicons-media-spreadsheet`)。

| スラッグ | ラベル | 必須 cap | 担当クラス |
| --- | --- | --- | --- |
| `drwp_reports` | 日報一覧 | `edit_posts` | `DRWP_Admin::reports_page` |
| `drwp_report_edit` | 日報編集 | `edit_posts` | `DRWP_Admin::report_edit_page` |
| `drwp_projects` | 現場 | `manage_options` | `DRWP_Project::render_page` |
| `drwp_license` | ライセンス | `manage_options` | `DRWP_License_Admin::render_page` |
| `drwp_audit` | 操作履歴 | `manage_options` | `DRWP_Audit_Admin::render_page` |
| `drwp_csv_import` | CSV インポート | `edit_posts` | `DRWP_CSV_Import::render_page` |
| `drwp_notifications` | 通知設定 | `manage_options` | `DRWP_Notifications_Admin::render_page` |
| `drwp_output` | 公開設定 | `manage_options` | `DRWP_Output_Admin::render_page` |
| `drwp_login_settings` | ログイン設定 | `manage_options` | `DRWP_Login::render_settings_page` |
| `drwp_report_preview` | 公開プレビュー(非表示) | `edit_posts` | `DRWP_Admin::report_preview_page` |

### 5.1 日報編集ページ

`drwp_report_edit?id=N` で開く。次の 2 つのフォームを縦に並べる:

**(A) 報告本体フォーム**: `report_date`, `work_description`, `issues`, `next_plan`, `public_*`, `post_template`, `post_category_id`, `post_tags`, `post_status`, `scheduled_at` を編集。送信先は `admin-post.php` (`action=drwp_save_report`)。

**(B) 現場エントリフォーム**: エントリの追加/編集/削除。各カード = 1 エントリで、`project_id`, `started_at`, `ended_at`, `work_description`, `issues`, `next_plan`, `public_title`, `public_body`, 写真複数を編集可能。`+ 現場を追加` で hidden `<template>` をクローン、`この現場を削除` でカード単位削除。保存時は `entries_submitted=1` をマーカーに同梱して `DRWP_Report_Entry::sync` を呼ぶ。

両フォームは独立しているため、メタだけ直したい / エントリだけ仕上げたい、というワークフローに合わせて片方ずつ保存できる。

### 5.2 日報一覧

絞り込み: ステータス、日付範囲、現場、作業者。一括操作: 承認、差戻し、CSV エクスポート、**記事化(作成 or 更新)**。

---

## 6. フロント機能

### 6.1 `[drwp_report_form]` — 日報入力フォーム

`class-drwp-report-form.php`。 field worker 向けスマホフォーム。

- 未ログインなら「日報を投稿するにはログインしてください。」と表示
- `edit_posts` がなければ「権限がありません」と表示
- 初期エントリカードを 1 枚自動生成、`+ 現場を追加` で追加、2 枚以上のときのみ「この現場を削除」が出る
- 写真は撮影 / 端末選択を兼ねた `<input type="file" accept="image/*" capture="environment" multiple>`
- 送信時に **写真先行アップロード** → 完了後に `POST /reports` で本体を送る
- 完了後は緑バナーでメッセージ表示 + 自動スクロール、フォームはリセットされて再投稿可

**JS への config 引き渡し**: 当初は `wp_localize_script` / `wp_add_inline_script` を使ったが、ホストのページキャッシュや最適化プラグインが補助 `<script>` チャンクを剥がす環境があったため、**フォーム wrapper の `data-drwp-mform-config` 属性に JSON を埋め込み、JS が `getAttribute → JSON.parse` で受け取る**方式に統一した。`wpautop` / キャッシュ / 最適化のいずれにも干渉されない。

#### 設定 (PHP → JS) のキー

`rest_root`, `nonce`, `today`, `license_ok`, `projects[]`, `i18n{ add_entry, remove_entry, entry_label, pick_project, work, issues, next, photos, pick_photos, started, ended, need_project, need_work, need_entry, uploading, sending, sent, send_failed }`

#### エントリ単位で送信されるフィールド

`project_id` (required), `started_at`, `ended_at`, `work_description` (required), `issues`, `next_plan`, `attachment_ids[]`

### 6.2 `[drwp_login_form]` — ログインフォーム

`class-drwp-login.php`。

- 未ログイン時: `wp_login_form()` をスタイル付きで描画、リダイレクト先 `redirect_to` のチェーンは:
  1. `?redirect_to=...` クエリが来ていればそれ
  2. それ以外で単一ページ (`is_singular()`) ならそのページ自身
  3. それ以外なら `/wp-admin/admin.php?page=drwp_reports`
- 「パスワードをお忘れですか?」リンクは、設定で `drwp_login_lostpass_page_id` が指定されていればその固定ページへ、未設定なら `wp_lostpassword_url()` (WP 標準)
- ログイン済み時: `<div class="drwp-login-bar">` をドキュメントフロー内に描画(`[drwp_login_form]` をページ上部に置く運用前提)。「○○ さんとしてログイン中 [ログアウト]」

### 6.3 `[drwp_lostpassword_form]` — パスワード再設定フォーム

1 つのショートコードで WP 標準のパスワード再設定 2 段階フローをフロントで完結させる。配置は `drwp_login_lostpass_page_id` で指定された固定ページ本文。

URL の状態で表示が切り替わる:

| URL | 表示 |
| --- | --- |
| (param なし) | 再設定リクエストフォーム(ユーザー名 / メアド入力) |
| `?lpw=sent` | 「メールを送りました」確認画面 |
| `?key=...&login=...` | 新パスワード入力フォーム(`check_password_reset_key` で事前検証) |
| `?lpw=success` | 「更新しました、ログインへ」 |
| `?lpw=invalid_key` / `mismatch` / `weak` / `not_found` | エラー表示 + 再フォーム |

POST 処理は `template_redirect` フックで実行(出力前に `wp_safe_redirect` できる)、毎ステップで PRG(POST → Redirect → GET)を踏むので URL 共有や再読込で同じ操作が再発火しない。

再設定リンクを含むメールの URL は `retrieve_password_message` フィルタで自動的にこのフロントページに差し替わる(`/wp-login.php?action=rp&...` → `{lostpass_page}?key=...&login=...`)ため、運用者が個別にメールテンプレートを書き換える必要は無い。

### 6.4 `[drwp_report_archive]` — 過去日報の閲覧

`class-drwp-report-archive.php`。

ランディングページから「過去にどの作業者がどんな日報を書いたか」を確認できるショートコード。1 つのショートコードが 2 つのビューを URL 状態で切り替える:

- パラメータなし: 一覧 + 検索フィルタ
- `?drwp_id=N`: 単一日報の閲覧(read-only)

**閲覧範囲**: ログイン + `edit_posts` を持つユーザー全員が、すべての日報を見られる。これは admin 画面の「自分の日報だけ見える」スコープより**意図的に広い** — 過去日報はチームの記録として共有することに価値があるため。承認状態はバッジで明示。

**検索フィルタ**:
- キーワード (`drwp_q`): フリーテキスト LIKE。レポート本体の `work_description` と、各エントリの `work_description` の両方を検索(EXISTS サブクエリ)
- 作成者 (`drwp_author`): 日報を書いたことのあるユーザーのドロップダウン
- 期間 (`drwp_from` / `drwp_to`): `report_date` の YYYY-MM-DD 範囲
- ステータス (`drwp_status`): `pending` / `approved` / `revision_requested` / すべて
- 表示件数 (`drwp_per`): 10 / 20 / 50 / 100、不正値は 20 にクランプ

**ページネーション**: `drwp_p` クエリ、先頭・末尾固定 + 現在±2 を表示。

**単一ビュー**: 日付・ステータスバッジ・作成者を見出しに、続けて各現場エントリ(またはレガシーのフラット報告)の作業内容・問題点・次回予定・写真。写真はクリックで原寸を別タブ。

**自分の pending を編集** (`?drwp_id=N&drwp_edit=1`): 自分が作成した `pending` 状態の日報のみフロントから編集可能。日付・現場・時刻・作業内容・問題点・次回予定・写真(追加削除)を編集できる。`DRWP_Report_Entry::sync()` で wholesale 置換、`template_redirect` 上の POST ハンドラで PRG パターン。承認済み・差戻し・他人の日報は直接 URL を叩いてもサーバ側で拒否(defense in depth)。

**ラベル翻訳**: ステータス値 (`pending` / `approved` / `revision_requested` / `draft` / `publish` / `active` 等)は DB には英語識別子で保管、UI 出力は `DRWP_Labels::review_status()` / `post_status()` / `project_status()` で日本語ラベルに変換。マッピング一覧は `includes/class-drwp-labels.php` に集約。

### 6.5 `/wp-login.php` のリダイレクト

オプトイン(`drwp_login_redirect_enabled` チェック + `drwp_login_page_id` 設定が必要)。

`login_init` フックで `/wp-login.php` への GET を `drwp_login_page_id` のページにリダイレクト。**ホワイトリストで安全側**に倒してあり、次の場合は転送しない:

- POST(実際のサインイン送信)
- `action=` が `''` 以外で `login` 以外(`logout`, `lostpassword`, `resetpass`, `rp`, `register`, `postpass`, `confirm_admin_email`, `confirmaction`, **Two Factor の `validate_2fa`** 等)
- `interim-login=1` (admin 内セッション切れモーダル)

このホワイトリストが、**Two Factor プラグインの 2 段階認証チャレンジ画面を壊さない**インテグレーションポイントである。

---

## 7. REST API

namespace: `drwp/v1`(`/wp-json/drwp/v1/...`)、認証は WP の Cookie + REST nonce、ライセンスチェックは書込系のみ。

| メソッド | パス | 権限 | 用途 |
| --- | --- | --- | --- |
| GET | `/reports` | `edit_posts` | 一覧取得(自分のみ or 全件は権限次第) |
| POST | `/reports` | `edit_posts` + ライセンス | 新規投稿(entries[] を含む) |
| GET | `/reports/{id}` | 閲覧可能か(自分の or `edit_others_posts`) | 単一取得 |
| PATCH | `/reports/{id}` | 編集可能か(自分の or `edit_others_posts`) + ライセンス | 部分更新(entries[] 含む) |
| POST | `/reports/{id}/review` | `edit_others_posts` + ライセンス | 承認/差戻し |
| GET | `/reports/{id}/comments` | 閲覧可能か | コメント一覧 |
| POST | `/reports/{id}/comments` | 閲覧可能か + ライセンス | コメント追加 |
| GET | `/reports/{id}/audit` | 閲覧可能か | 監査ログ取得 |
| GET | `/projects` | `edit_posts` | 現場一覧 |
| GET | `/license` | `edit_posts` | ライセンス状態取得 |
| POST | `/upload-photo` | `edit_posts` + ライセンス | 写真 1 枚アップロード(multipart/form-data) |

### `entries[]` の更新セマンティクス

PATCH `/reports/{id}` で `entries` キーが含まれていれば、それを **完全置換**として `DRWP_Report_Entry::sync()` に渡す。キーが含まれていなければエントリは無変更(部分更新)。

---

## 8. CSV インポート

`includes/class-drwp-csv-import.php`、エンドポイント: 管理画面 「日報 → CSV インポート」 (`drwp_csv_import`)、最大 5MB、UTF-8 / UTF-8 BOM / CP932 (SJIS-win) / EUC-JP / JIS / ASCII を自動判別。

**モード判定**: ヘッダに `entry_group` 列があれば **マルチエントリモード**、なければ **レガシーモード**。

### 8.1 レガシーモード(1 行 = 1 日報)

必須: `report_date`, `work_description`

任意: `project_name`, `issues`, `next_plan`, `public_title`, `public_intro`, `public_body`, `public_next_plan`, `post_template`, `post_tags`

`project_name` が未登録なら自動作成。

### 8.2 マルチエントリモード(1 行 = 1 現場エントリ)

ヘッダ:

- **必須**: `entry_group`, `report_date`, `work_description`
- **エントリ単位**(各行から): `project_name`, `started_at`, `ended_at`, `work_description`, `issues`, `next_plan`, `entry_public_title`, `entry_public_body`
- **レポート単位**(各グループの先頭行から): `report_date`, `post_template`, `post_tags`, `post_status`, `post_category_id`, `scheduled_at`

挙動:

- 同じ `entry_group` 値の行が 1 つの日報のエントリにまとまる
- 空欄の `entry_group` は synthetic group(`__row_N`)になり、1 行 = 1 エントリの日報になる(ファイル内でフラット/エントリ式が混在しないように)
- グループ内の全行で `work_description` が空のときは親レポートも作成しない(ghost record 防止)
- 日付不正の行は当該グループだけ拒否、他は通る

写真は CSV インポートでは扱わない(バイナリ → CSV はワークフロー外)。

---

## 9. 記事化 (Post Conversion)

`includes/class-drwp-post-converter.php`、トリガーは管理画面の一括操作 `bulk_convert`(`DRWP_Admin::bulk_reports`)。

### 9.1 投稿数

| 元 | 生成される投稿数 |
| --- | --- |
| エントリ無しのレガシー日報 | 1 投稿 |
| 1 エントリの日報 | 1 投稿 |
| N エントリの日報 | N 投稿(現場ごと) |

### 9.2 タイトル / 本文のフォールバック

**レガシー(報告単位)**

- タイトル: `report.public_title` → 「現場レポート」
- 本文: `public_intro` + `<h2>本日の作業内容</h2>` + `public_body` + 写真ギャラリー + `<h2>今後の予定</h2>` + `public_next_plan`(設定があるものだけ出力)

**マルチエントリ(エントリ単位)**

- タイトル: `entry.public_title` → 「{現場名} - {日付}」
- 本文: `entry.public_body` → `entry.work_description`(内部メモが顧客向け記事に漏れない安全装置でもある)、続けて写真ギャラリー

### 9.3 投稿メタの反映

- `post_status`: `report.post_status`(`draft` / `pending` / `future`)。`future` の場合 `scheduled_at` を `post_date_gmt` に反映
- `post_type`: `DRWP_Output::post_type()` (`drwp_output_post_type` オプション。デフォルト `post`、custom post type 可)。再 sync では既存投稿の `post_type` を維持(permalink を壊さないため)
- カテゴリ: `report.post_category_id` を `wp_set_post_categories`
- タグ: `report.post_tags` を「カンマ・読点・改行」で分割して `wp_set_post_tags`
- アイキャッチ: `DRWP_Output::auto_thumbnail()` が true かつ既存アイキャッチ無しの場合、最初の写真を `set_post_thumbnail`

### 9.4 連携 ID の保存

- レガシー: `report.linked_post_id`
- エントリ単位: `entry.linked_post_id`(各エントリが自分の投稿を持つ)、`report.linked_post_id` は先頭エントリの投稿 ID

再記事化(再 sync)時は `linked_post_id` を見て既存投稿を上書き更新する。

---

## 10. 通知

`includes/class-drwp-notifications.php`、管理画面: 「通知設定」(`drwp_notifications`)。

トリガー(オプションで個別 ON/OFF):

| イベント | オプション | 既定 |
| --- | --- | --- |
| 日報が投稿された | `drwp_notify_on_pending` | OFF |
| レビューステータスが変わった | `drwp_notify_on_review` | OFF |
| コメントが付いた | `drwp_notify_on_comment` | OFF |

`drwp_notify_enabled` 全体スイッチが必要。送信者 From アドレスは `drwp_notify_from_email`。

---

## 11. ログイン / 2FA

`includes/class-drwp-login.php`、管理画面: 「ログイン設定」(`drwp_login_settings`)。

### 11.1 設定項目

| オプション | 説明 |
| --- | --- |
| `drwp_login_redirect_enabled` | `/wp-login.php` をカスタムページに転送する |
| `drwp_login_page_id` | ログインフォームを置いた固定ページ ID |
| `drwp_login_lostpass_page_id` | `[drwp_lostpassword_form]` を置いた固定ページ ID。設定時は再設定リンク + 再設定メール本文の URL が両方ここに向く |
| `drwp_login_admin_lockdown` | 編集者未満が `/wp-admin/` にアクセスしたらフロントへ強制リダイレクトする(後述) |

### 11.2 2 段階認証 (TOTP / Google Authenticator)

**自前実装はしない**。WordPress 公式の Two Factor プラグイン
(<https://wordpress.org/plugins/two-factor/>) に委譲。

- 設定ページで `class_exists('Two_Factor_Core')` を見て有効/未インストールを判定
- 有効: プロフィール画面 (`profile.php#two-factor-options`) へのリンクを表示
- 未インストール: プラグイン検索画面へのインストール導線を表示

セットアップ手順:

1. Two Factor プラグインをインストール&有効化
2. 各ユーザーが Users → Profile → 「Two-Factor Options」で TOTP を有効化
3. 表示された QR コードを Google Authenticator / Authy / 1Password 等で読み取り
4. 6 桁コードを入力して登録、リカバリコードを安全に保管

「Time Based One-Time Password (TOTP)」を有効にしたユーザーは、`/wp-login.php` のサインイン後に Two Factor のチャレンジ画面 (`action=validate_2fa`) に進む。drwp の `/wp-login.php` リダイレクトはこの action を**転送しない**ため、Two Factor の動作に介入しない。

### 11.3 wp-login.php ブランディング

`login_enqueue_scripts` フックで `public/assets/wp-login.css` を流し、サインイン画面・パスワード再設定の最終画面・Two Factor の TOTP チャレンジ画面の見た目をブランドに揃える(背景色、フォーム枠、ボタン色 `#111827`)。

`login_headerurl` / `login_headertext` フィルタで、ロゴリンクの遷移先を `home_url()`、テキストを `get_bloginfo('name')` に差し替える。

WP コアの HTML 構造は変更しないため、Two Factor / その他のログイン系プラグインの追加 UI も自動的に同じ外観になる。

### 11.4 管理画面ロックダウン

`drwp_login_admin_lockdown` を ON にすると、**`edit_others_posts` を持たないユーザー**(寄稿者・投稿者・購読者など)が `/wp-admin/` を叩いた瞬間にフロントに弾かれる:

- 転送先: `drwp_login_page_id` 設定があればそのページ、なければ `home_url('/')`
- 例外(通す admin エンドポイント):
  - `profile.php` — Two Factor の TOTP 登録 / パスワード変更
  - `admin-ajax.php` — Two Factor 含む各プラグインの ajax 通信
  - `admin-post.php` — フォーム POST 先
- `wp_doing_ajax()` / `DOING_CRON` の処理は無条件で通す
- `show_admin_bar` フィルタも同じ条件で false を返し、フロントヘッダの admin bar を非表示化

これにより「事務所側はフル admin、現場作業員はフロント完結」の役割分離が UI レイヤーで強制される。

---

## 12. ライセンス

`includes/class-drwp-license.php` + `class-drwp-license-admin.php`。

書込系の操作(REST POST/PATCH、admin の save、CSV インポート、写真アップロード)は `DRWP_License::can_write()` を通る。

ライセンスサーバから署名付きの状態を受け取り、`drwp_license_*` オプションに保存。次の状態を持つ:

- `active`: 書込 OK
- `inactive`: 書込ブロック、UI に「ライセンス未有効」を表示
- `unknown`: API 失敗時。`drwp_license_last_valid_at` から **猶予期間内**なら書込 OK、超過したら inactive 同様にブロック

API 応答は `drwp_license_public_key` で署名検証する。鍵ローテーションは `drwp_license_previous_keys` に旧鍵を退避することで対応する。

---

## 13. 監査ログ

`drwp_audit_logs` テーブル。`DRWP_Audit::log($event, $message, $report_id, $meta)` を各所から呼ぶ。

主なイベント:

- `report_created` / `report_updated` / `report_imported`
- `entries_updated`
- `photos_updated`
- `review_approved` / `review_rejected` / `review_requested_revision`
- `comment_added`
- ライセンス系・通知系イベント

管理画面の「操作履歴」で全ログを閲覧、`/wp-json/drwp/v1/reports/{id}/audit` でログを取得。

---

## 14. 設定オプション一覧

機能ごとにまとめる。すべて `wp_options` の row(`option_name`)。

### ライセンス (`class-drwp-license.php`)

`drwp_license_api_url`, `drwp_license_key`, `drwp_license_status`, `drwp_license_plan`, `drwp_license_expires_at`, `drwp_license_checked_at`, `drwp_license_last_valid_at`, `drwp_license_last_message`, `drwp_license_public_key`, `drwp_license_previous_keys`, `drwp_license_signature_valid`, `drwp_license_admin_token`(または定数 `DRWP_LICENSE_ADMIN_TOKEN`)

### 通知 (`class-drwp-notifications.php`)

`drwp_notify_enabled`, `drwp_notify_on_pending`, `drwp_notify_on_review`, `drwp_notify_on_comment`, `drwp_notify_from_email`

### 公開設定 (`class-drwp-output.php`)

`drwp_output_post_type`(`post` 既定、カスタム投稿タイプ可), `drwp_output_auto_thumbnail`

### ログイン / 2FA (`class-drwp-login.php`)

`drwp_login_page_id`, `drwp_login_redirect_enabled`, `drwp_login_lostpass_page_id`, `drwp_login_admin_lockdown`

### スキーマ (`class-drwp-db.php`)

`drwp_schema_version`

---

## 15. アセット

| ファイル | 役割 |
| --- | --- |
| `admin/assets/admin.css` | 管理画面共通 CSS(日報編集ページのエントリカード等) |
| `admin/assets/admin.js` | 管理画面 JS(写真ピッカー、エントリ動的追加/削除、メディアライブラリ連携) |
| `public/assets/mobile-form.css` | `[drwp_report_form]` 用 CSS |
| `public/assets/mobile-form.js` | `[drwp_report_form]` 用 JS(config は wrapper の data 属性から読む) |
| `public/assets/login.css` | `[drwp_login_form]` / `[drwp_lostpassword_form]` 用 CSS + ログイン状態バー |
| `public/assets/wp-login.css` | `/wp-login.php` ブランディング(背景色、フォーム枠、ボタン色) |
| `public/assets/archive.css` | `[drwp_report_archive]` 用 CSS(一覧カード・フィルタ・単一ビュー・フロント編集フォーム) |
| `public/assets/archive-edit.js` | フロント編集フォームの動的追加/削除・写真アップロード JS |

---

## 16. 拡張・運用上の注意

- **アセット配信経路**: `[drwp_report_form]` の config を `wp_localize_script` や `wp_add_inline_script` で出すと、ホストのキャッシュ/最適化プラグインに剥がされる事故が観測されている(LiteSpeed Cache, Autoptimize 等)。本プラグインは **wrapper 要素の data 属性に JSON を埋め込む**方式に統一しているので、新規にフロント機能を足すときは同じパターンを推奨する。
- **`wpautop` との共存**: ショートコード返り値文字列に `<script>` / `<style>` を直接含めると `wpautop` に壊される。CSS/JS は必ず enqueue 経由、データは data 属性で渡す。
- **エントリ無し報告との後方互換**: 既存のレガシーフラット報告は壊さず動かす。記事化のフォールバックチェーンも両方を扱う。
- **CSV インポートのフラット/エントリ判定**: ヘッダの `entry_group` 列の **有無**だけがモード切替トリガー。`entry_group` 列があれば全行をエントリ式として扱う(ファイル内混在を防ぐ)。
- **2FA は自作しない**: 自前 TOTP 実装はセキュリティリスクが大きい(鍵保管・タイムスキュー・リカバリコード・リプレイ防止)。Two Factor プラグインに委譲する方針を維持する。

---

## 17. バージョン履歴(主要変更)

| バージョン | 主な変更 |
| --- | --- |
| 1.10.0 | エントリ単位の `public_title` / `public_body` 追加 / CSV マルチエントリ対応 / 管理画面でエントリ追加削除可 |
| 1.9.x | マルチエントリ基盤(`drwp_report_entries`, 写真の `entry_id` 紐付け, REST に `entries[]` 追加, スマホフォーム) |
| (それ以前) | レガシーフラット報告のみ、レビュー/記事化/CSV/通知/ライセンス |
