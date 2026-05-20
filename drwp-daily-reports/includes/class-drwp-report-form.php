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
 * The rendered form is multi-entry by design (1 day report : N
 * jobsite visits) — workers who hit one site still just have one
 * entry card. The form talks to /wp-json/drwp/v1/ for projects,
 * photo uploads, and the report POST. Submissions land as
 * review_status=pending and feed the existing review queue.
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
            'i18n'        => [
                'add_entry'    => __('現場を追加', 'drwp-daily-reports'),
                'remove_entry' => __('この現場を削除', 'drwp-daily-reports'),
                'entry_label'  => __('現場', 'drwp-daily-reports'),
                'pick_project' => __('選択してください', 'drwp-daily-reports'),
                'work'         => __('作業内容', 'drwp-daily-reports'),
                'issues'       => __('問題点 (任意)', 'drwp-daily-reports'),
                'next'         => __('次回予定 (任意)', 'drwp-daily-reports'),
                'photos'       => __('写真', 'drwp-daily-reports'),
                'pick_photos'  => __('カメラで撮影 / 端末から選択', 'drwp-daily-reports'),
                'started'      => __('開始時刻', 'drwp-daily-reports'),
                'ended'        => __('終了時刻', 'drwp-daily-reports'),
                'need_project' => __('現場を選択してください。', 'drwp-daily-reports'),
                'need_work'    => __('作業内容を入力してください。', 'drwp-daily-reports'),
                'need_entry'   => __('現場エントリを 1 つ以上入力してください。', 'drwp-daily-reports'),
                'uploading'    => __('写真をアップロード中…', 'drwp-daily-reports'),
                'sending'      => __('送信中…', 'drwp-daily-reports'),
                'sent'         => __('送信しました。レビュー待ちに入っています。', 'drwp-daily-reports'),
                'send_failed'  => __('送信に失敗しました。', 'drwp-daily-reports'),
            ],
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
                    <span class="drwp-mform-label"><?php esc_html_e('日付', 'drwp-daily-reports'); ?></span>
                    <input type="date" name="report_date" value="<?php echo esc_attr($config['today']); ?>" required>
                </label>

                <div class="drwp-mform-entries" data-role="entries"></div>

                <button type="button" class="drwp-mform-add" data-role="add-entry">
                    + <?php echo esc_html($config['i18n']['add_entry']); ?>
                </button>

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

    private static function css() {
        return <<<CSS
.drwp-mform-wrap { max-width: 640px; margin: 0 auto; padding: 16px; }
.drwp-mform-warn { background: #fef3c7; color: #92400e; padding: 10px 14px; border-radius: 8px; }
.drwp-mform { display: flex; flex-direction: column; gap: 14px; }
.drwp-mform-row { display: flex; flex-direction: column; gap: 6px; }
.drwp-mform-label { font-weight: 600; font-size: 0.95rem; }
.drwp-mform-label em { color: #b91c1c; font-style: normal; }
.drwp-mform input[type=date],
.drwp-mform input[type=time],
.drwp-mform select,
.drwp-mform textarea {
    width: 100%; box-sizing: border-box; font: inherit; font-size: 16px;
    padding: 12px 14px; border: 1px solid #cbd5e1; border-radius: 10px; background: #fff;
}
.drwp-mform textarea { resize: vertical; }
.drwp-mform-entries { display: flex; flex-direction: column; gap: 14px; }
.drwp-mform-entry {
    border: 1px solid #cbd5e1; border-radius: 12px; padding: 14px;
    background: #f8fafc; display: flex; flex-direction: column; gap: 12px;
}
.drwp-mform-entry-head {
    display: flex; justify-content: space-between; align-items: center;
    font-weight: 700; font-size: 1rem; color: #0f172a;
}
.drwp-mform-entry-head .remove {
    background: transparent; color: #b91c1c; border: 1px solid #fecaca;
    padding: 6px 10px; border-radius: 8px; font: inherit; font-size: 0.85rem; cursor: pointer;
}
.drwp-mform-times { display: flex; gap: 8px; }
.drwp-mform-times .col { flex: 1; display: flex; flex-direction: column; gap: 4px; font-size: 0.85rem; color: #475569; }
.drwp-mform-photo-pick {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    min-height: 56px; padding: 12px 16px; border: 2px dashed #94a3b8; border-radius: 10px;
    background: #fff; color: #475569; cursor: pointer; font-weight: 600;
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
.drwp-mform-add {
    padding: 14px; border: 2px dashed #94a3b8; border-radius: 12px;
    background: transparent; color: #1f2937; font-size: 1rem; font-weight: 700; cursor: pointer;
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
     * Inline JS. No bundler, no globals. Each entry owns its own
     * pending photo list so uploads and the post payload stay
     * aligned. The "+" button appends a new entry; the per-entry
     * remove button drops it (last entry can't be removed — we
     * always keep one card so the form doesn't go blank).
     */
    private static function js() {
        return <<<JS
var form = document.getElementById('drwp-mform');
if (!form) return;

var entriesEl = form.querySelector('[data-role=entries]');
var status    = form.querySelector('[data-role=status]');
var submitBtn = form.querySelector('.drwp-mform-submit');
var addBtn    = form.querySelector('[data-role=add-entry]');
var i18n      = config.i18n;
var entries   = [];   // [{ data: HTMLElement, pendingFiles: File[] }]

function setStatus(text, cls) {
    status.textContent = text || '';
    status.className = 'drwp-mform-status' + (cls ? ' ' + cls : '');
}

function projectOptions(selected) {
    var html = '<option value="">' + escapeHtml(i18n.pick_project) + '</option>';
    config.projects.forEach(function (p) {
        var sel = (selected && Number(selected) === p.id) ? ' selected' : '';
        html += '<option value="' + p.id + '"' + sel + '>' + escapeHtml(p.name) + '</option>';
    });
    return html;
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]);
    });
}

function addEntry() {
    var idx = entries.length + 1;
    var el = document.createElement('div');
    el.className = 'drwp-mform-entry';
    el.innerHTML =
        '<div class="drwp-mform-entry-head">' +
            '<span data-role="entry-title">' + escapeHtml(i18n.entry_label) + ' #' + idx + '</span>' +
            '<button type="button" class="remove">' + escapeHtml(i18n.remove_entry) + '</button>' +
        '</div>' +
        '<label class="drwp-mform-row">' +
            '<span class="drwp-mform-label">' + escapeHtml(i18n.entry_label) + ' <em>*</em></span>' +
            '<select name="project_id" required>' + projectOptions() + '</select>' +
        '</label>' +
        '<div class="drwp-mform-times">' +
            '<label class="col">' + escapeHtml(i18n.started) + '<input type="time" name="started_at"></label>' +
            '<label class="col">' + escapeHtml(i18n.ended)   + '<input type="time" name="ended_at"></label>' +
        '</div>' +
        '<label class="drwp-mform-row">' +
            '<span class="drwp-mform-label">' + escapeHtml(i18n.work) + ' <em>*</em></span>' +
            '<textarea name="work_description" rows="4" required></textarea>' +
        '</label>' +
        '<label class="drwp-mform-row">' +
            '<span class="drwp-mform-label">' + escapeHtml(i18n.issues) + '</span>' +
            '<textarea name="issues" rows="2"></textarea>' +
        '</label>' +
        '<label class="drwp-mform-row">' +
            '<span class="drwp-mform-label">' + escapeHtml(i18n.next) + '</span>' +
            '<textarea name="next_plan" rows="2"></textarea>' +
        '</label>' +
        '<div class="drwp-mform-row">' +
            '<span class="drwp-mform-label">' + escapeHtml(i18n.photos) + '</span>' +
            '<label class="drwp-mform-photo-pick"><span>' + escapeHtml(i18n.pick_photos) + '</span>' +
                '<input type="file" accept="image/*" capture="environment" multiple data-role="entry-photos">' +
            '</label>' +
            '<div class="drwp-mform-photos" data-role="entry-preview"></div>' +
        '</div>';

    entriesEl.appendChild(el);
    var rec = { el: el, pendingFiles: [] };
    entries.push(rec);

    var fileInput = el.querySelector('[data-role=entry-photos]');
    var preview = el.querySelector('[data-role=entry-preview]');

    fileInput.addEventListener('change', function () {
        for (var i = 0; i < fileInput.files.length; i++) rec.pendingFiles.push(fileInput.files[i]);
        fileInput.value = '';
        renderEntryPreview(rec, preview);
    });

    el.querySelector('.remove').addEventListener('click', function () {
        if (entries.length === 1) return;  // always keep one
        entries = entries.filter(function (e) { return e !== rec; });
        el.parentNode.removeChild(el);
        renumberEntries();
    });

    renumberEntries();
}

function renderEntryPreview(rec, preview) {
    preview.innerHTML = '';
    rec.pendingFiles.forEach(function (file, idx) {
        var item = document.createElement('div');
        item.className = 'item';
        var img = document.createElement('img');
        img.src = URL.createObjectURL(file);
        item.appendChild(img);
        var del = document.createElement('button');
        del.type = 'button';
        del.textContent = '×';
        del.onclick = function () {
            rec.pendingFiles.splice(idx, 1);
            renderEntryPreview(rec, preview);
        };
        item.appendChild(del);
        preview.appendChild(item);
    });
}

function renumberEntries() {
    entries.forEach(function (rec, i) {
        var t = rec.el.querySelector('[data-role=entry-title]');
        t.textContent = i18n.entry_label + ' #' + (i + 1);
    });
}

addBtn.addEventListener('click', addEntry);
addEntry();  // start with one

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
            if (!r.ok) throw new Error((j && j.message) || ('upload failed HTTP ' + r.status));
            return j.id;
        });
    });
}

form.addEventListener('submit', function (e) {
    e.preventDefault();
    submitBtn.disabled = true;

    if (!entries.length) {
        setStatus(i18n.need_entry, 'err');
        submitBtn.disabled = false;
        return;
    }

    // Validate every entry up-front so we don't run uploads only to
    // fail at the final POST.
    var entryPayloads = [];
    for (var i = 0; i < entries.length; i++) {
        var rec = entries[i];
        var projectId = Number(rec.el.querySelector('select[name=project_id]').value) || 0;
        var work = rec.el.querySelector('textarea[name=work_description]').value.trim();
        if (!projectId) {
            setStatus('#' + (i + 1) + ' ' + i18n.need_project, 'err');
            submitBtn.disabled = false;
            return;
        }
        if (!work) {
            setStatus('#' + (i + 1) + ' ' + i18n.need_work, 'err');
            submitBtn.disabled = false;
            return;
        }
        entryPayloads.push({
            project_id:       projectId,
            started_at:       rec.el.querySelector('input[name=started_at]').value || null,
            ended_at:         rec.el.querySelector('input[name=ended_at]').value || null,
            work_description: work,
            issues:           rec.el.querySelector('textarea[name=issues]').value || '',
            next_plan:        rec.el.querySelector('textarea[name=next_plan]').value || '',
            _files:           rec.pendingFiles
        });
    }

    var totalPhotos = entryPayloads.reduce(function (a, e) { return a + e._files.length; }, 0);
    var uploaded = 0;
    var chain = Promise.resolve();
    entryPayloads.forEach(function (ep) {
        ep.attachment_ids = [];
        ep._files.forEach(function (file) {
            chain = chain.then(function () {
                setStatus(i18n.uploading + ' (' + (uploaded + 1) + '/' + totalPhotos + ')');
                return uploadOne(file).then(function (id) {
                    ep.attachment_ids.push(id);
                    uploaded++;
                });
            });
        });
    });

    chain.then(function () {
        setStatus(i18n.sending);
        return fetch(config.rest_root + 'reports', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': config.nonce
            },
            body: JSON.stringify({
                report_date: form.report_date.value || config.today,
                entries: entryPayloads.map(function (ep) {
                    return {
                        project_id:       ep.project_id,
                        started_at:       ep.started_at,
                        ended_at:         ep.ended_at,
                        work_description: ep.work_description,
                        issues:           ep.issues,
                        next_plan:        ep.next_plan,
                        attachment_ids:   ep.attachment_ids
                    };
                })
            })
        }).then(function (r) {
            return r.json().then(function (j) {
                if (!r.ok) throw new Error((j && j.message) || ('HTTP ' + r.status));
                return j;
            });
        });
    }).then(function (report) {
        setStatus(i18n.sent + ' (#' + report.id + ')', 'ok');
        // Clear all entries and start fresh with one.
        entriesEl.innerHTML = '';
        entries = [];
        addEntry();
        form.report_date.value = config.today;
        submitBtn.disabled = false;
    }).catch(function (err) {
        setStatus(err && err.message ? err.message : i18n.send_failed, 'err');
        submitBtn.disabled = false;
    });
});
JS;
    }
}
