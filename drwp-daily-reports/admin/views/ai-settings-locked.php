<?php if (!defined('ABSPATH')) exit;
// Pro プランに上げないと使えない旨を案内するページ。`drwp_ai`
// メニューは出したまま中身だけ差し替えるので、過去設定がある場合
// (ダウングレード)はそれが消えないこと、ライセンスを戻せばまた
// 普通に編集できることを操作員に明示する。
$plan_label = DRWP_License::plan();
$license_state_url = admin_url('admin.php?page=drwp_license');
?>
<div class="wrap">
  <h1><?php esc_html_e('AI設定', 'drwp-daily-reports'); ?></h1>

  <div class="drwp-plan-locked">
    <div class="drwp-plan-locked-badge"><?php esc_html_e('Pro プランで利用可能', 'drwp-daily-reports'); ?></div>
    <h2 class="drwp-plan-locked-title">
      <?php esc_html_e('AI 機能は Pro プランで利用可能です', 'drwp-daily-reports'); ?>
    </h2>
    <p class="drwp-plan-locked-lead">
      <?php esc_html_e('現在のプラン:', 'drwp-daily-reports'); ?>
      <code><?php echo esc_html($plan_label !== '' ? $plan_label : 'basic'); ?></code>
    </p>
    <p class="drwp-plan-locked-body">
      <?php esc_html_e('AI モデルとの接続設定、案件ブリーフィングの生成といった機能は Pro プランでお使いいただけます。プランを変更すると、既存の設定値はそのまま使える状態で復帰します。', 'drwp-daily-reports'); ?>
    </p>
    <p>
      <a class="button button-primary" href="<?php echo esc_url($license_state_url); ?>">
        <?php esc_html_e('ライセンス状態を確認する', 'drwp-daily-reports'); ?>
      </a>
    </p>
  </div>
</div>

<style>
.drwp-plan-locked {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-left: 4px solid #6366f1;
    border-radius: 8px;
    padding: 20px 24px;
    max-width: 720px;
    margin-top: 16px;
}
.drwp-plan-locked-badge {
    display: inline-block;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: #fff;
    font-size: .78em;
    font-weight: 700;
    padding: 3px 12px;
    border-radius: 999px;
    letter-spacing: .04em;
}
.drwp-plan-locked-title { margin: 12px 0 6px; font-size: 1.4em; color: #0f172a; }
.drwp-plan-locked-lead  { margin: 0 0 10px; color: #475569; }
.drwp-plan-locked-lead code { background: #f1f5f9; padding: 2px 8px; border-radius: 4px; font-size: .92em; }
.drwp-plan-locked-body  { color: #1f2937; line-height: 1.7; margin: 0 0 16px; }
</style>
