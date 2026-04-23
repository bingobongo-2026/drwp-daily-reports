jQuery(function($){
  let frame;
  $('#drwp-open-media').on('click', function(e){
    e.preventDefault();
    if (frame) { frame.open(); return; }
    frame = wp.media({ title: '写真を選択', button: { text: '使用する' }, multiple: true });
    frame.on('select', function(){
      const items = frame.state().get('selection').toJSON();
      const list = $('#drwp-photo-list');
      list.empty();
      items.forEach(function(item){
        list.append('<div class="drwp-photo-item" style="display:inline-block;margin:0 8px 8px 0;"><img src="' + item.sizes.thumbnail.url + '" /><input type="hidden" name="attachment_ids[]" value="' + item.id + '"></div>');
      });
    });
    frame.open();
  });
});


jQuery(function($){
  $(document).on('change', '#drwp-check-all', function(){
    $('input[name="report_ids[]"]').prop('checked', $(this).is(':checked'));
  });
});
