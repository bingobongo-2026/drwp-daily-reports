<?php
if (!defined('ABSPATH')) exit;
?>
<div class="wrap">
    <h1><?php esc_html_e('jijipom コンテンツビルダー', 'jijipom-content-builder'); ?></h1>

    <p class="description" style="max-width:820px;">
        <?php esc_html_e('下のビルダーで各ページの内容を入力し、「⬇ ZIPをエクスポート」でダウンロードします。書き出した ZIP は jijipom テーマの「外観 > コンテンツ取込」から取り込むと、各ページの内容(カスタマイザー設定)と固定ページにまとめて反映されます。別サイトへ持って行って取り込むこともできます。', 'jijipom-content-builder'); ?>
    </p>
    <p class="description" style="max-width:820px;">
        <?php
        printf(
            /* translators: 1: ショートコード, 2: 全幅ショートコード */
            esc_html__('フロントページに設置したい場合は、固定ページや投稿にショートコード %1$s を貼り付けてください。画面幅いっぱいに広げたいときは %2$s（フルワイドのページ推奨）。', 'jijipom-content-builder'),
            '<code>[jijipom_builder]</code>',
            '<code>[jijipom_builder width=&quot;full&quot;]</code>'
        );
        ?>
    </p>

    <div style="border:1px solid #dcdcde;border-radius:8px;overflow:hidden;background:#fff;">
        <?php echo JCB_Plugin::builder_iframe(640); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 内部生成のマークアップ ?>
    </div>
    <p class="description">
        <?php esc_html_e('入力内容はこのブラウザに保存されます。編集したら右上の「⬇ ZIPをエクスポート」を押してダウンロードし、次に「外観 > コンテンツ取込」で取り込んでください。', 'jijipom-content-builder'); ?>
    </p>
</div>
