/**
 * Field-worker mobile form for the [drwp_report_form] shortcode.
 *
 * Loaded as a real .js file rather than inlined in the shortcode
 * return string — when WordPress's wpautop runs on the_content
 * (the path most shortcodes take), it can corrupt `<script>` and
 * `<style>` blocks emitted as inline strings. Loading via
 * wp_enqueue_script sidesteps wpautop entirely and is the standard
 * fix for the "shortcode JS dies with Invalid or unexpected token"
 * symptom.
 *
 * Configuration is passed via window.drwpMformConfig, populated by
 * wp_add_inline_script('drwp-mform', '...', 'before') in the
 * PHP-side render() call.
 */
(function () {
    // Config rides on the wrapper element's data attribute. This
    // sidesteps every plugin-mediated <script> transport (inline,
    // localize, add_inline_script) that page-cache / asset-optimizer
    // layers have been observed to strip in production. If the HTML
    // is on the page, the config is on the page.
    var wrap = document.querySelector('.drwp-mform-wrap[data-drwp-mform-config]');
    if (!wrap) {
        if (window.console && window.console.warn) {
            window.console.warn('drwp-mform: wrapper with data-drwp-mform-config not found on page.');
        }
        return;
    }
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

    var entriesEl = form.querySelector('[data-role=entries]');
    var status    = form.querySelector('[data-role=status]');
    var submitBtn = form.querySelector('.drwp-mform-submit');
    var addBtn    = form.querySelector('[data-role=add-entry]');
    var i18n      = config.i18n;
    var entries   = [];   // [{ el: HTMLElement, pendingFiles: File[] }]

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
        // entries.length == 1 means there's nothing to remove —
        // hiding the button (rather than disabling the click handler)
        // makes the available action obvious at a glance, especially
        // on the first submission where the worker hasn't yet
        // discovered that adding a card unlocks removal.
        var canRemove = entries.length > 1;
        entries.forEach(function (rec, i) {
            var t = rec.el.querySelector('[data-role=entry-title]');
            t.textContent = i18n.entry_label + ' #' + (i + 1);
            var btn = rec.el.querySelector('.remove');
            if (btn) btn.style.display = canRemove ? '' : 'none';
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

    // Cache the submit button's resting label once so we can restore
    // it after switching to a "sending…" indicator. Reading from the
    // DOM each time would pick up the indicator text instead.
    var submitDefaultLabel = (submitBtn.textContent || '').trim();

    function startSending() {
        submitBtn.disabled = true;
        submitBtn.textContent = i18n.sending;
        setStatus('');   // clear any prior success banner
    }
    function stopSending() {
        submitBtn.disabled = false;
        submitBtn.textContent = submitDefaultLabel;
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        startSending();

        if (!entries.length) {
            setStatus(i18n.need_entry, 'err');
            stopSending();
            return;
        }

        var entryPayloads = [];
        for (var i = 0; i < entries.length; i++) {
            var rec = entries[i];
            var projectId = Number(rec.el.querySelector('select[name=project_id]').value) || 0;
            var work = rec.el.querySelector('textarea[name=work_description]').value.trim();
            if (!projectId) {
                setStatus('#' + (i + 1) + ' ' + i18n.need_project, 'err');
                stopSending();
                return;
            }
            if (!work) {
                setStatus('#' + (i + 1) + ' ' + i18n.need_work, 'err');
                stopSending();
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
            entriesEl.innerHTML = '';
            entries = [];
            addEntry();
            form.report_date.value = config.today;
            stopSending();
            // After resetting the form, the success banner stays at
            // the bottom — scroll it into view so the worker isn't
            // left staring at an empty re-populated form wondering
            // whether the previous submission went through.
            status.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }).catch(function (err) {
            setStatus(err && err.message ? err.message : i18n.send_failed, 'err');
            stopSending();
        });
    });
})();
