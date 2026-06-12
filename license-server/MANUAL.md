# 日報マン ライセンスサーバー 利用マニュアル

このドキュメントは「License Server を初めて触る運用担当者」向けの
操作手順書です。エンドポイント定義などのリファレンスは
[`README.md`](./README.md) を参照してください。

## 目次

1. [License Server は何をするものか](#1-license-server-は何をするものか)
2. [起動と停止](#2-起動と停止)
3. [管理画面 (HTML UI) の使い方](#3-管理画面-html-ui-の使い方)
4. [ライセンスの発行と編集](#4-ライセンスの発行と編集)
5. [WordPress プラグインとの紐付け](#5-wordpress-プラグインとの紐付け)
6. [鍵ローテーション](#6-鍵ローテーション)
7. [動作確認チェックリスト](#7-動作確認チェックリスト)
8. [トラブルシューティング](#8-トラブルシューティング)
9. [本番運用時の注意](#9-本番運用時の注意)

---

## 1. License Server は何をするものか

DRWP プラグインは「正規のライセンスを持つ WordPress サイトでだけ
機能を有効化する」仕組みを持っており、その判定を担うのが
License Server です。役割は 3 つだけです。

- **ライセンスを管理する** (発行・編集・失効・削除)
- **プラグインから来た問い合わせ (`/api/check`) に答える** — そのキー・
  そのドメインで使ってよいか、いつまで使ってよいかを返す
- **応答に Ed25519 署名を付ける** — プラグイン側で改ざんやリプレイを
  検知できるようにする

ライセンスデータは SQLite (`./data.sqlite3` 等) に、署名用の秘密鍵は
`./signing.key` に保存されます。両者の置き場所は環境変数で変えられ
ます ([§9](#9-本番運用時の注意))。

---

## 2. 起動と停止

### 2.1 Docker で動かす場合 (推奨・最初はこれ)

リポジトリのルート (`docker-compose.yml` がある階層) で:

```sh
cp .env.example .env          # 初回のみ。ポートや管理トークンを変えたければ編集
bash scripts/docker-setup.sh
```

これだけで以下が立ち上がります。

| 役割 | URL |
| --- | --- |
| WordPress | http://localhost:8080 |
| WordPress 管理者 | `admin` / `adminpass` |
| License Server JSON API | http://localhost:8001 |
| License Server HTML UI | http://localhost:8001/admin/ui/licenses |
| License Server 管理トークン | `demo-token` (Basic 認証ユーザは `admin`) |

セットアップスクリプトは最後に `fetch_public_key: OK / check_now: active
/ status=active signature_valid=valid` と出力します。ここが `valid` に
ならない場合は §8 を参照してください。

**停止と再起動**

```sh
docker compose down            # コンテナ停止 (データは残る)
docker compose up -d            # 再開
docker compose down -v          # データも含めて完全リセット
```

### 2.2 単体で動かす場合 (Docker を使わない)

```sh
cd license-server
pip install -r requirements.txt
export DRWP_ADMIN_TOKEN=demo-token
uvicorn app.main:app --reload --port 8001
```

`DRWP_ADMIN_TOKEN` を設定していないと `/admin/*` は 503 を返します
([§9](#9-本番運用時の注意))。

---

## 3. 管理画面 (HTML UI) の使い方

ブラウザで http://localhost:8001/admin/ui/licenses を開きます。

最初に **Basic 認証**を求められます。

- ユーザ名: `admin`
- パスワード: `DRWP_ADMIN_TOKEN` の値 (デフォルトは `demo-token`)

ログインすると 1 画面で全ライセンスが一覧されます。

| 列 | 意味 |
| --- | --- |
| キー | プラグイン側の「ライセンスキー」欄に入れる値 |
| ドメイン | このキーを使ってよいサイトのホスト名 (例: `example.com`) |
| プラン | `basic` (ベーシック) / `pro` (プロ) のいずれか。プロのみ AI 機能が利用できる |
| 状態 | `active` 以外はプラグイン側でも非アクティブ扱い |
| 有効期限 | ISO 8601 (例: `2099-12-31T23:59:59+00:00`)、未設定は無期限 |
| 更新日時 | 最後に編集された時刻 |

右側の **新規作成 / 編集 / 削除** ボタンから操作します。

`?msg=created` のようなクエリで上部にフラッシュメッセージが出ます。
リロードしても再表示されない一時的な通知です。

---

## 4. ライセンスの発行と編集

### 4.1 新規発行 (HTML UI から)

「新規作成」ボタン → フォームを埋めて送信。

- **license_key**: 任意の文字列。重複は弾かれます。バックエンドで
  ユニーク制約があるため、人間が読める接頭辞 + ランダム ID 等を
  推奨 (例: `ACME-7F2C-9KQ4`)。
- **domain**: プラグインから問い合わせてくる「サイトのドメイン」
  と完全一致が必要です。`www.` の有無、サブドメイン、`http://` の
  ありなしで挙動が変わるので注意。プラグインは
  `wp_parse_url(home_url(), PHP_URL_HOST)` の結果を送ってきます。
  - 例: WP の管理画面で「サイトアドレス」が `https://example.com`
    なら、ドメイン欄には `example.com` を入れる
- **plan**: 自由テキスト。プラグインはこの値を `expires_at` と一緒に
  画面表示するだけで、ロジックに使うことはほぼありません。
- **status**: `active` でなければプラグイン側で書き込み・記事化が
  ブロックされます。一時停止したいときは `inactive` に。
- **expires_at**: 空欄で無期限。日時を入れる場合は
  `2099-12-31T23:59:59+00:00` 形式 (タイムゾーン付き ISO 8601)。

「作成」ボタンで保存され、一覧画面にリダイレクトされます。

### 4.2 編集

一覧の **編集** ボタンから。`license_key` だけは変更不可です (キーを
変えたい場合は削除 → 新規作成)。

### 4.3 削除

一覧の **削除** ボタン → ブラウザの confirm に OK で即時削除されます。
**取り消しはできません**。失効させたいだけなら、削除より `status` を
`inactive` にする方が安全です (履歴が残る、復活できる)。

### 4.4 JSON API から操作 (CI / スクリプト向け)

```sh
TOKEN=demo-token
BASE=http://localhost:8001

# 一覧
curl -u admin:$TOKEN $BASE/admin/licenses | jq .

# 作成
curl -u admin:$TOKEN -H 'Content-Type: application/json' \
  -X POST $BASE/admin/licenses \
  -d '{"license_key":"ACME-001","domain":"example.com","plan":"pro","status":"active"}'

# 部分更新 (失効させる)
curl -u admin:$TOKEN -H 'Content-Type: application/json' \
  -X PATCH $BASE/admin/licenses/ACME-001 \
  -d '{"status":"inactive"}'

# 削除
curl -u admin:$TOKEN -X DELETE $BASE/admin/licenses/ACME-001
```

`409` が返ったらキー重複、`404` は対象なし、`401` は管理トークン誤り、
`503` はサーバ側に `DRWP_ADMIN_TOKEN` が設定されていない、です。

---

## 5. WordPress プラグインとの紐付け

プラグイン側の手順は WordPress 管理画面 → 「日報マン」 →
「ライセンス」ページから行います。

`scripts/docker-setup.sh` で立ち上げた場合は既に紐付け済みなので、
ここは「自分でゼロから設定するとき」の手順です。

1. **API URL** — License Server のベース URL を入れる
   - Docker 同一ホスト内から呼ぶ場合: `http://license:8000`
     (compose のサービス名 `license` を使う)
   - 別マシンに置いている場合: `https://license.example.com` のように
     外部から到達できる URL を入れる
2. **ライセンスキー** — License Server 側で発行した `license_key` を
   そのまま貼る
3. **管理トークン (任意)** — `/admin/rotate-signing-key` を押したい
   ときだけ必要。本番では DB に置きたくないので
   `wp-config.php` で `define('DRWP_LICENSE_ADMIN_TOKEN', '...')`
   する方が安全。
4. 設定を保存 → **「公開鍵を取得」**ボタンを押す
5. **「ライセンスをチェック」**ボタンを押す
6. 画面上の表示が以下のようになっていれば成功:
   - `状態: active`
   - `署名検証: valid`

うまくいかないときは §8 を参照してください。

---

## 6. 鍵ローテーション

Ed25519 の署名鍵を更新する操作です。鍵が漏洩した、定期更新の運用に
したい、といった場合に使います。

### 6.1 仕組み

- 新しい鍵ペアを生成し、それまでのアクティブ鍵を **previous_keys** に
  退避します (最大 3 本まで保持)。
- `/api/public-key` は新しい公開鍵と previous の一覧を返します。
- プラグイン側は active + previous のどれかと一致すれば検証 OK と
  扱うので、**旧鍵で署名された応答も検証が壊れない**まま新鍵に
  入れ替えられます。
- 4 回ローテーションすると最古の previous は破棄されるため、それ
  以前の応答を検証する必要があるなら別途バックアップしてください。

### 6.2 操作

**HTML UI からはできません** (危険操作なのであえて UI を用意していません)。
WordPress プラグインの「ライセンス」画面に **鍵ローテーションボタン**
があるか、curl で直接叩きます。

```sh
curl -u admin:$DRWP_ADMIN_TOKEN -X POST \
  http://localhost:8001/admin/rotate-signing-key
```

返り値の例:

```json
{
  "public_key": "T9c8...",
  "previous_keys": ["abQ1...", "9xP2..."]
}
```

ローテーション後、プラグイン側で必ず **「公開鍵を取得」→「ライセンス
チェック」** を実行してください。プラグインに古い public_key だけが
残っていると、新しい署名が検証できず `signature_valid: invalid` に
なります。

---

## 7. 動作確認チェックリスト

新しく環境を組んだとき、以下を順に確認します。

1. **ヘルスチェック**

   ```sh
   curl http://localhost:8001/healthz
   # => {"ok":true}
   ```

2. **公開鍵が取れる**

   ```sh
   curl http://localhost:8001/api/public-key | jq .
   # => {"public_key":"...","previous_keys":[],"algorithm":"ed25519"}
   ```

3. **管理 UI に入れる** — http://localhost:8001/admin/ui/licenses で
   一覧画面が表示される (Basic 認証は通る)。

4. **ライセンスが発行できる** — UI から 1 件作成して一覧に出る。

5. **`/api/check` が正しい答えを返す**

   ```sh
   curl -H 'Content-Type: application/json' \
     -d '{"license_key":"ACME-001","domain":"example.com"}' \
     http://localhost:8001/api/check
   # status が active / 署名が付いている
   ```

6. **プラグイン側で `valid` になる** — WordPress の DRWP ライセンス
   画面で「ライセンスをチェック」を押し、`状態: active /
   署名検証: valid` が出る。

7. **ドメイン違いを弾けるか** — プラグインの「ライセンスキー」を
   別ドメインで登録されたものに差し替えてチェック → `状態:
   domain_mismatch` が返る。

ここまで通れば一通り動いています。

---

## 8. トラブルシューティング

### `状態: inactive / signature_valid: no_key`

公開鍵が取得できていません。

- API URL を確認 (`http://license:8000` vs `http://localhost:8001`
  の取り違いがよくある)
- `curl <API_URL>/api/public-key` で疎通確認
- ファイアウォール、Docker のネットワーク設定

### `状態: inactive / signature_valid: invalid`

公開鍵キャッシュとサーバの鍵が食い違っています。

- 鍵ローテーションをした直後なら、プラグイン側で「公開鍵を取得」
  ボタンを押し直す
- 環境を作り直した (`docker compose down -v`) 場合は鍵もリセット
  されているので、プラグイン側のキャッシュも一度クリアして再取得

### `状態: inactive / signature_valid: stale`

リプレイ防止の `issued_at` ウィンドウ (15 分) を外れています。

- ホストとコンテナの時計がずれていないか確認 (`date` と
  `docker compose exec license date` を比較)
- NTP を効かせる

### `状態: domain_mismatch`

License Server に登録した `domain` と、プラグインが送ってくる
ホスト名が一致していません。

- WordPress 管理画面 → 設定 → 一般 → 「サイトアドレス (URL)」を確認
- License Server 側のドメイン欄を `www.` ありなしまで含めて合わせる

### `401 Unauthorized` (管理 API)

`DRWP_ADMIN_TOKEN` の値か、Basic 認証のユーザ名 (`admin` 固定) が
誤っています。

### `503 Service Unavailable` (管理 API)

サーバ側で `DRWP_ADMIN_TOKEN` 環境変数が設定されていません。

### ライセンス画面で何もボタンを押せない

プラグインの権限です。WordPress 管理者ロールでログインし直すか、
プラグイン側の `DRWP_Admin::CAP_MANAGE` を満たすロールに切り替え
てください。

### ログを見たい

```sh
docker compose logs -f license       # License Server
docker compose logs -f wordpress     # WordPress (PHP / debug.log)
```

WordPress 側はさらに詳しく:

```sh
docker compose exec wordpress tail -f /var/www/html/wp-content/debug.log
```

---

## 9. 本番運用時の注意

開発用 docker-compose をそのまま本番に持っていかないでください。
最低限以下を満たすこと。

- **`DRWP_ADMIN_TOKEN` を強固なランダム値に変える** (32 文字以上推奨)。
  デフォルトの `demo-token` のままでは管理 API が誰でも叩けます。
- **HTTPS を前段に置く** — Basic 認証の資格情報や `/api/check` の
  リクエストボディが平文で流れます。Caddy / nginx / ALB などで TLS
  終端する想定です。
- **署名鍵を永続化する場所を変える** — `DRWP_SIGNING_KEY` を
  コンテナイメージの中ではなく、外部ボリュームか KMS / Secrets
  Manager から注入する。コンテナを作り直すたびに鍵が再生成されると、
  プラグイン側で `signature_valid: invalid` の嵐になります。
- **SQLite を共有ストレージに置く** — `DRWP_LICENSE_DB` を永続ボリュ
  ームに向ける。
- **WordPress 側は constant で管理トークンを保持** — オプション
  (DB 内) ではなく `define('DRWP_LICENSE_ADMIN_TOKEN', '...')` を
  `wp-config.php` に書く。`DRWP_License::admin_token_source()` が
  `constant` を返すことを確認。
- **鍵ローテーションを定期実行** — 例: 90 日ごと。ローテーション後は
  WP 側で公開鍵の再取得 + チェックを忘れずに。
- **バックアップ** — `data.sqlite3` と `signing.key`、`signing.key.previous.json`
  の 3 ファイル。前者を失うとライセンスを再発行する羽目になり、
  後者を失うと過去の署名が検証できなくなります。

---

## 関連ドキュメント

- [`README.md`](./README.md) — API リファレンス
- [`../README.md`](../README.md) — リポジトリ全体の概要、ローカル
  Docker クイックスタート
- [`../drwp-daily-reports/README.md`](../drwp-daily-reports/README.md) —
  WordPress プラグイン側の機能一覧
