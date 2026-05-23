/**
 * Frontend edit form for own pending reports. Driven by the
 * data-drwp-edit-config JSON on the <form>. Handles three dynamic
 * interactions; everything else (entry pre-fill, project options,
 * existing photo rows) is rendered server-side, so a page with JS
 * disabled still shows the right data — just without add / remove
 * controls.
 *
 *   - "+ 現場を追加": clone the <template> with __IDX__ swapped for
 *     the next free index, append it.
 *   - "この現場を削除": detach the card.
 *   - "+ 写真を追加": file picker → REST /upload-photo → append a
 *     thumbnail card with the returned attachment_id baked into a
 *     hidden input so the form submits the new IDs alongside the
 *     existing ones.
 *
 * Config lives on a data attribute (same pattern as mobile-form.js)
 * to survive aggressive page-cache / asset-optimizer stacks that
 * strip auxiliary <script> chunks.
 */
(function () {
    var form = document.querySelector('form.drwp-archive-edit-form[data-drwp-edit-config]');
    if (!form) return;

    var config;
    try {
        config = JSON.parse(form.getAttribute('data-drwp-edit-config'));
    } catch (e) {
        if (window.console && window.console.error) {
            window.console.error('drwp-archive-edit: failed to parse config JSON:', e);
        }
        return;
    }
    if (!config) return;

    var entriesEl = form.querySelector('[data-role=entries]');
    var addBtn    = form.querySelector('[data-role=add-entry]');
    var template  = document.getElementById('drwp-archive-edit-template');
    var i18n      = config.i18n || {};

    var nextIdx = entriesEl.querySelectorAll('.drwp-archive-edit-entry').length;

    /* -- Add entry --------------------------------------------- */
    if (addBtn && template) {
        addBtn.addEventListener('click', function () {
            var html = template.innerHTML
                .replace(/__IDX__/g, String(nextIdx))
                .replace(/__N__/g, String(nextIdx + 1));
            var holder = document.createElement('div');
            holder.innerHTML = html;
            var card = holder.firstElementChild;
            if (card) entriesEl.appendChild(card);
            nextIdx++;
        });
    }

    /* -- Remove entry / remove photo (event delegation) -------- */
    entriesEl.addEventListener('click', function (e) {
        var t = e.target;
        if (!(t instanceof Element)) return;
        if (t.matches('[data-role=remove-entry]')) {
            var card = t.closest('.drwp-archive-edit-entry');
            if (card && entriesEl.querySelectorAll('.drwp-archive-edit-entry').length > 1) {
                card.remove();
            }
            // If only one card remains we silently ignore — a
            // report must have ≥ 1 entry (server enforces).
        } else if (t.matches('[data-role=remove-photo]')) {
            var item = t.closest('.drwp-archive-edit-photo-item');
            if (item) item.remove();
        }
    });

    /* -- Photo upload (event delegation on change) ------------- */
    entriesEl.addEventListener('change', function (e) {
        var t = e.target;
        if (!(t instanceof HTMLInputElement)) return;
        if (!t.matches('[data-role=photo-input]')) return;

        var card = t.closest('.drwp-archive-edit-entry');
        if (!card) return;
        var idx = card.getAttribute('data-idx');
        var photosDiv = card.querySelector('[data-role=photos]');
        var status = card.querySelector('[data-role=photo-status]');
        var files = Array.from(t.files || []);
        if (!files.length) return;

        var done = 0;
        var total = files.length;
        var chain = Promise.resolve();
        if (status) status.textContent = (i18n.uploading || 'Uploading…') + ' (0/' + total + ')';

        files.forEach(function (file) {
            chain = chain.then(function () {
                return uploadOne(file).then(function (att) {
                    appendPhotoCard(photosDiv, idx, att);
                    done++;
                    if (status) status.textContent = (i18n.uploading || 'Uploading…') + ' (' + done + '/' + total + ')';
                });
            }).catch(function (err) {
                if (status) status.textContent = (i18n.upload_failed || 'Upload failed') + ': ' + (err && err.message ? err.message : err);
            });
        });
        chain.then(function () {
            // Reset the input so picking the same file again still
            // fires a change event. Status clears after a moment.
            t.value = '';
            if (status && done === total) {
                setTimeout(function () { status.textContent = ''; }, 1500);
            }
        });
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
                if (!r.ok) throw new Error((j && j.message) || ('HTTP ' + r.status));
                return j;
            });
        });
    }

    function appendPhotoCard(photosDiv, idx, att) {
        if (!photosDiv) return;
        var div = document.createElement('div');
        div.className = 'drwp-archive-edit-photo-item';

        if (att.thumbnail_url || att.full_url) {
            var img = document.createElement('img');
            img.src = att.thumbnail_url || att.full_url;
            img.alt = '';
            div.appendChild(img);
        }

        var hidId = document.createElement('input');
        hidId.type = 'hidden';
        hidId.name = 'entries[' + idx + '][attachment_ids][]';
        hidId.value = String(att.id);
        div.appendChild(hidId);

        var capInput = document.createElement('input');
        capInput.type = 'text';
        capInput.name = 'entries[' + idx + '][attachment_captions][]';
        capInput.placeholder = 'キャプション';
        capInput.value = '';
        div.appendChild(capInput);

        var rm = document.createElement('button');
        rm.type = 'button';
        rm.className = 'drwp-archive-edit-photo-remove';
        rm.setAttribute('data-role', 'remove-photo');
        rm.textContent = '×';
        div.appendChild(rm);

        photosDiv.appendChild(div);
    }
})();
