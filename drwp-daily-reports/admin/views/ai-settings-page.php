<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1><?php esc_html_e('AI 設定', 'drwp-daily-reports'); ?></h1>

  <?php if (isset($_GET['saved'])): ?>
    <div class="notice notice-success"><p><?php esc_html_e('保存しました。', 'drwp-daily-reports'); ?></p></div>
  <?php endif; ?>

  <p><?php esc_html_e('ローカル AI（Ollama）と連携して、現場ごとの次回訪問ブリーフィングを生成します。データは Ollama サーバーの外には送信されません。', 'drwp-daily-reports'); ?></p>

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
        <th><label for="drwp-ai-url"><?php esc_html_e('Ollama URL', 'drwp-daily-reports'); ?></label></th>
        <td>
          <input type="url" id="drwp-ai-url" name="url" value="<?php echo esc_attr($url); ?>" class="regular-text" placeholder="http://localhost:11434" />
          <p class="description"><?php esc_html_e('Ollama サーバーの URL。同じマシンで動かしている場合は http://localhost:11434', 'drwp-daily-reports'); ?></p>
        </td>
      </tr>
      <tr>
        <th><label for="drwp-ai-model"><?php esc_html_e('モデル', 'drwp-daily-reports'); ?></label></th>
        <td>
          <input type="text" id="drwp-ai-model" name="model" value="<?php echo esc_attr($model); ?>" class="regular-text" placeholder="gemma3:4b" />
          <p class="description"><?php esc_html_e('使用するモデル名。例: gemma3:4b（日本語対応・軽量）、qwen2.5:7b、llama3.2:3b', 'drwp-daily-reports'); ?></p>
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
    <span class="description" style="margin-left:8px;"><?php esc_html_e('保存した URL に接続し、利用可能なモデル一覧を取得します。', 'drwp-daily-reports'); ?></span>
  </form>

  <?php if ($test): ?>
    <?php if (isset($test['ok'])): ?>
      <div class="notice notice-success">
        <p>
          <?php esc_html_e('接続成功。', 'drwp-daily-reports'); ?>
          <?php if (!empty($test['ok']['models'])): ?>
            <?php esc_html_e('利用可能なモデル:', 'drwp-daily-reports'); ?>
            <code><?php echo esc_html(implode(', ', $test['ok']['models'])); ?></code>
          <?php else: ?>
            <?php esc_html_e('（インストール済みモデルなし）', 'drwp-daily-reports'); ?>
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

  <h2><?php esc_html_e('セットアップ手順', 'drwp-daily-reports'); ?></h2>
  <ol>
    <li><a href="https://ollama.com/download" target="_blank" rel="noopener">Ollama</a> をインストール</li>
    <li>ターミナルで <code>ollama pull gemma3:4b</code> を実行してモデルを取得</li>
    <li>このページで「有効にする」をチェックし、保存 → 接続テスト</li>
    <li>「現場」ページで「AI ブリーフィング」ボタンをクリック</li>
  </ol>
</div>
