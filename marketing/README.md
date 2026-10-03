# 日報マン マーケティング素材

[nippo-man.com](https://nippo-man.com/) のトップページとして使う LP(ランディングページ)です。

**LP 本体は [`license-server/app/static/index.html`](../license-server/app/static/index.html) に移動しました。**
nippo-man.com ではライセンスサーバ (FastAPI) がドメイン直下で動いているため、
その `/` ルートが LP を配信します (= このファイルがそのままトップページになります)。

LP は完全に自己完結した1ファイル(画像・外部フォント・外部スクリプトなし)なので、
別の場所に置くだけ・貼るだけでも動きます。

## 公開・更新手順 (nippo-man.com)

1. `license-server/app/static/index.html` を編集して main にマージ
2. VPS で:
   ```bash
   git pull
   docker compose build license
   docker compose up -d license
   ```
3. https://nippo-man.com/ で表示を確認

## 別の場所に設置する場合

### 方法 A: 静的ホスティング

`index.html` をドキュメントルートに置くだけです。
head に title / meta description / OGP / favicon を含んでいるので、そのまま公開できます。

### 方法 B: WordPress の固定ページ

1. 固定ページを新規作成し、テンプレートは「フルワイド」や白紙系を選択
2. 「カスタム HTML」ブロックに、`<body>` の中身(`<style>` ブロックを含む)をコピーして貼り付け
3. **設定 > 表示設定** で「ホームページの表示」をその固定ページに指定

CSS はすべて `nm-` プレフィックスのクラスにスコープしているので、テーマのスタイルとの衝突は最小限です。

## 公開前に差し替えるもの

| 場所 | 何をする |
| --- | --- |
| 「無料で試す」「お問い合わせ」ボタン | `href="#contact"` / `href="#"` を実際のフォーム URL に差し替え |
| フッター | 特定商取引法に基づく表記 / プライバシーポリシーのリンク先を設定 |
| OGP 画像 | 用意でき次第 `<meta property="og:image" content="...">` を head に追加 |
| 料金・プラン | 変更があれば `#pricing` セクションを更新 |

## カスタマイズしやすい箇所

- 配色: `<style>` 冒頭の `:root` 変数(`--brand` を変えると全体のキーカラーが変わります)
- ヒーローの管理画面モック: `.nm-mock` 内のダミー日報カード(日付・案件名・状態チップ)
- 課題 4 枚 / FAQ: 顧客から実際に聞く課題・質問に合わせて文言調整
