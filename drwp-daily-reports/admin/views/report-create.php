<?php if (!defined('ABSPATH')) { exit; } ?>
<div class="wrap">
    <h1>日報作成</h1>

    <?php if (!empty($_GET['drwp_notice'])) : ?>
        <div class="notice notice-warning"><p><?php echo esc_html(DRWP_Admin::notice_message($_GET['drwp_notice'])); ?></p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
        <?php wp_nonce_field('drwp_save_report'); ?>
        <input type="hidden" name="action" value="drwp_save_report">

        <table class="form-table">
            <tr>
                <th><label for="project_id">現場</label></th>
                <td>
                    <select name="project_id" id="project_id" required>
                        <option value="">選択してください</option>
                        <?php foreach ($projects as $project) : ?>
                            <option value="<?php echo esc_attr($project->id); ?>"><?php echo esc_html($project->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr><th><label for="report_date">日付</label></th><td><input type="date" name="report_date" id="report_date" value="<?php echo esc_attr(date('Y-m-d')); ?>" required></td></tr>
            <tr><th><label for="work_category">作業区分</label></th><td><input type="text" name="work_category" id="work_category" class="regular-text"></td></tr>
            <tr><th><label for="worker_count">作業人数</label></th><td><input type="number" name="worker_count" id="worker_count" min="0" step="1"></td></tr>
            <tr><th><label for="work_description">作業内容</label></th><td><textarea name="work_description" id="work_description" rows="6" class="large-text" required></textarea></td></tr>
            <tr><th><label for="issues">補足事項</label></th><td><textarea name="issues" id="issues" rows="4" class="large-text"></textarea></td></tr>
            <tr><th><label for="next_plan">今後の予定</label></th><td><textarea name="next_plan" id="next_plan" rows="4" class="large-text"></textarea></td></tr>
            <tr>
                <th>写真</th>
                <td>
                    <input type="file" name="drwp_photos[]" multiple accept="image/*">
                    <p class="description">複数画像を選択できます。写真種別とコメントは選んだ順に最初の数件へ対応します。</p>
                    <div style="margin-top:12px;">
                        <?php for ($i = 0; $i < 3; $i++) : ?>
                            <p>
                                <select name="drwp_photo_type[]">
                                    <option value="during">作業中</option>
                                    <option value="before">作業前</option>
                                    <option value="after">作業後</option>
                                    <option value="issue">補足</option>
                                </select>
                                <input type="text" name="drwp_photo_caption[]" class="regular-text" placeholder="写真コメント">
                            </p>
                        <?php endfor; ?>
                    </div>
                </td>
            </tr>
        </table>

        <?php submit_button('日報を保存'); ?>
    </form>
</div>
