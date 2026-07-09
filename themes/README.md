# themes/

日報マンと一緒に工務店へ配布する WordPress テーマのソースを管理します。

## jijipom

軽量・SEO 重視のベーステーマ。ライセンスサーバー経由の自動アップデートに
対応しています。

### 自動アップデートの仕組み

`inc/theme-updater.php`（`functions.php` から読み込み）が、日報マン本体の
`DRWP_Updater` と同じ方式でライセンスサーバーの `/api/theme/update` を
ポーリングし、新版があれば WordPress 標準のテーマ更新フローで自動更新します。

- 更新元 URL とライセンスキーは、**日報マンが `wp_options` に保存している値
  （`drwp_license_api_url` / `drwp_license_key`）を流用**します。テーマ単体の
  設定は不要ですが、同じサイトに日報マンが導入され、ライセンスが有効
  （active）であることが前提です。
- ダウンロードはライセンス検証付き（`/api/theme/download`）。

### 配布用 zip の作り方

`style.css` の `Version:` を上げてから、テーマフォルダを丸ごと zip 化します。
（トップに `jijipom/` フォルダが来る形にすること。）

```sh
cd themes
zip -rq "jijipom-$(grep -m1 'Version:' jijipom/style.css | sed 's/.*Version:[[:space:]]*//').zip" jijipom -x "*.DS_Store"
```

できた zip を、ライセンスサーバー管理画面の「サーバー設定 → テーマ配布」から
アップロードすると各サイトへ配信されます。バージョンは zip 内 `style.css` の
ヘッダから自動抽出されます。

> 注意: 更新チェッカーが入る前のバージョンからは自動更新できません。初回だけ
> 各サイトへ手動でインストールしてください（外観 → テーマ → 新規追加 →
> テーマのアップロード）。以降は自動で更新案内が出ます。
