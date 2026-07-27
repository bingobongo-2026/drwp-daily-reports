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
- 日報マン プラグイン(`drwp-daily-reports`): **1.70.0**
- テーマ jijipom: **1.18.0**
- 子テーマ jijipom-child: **1.0.0**
- プラグイン jijipom-content-builder: **1.9.1**
- license-server: 稼働中

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

## VPS / デプロイ(参考・秘密情報は各自の保管先で)
- nippo-man.com (133.167.125.119)、SSH は `ubuntu`(root ではない)、鍵はパスフレーズ付き。
