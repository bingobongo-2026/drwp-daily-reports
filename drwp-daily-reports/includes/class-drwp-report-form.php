<?php
if (!defined('ABSPATH')) exit;

/**
 * Front-end shortcode that lets a logged-in field worker submit a
 * daily report from their phone without going through /wp-admin.
 *
 * Usage:
 *
 *     [drwp_report_form]
 *
 * Place it on any published page or post. The shortcode renders a
 * mobile-first form that talks to the existing REST endpoints under
 * /wp-json/drwp/v1/. Submissions land as review_status=pending so the
 * office team picks them up in the existing review queue.
 *
 * Requirements for the visitor:
 *   - logged in (WP cookie auth supplies the REST nonce)
 *   - has the edit_posts capability (Contributor or higher)
 *   - the plugin's license is active or in grace, otherwise the
 *     REST POST returns 402 and we surface the message verbatim
 */
class DRWP_Report_Form {

    public static function init() {
        add_shortcode('drwp_report_form', [__CLASS__, 'render']);
    }

    public static function render($atts = [], $content = '') {
        if (!is_user_logged_in()) {
            return self::wrap(self::login_prompt());
        }
        if (!current_user_can('edit_posts')) {
            return self::wrap('<p>' . esc_html__('日報を投稿する権限がありません。管理者にお問い合わせください。', 'drwp-daily-reports') . '</p>');
        }

        $projects = array_map(function ($p) {
            return ['id' => (int) $p->id, 'name' => (string) $p->name];
        }, DRWP_Project::all());

        $config = [
            'rest_root'   => esc_url_raw(rest_url('drwp/v1/')),
            'nonce'       => wp_create_nonce('wp_rest'),
            'today'       => current_time('Y-m-d'),
            'license_ok'  => DRWP_License::can_write(),
            'projects'    => $projects,
        ];

        ob_start();
        ?>
        <div class="drwp-mform-wrap">
            <?php if (!$config['license_ok']) : ?>
                <p class="drwp-mform-warn">
                    <?php esc_html_e('現在ライセンスが有効ではないため、送信しても保存されません。管理者に確認してください。', 'drwp-daily-reports'); ?>
                </p>
            <?php endif; ?>

            <form class="drwp-mform" id="drwp-mform" novalidate>
                <label class="drwp-mform-row">
                    <span class="drwp-mform-label"><?php esc_html_e('現場', 'drwp-daily-reports'); ?> <em>*</em></span>
                    <select name="project_id" required>
                        <option value=""><?php esc_html_e('選択してください', 'drwp-daily-reports'); ?></option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?php echo (int) $p['id']; ?>"><?php echo esc_html($p['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="drwp-mform-row">
                    <span class="drwp-mform-label"><?php esc_html_e('日付', 'drwp-daily-reports'); ?></span>
                    <input type="date" name="report_date" value="<?php echo esc_attr($config['today']); ?>" required>
                </label>

                <label class="drwp-mform-row">
                    <span class="drwp-mform-label"><?php esc_html_e('作業内容', 'drwp-daily-reports'); ?> <em>*</em></span>
                    <textarea name="work_description" rows="5" required placeholder="<?php esc_attr_e('例: 外壁の高圧洗浄を実施。北面と東面が完了。', 'drwp-daily-reports'); ?>"></textarea>
                </label>

                <label class="drwp-mform-row">
                    <span class="drwp-mform-label"><?php esc_html_e('問題点 (任意)', 'drwp-daily-reports'); ?></span>
                    <textarea name="issues" rows="3" placeholder="<?php esc_attr_e('例: 雨により午後3時で中断。', 'drwp-daily-reports'); ?>"></textarea>
                </label>

                <label class="drwp-mform-row">
                    <span class="drwp-mform-label"><?php esc_html_e('次回予定 (任意)', 'drwp-daily-reports'); ?></span>
                    <textarea name="next_plan" rows="3" placeholder="<?php esc_attr_e('例: 明日は南面と西面を実施。', 'drwp-daily-reports'); ?>"></textarea>
                </label>

                <div class="drwp-mform-row">
                    <span class="drwp-mform-label"><?php esc_html_e('写真', 'drwp-daily-reports'); ?></span>
                    <label class="drwp-mform-photo-pick">
                        <span><?php esc_html_e('カメラで撮影 / 端末から選択', 'drwp-daily-reports'); ?></span>
                        <input type="file" name="photos" accept="image/*" capture="environment" multiple>
                    </label>
                    <div class="drwp-mform-photos" data-role="photo-preview"></div>
                </div>

                <button type="submit" class="drwp-mform-submit">
                    <?php esc_html_e('下書きとして送信', 'drwp-daily-reports'); ?>
                </button>

                <p class="drwp-mform-help">
                    <?php esc_html_e('送信した日報は「レビュー待ち」として保存されます。事務所側で内容を確認のうえ、必要に応じて公開されます。', 'drwp-daily-reports'); ?>
                </p>

                <div class="drwp-mform-status" data-role="status" aria-live="polite"></div>
            </form>
        </div>

        <style><?php echo self::css(); ?></style>
        <script>
            (function () {
                var config = <?php echo wp_json_encode($config); ?>;
                <?php echo self::js(); ?>
            })();
        </script>
        <?php
        return ob_get_clean();
    }

    private static function login_prompt() {
        $current_url = is_singular() ? get_permalink() : home_url(add_query_arg(null, null));
        $login_url = wp_login_url($current_url);
        return sprintf(
            '<p>%s <a href="%s">%s</a></p>',
            esc_html__('日報を投稿するにはログインしてください。', 'drwp-daily-reports'),
            esc_url($login_url),
            esc_html__('ログイン画面へ', 'drwp-daily-reports')
        );
    }

    private static function wrap($inner) {
        return '<div class="drwp-mform-wrap">' . $inner . '</div>';
    }

    /**
     * Inline CSS. Mobile-first: a single column with large tap targets,
     * never narrower than 16px font (otherwise iOS Safari zooms on
     * focus). Capped at 640px on larger screens so the form stays
     * readable on tablets / desktops too.
     */
    private static function css() {
        return <<<CSS
.drwp-mform-wrap { max-width: 640px; margin: 0 auto; padding: 16px; }
.drwp-mform-warn { background: #fef3c7; color: #92400e; padding: 10px 14px; border-radius: 8px; }
.drwp-mform { display: flex; flex-direction: column; gap: 14px; }
.drwp-mform-row { display: flex; flex-direction: column; gap: 6px; }
.drwp-mform-label { font-weight: 600; font-size: 0.95rem; }
.drwp-mform-label em { color: #b91c1c; font-style: normal; }
.drwp-mform input[type=date],
.drwp-mform select,
.drwp-mform textarea {
    width: 100%; box-sizing: border-box; font: inherit; font-size: 16px;
    padding: 12px 14px; border: 1px solid #cbd5e1; border-radius: 10px; background: #fff;
}
.drwp-mform textarea { resize: vertical; }
.drwp-mform-photo-pick {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    min-height: 56px; padding: 12px 16px; border: 2px dashed #94a3b8; border-radius: 10px;
    background: #f8fafc; color: #475569; cursor: pointer; font-weight: 600;
}
.drwp-mform-photo-pick input { display: none; }
.drwp-mform-photos { display: grid; grid-template-columns: repeat(auto-fill, minmax(96px, 1fr)); gap: 8px; }
.drwp-mform-photos:empty { display: none; }
.drwp-mform-photos .item { position: relative; padding-top: 100%; border-radius: 8px; overflow: hidden; background: #e5e7eb; }
.drwp-mform-photos .item img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
.drwp-mform-photos .item button {
    position: absolute; top: 4px; right: 4px;
    width: 28px; height: 28px; border-radius: 999px; border: 0;
    background: rgba(0,0,0,.6); color: #fff; font-size: 18px; line-height: 1; cursor: pointer;
}
.drwp-mform-submit {
    margin-top: 6px; padding: 16px; border: 0; border-radius: 12px;
    background: #111827; color: #fff; font-size: 1.05rem; font-weight: 700; cursor: pointer;
}
.drwp-mform-submit:disabled { opacity: 0.6; cursor: progress; }
.drwp-mform-help { font-size: 0.85rem; color: #64748b; }
.drwp-mform-status { min-height: 24px; padding: 8px 0; font-size: 0.95rem; }
.drwp-mform-status.ok { color: #166534; }
.drwp-mform-status.err { color: #991b1b; }
CSS;
    }

    /**
     * Inline JS. No bundler, no globals. Reads `config` from the
     * enclosing IIFE. Uploads each photo serially before posting the
     * report — keeps the upload-photo endpoint behavior unchanged
     * (one file per call) and the field worker sees a per-photo
     * progress indicator instead of all-or-nothing.
     */
    private static function js() {
        return <<<JS
var form = document.getElementById('drwp-mform');
if (!form) return;

var status = form.querySelector('[data-role=status]');
var preview = form.querySelector('[data-role=photo-preview]');
var fileInput = form.querySelector('input[type=file]');
var submitBtn = form.querySelector('.drwp-mform-submit');
var pendingFiles = [];

function setStatus(text, cls) {
    status.textContent = text || '';
    status.className = 'drwp-mform-status' + (cls ? ' ' + cls : '');
}

function renderPreviews() {
    preview.innerHTML = '';
    pendingFiles.forEach(function (file, idx) {
        var item = document.createElement('div');
        item.className = 'item';
        var img = document.createElement('img');
        img.src = URL.createObjectURL(file);
        item.appendChild(img);
        var del = document.createElement('button');
        del.type = 'button';
        del.textContent = '×';
        del.setAttribute('aria-label', '削除');
        del.onclick = function () {
            pendingFiles.splice(idx, 1);
            renderPreviews();
        };
        item.appendChild(del);
        preview.appendChild(item);
    });
}

fileInput.addEventListener('change', function () {
    for (var i = 0; i < fileInput.files.length; i++) pendingFiles.push(fileInput.files[i]);
    fileInput.value = '';
    renderPreviews();
});

function uploadOne(file) {
    var body = new FormData();
    body.append('file', file, file.name);
    return fetch(config.rest_root + 'upload-photo', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-WP-Nonce': config.nonce },
        body: body
    }).then(function (r) {
        return r.json().then(function (j) {
            if (!r.ok) throw new Error((j && j.message) || ('写真アップロード失敗 HTTP ' + r.status));
            return j.id;
        });
    });
}

form.addEventListener('submit', function (e) {
    e.preventDefault();
    submitBtn.disabled = true;

    var payload = {
        project_id:       Number(form.project_id.value) || null,
        report_date:      form.report_date.value || config.today,
        work_description: form.work_description.value || '',
        issues:           form.issues.value || '',
        next_plan:        form.next_plan.value || ''
    };

    if (!payload.project_id) {
        setStatus('現場を選択してください。', 'err');
        submitBtn.disabled = false;
        return;
    }
    if (!payload.work_description.trim()) {
        setStatus('作業内容を入力してください。', 'err');
        submitBtn.disabled = false;
        return;
    }

    var uploaded = [];
    var chain = Promise.resolve();
    pendingFiles.forEach(function (file, i) {
        chain = chain.then(function () {
            setStatus('写真をアップロード中… (' + (i + 1) + '/' + pendingFiles.length + ')');
            return uploadOne(file).then(function (id) { uploaded.push(id); });
        });
    });

    chain.then(function () {
        if (uploaded.length) {
            payload.attachment_ids = uploaded;
            payload.attachment_captions = uploaded.map(function () { return ''; });
        }
        setStatus('送信中…');
        return fetch(config.rest_root + 'reports', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': config.nonce
            },
            body: JSON.stringify(payload)
        }).then(function (r) {
            return r.json().then(function (j) {
                if (!r.ok) throw new Error((j && j.message) || ('送信失敗 HTTP ' + r.status));
                return j;
            });
        });
    }).then(function (report) {
        setStatus('送信しました (#' + report.id + ')。レビュー待ちに入っています。', 'ok');
        form.reset();
        form.report_date.value = config.today;
        pendingFiles = [];
        renderPreviews();
        submitBtn.disabled = false;
    }).catch(function (err) {
        setStatus(err && err.message ? err.message : '送信に失敗しました。', 'err');
        submitBtn.disabled = false;
    });
});
JS;
    }
}
