# license-server v1.9

DRWP プラグイン用のスタンドアロンライセンスサーバー（FastAPI）。

ライセンスキーごとに「有効か / 失効か / ドメイン違反か」を判定し、結果に Ed25519 で署名して返します。プラグイン側は libsodium で署名を検証するので、ネットワーク上で応答を改ざんしても機能解放につながりません。

起動手順 ・ ライセンス発行 ・ 鍵ローテーション ・ トラブルシューティングの**操作マニュアル**は [`MANUAL.md`](./MANUAL.md) を、リポジトリ全体のクイックスタートは [上位 README](../README.md) を参照してください。本ファイルはエンドポイント・環境変数・ベーシック認証の仕組みの**リファレンス**です。

---

## ベーシック認証（ここを最初に読んでください）

`/admin/*` と `/admin/ui/*` は HTTP Basic 認証で保護されています。**ユーザー名とパスワードの両方が一致しなければ 401** が返ります。

### 現在の認証情報の決まり方

ユーザー名・パスワードはそれぞれ次の優先順位で解決されます（上にあるほど強い）。

| 優先度 | ユーザー名 | パスワード |
| --- | --- | --- |
| 1. DB に保存された値 | `settings.admin_username` | `settings.admin_token` |
| 2. 環境変数 | `DRWP_ADMIN_USERNAME` | `DRWP_ADMIN_TOKEN` |
| 3. デフォルト | `admin` | （なし → 503） |

ローカル開発スタック（`docker-compose.yml`）は環境変数 `DRWP_ADMIN_TOKEN` のデフォルトを `demo-token` にしているので、何も設定しなければ:

- ユーザー名: `admin`
- パスワード: `demo-token`

でログインできます。

### 管理画面から変更する

`/admin/ui/settings` の「管理ユーザー名 / トークン」セクションで変更できます。保存すると DB に書き込まれ、以降は環境変数より優先されます。「自動生成」ボタンはブラウザ側で 32 バイトのランダム値（hex 64 文字）を生成します。

保存直後はベーシック認証ダイアログが再表示されます。**新しい値**を入れてください（古い値はもう効きません）。

> ブラウザは (ホスト, realm) で認証情報をキャッシュして自動再送します。値を変えてもダイアログが再表示されないと古い情報がループしてしまうため、サーバー側で値を変えるたびに realm 文字列のバージョン番号を上げています。これによりブラウザは「別の認証ドメイン」と認識し、改めてダイアログを出します。

### ログインできなくなった時

DB に保存したパスワードを忘れた・古いブラウザキャッシュが暴走しているなど。

**①パスワードを思い出すだけなら**: DB を直接読めば取り出せます。

```sh
docker compose exec license python -c \
  "import sqlite3; print(list(sqlite3.connect('/data/data.sqlite3').execute('SELECT key, value FROM settings')))"
```

`admin_token` の値がそのまま現パスワードです。

**②環境変数のデフォルトに戻すなら**: DB の認証情報行を消すと環境変数（デフォルト `demo-token`）にフォールバックします。

```sh
docker compose exec license python -c \
  "import sqlite3; c = sqlite3.connect('/data/data.sqlite3'); \
   c.execute(\"DELETE FROM settings WHERE key IN ('admin_token','admin_username','admin_token_version')\"); \
   c.commit()"
docker compose restart license
```

**③それでもダイアログがループする場合**: ブラウザ側のキャッシュが残っています。シークレットウィンドウで開くか、Chrome なら `chrome://settings/passwords` の該当ホストのエントリを削除してください。

---

## 環境変数

`.env.example` を参考に設定。

| 変数 | 用途 | デフォルト |
| --- | --- | --- |
| `DRWP_ADMIN_TOKEN` | ベーシック認証のパスワード初期値 | （未設定だと `/admin/*` は 503） |
| `DRWP_ADMIN_USERNAME` | ベーシック認証のユーザー名初期値 | `admin` |
| `DRWP_LICENSE_DB` | SQLite DB ファイルのパス | `./data.sqlite3` |
| `DRWP_SIGNING_KEY` | Ed25519 秘密鍵ファイルのパス | `./signing.key`（無ければ初回起動時に生成） |

パスワード比較は `secrets.compare_digest` による定数時間比較。ユーザー名も同じ関数で比較します。

---

## エンドポイント

### 公開エンドポイント

- `GET /api/public-key` — 現在の公開鍵 + 過去鍵リスト（base64 raw 32 byte Ed25519）+ アルゴリズム名
- `POST /api/check` — `{license_key, domain, site_token?}` を受け取り、署名付きのライセンス状態を返す
- `GET /healthz` — ヘルスチェック

### 管理 API（ベーシック認証）

- `GET    /admin/licenses` — 一覧（`?search=...` で部分一致）
- `POST   /admin/licenses` — 作成
- `GET    /admin/licenses/{license_key}` — 取得
- `PATCH  /admin/licenses/{license_key}` — 部分更新
- `DELETE /admin/licenses/{license_key}` — 削除
- `POST   /admin/rotate-signing-key` — 署名鍵のローテート

### 管理 UI（HTML、ベーシック認証）

- `GET  /admin/ui` → `/admin/ui/licenses` にリダイレクト
- `GET  /admin/ui/licenses` — 一覧
- `GET  /admin/ui/licenses/new` — 新規作成フォーム
- `GET  /admin/ui/licenses/{license_key}/edit` — 編集フォーム
- `POST /admin/ui/licenses` / `.../{license_key}/edit` / `.../{license_key}/delete` — フォーム送信先（処理後に `?msg=...` 付きでリダイレクト）
- `GET  /admin/ui/settings` — サーバー設定（管理ユーザー名・トークン、署名鍵、バックアップ）
- `POST /admin/ui/settings/admin-token` — 管理ユーザー名 / トークンの保存・削除
- `POST /admin/ui/settings/rotate-signing` — 署名鍵をローテート
- `GET  /admin/ui/settings/backup` — 署名鍵 + 過去鍵 + DB を zip で取得
- `POST /admin/ui/settings/restore` — zip からの復元

---

## 署名フォーマット

`/api/check` の応答は、`signature` フィールドを除いた本文を以下の正規 JSON 形式にして Ed25519 で署名します。

- キーをソート
- 区切り文字を `,` と `:` に固定（余分な空白なし）
- Python 表現で `json.dumps(payload, sort_keys=True, separators=(",", ":"))`

検証側も同じ正規形を再構築してから `verify` を呼ぶ必要があります。

---

## バックアップ / 復元

`/admin/ui/settings` の「バックアップ / 復元」セクションから 1 クリックで zip を取得できます。zip には以下の 3 ファイルが含まれます。

- `signing.key` — 現在の Ed25519 秘密鍵
- `signing.key.previous.json` — 引退済み公開鍵リスト（最大 3 件）
- `data.sqlite3` — ライセンス DB（settings テーブル含む = ユーザー名 / トークンも復元される）

月 1 回程度ダウンロードして手元に保管しておけば、コンテナごと消えた場合でも復元できます。復元は同名ファイルを上書きする形で行われ、復元後は管理 UI 上で公開鍵が想定通りであることを確認してください。

---

## テスト実行

```sh
pip install -r requirements.txt pytest httpx
pytest
```
