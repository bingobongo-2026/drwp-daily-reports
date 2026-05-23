/**
 * Frontend edit form for own pending reports. The form posts a
 * single flat report (no entry cards) — JS handles only:
 *
 *   - "× 削除" on each existing photo card
 *   - "+ 写真を追加" file picker → REST /upload-photo → append
 *     a thumbnail card with hidden inputs the form submits
 *
 * Config rides on the form's data attribute, same pattern as
 * mobile-form.js (page-cache stacks have been observed to strip
 * wp_localize_script / wp_add_inline_script payloads).
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

    var photosDiv = form.querySelector('[data-role=photos]');
    var photoInput = form.querySelector('[data-role=photo-input]');
    var status     = form.querySelector('[data-role=photo-status]');
    var i18n       = config.i18n || {};

    // -- Photo "×" removal (event delegation) -----------------
    if (photosDiv) {
        photosDiv.addEventListener('click', function (e) {
            var t = e.target;
            if (!(t instanceof Element)) return;
            if (!t.matches('[data-role=remove-photo]')) return;
            var item = t.closest('.drwp-archive-edit-photo-item');
            if (item) item.remove();
        });
    }

    // -- Photo upload -----------------------------------------
    if (photoInput) {
        photoInput.addEventListener('change', function () {
            var files = Array.from(photoInput.files || []);
            if (!files.length) return;

            var done = 0;
            var total = files.length;
            var chain = Promise.resolve();
            if (status) status.textContent = (i18n.uploading || 'Uploading…') + ' (0/' + total + ')';

            files.forEach(function (file) {
                chain = chain.then(function () {
                    return uploadOne(file).then(function (att) {
                        appendPhotoCard(att);
                        done++;
                        if (status) status.textContent = (i18n.uploading || 'Uploading…') + ' (' + done + '/' + total + ')';
                    });
                }).catch(function (err) {
                    if (status) status.textContent = (i18n.upload_failed || 'Upload failed') + ': ' + (err && err.message ? err.message : err);
                });
            });
            chain.then(function () {
                photoInput.value = '';
                if (status && done === total) {
                    setTimeout(function () { status.textContent = ''; }, 1500);
                }
            });
        });
    }

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

    function appendPhotoCard(att) {
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
        hidId.name = 'attachment_ids[]';
        hidId.value = String(att.id);
        div.appendChild(hidId);

        var capInput = document.createElement('input');
        capInput.type = 'text';
        capInput.name = 'attachment_captions[]';
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
