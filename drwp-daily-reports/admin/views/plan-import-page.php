<?php if (!defined('ABSPATH')) exit;

// 予定のCSV取り込み画面 — サイボウズ Office のスケジュール書き出しが対象。
//
// 手順1: CSVをアップロード (token 無しのとき)
// 手順2: 列の対応付け・担当者の対応付けを確認して実行 (token 有りのとき)
//
// 変数は DRWP_Plan_Import::render_page() から渡ってくる:
//   $token, $headers, $rows, $truncated, $mapping, $fields,
//   $workers, $projects, $preview, $unmatched

$err_key  = isset($_GET['error']) ? sanitize_key(wp_unslash($_GET['error'])) : '';
$err_msgs = [
    'nofile'  => __('CSVファイルが選択されていません。', 'drwp-daily-reports'),
    'empty'   => __('ファイルの中身が空でした。', 'drwp-daily-reports'),
    'parse'   => __('CSVとして読み取れませんでした。1行目に列名がある形式で書き出してください。', 'drwp-daily-reports'),
    'expired' => __('アップロードしたデータの有効期限が切れました。もう一度CSVを選んでください。', 'drwp-daily-reports'),
    'nodate'  => __('「予定日」に対応するCSVの列を選んでください。', 'drwp-daily-reports'),
];
$row_errors = get_transient(DRWP_Plan_Import::TRANSIENT_PREFIX . 'err_' . get_current_user_id());
if ($row_errors) delete_transient(DRWP_Plan_Import::TRANSIENT_PREFIX . 'err_' . get_current_user_id());
?>
<div class="wrap">
  <h1><?php esc_html_e('予定のCSV取り込み', 'drwp-daily-reports'); ?></h1>

  <?php if ($err_key && isset($err_msgs[$err_key])): ?>
    <div class="notice notice-error"><p><?php echo esc_html($err_msgs[$err_key]); ?></p></div>
  <?php endif; ?>

  <?php if (!empty($_GET['done'])): ?>
    <div class="notice notice-success">
      <p><?php
        printf(
          /* translators: 1: 新規件数 2: 更新件数 3: 取り込めなかった件数 */
          esc_html__('取り込みが完了しました。新規 %1$d件 / 更新 %2$d件 / 取り込めず %3$d件', 'drwp-daily-reports'),
          (int) ($_GET['created'] ?? 0),
          (int) ($_GET['updated'] ?? 0),
          (int) ($_GET['skipped'] ?? 0)
        );
      ?></p>
      <p><a href="<?php echo esc_url(admin_url('admin.php?page=drwp_plans')); ?>"><?php esc_html_e('予定一覧を見る', 'drwp-daily-reports'); ?></a></p>
    </div>
  <?php endif; ?>

  <?php if ($row_errors && is_array($row_errors)): ?>
    <div class="notice notice-warning">
      <p><strong><?php esc_html_e('取り込めなかった行', 'drwp-daily-reports'); ?></strong></p>
      <ul style="list-style:disc;margin-left:20px">
        <?php foreach ($row_errors as $msg): ?>
          <li><?php echo esc_html($msg); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

<?php if (!$token || !$headers): ?>

  <?php /* ---------- 手順1: アップロード ---------- */ ?>
  <p><?php esc_html_e('サイボウズ Office で書き出したスケジュールのCSVを取り込み、「予定」として登録します。', 'drwp-daily-reports'); ?></p>

  <div class="card" style="max-width:760px">
    <h2 class="title"><?php esc_html_e('サイボウズ側の準備', 'drwp-daily-reports'); ?></h2>
    <ol style="margin-left:18px">
      <li><?php esc_html_e('サイボウズ Office でスケジュールをCSV形式で書き出します。', 'drwp-daily-reports'); ?></li>
      <li><?php esc_html_e('書き出し時は「先頭行に項目名を含める」を有効にしてください。', 'drwp-daily-reports'); ?></li>
      <li><?php esc_html_e('文字コードはシフトJIS・UTF-8のどちらでも取り込めます。', 'drwp-daily-reports'); ?></li>
    </ol>
    <p class="description"><?php esc_html_e('列の並びや列名は問いません。次の画面で、CSVのどの列を予定のどの項目に入れるかを選べます。', 'drwp-daily-reports'); ?></p>
  </div>

  <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:16px">
    <?php wp_nonce_field('drwp_plan_import_upload'); ?>
    <input type="hidden" name="action" value="drwp_plan_import_upload">
    <p>
      <input type="file" name="csv" accept=".csv,text/csv" required>
    </p>
    <p>
      <button type="submit" class="button button-primary"><?php esc_html_e('CSVを読み込む', 'drwp-daily-reports'); ?></button>
    </p>
  </form>

<?php else: ?>

  <?php /* ---------- 手順2: 対応付けと確認 ---------- */ ?>
  <p>
    <?php
      printf(
        /* translators: %d: 読み込んだ行数 */
        esc_html__('%d件の行を読み込みました。取り込む内容を確認してください。', 'drwp-daily-reports'),
        count($rows)
      );
    ?>
  </p>
  <?php if ($truncated): ?>
    <div class="notice notice-warning inline">
      <p><?php
        printf(
          /* translators: %d: 上限行数 */
          esc_html__('1回に取り込めるのは%d行までです。超えた分は読み込んでいません。CSVを分割して取り込んでください。', 'drwp-daily-reports'),
          (int) DRWP_Plan_Import::MAX_ROWS
        );
      ?></p>
    </div>
  <?php endif; ?>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <?php wp_nonce_field('drwp_plan_import_run'); ?>
    <input type="hidden" name="action" value="drwp_plan_import_run">
    <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">

    <h2><?php esc_html_e('1. 列の対応付け', 'drwp-daily-reports'); ?></h2>
    <p class="description"><?php esc_html_e('CSVの列名から自動で推測しています。違っていれば選び直してください。', 'drwp-daily-reports'); ?></p>
    <table class="form-table">
      <tbody>
      <?php foreach ($fields as $key => $def): ?>
        <tr>
          <th scope="row">
            <label for="drwp-map-<?php echo esc_attr($key); ?>">
              <?php echo esc_html($def['label']); ?>
              <?php if ($def['required']): ?><span style="color:#b32d2e">*</span><?php endif; ?>
            </label>
          </th>
          <td>
            <select name="map[<?php echo esc_attr($key); ?>]" id="drwp-map-<?php echo esc_attr($key); ?>">
              <option value=""><?php esc_html_e('— 取り込まない —', 'drwp-daily-reports'); ?></option>
              <?php foreach ($headers as $i => $h): ?>
                <option value="<?php echo esc_attr($i); ?>" <?php selected(isset($mapping[$key]) && (int) $mapping[$key] === (int) $i); ?>>
                  <?php echo esc_html($h !== '' ? $h : sprintf(__('(%d列目)', 'drwp-daily-reports'), $i + 1)); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if ($key === 'title'): ?>
              <p class="description"><?php esc_html_e('タイトルとメモは、予定の「メモ」欄にまとめて入ります。', 'drwp-daily-reports'); ?></p>
            <?php elseif ($key === 'external_id'): ?>
              <p class="description"><?php esc_html_e('サイボウズ側の予定IDがある場合に選ぶと、同じCSVを取り込み直しても重複しません。無い場合は日付・時刻・内容から自動で判定します。', 'drwp-daily-reports'); ?></p>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <h2><?php esc_html_e('2. 担当者・現場の割り当て', 'drwp-daily-reports'); ?></h2>
    <table class="form-table">
      <tbody>
        <tr>
          <th scope="row"><label for="drwp-import-project"><?php esc_html_e('現場 (案件)', 'drwp-daily-reports'); ?></label></th>
          <td>
            <select name="project_id" id="drwp-import-project">
              <option value=""><?php esc_html_e('— 未設定のまま取り込む —', 'drwp-daily-reports'); ?></option>
              <?php foreach ($projects as $p): ?>
                <option value="<?php echo esc_attr((int) $p->id); ?>"><?php echo esc_html($p->name); ?></option>
              <?php endforeach; ?>
            </select>
            <p class="description"><?php esc_html_e('CSVには現場の情報が無いため、全件に同じ現場を割り当てるか、未設定のまま取り込んで後から個別に設定します。', 'drwp-daily-reports'); ?></p>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="drwp-import-default-user"><?php esc_html_e('担当者が特定できない行', 'drwp-daily-reports'); ?></label></th>
          <td>
            <select name="default_user_id" id="drwp-import-default-user">
              <option value=""><?php esc_html_e('— 未設定のまま取り込む —', 'drwp-daily-reports'); ?></option>
              <?php foreach ($workers as $uid => $name): ?>
                <option value="<?php echo esc_attr((int) $uid); ?>"><?php echo esc_html($name); ?></option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>
      </tbody>
    </table>

    <?php if ($unmatched): ?>
      <h3><?php esc_html_e('対応するユーザーが見つからない参加者', 'drwp-daily-reports'); ?></h3>
      <p class="description"><?php esc_html_e('サイボウズ側の名前と一致するユーザーがいませんでした。割り当て先を選ぶと、その名前の行はそのユーザーの予定になります。', 'drwp-daily-reports'); ?></p>
      <table class="form-table">
        <tbody>
        <?php foreach ($unmatched as $name): ?>
          <tr>
            <th scope="row"><?php echo esc_html($name); ?></th>
            <td>
              <select name="user_map[<?php echo esc_attr($name); ?>]">
                <option value=""><?php esc_html_e('— 割り当てない —', 'drwp-daily-reports'); ?></option>
                <?php foreach ($workers as $uid => $wname): ?>
                  <option value="<?php echo esc_attr((int) $uid); ?>"><?php echo esc_html($wname); ?></option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <h2><?php esc_html_e('3. 取り込み内容の確認', 'drwp-daily-reports'); ?></h2>
    <p class="description"><?php esc_html_e('先頭20件を、いまの対応付けで変換した結果です。', 'drwp-daily-reports'); ?></p>
    <table class="widefat striped" style="max-width:1000px">
      <thead>
        <tr>
          <th><?php esc_html_e('予定日', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('時間', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('メモ', 'drwp-daily-reports'); ?></th>
          <th><?php esc_html_e('担当者', 'drwp-daily-reports'); ?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($preview as $row): $b = $row['built']; ?>
        <tr>
          <?php if ($b['error'] !== null): ?>
            <td colspan="4" style="color:#b32d2e"><?php echo esc_html($b['error']); ?></td>
          <?php else: ?>
            <td><?php echo esc_html($b['data']['planned_date']); ?></td>
            <td><?php
              $s = $b['data']['started_at']; $e = $b['data']['ended_at'];
              if ($s === null && $e === null) {
                  esc_html_e('終日', 'drwp-daily-reports');
              } else {
                  echo esc_html(substr((string) $s, 0, 5) . ' 〜 ' . substr((string) $e, 0, 5));
              }
            ?></td>
            <td><?php echo esc_html(mb_strimwidth((string) $b['data']['notes'], 0, 60, '…')); ?></td>
            <td><?php
              if ($row['user_id']) {
                  echo esc_html($workers[$row['user_id']] ?? '#' . (int) $row['user_id']);
              } elseif ($b['assignee'] !== '') {
                  echo '<span style="color:#8a6d3b">' . esc_html($b['assignee']) . '</span>';
              } else {
                  echo '—';
              }
            ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <p style="margin-top:16px">
      <button type="submit" class="button button-primary"><?php esc_html_e('この内容で取り込む', 'drwp-daily-reports'); ?></button>
      <a href="<?php echo esc_url(admin_url('admin.php?page=drwp_plan_import')); ?>" class="button"><?php esc_html_e('別のCSVを選ぶ', 'drwp-daily-reports'); ?></a>
    </p>
    <p class="description">
      <?php esc_html_e('同じ予定を取り込み直した場合は、新しく作らずに既存の予定を更新します。', 'drwp-daily-reports'); ?>
    </p>
  </form>

<?php endif; ?>
</div>
