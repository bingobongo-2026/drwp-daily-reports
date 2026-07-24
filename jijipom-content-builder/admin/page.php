<?php
if (!defined('ABSPATH')) exit;

$jcb_builder = JCB_URL . 'assets/builder.html?v=' . JCB_VERSION;
?>
<div class="wrap">
    <h1><?php esc_html_e('jijipom コンテンツビルダー', 'jijipom-content-builder'); ?></h1>

    <p class="description" style="max-width:820px;">
        <?php esc_html_e('下のビルダーで各ページの内容を入力し、「ZIPをエクスポート」でダウンロードします。書き出した ZIP は jijipom テーマの「外観 > コンテンツ取込」から取り込むと、各ページの内容(カスタマイザー設定)と固定ページにまとめて反映されます。別サイトへ持って行って取り込むこともできます。', 'jijipom-content-builder'); ?>
    </p>

    <div style="border:1px solid #dcdcde;border-radius:8px;overflow:hidden;background:#fff;">
        <iframe src="<?php echo esc_url($jcb_builder); ?>" title="jijipom builder"
                style="width:100%;height:80vh;border:0;display:block;"></iframe>
    </div>
    <p class="description">
        <?php esc_html_e('入力内容はこのブラウザに保存されます。編集したら右上の「⬇ ZIPをエクスポート」を押してダウンロードし、次に「外観 > コンテンツ取込」で取り込んでください。', 'jijipom-content-builder'); ?>
    </p>
</div>
