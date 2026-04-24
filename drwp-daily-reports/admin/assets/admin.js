jQuery(function ($) {
  const $list = $('#drwp-photo-list');
  if (!$list.length) return;

  let frame;

  function render(attachment) {
    const id = attachment.id;
    const thumb =
      (attachment.sizes && (attachment.sizes.thumbnail || attachment.sizes.medium)) ||
      attachment;
    const url = thumb.url || attachment.url;
    return $(
      '<div class="drwp-photo-item">' +
        '<a href="#" class="drwp-photo-remove" aria-label="削除">×</a>' +
        '<img src="' + url + '" alt="" />' +
        '<input type="hidden" name="attachment_ids[]" value="' + id + '" />' +
        '<input type="text" name="attachment_captions[]" class="drwp-photo-caption" placeholder="キャプション" value="" />' +
      '</div>'
    );
  }

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
          $list.append(render(att));
        });
      });
    }
    frame.open();
  });

  $list.on('click', '.drwp-photo-remove', function (e) {
    e.preventDefault();
    $(this).closest('.drwp-photo-item').remove();
  });

  if (window.Sortable) {
    window.Sortable.create($list[0], { animation: 150 });
  }
});
