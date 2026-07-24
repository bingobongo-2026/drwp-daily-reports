<?php
if (!defined('ABSPATH')) exit;

$jcb_theme_ok = JCB_Plugin::theme_ok();
$jcb_builder  = JCB_URL . 'assets/builder.html?v=' . JCB_VERSION;

$jcb_errmap = [
    'no_theme' => __('jijipom テーマが有効ではありません。取り込みは jijipom(またはその子テーマ)を有効化してから行ってください。', 'jijipom-content-builder'),
    'no_file'  => __('ZIP ファイルが選択されていません。', 'jijipom-content-builder'),
    'bad_zip'  => __('ZIP から jijipom-content.json を読み取れませんでした。エクスポートした ZIP を選んでください。', 'jijipom-content-builder'),
    'bad_json' => __('ファイルの形式が正しくありません(jijipom-content の JSON ではありません)。', 'jijipom-content-builder'),
];
?>
<div class="wrap">
    <h1><?php esc_html_e('jijipom コンテンツビルダー', 'jijipom-content-builder'); ?></h1>

    <?php if (!empty($_GET['jcb_done'])): ?>
        <div class="notice notice-success is-dismissible"><p>
            <?php
            printf(
                /* translators: 1: 設定件数, 2: 固定ページ件数 */
                esc_html__('取り込みました。テーマ設定 %1$d 件、固定ページ %2$d 件を反映しました。', 'jijipom-content-builder'),
                (int) ($_GET['jcb_mods'] ?? 0),
                (int) ($_GET['jcb_pages'] ?? 0)
            );
            ?>
            <a href="<?php echo esc_url(home_url('/')); ?>" target="_blank" rel="noopener"><?php esc_html_e('サイトを表示', 'jijipom-content-builder'); ?></a>
        </p></div>
    <?php endif; ?>
    <?php if (!empty($_GET['jcb_err']) && isset($jcb_errmap[$_GET['jcb_err']])): ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html($jcb_errmap[sanitize_key($_GET['jcb_err'])]); ?></p></div>
    <?php endif; ?>

    <?php if (!$jcb_theme_ok): ?>
        <div class="notice notice-warning"><p>
            <?php esc_html_e('現在 jijipom テーマが有効ではありません。ビルダーとエクスポートは利用できますが、「取り込み(反映)」は jijipom を有効化してから行ってください。', 'jijipom-content-builder'); ?>
        </p></div>
    <?php endif; ?>

    <p class="description" style="max-width:820px;">
        <?php esc_html_e('下のビルダーで各ページの内容を入力し、「ZIPをエクスポート」でダウンロードします。その ZIP を下の「取り込み」で読み込むと、jijipom の各ページ設定(カスタマイザー)と固定ページにまとめて反映されます。別サイトへ持って行って取り込むこともできます。', 'jijipom-content-builder'); ?>
    </p>

    <h2><?php esc_html_e('1. コンテンツを編集', 'jijipom-content-builder'); ?></h2>
    <div style="border:1px solid #dcdcde;border-radius:8px;overflow:hidden;background:#fff;">
        <iframe src="<?php echo esc_url($jcb_builder); ?>" title="jijipom builder"
                style="width:100%;height:78vh;border:0;display:block;"></iframe>
    </div>
    <p class="description"><?php esc_html_e('入力内容はこのブラウザに保存されます。編集したら右上の「ZIPをエクスポート」を押してダウンロードしてください。', 'jijipom-content-builder'); ?></p>

    <h2 style="margin-top:28px;"><?php esc_html_e('2. 取り込み（jijipom へ反映）', 'jijipom-content-builder'); ?></h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data"
          style="max-width:820px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 18px;">
        <?php wp_nonce_field('jcb_import'); ?>
        <input type="hidden" name="action" value="jcb_import" />

        <p>
            <label for="jcb_zip" style="font-weight:600;display:block;margin-bottom:6px;"><?php esc_html_e('エクスポートした ZIP を選択', 'jijipom-content-builder'); ?></label>
            <input type="file" id="jcb_zip" name="jcb_zip" accept=".zip,application/zip,application/json" required />
        </p>

        <p style="margin:.6em 0;">
            <label><input type="checkbox" name="jcb_create_pages" value="1" checked />
                <?php esc_html_e('固定ページ(サービス/会社概要/お問い合わせ/プライバシー/ホーム)を作成・更新する', 'jijipom-content-builder'); ?></label>
        </p>
        <p style="margin:.6em 0;">
            <label><input type="checkbox" name="jcb_set_front" value="1" checked />
                <?php esc_html_e('「ホーム」を固定のトップページに設定する', 'jijipom-content-builder'); ?></label>
        </p>

        <p class="description" style="margin:.4em 0 1em;">
            <?php esc_html_e('取り込みは jijipom のテーマ設定(カスタマイザー)を上書きします。既存の色・フォント・ヘッダー/フッター・SNS などビルダーに無い項目はそのまま残ります。固定ページの本文はビルダーで入力があった場合のみ上書きされます。', 'jijipom-content-builder'); ?>
        </p>

        <?php submit_button(__('取り込んで反映する', 'jijipom-content-builder'), 'primary', 'submit', false, $jcb_theme_ok ? [] : ['disabled' => 'disabled']); ?>
    </form>
</div>
