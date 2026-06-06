# DRWP Daily Reports

施工日報の管理を行う WordPress プラグインと、その有効性検証用ライセンスサーバーの組み合わせです。このリポジトリには以下の 2 つが入っています。

- **`drwp-daily-reports/`** — WordPress プラグイン本体（日報管理画面、REST API、ダッシュボードウィジェット、写真添付、投稿変換）。機能の詳細は [`drwp-daily-reports/README.md`](drwp-daily-reports/README.md) を参照。
- **`license-server/`** — ライセンス検証応答に Ed25519 で署名するスタンドアロンの FastAPI サーバー。プラグイン側は libsodium で署名を検証するので、応答を改ざんしても機能が解放されません。エンドポイントの詳細は [`license-server/README.md`](license-server/README.md) を参照。

---

## ローカル Docker でのクイックスタート

```sh
# 1. 環境変数のデフォルトをコピー（任意）
cp .env.example .env

# 2. 一発セットアップ: ビルド + WP インストール + プラグイン有効化
#    + デモライセンス投入 + 初回ライセンスチェックまで自動実行
bash scripts/docker-setup.sh
```

完了するとローカルで以下の URL にアクセスできます。

| サービス | URL | 認証 |
| --- | --- | --- |
| WordPress | http://localhost:8080 | — |
| WordPress 管理画面 | http://localhost:8080/wp-admin | ユーザー `admin` / パスワード `adminpass` |
| ライセンス JSON API | http://localhost:8001 | 後述 |
| ライセンス管理画面 (HTML) | http://localhost:8001/admin/ui/licenses | 後述 |

### ライセンスサーバーのベーシック認証

**初期状態のログイン情報**:

- ユーザー名: `admin`
- パスワード: `demo-token`

これは `docker-compose.yml` の環境変数 `DRWP_ADMIN_TOKEN` のデフォルト値です。`.env` で `DRWP_ADMIN_TOKEN` を上書きするとそちらが使われます。

**運用中の変更**: 管理画面の「サーバー設定」ページからユーザー名とパスワードを変更できます。変更すると DB に保存された値が環境変数より優先されます。

**パスワードを忘れた場合** の確認・初期化方法は [`license-server/README.md`](license-server/README.md) の「ログインできなくなった時」セクションを参照してください。

プラグインのソース（`./drwp-daily-reports/`）はコンテナに**バインドマウント**されているので、ホスト側で PHP を編集すれば再ビルド不要で即反映されます。

---

## 日常的に使うコマンド

```sh
# WordPress のエラーログを追う
docker compose exec wordpress tail -f /var/www/html/wp-content/debug.log

# WP-CLI をスタック内で実行（任意の wp サブコマンド）
docker compose run --rm wpcli plugin list

# ライセンスサーバーの pytest 実行（スタック内ではなくローカル）
cd license-server && pip install -r requirements.txt pytest httpx && pytest

# 全停止（ボリュームは保持）
docker compose down

# まっさらに戻す（MySQL / WP アップロード / ライセンス鍵・DB すべて削除）
docker compose down -v

# ライセンスサーバーだけ再ビルド（コード変更時）
docker compose up -d --build license
```

### WP-CLI コマンド

`WP_CLI` 定数が定義されているとプラグインは `wp drwp` 系コマンドを 8 つ登録します。`wpcli` ヘルパーコンテナ経由で実行:

```sh
# 日報
docker compose run --rm wpcli drwp report list
docker compose run --rm wpcli drwp report list --status=pending --format=csv
docker compose run --rm wpcli drwp report show 42
docker compose run --rm wpcli drwp report convert 42

# ライセンス
docker compose run --rm wpcli drwp license fetch-key
docker compose run --rm wpcli drwp license check

# 案件、監査ログ
docker compose run --rm wpcli drwp project list
docker compose run --rm wpcli drwp audit tail --event=review_status_changed --n=10
```

これらのコマンドは管理画面と同じ `DRWP_License` / `DRWP_Post_Converter` / `DRWP_Audit` ヘルパーを使うので、ライセンスチェック、監査ログ、バリデーションがエントリーポイントによらず一貫します。

---

## プラグインのテスト（PHPUnit + WP テストライブラリ）

```sh
cd drwp-daily-reports
composer install
# 初回のみ: WP develop テストライブラリとテスト用 DB を用意。
# 引数: <db-name> <db-user> <db-pass> [host] [version]
bash bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest
vendor/bin/phpunit
```

テストは実際の `WP_UnitTestCase` を使うので MySQL に接続します。カバー範囲は `DRWP_License`、`DRWP_Audit`、`DRWP_Comment`、`DRWP_Project`、`DRWP_Media`、`DRWP_Post_Converter`、`DRWP_REST` の各エンドポイント。CI ワークフローは同じテストを PHP 7.4 と 8.2 × MySQL 8 サービスコンテナで実行します。

---

## ファイル配置

```
docker-compose.yml         WP + MySQL + license-server スタック
scripts/docker-setup.sh    冪等なブートストラップスクリプト
.env.example               調整可能な値（ホストポート、管理者認証情報など）

drwp-daily-reports/        WordPress プラグインのソース
                           （wordpress コンテナにバインドマウント）
license-server/            FastAPI サーバーのソース（Dockerfile からビルド）
.github/workflows/ci.yml   PR ごとに php -l マトリクスと pytest を実行
```

---

## 本番運用の注意

- Docker スタックはローカル開発専用です。本番では WordPress とライセンスサーバーを別々にデプロイし、両方の前段に TLS を置き、`DRWP_ADMIN_TOKEN` を強固なランダム値に変え、署名鍵をコンテナイメージの外で永続化してください。
- プラグイン側の `Requires PHP: 7.4` は CI マトリクスで担保しています。同梱の WordPress イメージは PHP 8.2 で動作。
- 署名鍵のローテートは `POST /admin/rotate-signing-key` または管理画面の「サーバー設定」ページから。古い署名は previous-key セット（最大 3 鍵）で検証され続けるので、クライアントが `/api/public-key` を再取得するまで無停止で移行できます。
- ライセンスサーバーの DB と署名鍵は管理画面の「バックアップ / 復元」から zip 1 ファイルで取得・復元できます。月 1 回程度ダウンロードして手元に保管しておくと、コンテナごと消えた場合でも復元できます。
