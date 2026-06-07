# DRWP Daily Reports — 仕様書

| 項目 | 値 |
| --- | --- |
| プラグイン名 | DRWP Daily Reports |
| バージョン | 1.11.0 |
| Text Domain | `drwp-daily-reports` |
| 対象 | WordPress 上で日報の入力 → レビュー → 公開記事化までを一気通貫で扱う業務プラグイン |

本文書は 2026 年 5 月時点の `main` ブランチに基づく。コード上の事実が正で、本文書はそれを記述したスナップショットである。

---

## 1. 概要

現場作業員(フィールドワーカー)が **スマートフォンで現場ごとに日報を投稿**し、事務所側の編集者が **管理画面でレビュー**して、**ブログ記事として公開**するまでを一つのプラグインに集約している。**1 現場 = 1 日報** のフラット構造で、開始時刻 / 終了時刻を各日報に記録する。1 日に複数現場を回った日は複数の日報を立てる。

### 想定ユーザー

| 役割 | 主な WP ロール | 主な操作 |
| --- | --- | --- |
| 現場作業員 | Author / Contributor (`edit_posts`) | スマホで日報送信、自分の日報を編集 |
| 事務所編集者 | Editor (`edit_others_posts`) | 全日報のレビュー・承認・差戻し、公開タイトル/本文の作成 |
| サイト管理者 | Administrator (`manage_options`) | プロジェクト管理、通知設定、公開設定、ライセンス、監査ログ |

### 用語

- **日報 (report)**: 1 現場 = 1 レポート。日付と現場で識別。
- **記事化 (post conversion)**: 日報を WordPress の投稿(post)に変換する処理。1 日報 = 1 投稿。
- **公開項目**: 顧客向け記事の見出しと本文 (`public_title`, `public_intro`, `public_body`, `public_next_plan`)。作業員が書いた内部メモ (`work_description`) とは別管理。

---

## 2. システム構成

```
[現場作業員のスマホ]
   ↓ [drwp_login_form] でログイン
   ↓ [drwp_report_form] で日報入力(1 現場ずつ)
   ↓ POST /wp-json/drwp/v1/reports (写真は事前に /upload-photo)
[WordPress + drwp プラグイン]
   ↓ レビュー待ちキューに入る
[事務所編集者の管理画面]
   ↓ 日報編集ページで公開タイトル/本文を仕上げる
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
| `status` | VARCHAR(32) default `active` | `active` / `inactive` / `archived` |
| `created_at` / `updated_at` | DATETIME | |

### 3.2 `drwp_reports` — 日報

| カラム | 型 | 説明 |
| --- | --- | --- |
| `id` | BIGINT PK auto_inc | |
| `project_id` | BIGINT NULL | 現場 |
| `user_id` | BIGINT NOT NULL | 投稿した作業員の WP ユーザー ID |
| `report_date` | DATE NOT NULL | 日報の対象日 |
| `started_at` / `ended_at` | TIME NULL | 開始時刻 / 終了時刻(HH:MM:SS) |
| `work_description` / `issues` / `next_plan` | LONGTEXT NULL | 作業内容 / 問題点 / 次回予定(作業員入力) |
| `review_status` | VARCHAR(32) default `pending` | `pending` / `approved` / `needs_revision` |
| `public_title` / `public_intro` / `public_body` / `public_next_plan` | VARCHAR(255) / LONGTEXT NULL | 公開項目(事務所側で記入) |
| `post_template` | VARCHAR(64) default `standard` | `standard` / `site_report` / `before_after` |
| `post_category_id` | BIGINT NULL | 投稿時に付与するカテゴリ |
| `post_tags` | TEXT NULL | カンマ・読点・改行区切りのタグ文字列 |
| `post_status` | VARCHAR(32) default `draft` | WordPress 投稿ステータス |
| `scheduled_at` | DATETIME NULL | 予約公開時刻 |
| `linked_post_id` | BIGINT NULL | 記事化された WP 投稿の ID |
| `created_at` / `updated_at` | DATETIME | |

インデックス: `report_date`, `review_status`, `linked_post_id`

### 3.3 `drwp_report_photos` — 写真

| カラム | 型 | 説明 |
| --- | --- | --- |
| `id` | BIGINT PK auto_inc | |
| `report_id` | BIGINT NOT NULL | |
| `entry_id` | BIGINT NULL | v1.10 までのマルチエントリの名残カラム。新規行は常に NULL |
| `attachment_id` | BIGINT NOT NULL | WP メディアの attachment_id |
| `caption` | VARCHAR(255) NULL | |
| `sort_order` | INT UNSIGNED | |
| `created_at` | DATETIME | |

### 3.4 `drwp_comments` — レビューコメント

| カラム | 型 | 説明 |
| --- | --- | --- |
| `id` | BIGINT PK auto_inc | |
| `report_id` | BIGINT NOT NULL | |
| `user_id` | BIGINT NOT NULL | 投稿者 |
| `body` | LONGTEXT NOT NULL | |
| `created_at` | DATETIME | |

### 3.5 `drwp_audit_logs` — 監査ログ

| カラム | 型 | 説明 |
| --- | --- | --- |
| `id` | BIGINT PK auto_inc | |
| `report_id` | BIGINT NULL | イベントが日報に紐づく場合 |
| `user_id` | BIGINT default 0 | 操作者(`0` はシステム) |
| `event` | VARCHAR(64) NOT NULL | `report_created` / `report_updated` / `report_imported` / `photos_updated` / `review_status_changed` 等 |
| `message` | VARCHAR(255) NULL | 日本語の説明文 |
| `meta_json` | LONGTEXT NULL | 追加メタ情報の JSON |
| `created_at` | DATETIME | |

### 3.6 `drwp_customer_groups` — 顧客グループ

| カラム | 型 | 説明 |
| --- | --- | --- |
| `id` | BIGINT PK auto_inc | |
| `name` | VARCHAR(255) NOT NULL | グループ名 |
| `color` | VARCHAR(7) NULL | `#RRGGBB` ハイフン区切りの 16 進値。顧客一覧で名前横のドット表示に使う UI ヒント。空欄可 |
| `notes` | TEXT NULL | フリーテキストメモ(`wp_kses_post`) |
| `status` | VARCHAR(32) default `active` | `active` / `inactive` |
| `created_at` / `updated_at` | DATETIME | |

### 3.7 `drwp_customer_group_map` — 顧客 ↔ グループ

| カラム | 型 | 説明 |
| --- | --- | --- |
| `customer_id` | BIGINT NOT NULL | `drwp_customers.id` |
| `group_id` | BIGINT NOT NULL | `drwp_customer_groups.id` |
| `created_at` | DATETIME | |

主キーは `(customer_id, group_id)` の複合キー。逆引き(あるグループに属する顧客一覧)のため `group_id` 単独の KEY も持つ。`DRWP_Customer_Group::set_for_customer` は顧客 1 件分を delete → bulk insert する idempotent な API。

### v1.11 で削除されたテーブル

`drwp_report_entries` テーブル(v1.9〜1.10 の「1 日報 × N エントリ」用)は `DRWP_DB::maybe_upgrade()` で `DROP TABLE IF EXISTS` される。`drwp_report_photos.entry_id` はカラムは残るが、`UPDATE ... SET entry_id = NULL` で参照が外れる。

---

## 4. 役割と権限

`includes/class-drwp-admin.php` の `CAP_*` 定数。

| 定数 | 実 WP capability | 想定ロール | 用途 |
| --- | --- | --- | --- |
| `CAP_EDIT` | `edit_posts` | Author 以上(Contributor 可) | 日報の作成・自分の日報の編集、ショートコード経由の投稿 |
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

トップメニューは `drwp_reports`(ラベル: 「日報管理」、icon: `dashicons-media-spreadsheet`) のみ。WP の標準ホバー・フライアウト挙動に乗るため、サブメニューは全て同階層のフラット構成で並べる。「日報管理」にマウスホバーすると 日報一覧 〜 操作履歴 までが順に表示される。

並び順は `DRWP_Admin::menu()` での `add_submenu_page` 呼び出し順で固定。ログイン設定の render 関数は `DRWP_Login::render_settings_page` だが、サブメニュー登録自体は順序を一元管理するため `DRWP_Admin::menu()` から行う。

`manage_options` 必須の設定系 6 項目(公開設定 / ログイン設定 / 通知設定 / AI設定 / ライセンス / 操作履歴) は、編集者ロールのユーザーには WP 標準のキャパビリティチェックでそもそも表示されない。サイドバー上は CSS のみで「設定」グループとして見せる:

- `DRWP_Admin::mark_settings_section` が `admin_menu` priority 999 で `$submenu['drwp_reports']` を回り、設定系 6 行の `<li>` クラス枠 (`$item[4]`) に `drwp-settings-child` を、先頭行(公開設定)に追加で `drwp-settings-first` を付与する。
- `DRWP_Admin::settings_section_css` が `admin_head` で、設定系行をやや字下げ、先頭行に上罫線 + `::before` で「設定」ラベルを表示するスタイルを出力する。
- サブメニュー登録順序や構造には触らないので、WP のホバーフライアウトはそのまま全 12 項目を表示する。

### 日報管理 配下

| スラッグ | ラベル | 必須 cap | 担当クラス |
| --- | --- | --- | --- |
| `drwp_reports` | 日報一覧 | `edit_posts` | `DRWP_Admin::reports_page` |
| `drwp_operations` | 日報操作 | `edit_posts` | `DRWP_Admin::operations_page` |
| `drwp_articles` | 記事作成 | `publish_posts` | `DRWP_Admin::articles_page` |
| `drwp_projects` | 案件 | `manage_options` | `DRWP_Project::render_page` |
| `drwp_customers` | 顧客 | `manage_options` | `DRWP_Customer::render_page` |
| `drwp_customer_groups` | グループ | `manage_options` | `DRWP_Customer_Group::render_page` |
| `drwp_print` | PDF出力 | `edit_posts` | `DRWP_Print::render_page` |
| `drwp_output` | 公開設定 | `manage_options` | `DRWP_Output_Admin::render_page` |
| `drwp_login_settings` | ログイン設定 | `manage_options` | `DRWP_Login::render_settings_page` |
| `drwp_notifications` | 通知設定 | `manage_options` | `DRWP_Notifications_Admin::render_page` |
| `drwp_ai` | AI設定 | `manage_options` | `DRWP_AI_Admin::render_page` |
| `drwp_license` | ライセンス | `manage_options` | `DRWP_License_Admin::render_page` |
| `drwp_audit` | 操作履歴 | `manage_options` | `DRWP_Audit_Admin::render_page` |
| `drwp_report_edit` | 日報編集(リンク経由のみ) | `edit_posts` | `DRWP_Admin::report_edit_page` |
| `drwp_report_preview` | 公開プレビュー(非表示) | `edit_posts` | `DRWP_Admin::report_preview_page` |

### 5.1 日報編集ページ

`drwp_report_edit?id=N` で開く。カード形式のセクションで構成。

- **基本情報**: 現場、日付、開始時刻 / 終了時刻、作業内容、問題点、次回予定
- **公開設定**: 公開タイトル、導入文、公開本文、公開用今後の予定、テンプレート、カテゴリ、タグ、投稿状態、予約日時
- **写真**: 報告本体に紐づく写真の追加 / キャプション編集 / 並び替え
- **(下部) レビュー操作**(Editor 以上のみ)、コメント、操作履歴

保存は単一のフォーム(`drwp_save_report` action)、ボタンも 1 個。

### 5.2 現場ページ

`drwp_projects`。

- 新規追加フォーム(現場名 + 状態)
- 登録済み現場リスト
- 各行に「**編集**」ボタン → `?edit_id=N` でフォームに pre-fill、編集中の行はハイライト
- 状態ラベルは `DRWP_Labels::project_status()` で日本語表示

### 5.3 日報一覧

絞り込み: ステータス、日付範囲、現場、作業者。一括操作: 承認、差戻し、CSV エクスポート、**記事化(作成 or 更新)**。

---

## 6. フロント機能

### 6.1 `[drwp_report_form]` — 自分の日報ハブ + 入力フォーム

`class-drwp-report-form.php`。field worker 向けスマホ向けハブ。1 つのショートコードが URL 状態で 2 つのビューに切り替わる:

- パラメータなし: **自分の日報リスト** + 検索フィルタ + 上部に「+ 日報を書く」ボタン
- `?drwp_new=1`: **入力フォーム** + 「← 一覧に戻る」リンク

#### 共通の前提

- 未ログインなら「日報を投稿するにはログインしてください。」と表示
- `edit_posts` がなければ「権限がありません」と表示

#### 自分の日報リスト (default)

current_user_id() でスコープを固定。他人の日報は出ない(チーム横断は `[drwp_report_archive]` を使う)。

絞り込み:
- キーワード (`drwp_q`): `work_description` の LIKE
- 現場 (`drwp_project`): ドロップダウン。自分が書いたことのある現場のみ表示(短く保つため)
- 期間 (`drwp_from` / `drwp_to`): `report_date` の YYYY-MM-DD 範囲
- ステータス (`drwp_status`): `pending` / `approved` / `needs_revision` / すべて
- 表示件数 (`drwp_per`): 10 / 20 / 50

各リスト行: 日付 / ステータスバッジ / 現場名 / 時刻 / 作業内容 80 字スニペット。

ページネーション: prev / next + 「N / 総ページ数」インジケータ。

#### 入力フォーム (`?drwp_new=1`)

1 フォーム = 1 現場 = 1 日報。**カード追加機能は無し**(複数現場の日はフォームを連続投稿)。

入力項目: 日付、現場、開始時刻、終了時刻、作業内容、問題点、次回予定、写真。写真は撮影 / 端末選択を兼ねた `<input type="file" accept="image/*" capture="environment" multiple>`。送信時に **写真先行アップロード** → 完了後に `POST /reports` で本体を送る。完了後は緑バナーでメッセージ表示 + 自動スクロール、フォームはリセットされて連続投稿可能(ハブの一覧に戻りたい場合は上部の「← 一覧に戻る」)。

**JS への config 引き渡し**: フォーム wrapper の `data-drwp-mform-config` 属性に JSON を埋め込み、JS が `getAttribute → JSON.parse` で受け取る(ホストのページキャッシュや最適化プラグインが補助 `<script>` チャンクを剥がす環境への対策)。

config キー: `rest_root`, `nonce`, `today`, `license_ok`, `projects[]`, `i18n{ pick_project, need_project, need_work, uploading, sending, sent, send_failed }`

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

POST 処理は `template_redirect` フックで実行、毎ステップで PRG を踏む。再設定メールの URL は `retrieve_password_message` フィルタで自動的にこのフロントページに差し替わる。

### 6.4 `[drwp_report_archive]` — 過去日報の閲覧

`class-drwp-report-archive.php`。

ランディングページから「過去にどの作業者がどんな日報を書いたか」を確認できるショートコード。1 つのショートコードが URL 状態で 3 つのビューを切り替える:

- パラメータなし: 一覧 + 検索フィルタ
- `?drwp_id=N`: 単一日報の閲覧(read-only)
- `?drwp_id=N&drwp_edit=1`: 自分の `pending` 日報のフロント編集

**閲覧範囲**: ログイン + `edit_posts` を持つユーザー全員が、すべての日報を見られる(チーム共有を意図したスコープ)。

**検索フィルタ**:
- キーワード (`drwp_q`): フリーテキスト LIKE。`work_description` を検索
- 作成者 (`drwp_author`): 日報を書いたことのあるユーザーのドロップダウン
- 期間 (`drwp_from` / `drwp_to`): `report_date` の YYYY-MM-DD 範囲
- ステータス (`drwp_status`): `pending` / `approved` / `needs_revision` / すべて
- 表示件数 (`drwp_per`): 10 / 20 / 50 / 100、不正値は 20 にクランプ

**ページネーション**: `drwp_p` クエリ、先頭・末尾固定 + 現在±2 を表示。

**単一ビュー**: 日付、現場名、ステータスバッジ、作成者、開始/終了時刻、作業内容、問題点、次回予定、写真。

**自分の pending を編集** (`?drwp_id=N&drwp_edit=1`): 自分が作成した `pending` 状態の日報のみフロントから編集可能。日付・現場・時刻・作業内容・問題点・次回予定・写真(追加削除)を編集できる。`template_redirect` の POST ハンドラで PRG パターン。承認済み・差戻し・他人の日報は直接 URL を叩いてもサーバ側で拒否(defense in depth)。

**ラベル翻訳**: ステータス値 (`pending` / `approved` / `needs_revision` / `draft` / `publish` / `active` 等)は DB には英語識別子で保管、UI 出力は `DRWP_Labels::review_status()` / `post_status()` / `project_status()` で日本語ラベルに変換。マッピング一覧は `includes/class-drwp-labels.php` に集約。

### 6.5 `/wp-login.php` のリダイレクト

オプトイン(`drwp_login_redirect_enabled` チェック + `drwp_login_page_id` 設定が必要)。

`login_init` フックで `/wp-login.php` への GET を `drwp_login_page_id` のページにリダイレクト。ホワイトリスト方式で安全側に倒してあり、次の場合は転送しない:

- POST(実際のサインイン送信)
- `action=` が `''` 以外で `login` 以外(`logout`, `lostpassword`, `resetpass`, `rp`, `register`, `postpass`, `confirm_admin_email`, `confirmaction`, Two Factor の `validate_2fa` 等)
- `interim-login=1` (admin 内セッション切れモーダル)

このホワイトリストが、**Two Factor プラグインの 2 段階認証チャレンジ画面を壊さない**インテグレーションポイントである。

---

## 7. REST API

namespace: `drwp/v1`(`/wp-json/drwp/v1/...`)、認証は WP の Cookie + REST nonce、ライセンスチェックは書込系のみ。

| メソッド | パス | 権限 | 用途 |
| --- | --- | --- | --- |
| GET | `/reports` | `edit_posts` | 一覧取得(自分のみ or 全件は権限次第) |
| POST | `/reports` | `edit_posts` + ライセンス | 新規投稿 |
| GET | `/reports/{id}` | 閲覧可能か(自分の or `edit_others_posts`) | 単一取得 |
| PATCH | `/reports/{id}` | 編集可能か(自分の or `edit_others_posts`) + ライセンス | 部分更新 |
| POST | `/reports/{id}/review` | `edit_others_posts` + ライセンス | 承認/差戻し |
| GET | `/reports/{id}/comments` | 閲覧可能か | コメント一覧 |
| POST | `/reports/{id}/comments` | 閲覧可能か + ライセンス | コメント追加 |
| GET | `/reports/{id}/audit` | 閲覧可能か | 監査ログ取得 |
| GET | `/projects` | `edit_posts` | 現場一覧 |
| GET | `/license` | `edit_posts` | ライセンス状態取得 |
| POST | `/upload-photo` | `edit_posts` + ライセンス | 写真 1 枚アップロード(multipart/form-data) |

### 受け付ける入力フィールド (POST/PATCH /reports)

`project_id`, `report_date`, `started_at`, `ended_at`, `work_description`, `issues`, `next_plan`, `public_title`, `public_intro`, `public_body`, `public_next_plan`, `post_template`, `post_category_id`, `post_tags`, `post_status`, `scheduled_at`, `attachment_ids[]`, `attachment_captions[]`

PATCH は部分更新。`attachment_ids` キーが含まれていれば写真リンクテーブルを置換、含まれていなければ無変更。

---

## 8. 記事化 (Post Conversion)

`includes/class-drwp-post-converter.php`、トリガーは管理画面の一括操作 `bulk_convert`(`DRWP_Admin::bulk_reports`)。

**1 日報 = 1 投稿**。

### 9.1 タイトル / 本文の組み立て

- タイトル: `report.public_title`(空欄なら「現場レポート」)
- 本文:
  1. `public_intro`(設定があれば `<p>` で出力)
  2. `<h2>本日の作業内容</h2>` + `public_body`(`public_body` が設定されている時のみ)
  3. 写真ギャラリー(`drwp_report_photos`)
  4. `<h2>今後の予定</h2>` + `public_next_plan`(設定があれば)

### 9.2 投稿メタの反映

- `post_status`: `report.post_status`(`draft` / `pending` / `future`)。`future` の場合 `scheduled_at` を `post_date_gmt` に反映
- `post_type`: `DRWP_Output::post_type()` (`drwp_output_post_type` オプション。デフォルト `post`、custom post type 可)。再 sync では既存投稿の `post_type` を維持(permalink を壊さないため)
- カテゴリ: `report.post_category_id` を `wp_set_post_categories`
- タグ: `report.post_tags` を「カンマ・読点・改行」で分割して `wp_set_post_tags`
- アイキャッチ: `DRWP_Output::auto_thumbnail()` が true かつ既存アイキャッチ無しの場合、最初の写真を `set_post_thumbnail`

### 9.3 連携 ID

`report.linked_post_id` に保存。再記事化時はこれを見て既存投稿を上書き更新する。

---

## 9. 通知

`includes/class-drwp-notifications.php`、管理画面: 「通知設定」(`drwp_notifications`)。

トリガー(オプションで個別 ON/OFF):

| イベント | オプション | 既定 |
| --- | --- | --- |
| 日報が投稿された | `drwp_notify_on_pending` | OFF |
| レビューステータスが変わった | `drwp_notify_on_review` | OFF |
| コメントが付いた | `drwp_notify_on_comment` | OFF |

`drwp_notify_enabled` 全体スイッチが必要。送信者 From アドレスは `drwp_notify_from_email`。

---

## 10. ログイン / 2FA

`includes/class-drwp-login.php`、管理画面: 「ログイン設定」(`drwp_login_settings`)。

### 11.1 設定項目

| オプション | 説明 |
| --- | --- |
| `drwp_login_redirect_enabled` | `/wp-login.php` をカスタムページに転送する |
| `drwp_login_page_id` | ログインフォームを置いた固定ページ ID |
| `drwp_login_lostpass_page_id` | `[drwp_lostpassword_form]` を置いた固定ページ ID。設定時は再設定リンク + 再設定メール本文の URL が両方ここに向く |
| `drwp_login_admin_lockdown` | 編集者未満が `/wp-admin/` にアクセスしたらフロントへ強制リダイレクトする |

### 11.2 2 段階認証 (TOTP / Google Authenticator)

**自前実装はしない**。WordPress 公式の Two Factor プラグイン
(<https://wordpress.org/plugins/two-factor/>) に委譲。

設定ページで `class_exists('Two_Factor_Core')` を見て有効/未インストールを判定。インストール後、各ユーザーがプロフィール画面 (Users → Profile → 「Two-Factor Options」) で TOTP を有効化し、QR コードを Google Authenticator / Authy / 1Password 等で読み取る。

「Time Based One-Time Password (TOTP)」を有効にしたユーザーは、`/wp-login.php` のサインイン後に Two Factor のチャレンジ画面 (`action=validate_2fa`) に進む。drwp の `/wp-login.php` リダイレクトはこの action を**転送しない**ため、Two Factor の動作に介入しない。

### 11.3 wp-login.php ブランディング

`login_enqueue_scripts` フックで `public/assets/wp-login.css` を流し、サインイン画面・パスワード再設定の最終画面・Two Factor の TOTP チャレンジ画面の見た目をブランドに揃える。`login_headerurl` / `login_headertext` フィルタで、ロゴリンクの遷移先を `home_url()`、テキストを `get_bloginfo('name')` に差し替える。

### 11.4 管理画面ロックダウン

`drwp_login_admin_lockdown` を ON にすると、**`edit_others_posts` を持たないユーザー**(寄稿者・投稿者・購読者など)が `/wp-admin/` を叩いた瞬間にフロントに弾かれる。例外(通すエンドポイント):

- `profile.php` — Two Factor の TOTP 登録 / パスワード変更
- `admin-ajax.php` / `admin-post.php`

`show_admin_bar` フィルタも同じ条件で false を返し、フロントヘッダの admin bar を非表示化。

---

## 11. ライセンス

`includes/class-drwp-license.php` + `class-drwp-license-admin.php`。

書込系の操作(REST POST/PATCH、admin の save、CSV インポート、写真アップロード)は `DRWP_License::can_write()` を通る。

ライセンスサーバから署名付きの状態を受け取り、`drwp_license_*` オプションに保存。`active` / `inactive` / `unknown`(API 失敗時、`drwp_license_last_valid_at` から猶予期間内なら書込 OK)の 3 状態。API 応答は `drwp_license_public_key` で署名検証する。

---

## 12. 監査ログ

`drwp_audit_logs` テーブル。`DRWP_Audit::log($event, $message, $report_id, $meta)` を各所から呼ぶ。

主なイベント: `report_created` / `report_updated` / `photos_updated` / `review_status_changed` / `comment_added` / `report_edited_frontend` / `post_created_from_report` / `post_resynced`。

管理画面の「操作履歴」で全ログを閲覧、`/wp-json/drwp/v1/reports/{id}/audit` でログを取得。

---

## 13. 設定オプション一覧

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

## 14. アセット

| ファイル | 役割 |
| --- | --- |
| `admin/assets/admin.css` | 管理画面共通 CSS(日報編集ページのセクションカード等) |
| `admin/assets/admin.js` | 管理画面 JS(写真ピッカー、メディアライブラリ連携) |
| `public/assets/mobile-form.css` | `[drwp_report_form]` 用 CSS |
| `public/assets/mobile-form.js` | `[drwp_report_form]` 用 JS(config は wrapper の data 属性から読む) |
| `public/assets/login.css` | `[drwp_login_form]` / `[drwp_lostpassword_form]` 用 CSS + ログイン状態バー |
| `public/assets/wp-login.css` | `/wp-login.php` ブランディング(背景色、フォーム枠、ボタン色) |
| `public/assets/archive.css` | `[drwp_report_archive]` 用 CSS(一覧カード・フィルタ・単一ビュー・フロント編集フォーム) |
| `public/assets/archive-edit.js` | フロント編集フォームの写真アップロード/削除 JS |

---

## 15. 拡張・運用上の注意

- **アセット配信経路**: `[drwp_report_form]` の config を `wp_localize_script` や `wp_add_inline_script` で出すと、ホストのキャッシュ/最適化プラグインに剥がされる事故が観測されている(LiteSpeed Cache, Autoptimize 等)。本プラグインは **wrapper 要素の data 属性に JSON を埋め込む**方式に統一しているので、新規にフロント機能を足すときは同じパターンを推奨する。
- **`wpautop` との共存**: ショートコード返り値文字列に `<script>` / `<style>` を直接含めると `wpautop` に壊される。CSS/JS は必ず enqueue 経由、データは data 属性で渡す。
- **2FA は自作しない**: 自前 TOTP 実装はセキュリティリスクが大きい(鍵保管・タイムスキュー・リカバリコード・リプレイ防止)。Two Factor プラグインに委譲する方針を維持する。
- **v1.11 の構造変更**: v1.9〜1.10 で導入していた multi-entry モデル(1 日報 × N 現場エントリ)は v1.11 で撤回されている。`drwp_report_entries` テーブルは upgrade 時に DROP され、写真の `entry_id` 参照も NULL に戻される。本仕様書は v1.11 以降のフラットモデルを記述している。

---

## 16. バージョン履歴(主要変更)

| バージョン | 主な変更 |
| --- | --- |
| 1.11.0 | multi-entry モデル撤回(`drwp_report_entries` DROP、entry_group CSV モード削除)。`drwp_reports` に `started_at` / `ended_at` 追加。スマホフォームから「現場追加」UI 撤去 |
| 1.10.0 | エントリ単位の `public_title` / `public_body` 追加 / CSV マルチエントリ対応 / 管理画面でエントリ追加削除可 |
| 1.9.x | マルチエントリ基盤(`drwp_report_entries`, 写真の `entry_id` 紐付け, REST に `entries[]` 追加, スマホフォーム) |
| (それ以前) | レガシーフラット報告のみ、レビュー/記事化/CSV/通知/ライセンス |
