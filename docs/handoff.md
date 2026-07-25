# 引き継ぎメモ (handoff)

新しい会話でこのリポジトリの作業を続けるとき、このファイルを読めば状況を把握できます。

## リポジトリ構成
- `drwp-daily-reports/` … WordPress プラグイン「日報マン」(現場日報のレビュー・写真・記事化)
- `themes/jijipom/` … companion WordPress テーマ「jijipom」
- `jijipom-content-builder/` … jijipom 用コンテンツビルダー プラグイン(ライブプレビュー＋ZIP入出力)
- `license-server/` … FastAPI 製ライセンスサーバ(検証・署名/2FA・プラグイン/テーマ配布・運営契約AI・フリープランAdSense)
- `marketing/`, `scripts/`, `docker-compose.yml`, `README.md`

## 現在のバージョン(すべて main にマージ済み)
- 日報マン プラグイン(`drwp-daily-reports`): **1.69.0**
- テーマ jijipom: **1.17.0**
- プラグイン jijipom-content-builder: **1.8.1**
- license-server: 稼働中

## 作業ブランチと運用ルール
- 開発ブランチ名は**セッションごとに指定されるもの**を使う(固定ではない)。
  過去に使ったブランチ: `claude/admiring-feynman-fbFTE`(〜#260) →
  `claude/drwp-daily-reports-handoff-65lyes`(#261〜)。
  ※前回のPRがマージ済みなら、同じブランチ名でも必ず main から作り直す(古い履歴に積まない)。
- 各機能ごとに次を1サイクルで回す:
  1. `git fetch origin main && git checkout -B <branch> origin/main`(毎回 main から作り直す)
  2. 編集 → **バージョンを上げる** → `php -l`(PHP) / JS 構文チェック
  3. commit → `git push -u origin <branch> --force-with-lease`
  4. PR 作成 → **CI 6項目**(PHP lint 7.4/8.1/8.4・PHPUnit 7.4/8.2・License server pytest)グリーン
  5. squash マージ → main 同期 → **配布ZIPを作成して納品**
- 配布ZIP名は「**名前+バージョン+.zip**」(例: `jijipom1.17.0.zip`, `jijipom-content-builder1.8.1.zip`, 日報は `drwpdailyreports1.69.0.zip`)。ZIPには tests/bin/composer/phpunit 等の開発ファイルは含めない。
- CI の PHP lint は `drwp-daily-reports/` のみ対象。テーマ/新プラグインは手元で `php -l` する。

## 注意(変更禁止・慣習)
- 日報プラグインの内部スラッグは不変: `DRWP_*` / `drwp_*` / テキストドメイン `drwp-daily-reports` / REST 名前空間 `drwp/v1` / DB接頭辞 `drwp_*`。
- コミットメッセージ / PR本文 / コード / 配布物に **モデル識別子を書かない**。
- コミット trailer: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>` + `Claude-Session: ...`
- PR本文 trailer: `🤖 Generated with [Claude Code]...`
- git identity(検証済みコミット用): `user.email=noreply@anthropic.com` / `user.name=Claude`
  ※各PRのマージ後、main の tip は GitHub のスカッシュ・マージコミット(committer=noreply@github.com)になる。これは「マージ済み履歴」なので amend しない。
- PR は明示依頼があるときだけ作成(このプロジェクトは機能ごとに build+merge を継続依頼済み)。

## jijipom コンテンツビルダー(現状の主な機能)
- 管理画面「jijipom コンテンツ」＋ショートコード `[jijipom_builder]`(全幅は `width="full"`)。
- ヘッダーのアクションボタン5つ(下書き保存/下書き読込/ZIPエクスポート/印刷・PDF/リセット)は
  `.bar__actions` で1グループ化済み。個別のフレックスアイテムに戻すと、幅が足りないとき
  「リセット」だけが次の行に孤立するので注意(1.8.1 で修正)。
- ライブプレビュー: 基本設定タブ(サイトタイトル/ロゴ/フォント/ソーシャル)＋ ①トップ〜⑤プライバシー。PC/スマホ切替。
- 画像/動画/YouTube、各ブロック・項目・カードの表示切替、サービス項目のリンクURL。
- 「⬇ ZIPをエクスポート」= `jijipom-content.json`(= jijipom の theme_mods / pages にマッピング)。
- 「💾 下書き保存 / 📂 下書き読込」= `jijipom-draft.json`(入力途中の state を保存/復元)。
- 取り込み(反映)は **jijipom テーマ側**「外観 > コンテンツ取込」(`inc/importer.php`、ホワイトリスト＋型別サニタイズ、固定ページ作成/トップ設定、blogname/custom_logo/フォント/ソーシャル)。

## jijipom テーマ(ページ関連)
- 固定ページ用テンプレート: サービス / 会社概要 / お問い合わせ / プライバシーポリシー(`templates/page-*.php`、領域はカスタマイザー `inc/customizer-pages.php`)。
- トップ: `front-page.php`(自動適用)。カスタマイザー各セクションの下に、フロント指定した固定ページの本文(`the_content`)も表示(`template-parts/front-page/page-content.php`)。
  ※選択式の「トップページ」テンプレートは一度追加したが削除済み(front-page.php で対応)。

## VPS / デプロイ(参考・秘密情報は各自の保管先で)
- nippo-man.com (133.167.125.119)、SSH は `ubuntu`(root ではない)、鍵はパスフレーズ付き。
