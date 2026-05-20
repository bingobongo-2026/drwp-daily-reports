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

// --- Per-entry editing (multi-site report) -----------------------
jQuery(function ($) {
  const $entries = $('#drwp-entries');
  if (!$entries.length) return;

  // Running index for newly-added entries. Starting it past the
  // server-rendered entries' count means we never collide with their
  // entries[idx] names even if the user removes some in between.
  let nextIdx = $entries.children('.drwp-entry').length;

  function entryPhotoCard(idx, attachmentId, thumbUrl, caption) {
    return $(
      '<div class="drwp-photo-item">' +
        '<a href="#" class="drwp-photo-remove" aria-label="削除">×</a>' +
        (thumbUrl ? '<img src="' + thumbUrl + '" alt="" />' : '') +
        '<input type="hidden" name="entries[' + idx + '][attachment_ids][]" value="' + attachmentId + '" />' +
        '<input type="text" name="entries[' + idx + '][attachment_captions][]" class="drwp-photo-caption" placeholder="キャプション" value="' + (caption || '') + '" />' +
      '</div>'
    );
  }

  // --- Add entry: clone the <template>, replace placeholders --------
  $('#drwp-entry-add').on('click', function (e) {
    e.preventDefault();
    const tpl = document.getElementById('drwp-entry-template');
    if (!tpl) return;
    const idx = nextIdx++;
    const html = tpl.innerHTML
      .replace(/__IDX__/g, String(idx))
      .replace(/__N__/g, String(idx + 1));
    $entries.append(html);
  });

  // --- Remove entry -------------------------------------------------
  $entries.on('click', '.drwp-entry-remove', function (e) {
    e.preventDefault();
    $(this).closest('.drwp-entry').remove();
  });

  // --- Per-entry media picker ---------------------------------------
  // Reuse one wp.media frame across entries — re-targeting it each
  // open keeps the picker fast and avoids leaking dozens of detached
  // iframes after a long editing session.
  let entryFrame;
  let entryTargetIdx = null;
  $entries.on('click', '.drwp-entry-pick', function (e) {
    e.preventDefault();
    const $entry = $(this).closest('.drwp-entry');
    entryTargetIdx = parseInt($entry.attr('data-idx'), 10);
    const $target = $entry.find('[data-role=photos]');
    if (!entryFrame) {
      entryFrame = wp.media({
        title: '写真を選択',
        button: { text: '追加する' },
        multiple: true,
        library: { type: 'image' },
      });
      entryFrame.on('select', function () {
        const sel = entryFrame.state().get('selection').toJSON();
        const idx = entryTargetIdx;
        // entryTargetIdx is the data-idx at open time; if the user
        // managed to remove the card between open and select we just
        // skip the insertion silently.
        const $dest = $entries.find('.drwp-entry[data-idx="' + idx + '"] [data-role=photos]');
        if (!$dest.length) return;
        sel.forEach(function (att) {
          const thumb = (att.sizes && (att.sizes.thumbnail || att.sizes.medium)) || att;
          $dest.append(entryPhotoCard(idx, att.id, thumb.url || att.url, ''));
        });
      });
    }
    entryFrame.open();
    // Persist the new target across opens.
    void $target;
  });

  // Photo "×" removal works for entry photos via the shared handler
  // already bound on $list, but only if those photos are descendants
  // of #drwp-photo-list. Bind explicitly on #drwp-entries too.
  $entries.on('click', '.drwp-photo-remove', function (e) {
    e.preventDefault();
    $(this).closest('.drwp-photo-item').remove();
  });
});
