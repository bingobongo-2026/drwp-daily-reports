<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1><?php esc_html_e('案件', 'drwp-daily-reports'); ?></h1>

  <?php if (!empty($_GET['saved'])): ?>
    <div class="notice notice-success"><p><?php esc_html_e('案件を保存しました。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>
  <?php if (!empty($_GET['error']) && $_GET['error'] === 'missing_name'): ?>
    <div class="notice notice-error"><p><?php esc_html_e('案件名は必須です。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>

  <p>
    <button type="button" class="button button-primary" id="drwp-project-add-btn">
      + <?php esc_html_e('新しい案件を追加', 'drwp-daily-reports'); ?>
    </button>
  </p>

  <form method="get" class="drwp-project-search">
    <input type="hidden" name="page" value="drwp_projects" />
    <input type="search" name="s"
           value="<?php echo esc_attr($filters['search']); ?>"
           class="regular-text"
           placeholder="<?php esc_attr_e('案件名 / 顧客名 / 住所 / 仕事内容 / 備考', 'drwp-daily-reports'); ?>" />
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
      <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=drwp_projects')); ?>">
        <?php esc_html_e('クリア', 'drwp-daily-reports'); ?>
      </a>
      <span class="drwp-project-search-hit">
        <?php
          printf(
              esc_html(_n('%d 件ヒット', '%d 件ヒット', count($projects), 'drwp-daily-reports')),
              count($projects)
          );
        ?>
      </span>
    <?php endif; ?>
  </form>

  <table class="widefat striped" style="margin-top:8px;">
    <thead>
      <tr>
        <th>ID</th>
        <th><?php esc_html_e('案件名', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('顧客', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('住所', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('仕事内容', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('状態', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('操作', 'drwp-daily-reports'); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($projects)): ?>
        <tr><td colspan="7">
          <?php if ($filters['search'] !== '' || !empty($filters['group_id'])):
            esc_html_e('該当する案件が見つかりません。', 'drwp-daily-reports');
          else:
            esc_html_e('まだ案件がありません。', 'drwp-daily-reports');
          endif; ?>
        </td></tr>
      <?php else: foreach ($projects as $project):
        $customer = !empty($project->customer_id) ? DRWP_Customer::find((int) $project->customer_id) : null;
        $proj_addr_parts = array_filter([
            (string) ($project->prefecture ?? ''),
            (string) ($project->city ?? ''),
            (string) ($project->street ?? ''),
        ]);
        if ($proj_addr_parts) {
            $display_addr = implode('', $proj_addr_parts);
        } elseif ($customer) {
            $cust_addr_parts = array_filter([
                (string) ($customer->prefecture ?? ''),
                (string) ($customer->city ?? ''),
                (string) ($customer->street ?? ''),
            ]);
            $display_addr = $cust_addr_parts ? implode('', $cust_addr_parts) : ((string) ($customer->address ?? '') ?: '-');
        } else {
            $display_addr = ((string) ($project->address ?? '') ?: '-');
        }
      ?>
        <tr>
          <td><?php echo (int) $project->id; ?></td>
          <td><?php echo esc_html($project->name); ?></td>
          <td><?php echo esc_html($customer ? $customer->name : ($project->client_name ?: '-')); ?></td>
          <td><?php echo esc_html($display_addr); ?></td>
          <td><?php
            $jd = (string) ($project->job_description ?? '');
            echo esc_html(mb_strlen($jd) > 30 ? mb_substr($jd, 0, 30) . '…' : ($jd ?: '-'));
          ?></td>
          <td><?php echo esc_html(DRWP_Labels::project_status((string) $project->status)); ?></td>
          <td>
            <button type="button" class="button button-small drwp-project-edit-btn"
                    data-id="<?php echo (int) $project->id; ?>"
                    data-name="<?php echo esc_attr($project->name); ?>"
                    data-customer_id="<?php echo (int) ($project->customer_id ?? 0); ?>"
                    data-status="<?php echo esc_attr($project->status); ?>"
                    data-postal_code="<?php echo esc_attr($project->postal_code ?? ''); ?>"
                    data-prefecture="<?php echo esc_attr($project->prefecture ?? ''); ?>"
                    data-city="<?php echo esc_attr($project->city ?? ''); ?>"
                    data-street="<?php echo esc_attr($project->street ?? ''); ?>"
                    data-building="<?php echo esc_attr($project->building ?? ''); ?>"
                    data-phone="<?php echo esc_attr($project->phone ?? ''); ?>"
                    data-job_description="<?php echo esc_attr($project->job_description ?? ''); ?>"
                    data-client_name="<?php echo esc_attr($project->client_name ?? ''); ?>"
                    data-contact_person="<?php echo esc_attr($project->contact_person ?? ''); ?>"
                    data-notes="<?php echo esc_attr($project->notes ?? ''); ?>">
              <?php esc_html_e('編集', 'drwp-daily-reports'); ?>
            </button>
            <?php if (DRWP_AI::is_enabled()): ?>
            <button type="button" class="button button-small drwp-ai-briefing-btn"
                    data-id="<?php echo (int) $project->id; ?>"
                    data-name="<?php echo esc_attr($project->name); ?>">
              <?php esc_html_e('AI ブリーフィング', 'drwp-daily-reports'); ?>
            </button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <!-- Modal for add / edit -->
  <dialog id="drwp-project-dialog" class="drwp-project-modal">
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <?php wp_nonce_field('drwp_save_project'); ?>
      <input type="hidden" name="action" value="drwp_save_project" />
      <input type="hidden" name="id" id="drwp-pm-id" value="0" />

      <div class="drwp-project-modal-header">
        <h2 id="drwp-pm-title"><?php esc_html_e('新しい案件を追加', 'drwp-daily-reports'); ?></h2>
        <button type="button" class="drwp-project-modal-close">&times;</button>
      </div>

      <div class="drwp-project-modal-body">
        <table class="form-table">
          <tr>
            <th><label for="drwp-pm-name"><?php esc_html_e('案件名', 'drwp-daily-reports'); ?> <em style="color:#b91c1c;">*</em></label></th>
            <td><input type="text" id="drwp-pm-name" name="name" class="regular-text" required /></td>
          </tr>
          <tr>
            <th><label for="drwp-pm-customer"><?php esc_html_e('顧客', 'drwp-daily-reports'); ?></label></th>
            <td>
              <select id="drwp-pm-customer" name="customer_id">
                <option value="0"><?php esc_html_e('（未設定）', 'drwp-daily-reports'); ?></option>
                <?php foreach (($customers ?? []) as $c): ?>
                  <option value="<?php echo (int) $c->id; ?>"
                          data-postal_code="<?php echo esc_attr($c->postal_code ?? ''); ?>"
                          data-prefecture="<?php echo esc_attr($c->prefecture ?? ''); ?>"
                          data-city="<?php echo esc_attr($c->city ?? ''); ?>"
                          data-street="<?php echo esc_attr($c->street ?? ''); ?>"
                          data-building="<?php echo esc_attr($c->building ?? ''); ?>"
                          data-phone="<?php echo esc_attr($c->phone ?? ''); ?>">
                    <?php echo esc_html($c->name); ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <p class="description"><?php esc_html_e('該当する顧客を選択すると、住所・電話番号は顧客側の値が使われます。', 'drwp-daily-reports'); ?></p>
            </td>
          </tr>
          <tr>
            <th><label for="drwp-pm-contact"><?php esc_html_e('担当者名', 'drwp-daily-reports'); ?></label></th>
            <td><input type="text" id="drwp-pm-contact" name="contact_person" class="regular-text" /></td>
          </tr>
          <tr>
            <td colspan="2">
              <p class="description" style="margin:8px 0 4px;font-weight:600;">
                <?php esc_html_e('案件専用の住所・電話番号', 'drwp-daily-reports'); ?>
              </p>
              <p class="description" style="margin:0 0 8px;">
                <?php esc_html_e('顧客の住所・電話番号と異なる場合のみ入力してください。空欄なら顧客側の値が使われます。', 'drwp-daily-reports'); ?>
              </p>
            </td>
          </tr>
          <tr>
            <th><label for="drwp-pm-postal"><?php esc_html_e('郵便番号', 'drwp-daily-reports'); ?></label></th>
            <td>
              <div style="display:flex;gap:8px;align-items:center;">
                <input type="text" id="drwp-pm-postal" name="postal_code" class="regular-text" style="max-width:140px;" placeholder="123-4567" />
                <button type="button" class="button button-small" id="drwp-pm-zip-lookup"><?php esc_html_e('住所検索', 'drwp-daily-reports'); ?></button>
                <span id="drwp-pm-zip-status" style="font-size:.85em;"></span>
              </div>
            </td>
          </tr>
          <tr>
            <th><label for="drwp-pm-prefecture"><?php esc_html_e('都道府県', 'drwp-daily-reports'); ?></label></th>
            <td><input type="text" id="drwp-pm-prefecture" name="prefecture" class="regular-text" /></td>
          </tr>
          <tr>
            <th><label for="drwp-pm-city"><?php esc_html_e('市区町村', 'drwp-daily-reports'); ?></label></th>
            <td><input type="text" id="drwp-pm-city" name="city" class="regular-text" /></td>
          </tr>
          <tr>
            <th><label for="drwp-pm-street"><?php esc_html_e('番地', 'drwp-daily-reports'); ?></label></th>
            <td><input type="text" id="drwp-pm-street" name="street" class="regular-text" /></td>
          </tr>
          <tr>
            <th><label for="drwp-pm-building"><?php esc_html_e('建物名', 'drwp-daily-reports'); ?></label></th>
            <td><input type="text" id="drwp-pm-building" name="building" class="regular-text" /></td>
          </tr>
          <tr>
            <th><label for="drwp-pm-phone"><?php esc_html_e('電話番号', 'drwp-daily-reports'); ?></label></th>
            <td><input type="tel" id="drwp-pm-phone" name="phone" class="regular-text" /></td>
          </tr>
          <tr>
            <th><label for="drwp-pm-job"><?php esc_html_e('仕事内容', 'drwp-daily-reports'); ?></label></th>
            <td><textarea id="drwp-pm-job" name="job_description" rows="3" class="large-text"></textarea></td>
          </tr>
          <tr>
            <th><label for="drwp-pm-notes"><?php esc_html_e('備考', 'drwp-daily-reports'); ?></label></th>
            <td>
              <textarea id="drwp-pm-notes" name="notes" rows="2" class="large-text"></textarea>
              <p class="description"><?php esc_html_e('駐車場情報、入場方法など', 'drwp-daily-reports'); ?></p>
            </td>
          </tr>
          <tr>
            <th><label for="drwp-pm-status"><?php esc_html_e('状態', 'drwp-daily-reports'); ?></label></th>
            <td>
              <select name="status" id="drwp-pm-status">
                <option value="active"><?php echo esc_html(DRWP_Labels::project_status('active')); ?></option>
                <option value="inactive"><?php echo esc_html(DRWP_Labels::project_status('inactive')); ?></option>
              </select>
            </td>
          </tr>
        </table>
      </div>

      <div class="drwp-project-modal-footer">
        <button type="submit" class="button button-primary" id="drwp-pm-submit">
          <?php esc_html_e('保存', 'drwp-daily-reports'); ?>
        </button>
        <button type="button" class="button drwp-project-modal-close">
          <?php esc_html_e('キャンセル', 'drwp-daily-reports'); ?>
        </button>
      </div>
    </form>
  </dialog>

  <?php if (DRWP_AI::is_enabled()): ?>
  <dialog id="drwp-ai-briefing-dialog" class="drwp-project-modal">
    <div class="drwp-project-modal-header">
      <h2 id="drwp-ai-briefing-title"><?php esc_html_e('AI ブリーフィング', 'drwp-daily-reports'); ?></h2>
      <button type="button" class="drwp-project-modal-close">&times;</button>
    </div>
    <div class="drwp-project-modal-body">
      <p class="description" id="drwp-ai-briefing-meta"></p>
      <div id="drwp-ai-briefing-status" style="margin:8px 0;"></div>
      <div id="drwp-ai-briefing-output" style="white-space:pre-wrap;font-family:inherit;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:14px;min-height:200px;line-height:1.6;"></div>
    </div>
    <div class="drwp-project-modal-footer">
      <button type="button" class="button drwp-project-modal-close"><?php esc_html_e('閉じる', 'drwp-daily-reports'); ?></button>
    </div>
  </dialog>
  <?php endif; ?>
</div>

<style>
.drwp-project-modal{border:0;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.18);padding:0;max-width:640px;width:90vw}
.drwp-project-modal::backdrop{background:rgba(0,0,0,.45)}
.drwp-project-modal-header{display:flex;justify-content:space-between;align-items:center;padding:14px 20px;border-bottom:1px solid #e5e7eb}
.drwp-project-modal-header h2{margin:0;font-size:1.1em}
.drwp-project-modal-close{background:transparent;border:0;font-size:1.6em;cursor:pointer;color:#50575e;line-height:1;padding:0 4px}
.drwp-project-modal-body{padding:16px 20px;max-height:65vh;overflow-y:auto}
.drwp-project-modal-body .form-table th{width:120px;padding:6px 0;vertical-align:top}
.drwp-project-modal-body .form-table td{padding:6px 0}
.drwp-project-modal-footer{display:flex;gap:8px;align-items:center;padding:12px 20px;border-top:1px solid #e5e7eb;background:#f6f7f7;border-radius:0 0 12px 12px}
.drwp-project-search{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:8px 0}
.drwp-project-search-hit{color:#475569;font-size:.92em}
</style>

<script>
(function(){
  var dlg = document.getElementById('drwp-project-dialog');
  if (!dlg) return;

  var fields = ['name','customer','contact','postal','prefecture','city','street','building','phone','job','notes','status','id'];
  var map = {};
  fields.forEach(function(f){ map[f] = document.getElementById('drwp-pm-' + f); });
  var titleEl  = document.getElementById('drwp-pm-title');
  var submitEl = document.getElementById('drwp-pm-submit');

  var addTitle  = <?php echo wp_json_encode(__('新しい案件を追加', 'drwp-daily-reports')); ?>;
  var editTitle = <?php echo wp_json_encode(__('案件を編集', 'drwp-daily-reports')); ?>;
  var addLabel  = <?php echo wp_json_encode(__('追加', 'drwp-daily-reports')); ?>;
  var saveLabel = <?php echo wp_json_encode(__('更新', 'drwp-daily-reports')); ?>;

  function clearForm(){
    fields.forEach(function(f){ if(map[f]) map[f].value = ''; });
    if(map.status) map.status.value = 'active';
    if(map.id) map.id.value = '0';
    if(map.customer) map.customer.value = '0';
  }

  document.getElementById('drwp-project-add-btn').addEventListener('click', function(){
    clearForm();
    titleEl.textContent = addTitle;
    submitEl.textContent = addLabel;
    dlg.showModal();
    map.name.focus();
  });

  document.addEventListener('click', function(e){
    var btn = e.target.closest('.drwp-project-edit-btn');
    if (!btn) return;
    var d = btn.dataset;
    titleEl.textContent = editTitle + ' (#' + d.id + ')';
    submitEl.textContent = saveLabel;
    map.id.value        = d.id;
    map.name.value      = d.name;
    map.status.value    = d.status;
    if (map.customer) map.customer.value = d.customer_id || '0';
    map.postal.value    = d.postal_code || '';
    map.prefecture.value= d.prefecture || '';
    map.city.value      = d.city || '';
    map.street.value    = d.street || '';
    map.building.value  = d.building || '';
    map.phone.value     = d.phone || '';
    map.job.value       = d.job_description || '';
    map.contact.value   = d.contact_person || '';
    map.notes.value     = d.notes || '';
    dlg.showModal();
    map.name.focus();
  });

  /* ---- 顧客選択時のプレースホルダー更新 ---- */
  if (map.customer) {
    map.customer.addEventListener('change', function(){
      var opt = map.customer.options[map.customer.selectedIndex];
      ['postal','prefecture','city','street','building','phone'].forEach(function(k){
        var el = map[k];
        if (!el) return;
        var key = (k === 'postal') ? 'postal_code' : k;
        var hint = opt ? (opt.dataset[key] || '') : '';
        if (hint) {
          el.placeholder = '顧客: ' + hint;
        } else {
          el.placeholder = '';
        }
      });
    });
  }

  /* ---- 郵便番号から住所検索 ---- */
  document.getElementById('drwp-pm-zip-lookup').addEventListener('click', function(){
    var zip = (map.postal.value || '').replace(/[^0-9]/g, '');
    var st = document.getElementById('drwp-pm-zip-status');
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
    if (e.target.classList.contains('drwp-project-modal-close')) dlg.close();
    if (e.target === dlg) dlg.close();
  });

  <?php if (DRWP_AI::is_enabled()): ?>
  /* ---- AI ブリーフィング ---- */
  var aiDlg = document.getElementById('drwp-ai-briefing-dialog');
  var aiCfg = <?php echo wp_json_encode([
      'url'   => esc_url_raw(rest_url('drwp/v1/ai/briefing')),
      'nonce' => wp_create_nonce('wp_rest'),
  ]); ?>;
  aiDlg.addEventListener('click', function(e){
    if (e.target.classList.contains('drwp-project-modal-close')) aiDlg.close();
    if (e.target === aiDlg) aiDlg.close();
  });
  document.addEventListener('click', function(e){
    var btn = e.target.closest('.drwp-ai-briefing-btn');
    if (!btn) return;
    document.getElementById('drwp-ai-briefing-meta').textContent = '案件: ' + btn.dataset.name;
    var statusEl = document.getElementById('drwp-ai-briefing-status');
    var outEl = document.getElementById('drwp-ai-briefing-output');
    statusEl.textContent = '生成中… ローカルAIの応答には数秒〜数分かかる場合があります';
    statusEl.style.color = '#64748b';
    outEl.textContent = '';
    aiDlg.showModal();
    fetch(aiCfg.url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type':'application/json','X-WP-Nonce':aiCfg.nonce},
      body: JSON.stringify({project_id: Number(btn.dataset.id)}),
    }).then(function(r){
      return r.json().then(function(j){if(!r.ok)throw new Error(j.message||'HTTP '+r.status);return j;});
    }).then(function(d){
      statusEl.textContent = '';
      outEl.textContent = d.response || '（応答なし）';
    }).catch(function(err){
      statusEl.textContent = 'エラー: ' + err.message;
      statusEl.style.color = '#991b1b';
    });
  });
  <?php endif; ?>
})();
</script>
