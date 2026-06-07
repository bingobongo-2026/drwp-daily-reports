<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1><?php esc_html_e('顧客', 'drwp-daily-reports'); ?></h1>

  <?php if (!empty($_GET['saved'])): ?>
    <div class="notice notice-success"><p><?php esc_html_e('顧客を保存しました。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>
  <?php if (!empty($_GET['error']) && $_GET['error'] === 'missing_name'): ?>
    <div class="notice notice-error"><p><?php esc_html_e('顧客名は必須です。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>

  <p>
    <button type="button" class="button button-primary" id="drwp-customer-add-btn">
      + <?php esc_html_e('新しい顧客を追加', 'drwp-daily-reports'); ?>
    </button>
  </p>

  <form method="get" class="drwp-customer-search">
    <input type="hidden" name="page" value="drwp_customers" />
    <input type="search" name="s"
           value="<?php echo esc_attr($filters['search']); ?>"
           class="regular-text"
           placeholder="<?php esc_attr_e('顧客名 / 住所 / 電話 / メール / 備考', 'drwp-daily-reports'); ?>" />
    <?php if (!empty($groups)): ?>
      <select name="group_id">
        <option value="0"><?php esc_html_e('グループすべて', 'drwp-daily-reports'); ?></option>
        <?php foreach ($groups as $g): ?>
          <option value="<?php echo (int) $g->id; ?>" <?php selected((int) ($filters['group_id'] ?? 0), (int) $g->id); ?>>
            <?php echo esc_html($g->name); ?>
          </option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <button class="button button-primary"><?php esc_html_e('検索', 'drwp-daily-reports'); ?></button>
    <?php if ($filters['search'] !== '' || !empty($filters['group_id'])): ?>
      <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=drwp_customers')); ?>">
        <?php esc_html_e('クリア', 'drwp-daily-reports'); ?>
      </a>
      <span class="drwp-customer-search-hit">
        <?php
          printf(
              esc_html(_n('%d 件ヒット', '%d 件ヒット', count($customers), 'drwp-daily-reports')),
              count($customers)
          );
        ?>
      </span>
    <?php endif; ?>
  </form>

  <table class="widefat striped" style="margin-top:8px;">
    <thead>
      <tr>
        <th>ID</th>
        <th><?php esc_html_e('顧客名', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('グループ', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('住所', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('電話番号', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('メール', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('状態', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('操作', 'drwp-daily-reports'); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($customers)): ?>
        <tr><td colspan="8">
          <?php if ($filters['search'] !== '' || !empty($filters['group_id'])):
            esc_html_e('該当する顧客が見つかりません。', 'drwp-daily-reports');
          else:
            esc_html_e('まだ顧客がありません。', 'drwp-daily-reports');
          endif; ?>
        </td></tr>
      <?php else: foreach ($customers as $c):
        $cid = (int) $c->id;
        $cgs = $customer_groups[$cid] ?? [];
        $cgs_ids = $customer_group_ids[$cid] ?? [];
      ?>
        <tr>
          <td><?php echo $cid; ?></td>
          <td><?php echo esc_html($c->name); ?></td>
          <td>
            <?php if (empty($cgs)): ?>
              -
            <?php else: foreach ($cgs as $g):
              $dot_color = (string) ($g->color ?? '');
            ?>
              <span class="drwp-cg-chip"><?php if ($dot_color !== ''): ?><span class="drwp-cg-chip-dot" style="background:<?php echo esc_attr($dot_color); ?>;"></span><?php endif; ?><?php echo esc_html($g->name); ?></span>
            <?php endforeach; endif; ?>
          </td>
          <td><?php
            $parts = array_filter([
                (string) ($c->prefecture ?? ''),
                (string) ($c->city ?? ''),
                (string) ($c->street ?? ''),
            ]);
            echo esc_html($parts ? implode('', $parts) : ((string) ($c->address ?? '') ?: '-'));
          ?></td>
          <td><?php echo esc_html($c->phone ?: '-'); ?></td>
          <td><?php echo esc_html($c->email ?: '-'); ?></td>
          <td><?php echo esc_html(DRWP_Labels::project_status((string) $c->status)); ?></td>
          <td>
            <button type="button" class="button button-small drwp-customer-edit-btn"
                    data-id="<?php echo $cid; ?>"
                    data-name="<?php echo esc_attr($c->name); ?>"
                    data-status="<?php echo esc_attr($c->status); ?>"
                    data-postal_code="<?php echo esc_attr($c->postal_code ?? ''); ?>"
                    data-prefecture="<?php echo esc_attr($c->prefecture ?? ''); ?>"
                    data-city="<?php echo esc_attr($c->city ?? ''); ?>"
                    data-street="<?php echo esc_attr($c->street ?? ''); ?>"
                    data-building="<?php echo esc_attr($c->building ?? ''); ?>"
                    data-phone="<?php echo esc_attr($c->phone ?? ''); ?>"
                    data-email="<?php echo esc_attr($c->email ?? ''); ?>"
                    data-notes="<?php echo esc_attr($c->notes ?? ''); ?>"
                    data-group_ids="<?php echo esc_attr(wp_json_encode($cgs_ids)); ?>">
              <?php esc_html_e('編集', 'drwp-daily-reports'); ?>
            </button>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <dialog id="drwp-customer-dialog" class="drwp-customer-modal">
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <?php wp_nonce_field('drwp_save_customer'); ?>
      <input type="hidden" name="action" value="drwp_save_customer" />
      <input type="hidden" name="id" id="drwp-cm-id" value="0" />

      <div class="drwp-customer-modal-header">
        <h2 id="drwp-cm-title"><?php esc_html_e('新しい顧客を追加', 'drwp-daily-reports'); ?></h2>
        <button type="button" class="drwp-customer-modal-close">&times;</button>
      </div>

      <div class="drwp-customer-modal-body">
        <table class="form-table">
          <tr>
            <th><label for="drwp-cm-name"><?php esc_html_e('顧客名', 'drwp-daily-reports'); ?> <em style="color:#b91c1c;">*</em></label></th>
            <td><input type="text" id="drwp-cm-name" name="name" class="regular-text" required /></td>
          </tr>
          <tr>
            <th><label for="drwp-cm-postal"><?php esc_html_e('郵便番号', 'drwp-daily-reports'); ?></label></th>
            <td>
              <div style="display:flex;gap:8px;align-items:center;">
                <input type="text" id="drwp-cm-postal" name="postal_code" class="regular-text" style="max-width:140px;" placeholder="123-4567" />
                <button type="button" class="button button-small" id="drwp-cm-zip-lookup"><?php esc_html_e('住所検索', 'drwp-daily-reports'); ?></button>
                <span id="drwp-cm-zip-status" style="font-size:.85em;"></span>
              </div>
            </td>
          </tr>
          <tr>
            <th><label for="drwp-cm-prefecture"><?php esc_html_e('都道府県', 'drwp-daily-reports'); ?></label></th>
            <td><input type="text" id="drwp-cm-prefecture" name="prefecture" class="regular-text" /></td>
          </tr>
          <tr>
            <th><label for="drwp-cm-city"><?php esc_html_e('市区町村', 'drwp-daily-reports'); ?></label></th>
            <td><input type="text" id="drwp-cm-city" name="city" class="regular-text" /></td>
          </tr>
          <tr>
            <th><label for="drwp-cm-street"><?php esc_html_e('番地', 'drwp-daily-reports'); ?></label></th>
            <td><input type="text" id="drwp-cm-street" name="street" class="regular-text" /></td>
          </tr>
          <tr>
            <th><label for="drwp-cm-building"><?php esc_html_e('建物名', 'drwp-daily-reports'); ?></label></th>
            <td><input type="text" id="drwp-cm-building" name="building" class="regular-text" /></td>
          </tr>
          <tr>
            <th><label for="drwp-cm-phone"><?php esc_html_e('電話番号', 'drwp-daily-reports'); ?></label></th>
            <td><input type="tel" id="drwp-cm-phone" name="phone" class="regular-text" /></td>
          </tr>
          <tr>
            <th><label for="drwp-cm-email"><?php esc_html_e('メールアドレス', 'drwp-daily-reports'); ?></label></th>
            <td><input type="email" id="drwp-cm-email" name="email" class="regular-text" /></td>
          </tr>
          <tr>
            <th><label for="drwp-cm-groups"><?php esc_html_e('グループ', 'drwp-daily-reports'); ?></label></th>
            <td>
              <?php if (empty($groups)): ?>
                <p class="description">
                  <?php
                    printf(
                        // translators: %s is a link to the グループ admin page.
                        esc_html__('まだグループがありません。%s から登録してください。', 'drwp-daily-reports'),
                        '<a href="' . esc_url(admin_url('admin.php?page=drwp_customer_groups')) . '">'
                          . esc_html__('グループページ', 'drwp-daily-reports')
                          . '</a>'
                    );
                  ?>
                </p>
              <?php else: ?>
                <select id="drwp-cm-groups" name="group_ids[]" multiple size="<?php echo (int) min(6, max(3, count($groups))); ?>" class="regular-text" style="min-height:auto;height:auto;">
                  <?php foreach ($groups as $g): ?>
                    <option value="<?php echo (int) $g->id; ?>"><?php echo esc_html($g->name); ?></option>
                  <?php endforeach; ?>
                </select>
                <p class="description">
                  <?php esc_html_e('Ctrl / ⌘ クリックで複数選択できます。', 'drwp-daily-reports'); ?>
                </p>
              <?php endif; ?>
            </td>
          </tr>
          <tr>
            <th><label for="drwp-cm-notes"><?php esc_html_e('備考', 'drwp-daily-reports'); ?></label></th>
            <td><textarea id="drwp-cm-notes" name="notes" rows="3" class="large-text"></textarea></td>
          </tr>
          <tr>
            <th><label for="drwp-cm-status"><?php esc_html_e('状態', 'drwp-daily-reports'); ?></label></th>
            <td>
              <select name="status" id="drwp-cm-status">
                <option value="active"><?php echo esc_html(DRWP_Labels::project_status('active')); ?></option>
                <option value="inactive"><?php echo esc_html(DRWP_Labels::project_status('inactive')); ?></option>
              </select>
            </td>
          </tr>
        </table>
      </div>

      <div class="drwp-customer-modal-footer">
        <button type="submit" class="button button-primary" id="drwp-cm-submit">
          <?php esc_html_e('保存', 'drwp-daily-reports'); ?>
        </button>
        <button type="button" class="button drwp-customer-modal-close">
          <?php esc_html_e('キャンセル', 'drwp-daily-reports'); ?>
        </button>
      </div>
    </form>
  </dialog>
</div>

<style>
.drwp-customer-modal{border:0;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.18);padding:0;max-width:640px;width:90vw}
.drwp-customer-modal::backdrop{background:rgba(0,0,0,.45)}
.drwp-customer-modal-header{display:flex;justify-content:space-between;align-items:center;padding:14px 20px;border-bottom:1px solid #e5e7eb}
.drwp-customer-modal-header h2{margin:0;font-size:1.1em}
.drwp-customer-modal-close{background:transparent;border:0;font-size:1.6em;cursor:pointer;color:#50575e;line-height:1;padding:0 4px}
.drwp-customer-modal-body{padding:16px 20px;max-height:65vh;overflow-y:auto}
.drwp-customer-modal-body .form-table th{width:120px;padding:6px 0;vertical-align:top}
.drwp-customer-modal-body .form-table td{padding:6px 0}
.drwp-customer-modal-footer{display:flex;gap:8px;align-items:center;padding:12px 20px;border-top:1px solid #e5e7eb;background:#f6f7f7;border-radius:0 0 12px 12px}
.drwp-customer-search{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:8px 0}
.drwp-customer-search-hit{color:#475569;font-size:.92em}
/* Group chips shown in the 顧客 listing table — color dot + name
   in a soft pill. The color comes from the group's color column,
   left blank renders the pill without a dot. */
.drwp-cg-chip{display:inline-flex;align-items:center;gap:4px;padding:1px 8px 1px 6px;margin:1px 4px 1px 0;background:#f1f5f9;border-radius:10px;font-size:11px;line-height:1.7;color:#1d2327;white-space:nowrap}
.drwp-cg-chip-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#94a3b8;flex-shrink:0}
</style>

<script>
(function(){
  var dlg = document.getElementById('drwp-customer-dialog');
  if (!dlg) return;

  var fields = ['name','postal','prefecture','city','street','building','phone','email','notes','status','id'];
  var map = {};
  fields.forEach(function(f){ map[f] = document.getElementById('drwp-cm-' + f); });
  // Group multi-select — present only when at least one group is
  // registered; the template renders a help message instead when
  // the operator hasn't created any groups yet.
  var groupsEl = document.getElementById('drwp-cm-groups');
  var titleEl  = document.getElementById('drwp-cm-title');
  var submitEl = document.getElementById('drwp-cm-submit');

  var addTitle  = <?php echo wp_json_encode(__('新しい顧客を追加', 'drwp-daily-reports')); ?>;
  var editTitle = <?php echo wp_json_encode(__('顧客を編集', 'drwp-daily-reports')); ?>;
  var addLabel  = <?php echo wp_json_encode(__('追加', 'drwp-daily-reports')); ?>;
  var saveLabel = <?php echo wp_json_encode(__('更新', 'drwp-daily-reports')); ?>;

  function setGroupSelection(ids) {
    if (!groupsEl) return;
    var set = {};
    (Array.isArray(ids) ? ids : []).forEach(function (id) { set[String(id)] = true; });
    Array.prototype.forEach.call(groupsEl.options, function (opt) {
      opt.selected = !!set[opt.value];
    });
  }

  function clearForm(){
    fields.forEach(function(f){ if(map[f]) map[f].value = ''; });
    if(map.status) map.status.value = 'active';
    if(map.id) map.id.value = '0';
    setGroupSelection([]);
  }

  document.getElementById('drwp-customer-add-btn').addEventListener('click', function(){
    clearForm();
    titleEl.textContent = addTitle;
    submitEl.textContent = addLabel;
    dlg.showModal();
    map.name.focus();
  });

  document.addEventListener('click', function(e){
    var btn = e.target.closest('.drwp-customer-edit-btn');
    if (!btn) return;
    var d = btn.dataset;
    titleEl.textContent = editTitle + ' (#' + d.id + ')';
    submitEl.textContent = saveLabel;
    map.id.value        = d.id;
    map.name.value      = d.name;
    map.status.value    = d.status;
    map.postal.value    = d.postal_code || '';
    map.prefecture.value= d.prefecture || '';
    map.city.value      = d.city || '';
    map.street.value    = d.street || '';
    map.building.value  = d.building || '';
    map.phone.value     = d.phone || '';
    map.email.value     = d.email || '';
    map.notes.value     = d.notes || '';
    var gids = [];
    if (d.group_ids) {
      try { gids = JSON.parse(d.group_ids) || []; } catch (e) { gids = []; }
    }
    setGroupSelection(gids);
    dlg.showModal();
    map.name.focus();
  });

  document.getElementById('drwp-cm-zip-lookup').addEventListener('click', function(){
    var zip = (map.postal.value || '').replace(/[^0-9]/g, '');
    var st = document.getElementById('drwp-cm-zip-status');
    if (zip.length !== 7) { st.textContent = '7桁の郵便番号を入力してください'; st.style.color = '#991b1b'; return; }
    st.textContent = '検索中…'; st.style.color = '';
    fetch('https://zipcloud.ibsnet.co.jp/api/search?zipcode=' + zip)
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.results || !j.results.length) { st.textContent = '該当する住所が見つかりません'; st.style.color = '#991b1b'; return; }
        var a = j.results[0];
        map.prefecture.value = a.address1 || '';
        map.city.value = (a.address2 || '') + (a.address3 || '');
        st.textContent = ''; st.style.color = '';
        map.street.focus();
      })
      .catch(function(){ st.textContent = '通信エラー'; st.style.color = '#991b1b'; });
  });

  dlg.addEventListener('click', function(e){
    if (e.target.classList.contains('drwp-customer-modal-close')) dlg.close();
    if (e.target === dlg) dlg.close();
  });
})();
</script>
