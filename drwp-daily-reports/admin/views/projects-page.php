<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1><?php esc_html_e('現場', 'drwp-daily-reports'); ?></h1>

  <?php if (!empty($_GET['saved'])): ?>
    <div class="notice notice-success"><p><?php esc_html_e('現場を保存しました。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>
  <?php if (!empty($_GET['error']) && $_GET['error'] === 'missing_name'): ?>
    <div class="notice notice-error"><p><?php esc_html_e('現場名は必須です。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>

  <p>
    <button type="button" class="button button-primary" id="drwp-project-add-btn">
      + <?php esc_html_e('新しい現場を追加', 'drwp-daily-reports'); ?>
    </button>
  </p>

  <table class="widefat striped" style="margin-top:8px;">
    <thead>
      <tr>
        <th>ID</th>
        <th><?php esc_html_e('現場名', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('顧客名', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('住所', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('仕事内容', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('状態', 'drwp-daily-reports'); ?></th>
        <th><?php esc_html_e('操作', 'drwp-daily-reports'); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($projects)): ?>
        <tr><td colspan="7"><?php esc_html_e('まだ現場がありません。', 'drwp-daily-reports'); ?></td></tr>
      <?php else: foreach ($projects as $project): ?>
        <tr>
          <td><?php echo (int) $project->id; ?></td>
          <td><?php echo esc_html($project->name); ?></td>
          <td><?php echo esc_html($project->client_name ?: '-'); ?></td>
          <td><?php
            $addr_parts = array_filter([
                (string) ($project->prefecture ?? ''),
                (string) ($project->city ?? ''),
                (string) ($project->street ?? ''),
            ]);
            echo esc_html($addr_parts ? implode('', $addr_parts) : ((string) ($project->address ?? '') ?: '-'));
          ?></td>
          <td><?php
            $jd = (string) ($project->job_description ?? '');
            echo esc_html(mb_strlen($jd) > 30 ? mb_substr($jd, 0, 30) . '…' : ($jd ?: '-'));
          ?></td>
          <td><?php echo esc_html(DRWP_Labels::project_status((string) $project->status)); ?></td>
          <td>
            <button type="button" class="button button-small drwp-project-edit-btn"
                    data-id="<?php echo (int) $project->id; ?>"
                    data-name="<?php echo esc_attr($project->name); ?>"
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
        <h2 id="drwp-pm-title"><?php esc_html_e('新しい現場を追加', 'drwp-daily-reports'); ?></h2>
        <button type="button" class="drwp-project-modal-close">&times;</button>
      </div>

      <div class="drwp-project-modal-body">
        <table class="form-table">
          <tr>
            <th><label for="drwp-pm-name"><?php esc_html_e('現場名', 'drwp-daily-reports'); ?> <em style="color:#b91c1c;">*</em></label></th>
            <td><input type="text" id="drwp-pm-name" name="name" class="regular-text" required /></td>
          </tr>
          <tr>
            <th><label for="drwp-pm-client"><?php esc_html_e('顧客名', 'drwp-daily-reports'); ?></label></th>
            <td><input type="text" id="drwp-pm-client" name="client_name" class="regular-text" /></td>
          </tr>
          <tr>
            <th><label for="drwp-pm-contact"><?php esc_html_e('担当者名', 'drwp-daily-reports'); ?></label></th>
            <td><input type="text" id="drwp-pm-contact" name="contact_person" class="regular-text" /></td>
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
</div>

<style>
.drwp-project-modal{border:0;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.18);padding:0;max-width:640px;width:90vw}
.drwp-project-modal::backdrop{background:rgba(0,0,0,.45)}
.drwp-project-modal-header{display:flex;justify-content:space-between;align-items:center;padding:14px 20px;border-bottom:1px solid #e5e7eb}
.drwp-project-modal-header h2{margin:0;font-size:1.1em}
.drwp-project-modal-close{background:transparent;border:0;font-size:1.6em;cursor:pointer;color:#50575e;line-height:1;padding:0 4px}
.drwp-project-modal-body{padding:16px 20px;max-height:65vh;overflow-y:auto}
.drwp-project-modal-body .form-table th{width:90px;padding:6px 0;vertical-align:top}
.drwp-project-modal-body .form-table td{padding:6px 0}
.drwp-project-modal-footer{display:flex;gap:8px;align-items:center;padding:12px 20px;border-top:1px solid #e5e7eb;background:#f6f7f7;border-radius:0 0 12px 12px}
</style>

<script>
(function(){
  var dlg = document.getElementById('drwp-project-dialog');
  if (!dlg) return;

  var fields = ['name','client','contact','postal','prefecture','city','street','building','phone','job','notes','status','id'];
  var map = {};
  fields.forEach(function(f){ map[f] = document.getElementById('drwp-pm-' + f); });
  var titleEl  = document.getElementById('drwp-pm-title');
  var submitEl = document.getElementById('drwp-pm-submit');

  var addTitle  = <?php echo wp_json_encode(__('新しい現場を追加', 'drwp-daily-reports')); ?>;
  var editTitle = <?php echo wp_json_encode(__('現場を編集', 'drwp-daily-reports')); ?>;
  var addLabel  = <?php echo wp_json_encode(__('追加', 'drwp-daily-reports')); ?>;
  var saveLabel = <?php echo wp_json_encode(__('更新', 'drwp-daily-reports')); ?>;

  function clearForm(){
    fields.forEach(function(f){ if(map[f]) map[f].value = ''; });
    if(map.status) map.status.value = 'active';
    if(map.id) map.id.value = '0';
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
    map.postal.value    = d.postal_code || '';
    map.prefecture.value= d.prefecture || '';
    map.city.value      = d.city || '';
    map.street.value    = d.street || '';
    map.building.value  = d.building || '';
    map.phone.value     = d.phone || '';
    map.job.value       = d.job_description || '';
    map.client.value    = d.client_name || '';
    map.contact.value   = d.contact_person || '';
    map.notes.value     = d.notes || '';
    dlg.showModal();
    map.name.focus();
  });

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
})();
</script>
