<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1><?php esc_html_e('AI 設定', 'drwp-daily-reports'); ?></h1>

  <?php if (isset($_GET['saved'])): ?>
    <div class="notice notice-success"><p><?php esc_html_e('保存しました。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>

  <p><?php esc_html_e('日報を AI に渡して、文章生成・要約・チェックを行います。「運営契約 API」モードでは月次回数制限があり、API キーは不要です。「自分の API キー」モードでは OpenAI / Anthropic のキーを設定して直接利用します。', 'drwp-daily-reports'); ?></p>

  <div class="notice notice-info inline" style="margin:8px 0;">
    <p style="margin:.5em 0;"><strong><?php esc_html_e('有効にすると、次の場所に AI ボタンが表示されます:', 'drwp-daily-reports'); ?></strong></p>
    <ul style="margin:0 0 .5em 1.4em;list-style:disc;">
      <li><?php esc_html_e('案件ページ — 各案件の行に「AI ブリーフィング」「AI サマリ」', 'drwp-daily-reports'); ?></li>
      <li><?php esc_html_e('日報編集ページ — 「公開用コンテンツ」に「AI で下書きを生成」', 'drwp-daily-reports'); ?></li>
      <li><?php esc_html_e('日報一覧ページ — 上部に「AI 対応アラート」と「AI 振り返りアドバイス」', 'drwp-daily-reports'); ?></li>
    </ul>
  </div>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <?php wp_nonce_field('drwp_save_ai_settings'); ?>
    <input type="hidden" name="action" value="drwp_save_ai_settings" />

    <table class="form-table" role="presentation">
      <tr>
        <th><?php esc_html_e('AI 機能', 'drwp-daily-reports'); ?></th>
        <td>
          <label>
            <input type="checkbox" name="enabled" value="yes" <?php checked($enabled); ?> />
            <?php esc_html_e('有効にする', 'drwp-daily-reports'); ?>
          </label>
        </td>
      </tr>
      <tr>
        <th><?php esc_html_e('API キーのモード', 'drwp-daily-reports'); ?></th>
        <td>
          <fieldset>
            <label style="display:block;margin-bottom:6px;">
              <input type="radio" name="key_mode" value="own" data-key-mode="own" <?php checked($mode, 'own'); ?> />
              <?php esc_html_e('自分の API キーを使う', 'drwp-daily-reports'); ?>
              <span class="description"><?php esc_html_e(' — OpenAI / Anthropic に直接接続。回数制限なし、料金は自分の契約から。', 'drwp-daily-reports'); ?></span>
            </label>
            <label style="display:block;">
              <input type="radio" name="key_mode" value="managed" data-key-mode="managed" <?php checked($mode, 'managed'); ?> />
              <?php esc_html_e('運営契約の API を使う', 'drwp-daily-reports'); ?>
              <span class="description"><?php esc_html_e(' — API キー不要。プランごとに月間呼び出し回数の上限あり。', 'drwp-daily-reports'); ?></span>
            </label>
          </fieldset>
        </td>
      </tr>

      <?php // ---- managed モード時の使用量パネル ---- ?>
      <tr class="drwp-ai-managed-only">
        <th><?php esc_html_e('今月の使用量', 'drwp-daily-reports'); ?></th>
        <td>
          <?php if (is_array($managed_quota)):
              $used  = (int) ($managed_quota['used'] ?? 0);
              $limit = (int) ($managed_quota['limit'] ?? 0);
              $remain = max(0, $limit - $used);
              $pct = $limit > 0 ? min(100, (int) round($used / $limit * 100)) : 100;
          ?>
            <p style="margin:.2em 0;">
              <strong><?php echo (int) $used; ?> / <?php echo (int) $limit; ?> 回</strong>
              <span class="description"><?php printf(esc_html__('（残り %d 回、%s 分）', 'drwp-daily-reports'),
                  (int) $remain, esc_html((string) ($managed_quota['period'] ?? ''))); ?></span>
            </p>
            <div style="background:#e5e7eb;border-radius:4px;height:10px;width:300px;overflow:hidden;">
              <div style="background:<?php echo $pct >= 90 ? '#dc2626' : ($pct >= 70 ? '#f59e0b' : '#16a34a'); ?>;
                          height:100%;width:<?php echo (int) $pct; ?>%;transition:width .3s;"></div>
            </div>
            <p class="description" style="margin-top:6px;"><?php esc_html_e('使用量は月初 (UTC) にリセットされます。', 'drwp-daily-reports'); ?></p>
          <?php else: ?>
            <p class="description">
              <?php esc_html_e('まだ取得できていません。「接続テスト」を実行すると最新の残量が表示されます。', 'drwp-daily-reports'); ?>
            </p>
          <?php endif; ?>
        </td>
      </tr>

      <?php // ---- own モード時のフィールド ---- ?>
      <tr class="drwp-ai-own-only">
        <th><?php esc_html_e('バックエンド', 'drwp-daily-reports'); ?></th>
        <td>
          <label style="margin-right:14px;">
            <input type="radio" name="provider" value="openai" <?php checked($provider, 'openai'); ?> />
            OpenAI 互換 <span class="description">（OpenAI / Groq / Together など）</span>
          </label>
          <label>
            <input type="radio" name="provider" value="anthropic" <?php checked($provider, 'anthropic'); ?> />
            Anthropic Claude
          </label>
        </td>
      </tr>
      <tr class="drwp-ai-own-only">
        <th><label for="drwp-ai-url"><?php esc_html_e('エンドポイント URL', 'drwp-daily-reports'); ?></label></th>
        <td>
          <input type="url" id="drwp-ai-url" name="url" value="<?php echo esc_attr($url); ?>" class="regular-text" />
          <p class="description" id="drwp-ai-url-hint"></p>
        </td>
      </tr>
      <tr class="drwp-ai-own-only">
        <th><label for="drwp-ai-model"><?php esc_html_e('モデル', 'drwp-daily-reports'); ?></label></th>
        <td>
          <input type="text" id="drwp-ai-model" name="model" value="<?php echo esc_attr($model); ?>" class="regular-text" />
          <p class="description" id="drwp-ai-model-hint"></p>
        </td>
      </tr>
      <tr class="drwp-ai-own-only" id="drwp-ai-key-row">
        <th><label for="drwp-ai-key"><?php esc_html_e('API キー', 'drwp-daily-reports'); ?></label></th>
        <td>
          <input type="password" id="drwp-ai-key" name="api_key" value="" class="regular-text" autocomplete="new-password"
                 placeholder="<?php echo $api_key !== '' ? esc_attr__('（保存済み — 変更時のみ入力）', 'drwp-daily-reports') : esc_attr__('sk-... など', 'drwp-daily-reports'); ?>" />
          <?php if ($api_key !== ''): ?>
            <label style="margin-left:8px;font-size:.9em;">
              <input type="checkbox" name="api_key_clear" value="1" />
              <?php esc_html_e('キーを削除', 'drwp-daily-reports'); ?>
            </label>
          <?php endif; ?>
          <p class="description"><?php esc_html_e('OpenAI / Anthropic で必要。', 'drwp-daily-reports'); ?></p>
        </td>
      </tr>
    </table>
    <?php submit_button(__('保存', 'drwp-daily-reports')); ?>
  </form>

  <hr>

  <h2><?php esc_html_e('接続テスト', 'drwp-daily-reports'); ?></h2>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:8px;">
    <?php wp_nonce_field('drwp_ai_test'); ?>
    <input type="hidden" name="action" value="drwp_ai_test" />
    <button class="button"><?php esc_html_e('接続テスト', 'drwp-daily-reports'); ?></button>
    <span class="description" style="margin-left:8px;">
      <?php if ($mode === 'managed'): ?>
        <?php esc_html_e('ライセンスサーバ経由で運営契約 AI に接続し、今月の残量を取得します。', 'drwp-daily-reports'); ?>
      <?php else: ?>
        <?php esc_html_e('保存した API キーでバックエンドに接続します。', 'drwp-daily-reports'); ?>
      <?php endif; ?>
    </span>
  </form>

  <?php if ($test): ?>
    <?php if (isset($test['ok'])): ?>
      <div class="notice notice-success">
        <p>
          <?php esc_html_e('接続成功。', 'drwp-daily-reports'); ?>
          <?php if (!empty($test['ok']['models'])): ?>
            <?php esc_html_e('利用可能なモデル:', 'drwp-daily-reports'); ?>
            <code><?php echo esc_html(implode(', ', array_slice($test['ok']['models'], 0, 20))); ?></code>
          <?php endif; ?>
        </p>
      </div>
    <?php else: ?>
      <div class="notice notice-error">
        <p><?php esc_html_e('接続失敗:', 'drwp-daily-reports'); ?> <?php echo esc_html($test['error']); ?></p>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <hr>

  <h2><?php esc_html_e('セットアップガイド', 'drwp-daily-reports'); ?></h2>
  <details>
    <summary><strong><?php esc_html_e('運営契約 API を選んだ場合', 'drwp-daily-reports'); ?></strong></summary>
    <ol>
      <li><?php esc_html_e('特別な作業は不要です。「AI 機能を有効にする」+ モードを「運営契約の API を使う」にして保存。', 'drwp-daily-reports'); ?></li>
      <li><?php esc_html_e('AI を使えるのはプロプラン（と体験中のフリー）のみです。ベーシック・ライトは AI 非対応です。プロの月間上限は 500 回など、運営側で設定・調整されます。', 'drwp-daily-reports'); ?></li>
      <li><?php esc_html_e('使用量は月初 (UTC) にリセットされます。', 'drwp-daily-reports'); ?></li>
    </ol>
  </details>
  <details>
    <summary><strong>OpenAI / 互換サービス</strong></summary>
    <ol>
      <li>API キーを取得（<a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">OpenAI</a> / <a href="https://console.groq.com/keys" target="_blank" rel="noopener">Groq</a> など）</li>
      <li>URL は OpenAI なら <code>https://api.openai.com</code>、Groq なら <code>https://api.groq.com/openai</code></li>
      <li>モデル例: <code>gpt-4o-mini</code>, <code>llama-3.3-70b-versatile</code></li>
    </ol>
  </details>
  <details>
    <summary><strong>Anthropic Claude</strong></summary>
    <ol>
      <li><a href="https://console.anthropic.com" target="_blank" rel="noopener">Anthropic Console</a> で API キーを取得</li>
      <li>URL: <code>https://api.anthropic.com</code></li>
      <li>モデル例: <code>claude-haiku-4-5-20251001</code>（軽量・低コスト）, <code>claude-sonnet-4-6</code></li>
    </ol>
  </details>

  <hr>

  <h2><?php esc_html_e('AI プロンプトの編集（上級者向け）', 'drwp-daily-reports'); ?></h2>
  <p class="description" style="max-width:760px;">
    <?php esc_html_e('各 AI 機能が使う「システムプロンプト（AI への指示文）」を編集できます。文体や着眼点を自社向けに調整したいときに使います。通常は初期設定のままで問題ありません。日報の内容（案件名・作業内容など）は指示文とは別に自動で AI に渡されます。', 'drwp-daily-reports'); ?>
  </p>
  <p>
    <button type="button" class="button" id="drwp-ai-prompts-toggle" aria-expanded="<?php echo !empty($prompts_open) ? 'true' : 'false'; ?>" aria-controls="drwp-ai-prompts-editor">
      <?php esc_html_e('プロンプトを編集する', 'drwp-daily-reports'); ?>
    </button>
  </p>

  <div id="drwp-ai-prompts-editor"<?php echo !empty($prompts_open) ? '' : ' hidden'; ?>>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <?php wp_nonce_field('drwp_save_ai_prompts'); ?>
      <input type="hidden" name="action" value="drwp_save_ai_prompts" />

      <?php foreach ($prompt_defaults as $pkey => $pmeta):
        $current = DRWP_AI::system_prompt($pkey);
        $is_custom = ($current !== $pmeta['default']);
        $field_id = 'drwp-ai-prompt-' . $pkey;
      ?>
      <div class="drwp-ai-prompt-item" style="margin:0 0 26px;max-width:820px;">
        <h3 style="margin-bottom:2px;">
          <label for="<?php echo esc_attr($field_id); ?>"><?php echo esc_html($pmeta['label']); ?></label>
          <?php if ($is_custom): ?>
            <span class="drwp-ai-prompt-badge" style="font-size:11px;font-weight:600;color:#b45309;background:#fef3c7;border-radius:10px;padding:1px 9px;margin-left:8px;vertical-align:middle;">
              <?php esc_html_e('カスタム', 'drwp-daily-reports'); ?>
            </span>
          <?php endif; ?>
        </h3>
        <p class="description" style="margin:.2em 0 .5em;"><?php echo esc_html($pmeta['desc']); ?></p>
        <textarea id="<?php echo esc_attr($field_id); ?>" name="prompts[<?php echo esc_attr($pkey); ?>]"
                  rows="8" class="large-text code" style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12.5px;line-height:1.6;"
                  data-default="<?php echo esc_attr($pmeta['default']); ?>"><?php echo esc_textarea($current); ?></textarea>
        <p style="margin:.4em 0 0;">
          <button type="button" class="button-link drwp-ai-prompt-reset" data-target="<?php echo esc_attr($field_id); ?>">
            <?php esc_html_e('この項目を初期設定に戻す', 'drwp-daily-reports'); ?>
          </button>
        </p>
      </div>
      <?php endforeach; ?>

      <p>
        <?php submit_button(__('プロンプトを保存', 'drwp-daily-reports'), 'primary', 'submit', false); ?>
        <button type="submit" name="reset_all" value="1" class="button" style="margin-left:8px;"
                onclick="return confirm('<?php echo esc_js(__('すべてのプロンプトを初期設定に戻します。この操作は取り消せません。よろしいですか？', 'drwp-daily-reports')); ?>');">
          <?php esc_html_e('すべて初期設定に戻して保存', 'drwp-daily-reports'); ?>
        </button>
      </p>
      <p class="description">
        <?php esc_html_e('「この項目を初期設定に戻す」は編集欄を既定文に書き戻すだけです。反映するには「プロンプトを保存」を押してください。', 'drwp-daily-reports'); ?>
      </p>
    </form>
  </div>
</div>

<script>
(function(){
  var defaults = <?php echo wp_json_encode($defaults); ?>;
  var modeRadios = document.querySelectorAll('input[name="key_mode"]');
  var ownRows  = document.querySelectorAll('.drwp-ai-own-only');
  var mgRows   = document.querySelectorAll('.drwp-ai-managed-only');
  var providerRadios = document.querySelectorAll('input[name="provider"]');
  var urlEl = document.getElementById('drwp-ai-url');
  var modelEl = document.getElementById('drwp-ai-model');
  var urlHint = document.getElementById('drwp-ai-url-hint');
  var modelHint = document.getElementById('drwp-ai-model-hint');

  function applyMode(){
    var picked = document.querySelector('input[name="key_mode"]:checked');
    var mode = picked ? picked.value : 'own';
    ownRows.forEach(function (r) { r.style.display = (mode === 'own') ? '' : 'none'; });
    mgRows.forEach(function (r)  { r.style.display = (mode === 'managed') ? '' : 'none'; });
  }
  function applyProvider(){
    var picked = document.querySelector('input[name="provider"]:checked');
    if (!picked || !urlHint) return;
    var d = defaults[picked.value];
    urlHint.textContent = '推奨: ' + d.url;
    modelHint.textContent = '推奨: ' + d.model;
  }

  modeRadios.forEach(function (r) { r.addEventListener('change', applyMode); });
  providerRadios.forEach(function (r) {
    r.addEventListener('change', function () {
      var d = defaults[r.value];
      if (urlEl.value.trim() === '' || urlEl.dataset.prefilled === '1') {
        urlEl.value = d.url; urlEl.dataset.prefilled = '1';
      }
      if (modelEl.value.trim() === '' || modelEl.dataset.prefilled === '1') {
        modelEl.value = d.model; modelEl.dataset.prefilled = '1';
      }
      applyProvider();
    });
  });
  applyMode();
  applyProvider();

  // ---- プロンプト編集セクション: 開閉トグル + 個別リセット ----
  var promptsToggle = document.getElementById('drwp-ai-prompts-toggle');
  var promptsEditor = document.getElementById('drwp-ai-prompts-editor');
  if (promptsToggle && promptsEditor) {
    promptsToggle.addEventListener('click', function () {
      var open = promptsEditor.hasAttribute('hidden');
      if (open) { promptsEditor.removeAttribute('hidden'); }
      else { promptsEditor.setAttribute('hidden', ''); }
      promptsToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }
  document.querySelectorAll('.drwp-ai-prompt-reset').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var ta = document.getElementById(btn.getAttribute('data-target'));
      if (ta) { ta.value = ta.getAttribute('data-default') || ''; ta.focus(); }
    });
  });
})();
</script>
