<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1><?php esc_html_e('AI 設定', 'drwp-daily-reports'); ?></h1>

  <?php if (isset($_GET['saved'])): ?>
    <div class="notice notice-success"><p><?php esc_html_e('保存しました。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>

  <p><?php esc_html_e('日報を AI に渡して、文章生成・要約・チェックを行います。バックエンドは OpenAI 互換 / Anthropic から選択できます。', 'drwp-daily-reports'); ?></p>

  <div class="notice notice-info inline" style="margin:8px 0;">
    <p style="margin:.5em 0;"><strong><?php esc_html_e('有効にすると、次の場所に AI ボタンが表示されます（Pro プラン）:', 'drwp-daily-reports'); ?></strong></p>
    <ul style="margin:0 0 .5em 1.4em;list-style:disc;">
      <li><?php esc_html_e('案件ページ — 各案件の行に「AI ブリーフィング」「AI サマリ」', 'drwp-daily-reports'); ?></li>
      <li><?php esc_html_e('日報編集ページ — 「公開用コンテンツ」に「AI で下書きを生成」', 'drwp-daily-reports'); ?></li>
      <li><?php esc_html_e('日報一覧ページ — 上部に「AI 対応アラート」と「AI 振り返りアドバイス」(絞り込み中の最新60件をAIが読んで成功例・失敗例・今後の動き方を提案)', 'drwp-daily-reports'); ?></li>
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
        <th><?php esc_html_e('バックエンド', 'drwp-daily-reports'); ?></th>
        <td>
          <label style="margin-right:14px;">
            <input type="radio" name="provider" value="openai" data-needs-key="1" <?php checked($provider, 'openai'); ?> />
            OpenAI 互換 <span class="description">（OpenAI / Groq / Together など）</span>
          </label>
          <label>
            <input type="radio" name="provider" value="anthropic" data-needs-key="1" <?php checked($provider, 'anthropic'); ?> />
            Anthropic Claude
          </label>
        </td>
      </tr>
      <tr>
        <th><label for="drwp-ai-url"><?php esc_html_e('エンドポイント URL', 'drwp-daily-reports'); ?></label></th>
        <td>
          <input type="url" id="drwp-ai-url" name="url" value="<?php echo esc_attr($url); ?>" class="regular-text" />
          <p class="description" id="drwp-ai-url-hint"></p>
        </td>
      </tr>
      <tr>
        <th><label for="drwp-ai-model"><?php esc_html_e('モデル', 'drwp-daily-reports'); ?></label></th>
        <td>
          <input type="text" id="drwp-ai-model" name="model" value="<?php echo esc_attr($model); ?>" class="regular-text" />
          <p class="description" id="drwp-ai-model-hint"></p>
        </td>
      </tr>
      <tr id="drwp-ai-key-row">
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
    <span class="description" style="margin-left:8px;"><?php esc_html_e('保存した設定でバックエンドに接続し、レスポンスを確認します。', 'drwp-daily-reports'); ?></span>
  </form>

  <?php if ($test): ?>
    <?php if (isset($test['ok'])): ?>
      <div class="notice notice-success">
        <p>
          <?php esc_html_e('接続成功。', 'drwp-daily-reports'); ?>
          <?php if (!empty($test['ok']['models'])): ?>
            <?php esc_html_e('利用可能なモデル:', 'drwp-daily-reports'); ?>
            <code><?php echo esc_html(implode(', ', array_slice($test['ok']['models'], 0, 20))); ?></code>
            <?php if (count($test['ok']['models']) > 20): ?>
              <span class="description"><?php printf(esc_html__('他 %d 件', 'drwp-daily-reports'), count($test['ok']['models']) - 20); ?></span>
            <?php endif; ?>
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
</div>

<script>
(function(){
  var defaults = <?php echo wp_json_encode($defaults); ?>;
  var radios = document.querySelectorAll('input[name="provider"]');
  var urlEl = document.getElementById('drwp-ai-url');
  var modelEl = document.getElementById('drwp-ai-model');
  var urlHint = document.getElementById('drwp-ai-url-hint');
  var modelHint = document.getElementById('drwp-ai-model-hint');
  var keyRow = document.getElementById('drwp-ai-key-row');

  function apply(){
    var picked = document.querySelector('input[name="provider"]:checked');
    if (!picked) return;
    var d = defaults[picked.value];
    urlHint.textContent = '推奨: ' + d.url;
    modelHint.textContent = '推奨: ' + d.model;
    // 残った両プロバイダ(OpenAI / Anthropic)とも API キー必須なので
    // 常時 keyRow を見せる。data-needs-key 切替は廃止。
    keyRow.style.display = '';
  }

  // If the URL/model fields are empty when switching, prefill with defaults.
  radios.forEach(function(r){
    r.addEventListener('change', function(){
      var d = defaults[r.value];
      if (urlEl.value.trim() === '' || urlEl.dataset.prefilled === '1') {
        urlEl.value = d.url; urlEl.dataset.prefilled = '1';
      }
      if (modelEl.value.trim() === '' || modelEl.dataset.prefilled === '1') {
        modelEl.value = d.model; modelEl.dataset.prefilled = '1';
      }
      apply();
    });
  });
  apply();
})();
</script>
