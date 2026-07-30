# 引き継ぎメモ (handoff)

新しい会話でこのリポジトリの作業を続けるとき、このファイルを読めば状況を把握できます。

## リポジトリ構成
- `drwp-daily-reports/` … WordPress プラグイン「日報マン」(現場日報のレビュー・写真・記事化)
- `themes/jijipom/` … companion WordPress テーマ「jijipom」
- `themes/jijipom-child/` … jijipom の子テーマ(カスタマイズ用)
- `jijipom-content-builder/` … jijipom 用コンテンツビルダー プラグイン(ライブプレビュー＋ZIP入出力)
- `license-server/` … FastAPI 製ライセンスサーバ(検証・署名/2FA・プラグイン/テーマ配布・運営契約AI・フリープランAdSense)
- `marketing/`, `scripts/`, `docker-compose.yml`, `README.md`

## 現在のバージョン(すべて main にマージ済み)
- 日報マン プラグイン(`drwp-daily-reports`): **1.79.2**
- テーマ jijipom: **1.18.0**
- 子テーマ jijipom-child: **1.0.0**
- プラグイン jijipom-content-builder: **1.9.1**
- license-server: 稼働中(バージョン番号なし)

## 作業ブランチと運用ルール
- 開発ブランチ名は**セッションごとに指定されるもの**を使う(固定ではない)。
  過去に使ったブランチ: `claude/admiring-feynman-fbFTE`(〜#260) →
  `claude/drwp-daily-reports-handoff-65lyes`(#261〜#266)。
  ※前回のPRがマージ済みなら、同じブランチ名でも必ず main から作り直す(古い履歴に積まない)。
- 各機能ごとに次を1サイクルで回す:
  1. `git fetch origin main && git checkout -B <branch> origin/main`(毎回 main から作り直す)
  2. 編集 → **バージョンを上げる** → `php -l`(PHP) / JS 構文チェック
  3. commit → `git push -u origin <branch> --force-with-lease`
  4. PR 作成 → **CI 6項目**(PHP lint 7.4/8.1/8.4・PHPUnit 7.4/8.2・License server pytest)グリーン
  5. squash マージ → main 同期 → **配布ZIPを作成して納品**
- 配布ZIP名は「**名前+バージョン+.zip**」(例: `jijipom1.18.0.zip`, `jijipom-child1.0.0.zip`, `jijipom-content-builder1.9.1.zip`, 日報は `drwpdailyreports1.70.0.zip`)。ZIPには tests/bin/composer/phpunit 等の開発ファイルは含めない。
- CI の PHP lint は `drwp-daily-reports/` のみ対象。テーマ/新プラグインは手元で `php -l` する。

## 注意(変更禁止・慣習)
- 日報プラグインの内部スラッグは不変: `DRWP_*` / `drwp_*` / テキストドメイン `drwp-daily-reports` / REST 名前空間 `drwp/v1` / DB接頭辞 `drwp_*`。
- コミットメッセージ / PR本文 / コード / 配布物に **モデル識別子を書かない**。
- コミット trailer: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>` + `Claude-Session: ...`
- PR本文 trailer: `🤖 Generated with [Claude Code]...`
- git identity(検証済みコミット用): `user.email=noreply@anthropic.com` / `user.name=Claude`
  ※各PRのマージ後、main の tip は GitHub のスカッシュ・マージコミット(committer=noreply@github.com)になる。これは「マージ済み履歴」なので amend しない。
- PR は明示依頼があるときだけ作成(このプロジェクトは機能ごとに build+merge を継続依頼済み)。

## 日報マン: 予定のCSV取り込み(1.70.0 で追加)
- 管理画面「日報 > 予定の取り込み」。**サイボウズ Office** のスケジュールCSVを
  既存の「予定」(`wp_drwp_plans`)として登録する。実装は `class-drwp-plan-import.php`
  ＋ `admin/views/plan-import-page.php`。
- 手順は2段階(CSVアップロード → 列と担当者の対応付けを確認 → 実行)。
  アップロード内容はトランジェントで持ち回る(所有者チェックあり)。
- **列名は決め打ちしない**。サイボウズ Office は版・書き出し設定で列名が変わるため、
  画面で対応付ける方式。`guess_mapping()` がよくある列名から初期値を推測する。
- 文字コードはシフトJIS(Office 既定)/UTF-8(BOM有無)に対応。既存のCSV出力
  (`fputcsv_sjis`)と同じく SJIS-win で扱う。
- **重複防止**: `drwp_plans` に `external_source` / `external_id` ＋ 一意キー
  `external_ref` を追加済み。CSVに予定IDがあればそれを、無ければ内容のハッシュを
  鍵にして upsert する。更新時、CSVから決められない担当者・現場は**上書きしない**
  (取り込み後に画面で直した内容を壊さないため)。
- 取り込みは運用者権限(`edit_others_posts`)必須。ライセンス未有効時は402で停止。
- 現場(project_id)はCSVから決まらない(サイボウズに現場の概念がないため)。全件同じ
  案件を割り当てるか未設定で取り込む。1回の上限2000行。
- 純ロジックのテストは `tests/test-plan-import.php`(CI の PHPUnit で実行)。
- 定期自動同期は未実装。将来 Garoon 等を足せるよう `external_source` に
  `cybozu_office` を入れて区別している。
- ※README.txt の 1.8.0 に「CSV bulk import」の記載があるが、現行コードに該当実装は
  無かった(1.70.0 が最初のCSV取り込み)。

## jijipom コンテンツビルダー(現状の主な機能)
- 管理画面「jijipom コンテンツ」＋ショートコード `[jijipom_builder]`(全幅は `width="full"`)。
- ヘッダーのアクションボタン5つ(下書き保存/下書き読込/ZIPエクスポート/印刷・PDF/リセット)は
  `.bar__actions` で1グループ化済み。個別のフレックスアイテムに戻すと、幅が足りないとき
  「リセット」だけが次の行に孤立するので注意(1.8.1 で修正)。
- 基本設定タブに**ヘッダーボタン**の設定あり(メイン=右端 / サブ=その左隣)。それぞれ
  文言・リンク先URL・アイコン(11種)・背景色・文字色。色は「テーマ標準に戻す」で
  未指定に戻せる(未指定 = jijipom の配色に従う)。1.9.0 で追加。
- **プレビュー幅の注意**: `.work` のプレビュー列は `minmax(0,1fr)`。ここを素の `1fr` に
  戻すと、既定の `min-width:auto` のせいで中の `.device`(固定1140px)より狭くできず、
  列が1140pxまで広がってページが横にはみ出す(自動縮小 zoom も効かなくなる)。
  `.app` の最大幅は1640px(広い画面でPC表示を等倍で見るため)。1.9.1 で修正。
  幅変更への追従は resize イベント＋ ResizeObserver(幅が変わった時だけ再計算。
  高さで発火させると 1.5.4 と同種の無限ループになる)。
- ライブプレビュー: 基本設定タブ(サイトタイトル/ロゴ/フォント/ソーシャル)＋ ①トップ〜⑤プライバシー。PC/スマホ切替。
- 画像/動画/YouTube、各ブロック・項目・カードの表示切替、サービス項目のリンクURL。
- 「⬇ ZIPをエクスポート」= `jijipom-content.json`(= jijipom の theme_mods / pages にマッピング)。
- 「💾 下書き保存 / 📂 下書き読込」= `jijipom-draft.json`(入力途中の state を保存/復元)。
- 取り込み(反映)は **jijipom テーマ側**「外観 > コンテンツ取込」(`inc/importer.php`、ホワイトリスト＋型別サニタイズ、固定ページ作成/トップ設定、blogname/custom_logo/フォント/ソーシャル)。

## jijipom テーマ(ページ関連)
- **ヘッダーボタン**(1.18.0): カスタマイザー「トップページ > ヘッダーボタン」で
  メイン(`jijipom_header_cta_*`)とサブ(`jijipom_header_sub_*`)の2つ。各
  `_text` / `_url` / `_icon` / `_bg` / `_color`。描画は `jijipom_header_button()`
  (`inc/template-functions.php`)で、**文言とURLの両方**が入っているときだけ表示。
  アイコンは `jijipom_button_icon_svg()` のインラインSVG(11種・外部読込なし)。
  色は空文字 = テーマ標準(既存のホバー色設定と同じ規約)。
  取り込みホワイトリストにも10項目を追加済み(それ以前はCTAの文言・URLが取込対象から
  漏れていて、ビルダーから反映できなかった)。
- 固定ページ用テンプレート: サービス / 会社概要 / お問い合わせ / プライバシーポリシー(`templates/page-*.php`、領域はカスタマイザー `inc/customizer-pages.php`)。
- トップ: `front-page.php`(自動適用)。カスタマイザー各セクションの下に、フロント指定した固定ページの本文(`the_content`)も表示(`template-parts/front-page/page-content.php`)。
  ※選択式の「トップページ」テンプレートは一度追加したが削除済み(front-page.php で対応)。

## jijipom-child(子テーマ)
- `themes/jijipom-child/`(style.css / functions.php / readme.txt / screenshot.png)。
- **重要**: 親テーマは自分の style.css を `get_stylesheet_uri()` で読み込んでいる。
  これは「有効なテーマ」の style.css を指すため、子テーマを有効にすると**親の
  style.css が一切読み込まれず見た目が崩れる**。子の functions.php で
  `jijipom_child_enqueue_parent_style()` を優先度5で登録し、親CSS → 子CSS の順に
  なるようにしている。**この処理は消さないこと**。
- `theme.json` は意図的に置いていない(子に置くと親の設定を完全に置き換えるため、
  未配置にして親のものを継承させる)。
- テンプレートを差し替えるときは親から同じファイル名でコピーして子に置く。

## デバッグ調査の記録(2026-07 実施)と残タスク
日報プラグイン・ライセンスサーバーを調査し、確実に壊れているものを優先度順に
PR化した。**修正済み(#269〜#271)**:
- 日報 1.71.1: 予定CSV取り込みのトークンが `sanitize_key` で潰れ実質100%失敗
  していた(生成を小文字16進 `make_token()` に)。再取り込みで完了/キャンセルが
  active へ戻る問題も。
- 日報 1.72.0: REST に `do_action` がゼロで、モバイル(REST)由来の日報作成・
  レビュー・コメントの通知メールが飛んでいなかった → 4フック＋フロント再提出で発火。
  記事化(`convert_report`)が公開本文を `sanitize_text_field` で潰していた →
  `sanitize_writable`(本文は `wp_kses_post`)に。
- license-server: 管理認証の `compare_digest` が非ASCIIで TypeError→500 で永久
  ロックアウト → bytes比較。有効期限が ISO 文字列の辞書順比較で誤判定(日付のみが
  終日失効・JSTオフセット過去が有効に化ける)→ `datetime` 比較 `_is_expired()` に統一。
- ※license-server は pytest をローカル実行できる(venv に `requirements.txt`＋
  `pytest httpx` を入れる)。CI の「License server pytest」でも走る。

**追加で修正済み(#273〜#275)**:
- 日報 1.73.0: アーカイブ済みの除外を REST/フロント(一覧・詳細直リンク)/PDF へ
  拡大(レビュアーのみ archived=with/only)。`edit_requested` を編集許可4箇所に
  追加し、編集したら pending へ戻す。
- 日報 1.74.0: archive/restore/purge・review/comment・bulk・予定削除に退職者/
  ライセンスチェックを追加。`bulk_update_publish` に publish_posts ゲート。
  管理画面編集も REST と同じく承認済みは投稿者本人が触れないように。
- license-server: レート制限が totp_failed も数える(6桁コード総当たり対策)。
  WINDOW/BLOCK を本来の「WINDOW内に閾値回→最後の失敗からBLOCK秒遮断」に実装
  し直し(既存テストは同値で隠していたので別値化)。domain 空を API 422/
  フォームエラーで拒否。logging.basicConfig 追加(署名鍵ローテートが記録される)。

**さらに修正済み(#277〜#280)**:
- license-server: /admin/ui の更新系に CSRF ガード(Origin/Referer のヘッダー
  検証。非ブラウザは素通し・他オリジンは403+監査ログ csrf_rejected)。
- 日報 1.75.0: drwp_reports に user_id/project_id インデックス、
  DRWP_Project::find のリクエスト内キャッシュ(N+1解消・保存時 flush)、
  フロント一覧の範囲92日クランプ+LIMIT 2000、PDF の非数値ID入力で
  全件出力になるバグ修正+LIMIT 500。
- 日報 1.75.1: photo_kind(Before/After)が区分UIの無い画面の保存で毎回消えて
  いた問題を修正。※指定UIは記事作成モーダルに既にあった。sync() は
  photo_kind キーの無い行で既存の区分を保持する(送らない=保持、送る=上書き)。
- license-server: 一覧の有効期限を JST 表示+期限切れ/あとN日バッジ、作成後の
  自動生成キー表示、削除確認にキー・ドメイン明示、?msg= 未知値の偽装防止。

**続けて修正済み(#282〜#285)**:
- 日報 1.76.0: 編集ページの A(内容)/B(公開設定)が保存処理を共有していて
  互いのデータを消していた不具合を修正。各フォームが自分の担当欄だけを保存する
  ように分離(B保存後は編集ページに留まる)。※編集UIの3系統統合そのものは未着手。
- 日報 1.77.0: 管理画面の保存通知を共通ヘルパー DRWP_Admin::admin_notice() に
  統一。閉じるボタン付き・エスケープ済みで一貫化(文言は従来どおり)。
- 日報 1.77.1: admin.css を全 drwp_ ページで配信し、各一覧画面がコピペしていた
  フィルタパネルCSSを1箇所に集約(見た目不変)。※モーダルCSS・ダッシュボードの
  インラインstyle は未集約のまま。
- license-server(#285): 本番のTLS終端プロキシ運用に対応。Dockerfile の uvicorn に
  --proxy-headers --forwarded-allow-ips を追加(実client IP取得→IPロックの
  全顧客連鎖を解消、X-Forwarded-Proto尊重で Secure Cookie 判定を是正)。
  非推奨の @app.on_event を lifespan に移行(停止時にバックグラウンドタスクを
  キャンセル)。.env.example に全15個の DRWP_* 環境変数を記載。
  ※license-server はバージョン番号なし。反映には VPS 再デプロイが必要。
- license-server(#287): 一覧を50件/ページのページ送りに(絞り込み保持・admin API
  は従来どおり全件でプラグイン互換)。設定タブをURLハッシュ(#tab-audit 等)に
  反映しリンク共有・リロードでタブ維持。監査ログの表示件数を30/100/300で切替
  可能に。pytest は102件。
- license-server(#289): 管理トークンを salted PBKDF2 ハッシュで DB 保存。
  旧平文は起動時/旧バックアップ復元時に自動移行。※保留理由だった「管理者が
  現行トークンを閲覧できる」は再点検の結果、そもそも表示機能が存在しなかった
  (設定済みか否かの真偽値のみ)ため失われる機能なし。
- license-server(#290): DRWP_BACKUP_PASSPHRASE 設定でバックアップを暗号化
  .zip.enc として払い出し(PBKDF2→Fernet)。復元は平文/暗号化の両対応。
  未設定時は従来どおり平文 zip。pytest は108件。
- 日報 1.78.0: 予定と日報の紐づけ改善。日付だけで同日の日報候補が出て
  クリックで紐づけ可能に(従来は日付+案件の両方が必要で実質ID手入力)。
  「解除」ボタン追加。※「AI実行の進捗表示なし」は再点検の結果、既に
  実装済みだった(生成中…表示+ボタン無効化)ので残タスクから削除。

- 日報 1.79.0: 管理画面の編集UIを日報一覧のモーダルに統合(ユーザー判断:
  「一覧の編集モーダルを基準にする」)。モーダルが新規作成対応、一覧URLの
  ?edit=ID / ?view=ID / ?new=1 でモーダル直接オープン、旧・日報編集ページは
  自動リダイレクト化して画面ファイル・専用保存処理(admin-post の
  save_report / save_report_publish)・admin.js を撤去。保存は REST に一本化。
  写真のドラッグ並べ替えをモーダルに移植。レビュー/コメント=確認モーダル、
  公開設定・記事化=記事作成モーダルが担当。フロント編集は現場向けとして
  従来どおり(保存経路は同じ REST)。
  ※旧ページ専用だった「メディアライブラリから選択」は載せていない
  (PCアップロードで代替。要望が出たらモーダルに追加する)。

- 日報 1.79.1: モーダルCSS集約の第1弾。共通シェルを admin.css の drwp-modal-*
  に集約(基準=日報一覧。本文max-heightは78vhに統一)し、日報一覧・予定・社員・
  顧客G・案件Gの5ページを移行(各要素に共通クラスを併記する方式でJSのクラス
  参照は不変)。ダッシュボードのインラインstyle約30箇所をクラス+1つの
  styleブロックへ整理(WPダッシュボードには admin.css を配信していないため)。
  旧・日報編集ページURLのリダイレクトは「未公開なので互換不要」の指示で撤去。

- 日報 1.79.2: モーダルCSS集約の第2弾で完了。案件・顧客・記事一覧を移行し、
  管理画面8ページすべてのモーダルが admin.css の共通シェル (drwp-modal-*) を
  使用。記事作成モーダルの最大幅は基準に合わせ 780px→860px。

**未対応**: 2026-07 のデバッグ調査で挙がった項目はすべて対応済み。
実機での見た目確認(モーダル8ページ+ダッシュボード)は未実施なので、
次回のブラウザ確認時に崩れがないか一巡すること。

## VPS / デプロイ(参考・秘密情報は各自の保管先で)
- nippo-man.com (133.167.125.119)、SSH は `ubuntu`(root ではない)、鍵はパスフレーズ付き。
