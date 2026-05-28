jQuery(function ($) {
  const $list = $('#drwp-photo-list');
  if (!$list.length) return;

  let frame;

  function renderCard(id, thumbUrl) {
    return $(
      '<div class="drwp-photo-item">' +
        '<a href="#" class="drwp-photo-remove" aria-label="削除">×</a>' +
        '<img src="' + thumbUrl + '" alt="" />' +
        '<input type="hidden" name="attachment_ids[]" value="' + id + '" />' +
        '<input type="text" name="attachment_captions[]" class="drwp-photo-caption" placeholder="キャプション" value="" />' +
      '</div>'
    );
  }

  function renderFromMedia(attachment) {
    const thumb =
      (attachment.sizes && (attachment.sizes.thumbnail || attachment.sizes.medium)) ||
      attachment;
    return renderCard(attachment.id, thumb.url || attachment.url);
  }

  // --- Media Library picker ---
  $('#drwp-open-media').on('click', function (e) {
    e.preventDefault();
    if (!frame) {
      frame = wp.media({
        title: '写真を選択',
        button: { text: '追加する' },
        multiple: true,
        library: { type: 'image' },
      });
      frame.on('select', function () {
        const selection = frame.state().get('selection').toJSON();
        selection.forEach(function (att) {
          $list.append(renderFromMedia(att));
        });
      });
    }
    frame.open();
  });

  // --- Direct file upload ---
  const $fileInput = $('#drwp-upload-files');
  const $status = $('#drwp-upload-status');

  function setStatus(text, isError) {
    $status.text(text || '').toggleClass('drwp-upload-error', !!isError);
  }

  async function uploadOne(file) {
    if (!window.drwpRest) throw new Error('drwpRest config missing');
    const fd = new FormData();
    fd.append('file', file);
    const resp = await fetch(window.drwpRest.url + '/upload-photo', {
      method: 'POST',
      headers: { 'X-WP-Nonce': window.drwpRest.nonce },
      body: fd,
      credentials: 'same-origin',
    });
    if (!resp.ok) {
      let msg = 'HTTP ' + resp.status;
      try {
        const body = await resp.json();
        if (body && body.message) msg = body.message;
      } catch (e) {}
      throw new Error(msg);
    }
    return resp.json();
  }

  $fileInput.on('change', async function () {
    const files = Array.from(this.files || []);
    if (!files.length) return;

    const i18n = (window.drwpRest && window.drwpRest.i18n) || {};
    let done = 0;
    setStatus((i18n.uploading || 'Uploading…') + ' (0/' + files.length + ')');
    for (const file of files) {
      try {
        const meta = await uploadOne(file);
        $list.append(renderCard(meta.id, meta.thumbnail_url || meta.full_url));
        done++;
        setStatus((i18n.uploading || 'Uploading…') + ' (' + done + '/' + files.length + ')');
      } catch (err) {
        setStatus((i18n.failed || 'Upload failed') + ': ' + (err.message || err), true);
        break;
      }
    }
    if (done === files.length) {
      setTimeout(function () { setStatus(''); }, 2000);
    }
    // Allow re-selecting the same file.
    $fileInput.val('');
  });

  // --- Remove + drag-sort ---
  $list.on('click', '.drwp-photo-remove', function (e) {
    e.preventDefault();
    $(this).closest('.drwp-photo-item').remove();
  });

  if (window.Sortable) {
    window.Sortable.create($list[0], { animation: 150 });
  }
});

// --- Reports list: view + edit modals ----------------------------
(function () {
  var table = document.getElementById('drwp-reports-table');
  if (!table || !window.drwpRest) return;

  var rest = window.drwpRest;
  var viewDialog = document.getElementById('drwp-view-dialog');
  var editDialog = document.getElementById('drwp-edit-dialog');
  if (!viewDialog || !editDialog) return;

  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
  function api(path, opts) {
    opts = opts || {};
    opts.credentials = 'same-origin';
    opts.headers = Object.assign({ 'X-WP-Nonce': rest.nonce }, opts.headers || {});
    return fetch(rest.url + path, opts).then(function (r) {
      return r.json().then(function (j) { if (!r.ok) throw new Error(j.message || 'HTTP ' + r.status); return j; });
    });
  }

  // Close buttons
  [viewDialog, editDialog].forEach(function (dlg) {
    dlg.addEventListener('click', function (e) {
      if (e.target.classList.contains('drwp-modal-close') || e.target.classList.contains('drwp-modal-cancel')) {
        dlg.close();
      }
    });
    dlg.addEventListener('click', function (e) {
      if (e.target === dlg) dlg.close();
    });
  });

  // ---- View modal -----------------------------------------------
  table.addEventListener('click', function (e) {
    var btn = e.target.closest('.drwp-view-btn');
    if (!btn) return;
    var id = btn.dataset.id;
    var body = document.getElementById('drwp-view-body');
    body.innerHTML = '<p>読み込み中…</p>';
    viewDialog.showModal();

    api('/reports/' + id).then(function (d) {
      var time = '';
      if (d.started_at) time += d.started_at.substring(0, 5);
      if (d.started_at && d.ended_at) time += ' - ';
      if (d.ended_at) time += d.ended_at.substring(0, 5);

      var html = '<table class="form-table drwp-view-table">';
      html += '<tr><th>日付</th><td>' + esc(d.report_date) + '</td></tr>';
      html += '<tr><th>現場</th><td>' + esc(d.project_id ? (rest.projects && rest.projects[d.project_id] || '#' + d.project_id) : '（未設定）') + '</td></tr>';
      if (time) html += '<tr><th>時刻</th><td>' + esc(time) + '</td></tr>';
      html += '<tr><th>レビュー</th><td>' + esc(rest.labels && rest.labels[d.review_status] || d.review_status) + '</td></tr>';
      html += '<tr><th>作業内容</th><td class="drwp-view-text">' + esc(d.work_description || '') + '</td></tr>';
      if (d.issues) html += '<tr><th>特記事項</th><td class="drwp-view-text">' + esc(d.issues) + '</td></tr>';
      if (d.next_plan) html += '<tr><th>次回予定</th><td class="drwp-view-text">' + esc(d.next_plan) + '</td></tr>';
      if (d.public_title) html += '<tr><th>公開タイトル</th><td>' + esc(d.public_title) + '</td></tr>';
      html += '</table>';
      body.innerHTML = html;
    }).catch(function (err) {
      body.innerHTML = '<p style="color:#991b1b;">' + esc(err.message) + '</p>';
    });
  });

  // ---- Edit modal -----------------------------------------------
  table.addEventListener('click', function (e) {
    var btn = e.target.closest('.drwp-edit-btn');
    if (!btn) return;
    var id = btn.dataset.id;
    document.getElementById('drwp-edit-id').value = id;
    document.getElementById('drwp-edit-fullpage').href = rest.admin_edit_url.replace('__ID__', id);
    document.getElementById('drwp-edit-status').textContent = '';

    // Clear form
    ['drwp-edit-date','drwp-edit-started','drwp-edit-ended','drwp-edit-title'].forEach(function (k) {
      document.getElementById(k).value = '';
    });
    ['drwp-edit-work','drwp-edit-issues','drwp-edit-next'].forEach(function (k) {
      document.getElementById(k).value = '';
    });
    document.getElementById('drwp-edit-project').value = '';

    editDialog.showModal();

    api('/reports/' + id).then(function (d) {
      document.getElementById('drwp-edit-date').value = d.report_date || '';
      document.getElementById('drwp-edit-project').value = d.project_id || '';
      document.getElementById('drwp-edit-started').value = (d.started_at || '').substring(0, 5);
      document.getElementById('drwp-edit-ended').value = (d.ended_at || '').substring(0, 5);
      document.getElementById('drwp-edit-work').value = d.work_description || '';
      document.getElementById('drwp-edit-issues').value = d.issues || '';
      document.getElementById('drwp-edit-next').value = d.next_plan || '';
      document.getElementById('drwp-edit-title').value = d.public_title || '';
    }).catch(function (err) {
      document.getElementById('drwp-edit-status').textContent = err.message;
    });
  });

  // Save
  document.getElementById('drwp-edit-save').addEventListener('click', function () {
    var id = document.getElementById('drwp-edit-id').value;
    var statusEl = document.getElementById('drwp-edit-status');
    statusEl.textContent = '保存中…';
    this.disabled = true;
    var self = this;

    var payload = {
      report_date:      document.getElementById('drwp-edit-date').value,
      project_id:       Number(document.getElementById('drwp-edit-project').value) || null,
      started_at:       document.getElementById('drwp-edit-started').value || null,
      ended_at:         document.getElementById('drwp-edit-ended').value || null,
      work_description: document.getElementById('drwp-edit-work').value,
      issues:           document.getElementById('drwp-edit-issues').value,
      next_plan:        document.getElementById('drwp-edit-next').value,
      public_title:     document.getElementById('drwp-edit-title').value,
    };

    api('/reports/' + id, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    }).then(function () {
      editDialog.close();
      location.reload();
    }).catch(function (err) {
      statusEl.textContent = err.message;
      self.disabled = false;
    });
  });
})();
