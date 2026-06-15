/**
 * Field-worker mobile form for the [drwp_report_form] shortcode.
 *
 * 1 form = 1 report = 1 site visit. Photos are uploaded one at a
 * time to the REST /upload-photo endpoint before the report
 * payload itself is POSTed to /reports, so the report row never
 * exists referencing IDs that failed to upload.
 *
 * Config rides on the wrapper element's data attribute (page-cache
 * stacks have been observed to strip both wp_localize_script and
 * wp_add_inline_script payloads in production).
 */
(function () {
    var wrap = document.querySelector('.drwp-mform-wrap[data-drwp-mform-config]');
    if (!wrap) return;

    var config;
    try {
        config = JSON.parse(wrap.getAttribute('data-drwp-mform-config'));
    } catch (e) {
        if (window.console && window.console.error) {
            window.console.error('drwp-mform: failed to parse config JSON:', e);
        }
        return;
    }
    if (!config) return;

    var form = document.getElementById('drwp-mform');
    if (!form) return;

    var status      = form.querySelector('[data-role=status]');
    var submitBtn   = form.querySelector('.drwp-mform-submit');
    var photoInput  = form.querySelector('[data-role=photo-input]');
    var photoPrev   = form.querySelector('[data-role=photo-preview]');
    var i18n        = config.i18n || {};
    // Pending photo entries — each carries a File + its caption so
    // the submit handler can hand both arrays (attachment_ids /
    // attachment_captions) to the REST endpoint in sync, matching
    // the order the photos were picked.
    var pendingEntries = [];

    function setStatus(text, cls) {
        if (!status) return;
        status.textContent = text || '';
        status.className = 'drwp-mform-status' + (cls ? ' ' + cls : '');
    }

    var submitDefaultLabel = (submitBtn.textContent || '').trim();
    function startSending() {
        submitBtn.disabled = true;
        submitBtn.textContent = i18n.sending || '送信中…';
        setStatus('');
    }
    function stopSending() {
        submitBtn.disabled = false;
        submitBtn.textContent = submitDefaultLabel;
    }

    // ---- Photo picker ----------------------------------------
    if (photoInput) {
        photoInput.addEventListener('change', function () {
            var files = Array.from(photoInput.files || []);
            files.forEach(function (f) {
                pendingEntries.push({ file: f, caption: '' });
            });
            photoInput.value = '';
            renderPhotoPreview();
        });
    }

    function renderPhotoPreview() {
        if (!photoPrev) return;
        photoPrev.innerHTML = '';
        pendingEntries.forEach(function (entry, idx) {
            var item = document.createElement('div');
            item.className = 'item';

            // Thumbnail + delete button as a self-contained block so
            // the square-aspect padding trick still works while the
            // caption input lives outside that block.
            var thumb = document.createElement('div');
            thumb.className = 'photo-thumb';
            var img = document.createElement('img');
            img.src = URL.createObjectURL(entry.file);
            thumb.appendChild(img);
            var del = document.createElement('button');
            del.type = 'button';
            del.textContent = '×';
            del.setAttribute('aria-label', i18n.remove_photo || '削除');
            del.onclick = function () {
                pendingEntries.splice(idx, 1);
                renderPhotoPreview();
            };
            thumb.appendChild(del);
            item.appendChild(thumb);

            var caption = document.createElement('input');
            caption.type = 'text';
            caption.className = 'photo-caption';
            caption.maxLength = 255;
            caption.placeholder = i18n.caption_placeholder || '説明文（任意）';
            caption.value = entry.caption || '';
            // `input` writes straight back into pendingEntries so the
            // submit handler sees the latest text without re-reading
            // the DOM.
            caption.addEventListener('input', function () {
                entry.caption = caption.value;
            });
            item.appendChild(caption);

            photoPrev.appendChild(item);
        });
    }

    // ---- Upload + submit -------------------------------------
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
        startSending();

        var projectId = Number(form.project_id.value) || 0;
        var work = form.work_description.value.trim();
        if (!projectId) {
            setStatus(i18n.need_project || '案件を選択してください。', 'err');
            stopSending();
            return;
        }
        if (!work) {
            setStatus(i18n.need_work || '作業内容を入力してください。', 'err');
            stopSending();
            return;
        }

        var totalPhotos = pendingEntries.length;
        var uploaded = 0;
        var attachmentIds = [];
        var attachmentCaptions = [];
        var chain = Promise.resolve();
        pendingEntries.forEach(function (entry) {
            chain = chain.then(function () {
                setStatus((i18n.uploading || 'Uploading…') + ' (' + (uploaded + 1) + '/' + totalPhotos + ')');
                return uploadOne(entry.file).then(function (id) {
                    attachmentIds.push(id);
                    // Push caption *after* the id resolves so the
                    // arrays line up by index even if a previous
                    // upload threw and was retried. The caption is
                    // read off the live entry, so any keystrokes
                    // during the upload chain are picked up.
                    attachmentCaptions.push(entry.caption || '');
                    uploaded++;
                });
            });
        });

        chain.then(function () {
            setStatus(i18n.sending || '送信中…');
            return fetch(config.rest_root + 'reports', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce
                },
                body: JSON.stringify({
                    report_date:         form.report_date.value || config.today,
                    project_id:          projectId,
                    started_at:          form.started_at.value || null,
                    ended_at:            form.ended_at.value || null,
                    work_description:    work,
                    issues:              form.issues.value || '',
                    next_plan:           form.next_plan.value || '',
                    attachment_ids:      attachmentIds,
                    attachment_captions: attachmentCaptions,
                    // 予定チップから開いたときだけ値が入る。新しい日報を
                    // その予定に紐づけ、予定を「完了」にするのに使う。
                    linked_plan_id:      (form.linked_plan_id && form.linked_plan_id.value) || null
                })
            }).then(function (r) {
                return r.json().then(function (j) {
                    if (!r.ok) throw new Error((j && j.message) || ('HTTP ' + r.status));
                    return j;
                });
            });
        }).then(function (report) {
            setStatus((i18n.sent || '送信しました。') + ' (#' + report.id + ')', 'ok');
            // 予定チップから開かれた場合 (linked_plan_id が入っている)
            // は、カレンダー上の予定チップを「完了」状態に切り替えて
            // 見せたいので、成功メッセージを少し見せた後にリロード。
            // それ以外 (ダッシュボード等の独立フォーム) は連続入力
            // できるよう従来通り form.reset() で次の入力に備える。
            var fromPlan = form.linked_plan_id && form.linked_plan_id.value;
            if (fromPlan) {
                stopSending();
                setTimeout(function () { window.location.reload(); }, 800);
                return;
            }
            form.reset();
            pendingEntries = [];
            renderPhotoPreview();
            form.report_date.value = config.today;
            stopSending();
            status.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }).catch(function (err) {
            setStatus(err && err.message ? err.message : (i18n.send_failed || '送信に失敗しました。'), 'err');
            stopSending();
        });
    });
})();
