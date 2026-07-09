=== jijipom ===

Contributors: yourname
Requires at least: WordPress 6.0
Tested up to: 6.5
Requires PHP: 7.4
License: GNU General Public License v2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Tags: blog, custom-menu, featured-images, threaded-comments, translation-ready, accessibility-ready

どんなジャンルのサイトにも馴染む、シンプル・軽量・SEOに強いWordPressベーステーマ。
カスタマイズの「叩き台」としてお使いください。


== 特長 ==

* セマンティックなHTML5マークアップ
* 構造化データ(JSON-LD): WebSite / Organization / Article / BreadcrumbList
* OGP・Twitter Card を自動出力
* メタディスクリプション・canonical URL を自動生成
* パンくずリスト(表示 + 構造化データ)
* レスポンシブ / ダークモード自動対応
* アクセシビリティ配慮(スキップリンク、フォーカス表示、ARIA)
* 外部フォント・外部リクエストなしで高速表示
* 不要なメタ情報・絵文字スクリプトを除去して軽量化
* ブロックエディタ対応(theme.json / エディタスタイル)
* サイトタイトルに画像(ロゴ)を設定可能
* 文字タイトル・キャッチフレーズの表示/非表示をカスタマイザーで切替
* フルワイド(サイドバーなし)ページテンプレート同梱
* 翻訳対応(text domain: jijipom)


== インストール ==

1. このフォルダ(jijipom)を wp-content/themes/ にアップロード
   もしくは zip のまま「外観 > テーマ > 新規追加 > テーマのアップロード」から追加
2. 「外観 > テーマ」で jijipom を有効化
3. 「外観 > メニュー」で primary(メインメニュー)/ footer を設定
4. 「外観 > ウィジェット」でサイドバー・フッターを設定


== サイトタイトル・説明文の設定 ==

「外観 > カスタマイズ > サイト基本情報」で以下を設定できます。

* ロゴ         : サイトタイトルに使う画像を設定
* サイトタイトル(文字)を表示する
                : ロゴ画像だけ見せたいときはチェックを外す
                  (ロゴ未設定の場合は文字タイトルが常に表示されます)
* サイトの説明文(キャッチフレーズ)を表示する
                : チェックを外すと説明文を非表示にできます


== フルワイドページの使い方 ==

固定ページの編集画面で「ページ属性 > テンプレート」から
「フルワイド(サイドバーなし)」を選択すると、サイドバーを外し
本文を全体幅まで広げて表示します。ランディングページ向けです。


== カスタマイズの起点 ==

* 色・フォント・余白 : style.css 冒頭の :root 変数
* ブロックエディタの色/フォント : theme.json
* SEO出力の調整 : inc/seo.php
* パンくずの構成 : inc/breadcrumbs.php

フィルターフック例:
  add_filter( 'jijipom_meta_description', 'your_function' );
  add_filter( 'jijipom_content_width', function() { return 800; } );
  add_filter( 'jijipom_default_og_image', function() { return 'https://.../ogp.png'; } );


== SEOプラグインとの併用について ==

Yoast SEO / SEO SIMPLE PACK などを導入する場合、メタタグや構造化データが
重複します。functions.php 内の以下の行をコメントアウトしてテーマ側の出力を
止めてください:

  require get_template_directory() . '/inc/seo.php';

パンくず表示だけ残したい場合は、inc/seo.php 内の
jijipom_seo_output() の add_action を外す方法もあります。


== 変更履歴 ==

= 1.0.0 =
* 初回リリース
