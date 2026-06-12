<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap drwp-help">
  <h1 class="wp-heading-inline"><?php esc_html_e('日報マン 使い方ガイド', 'drwp-daily-reports'); ?></h1>
  <hr class="wp-header-end">

  <p class="description" style="max-width:760px;">
    <?php esc_html_e('現場の作業日報を WordPress 上で運用するためのプラグインです。下のタブから知りたい項目を開いてください。リンクのままブックマーク・社内共有できます。', 'drwp-daily-reports'); ?>
  </p>

  <h2 class="nav-tab-wrapper" style="margin-top:18px;">
    <?php foreach ($tabs as $slug => $label):
        $class = 'nav-tab' . ($current === $slug ? ' nav-tab-active' : '');
        $url = $slug === 'intro' ? $base_url : add_query_arg('tab', $slug, $base_url);
    ?>
      <a class="<?php echo esc_attr($class); ?>" href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a>
    <?php endforeach; ?>
  </h2>

  <style>
    .drwp-help .card { background:#fff; border:1px solid #c3c4c7; border-radius:8px; padding:18px 22px; margin:14px 0; max-width:880px; }
    .drwp-help .card h2 { margin-top:0; }
    .drwp-help .card h3 { margin-top:20px; font-size:1.05em; color:#1d2327; }
    .drwp-help .card ol, .drwp-help .card ul { margin:8px 0 12px 22px; line-height:1.8; }
    .drwp-help .card p { line-height:1.8; }
    .drwp-help .pill { display:inline-block; padding:1px 8px; border-radius:999px; font-size:.78em; background:#e0e7ff; color:#3730a3; vertical-align:middle; }
    .drwp-help .pill.pro { background:#fef3c7; color:#92400e; }
    .drwp-help .pill.admin { background:#fee2e2; color:#991b1b; }
    .drwp-help .pill.reviewer { background:#dcfce7; color:#166534; }
    .drwp-help .tip { background:#eff6ff; border-left:4px solid #3b82f6; padding:10px 14px; margin:12px 0; border-radius:0 6px 6px 0; }
    .drwp-help .warn { background:#fef3c7; border-left:4px solid #f59e0b; padding:10px 14px; margin:12px 0; border-radius:0 6px 6px 0; }
    .drwp-help kbd { background:#f6f7f7; border:1px solid #c3c4c7; border-bottom-width:2px; border-radius:4px; padding:1px 6px; font-size:.85em; }
    .drwp-help code { background:#f6f7f7; padding:1px 6px; border-radius:4px; font-size:.92em; }
  </style>

  <?php if ($current === 'intro'): ?>
    <div class="card">
      <h2><?php esc_html_e('日報マンとは', 'drwp-daily-reports'); ?></h2>
      <p><?php esc_html_e('現場担当者が書いた日報を、レビューを経て、最終的に WordPress の公開記事として発信するまでをひとつのワークフローで扱うプラグインです。', 'drwp-daily-reports'); ?></p>

      <h3><?php esc_html_e('できること', 'drwp-daily-reports'); ?></h3>
      <ul>
        <li><?php esc_html_e('現場担当者がスマホ/PC のフロント画面から日報を投稿（写真添付込み）', 'drwp-daily-reports'); ?></li>
        <li><?php esc_html_e('レビュアーが「承認」「差戻し」「コメント」で品質を担保', 'drwp-daily-reports'); ?></li>
        <li><?php esc_html_e('承認済み日報をワンクリックで公開記事に変換（テンプレート切替可）', 'drwp-daily-reports'); ?></li>
        <li><?php esc_html_e('案件 / 顧客 / 社員 / 予定（先々の訪問予定）を一元管理', 'drwp-daily-reports'); ?></li>
        <li><?php esc_html_e('AI による次回訪問ブリーフィング、公開記事下書き、案件サマリ、振り返りアドバイス', 'drwp-daily-reports'); ?> <span class="pill pro">Pro</span></li>
        <li><?php esc_html_e('操作履歴の自動保存・自動削除（既定 365 日）', 'drwp-daily-reports'); ?></li>
      </ul>

      <h3><?php esc_html_e('登場人物（権限）', 'drwp-daily-reports'); ?></h3>
      <ul>
        <li><span class="pill"><?php esc_html_e('社員', 'drwp-daily-reports'); ?></span> <?php esc_html_e('自分の日報を書く・編集する人。WordPress 上は「投稿者 (Author)」相当。', 'drwp-daily-reports'); ?></li>
        <li><span class="pill reviewer"><?php esc_html_e('レビュアー', 'drwp-daily-reports'); ?></span> <?php esc_html_e('他人の日報を見て承認・差戻しできる人。「編集者 (Editor)」相当。', 'drwp-daily-reports'); ?></li>
        <li><span class="pill admin"><?php esc_html_e('管理者', 'drwp-daily-reports'); ?></span> <?php esc_html_e('案件 / 顧客 / 社員 / ライセンス / AI / 通知 / 公開設定をいじる人。', 'drwp-daily-reports'); ?></li>
      </ul>

      <div class="tip">
        <strong><?php esc_html_e('まず最初に', 'drwp-daily-reports'); ?></strong> —
        <?php esc_html_e('管理者は「案件」「顧客」「社員」の3つを登録してから運用を始めてください。これらは日報を書く際の必須項目です。', 'drwp-daily-reports'); ?>
      </div>
    </div>

  <?php elseif ($current === 'report'): ?>
    <div class="card">
      <h2><?php esc_html_e('日報を書く', 'drwp-daily-reports'); ?></h2>

      <h3><?php esc_html_e('どこから日報を書ける？', 'drwp-daily-reports'); ?></h3>
      <ul>
        <li><strong><?php esc_html_e('WordPress 管理画面から', 'drwp-daily-reports'); ?></strong> — <code>日報マン → 日報一覧 → 新規追加</code>。PC で腰を据えて書く場合に向きます。</li>
        <li><strong><?php esc_html_e('フロント（社外）画面から', 'drwp-daily-reports'); ?></strong> — 公開ページにショートコード <code>[drwp_report_form]</code> を貼り付けたページにアクセスして書きます。スマホからの現場入力に最適です。</li>
      </ul>

      <h3><?php esc_html_e('入力項目', 'drwp-daily-reports'); ?></h3>
      <ul>
        <li><strong><?php esc_html_e('報告日', 'drwp-daily-reports'); ?></strong> — <?php esc_html_e('既定は本日。過去日付・未来日付の入力も可能。', 'drwp-daily-reports'); ?></li>
        <li><strong><?php esc_html_e('案件', 'drwp-daily-reports'); ?></strong> — <?php esc_html_e('プルダウンから選択。新規案件は管理者が「案件」ページで先に登録しておきます。', 'drwp-daily-reports'); ?></li>
        <li><strong><?php esc_html_e('開始時刻 / 終了時刻', 'drwp-daily-reports'); ?></strong> — <code>HH:MM</code> 形式。空欄可。</li>
        <li><strong><?php esc_html_e('作業内容', 'drwp-daily-reports'); ?></strong> — <?php esc_html_e('当日に実施したことを書きます。AI が後で要約やアドバイスを生成する材料になるので、できるだけ具体的に。', 'drwp-daily-reports'); ?></li>
        <li><strong><?php esc_html_e('特記事項', 'drwp-daily-reports'); ?></strong> — <?php esc_html_e('引き継ぎ・要対応事項・気付き。「対応必須アラート」AI 抽出の対象。', 'drwp-daily-reports'); ?></li>
        <li><strong><?php esc_html_e('次回予定', 'drwp-daily-reports'); ?></strong> — <?php esc_html_e('次に何をやるか。AI ブリーフィングが翌訪問時に呼び出します。', 'drwp-daily-reports'); ?></li>
        <li><strong><?php esc_html_e('写真', 'drwp-daily-reports'); ?></strong> — <?php esc_html_e('複数添付可。並び替えはドラッグで。1 枚目が公開記事のアイキャッチ候補になります。', 'drwp-daily-reports'); ?></li>
        <li><strong><?php esc_html_e('公開記事用タイトル', 'drwp-daily-reports'); ?></strong> — <?php esc_html_e('承認後に公開記事化する際の見出し。空欄なら案件名 + 報告日が使われます。', 'drwp-daily-reports'); ?></li>
      </ul>

      <h3><?php esc_html_e('保存ボタン', 'drwp-daily-reports'); ?></h3>
      <ul>
        <li><strong><?php esc_html_e('下書き保存', 'drwp-daily-reports'); ?></strong> — <?php esc_html_e('自分しか見えません。後で続き書きできます。', 'drwp-daily-reports'); ?></li>
        <li><strong><?php esc_html_e('レビュー依頼', 'drwp-daily-reports'); ?></strong> — <?php esc_html_e('「pending（レビュー待ち）」状態にしてレビュアーに通知します。', 'drwp-daily-reports'); ?></li>
      </ul>

      <div class="warn">
        <strong><?php esc_html_e('注意', 'drwp-daily-reports'); ?></strong> —
        <?php esc_html_e('ライセンス未設定 / 期限切れの状態では保存ができません。管理画面のヘッダーに赤い帯で警告が出ます。', 'drwp-daily-reports'); ?>
      </div>
    </div>

  <?php elseif ($current === 'review'): ?>
    <div class="card">
      <h2><?php esc_html_e('レビューフロー', 'drwp-daily-reports'); ?> <span class="pill reviewer"><?php esc_html_e('レビュアー', 'drwp-daily-reports'); ?></span></h2>

      <p><?php esc_html_e('日報は「下書き → レビュー待ち → 承認済み or 差戻し」の流れで状態が遷移します。レビュアーはダッシュボードの「レビュー待ち」カウンタからワンクリックで対象一覧を開けます。', 'drwp-daily-reports'); ?></p>

      <h3><?php esc_html_e('状態の意味', 'drwp-daily-reports'); ?></h3>
      <ul>
        <li><strong>pending</strong> — <?php esc_html_e('レビュー待ち。レビュアーの対応待ち。', 'drwp-daily-reports'); ?></li>
        <li><strong>approved</strong> — <?php esc_html_e('承認済み。公開記事化の対象になります。', 'drwp-daily-reports'); ?></li>
        <li><strong>needs_revision</strong> — <?php esc_html_e('差戻し。投稿者が修正してもう一度レビュー依頼を出します。', 'drwp-daily-reports'); ?></li>
        <li><strong>edit_requested</strong> — <?php esc_html_e('承認後に投稿者が再編集を希望してロックを開けてもらった状態。', 'drwp-daily-reports'); ?></li>
      </ul>

      <h3><?php esc_html_e('コメント', 'drwp-daily-reports'); ?></h3>
      <p><?php esc_html_e('差戻し時はコメント必須にすると親切です。コメントは日報詳細ページの下部に時系列で残ります。', 'drwp-daily-reports'); ?></p>

      <h3><?php esc_html_e('通知メール', 'drwp-daily-reports'); ?></h3>
      <p><?php esc_html_e('「通知設定」を有効にしておくと、レビュー依頼／レビュー完了／コメント追加のタイミングで関係者にメールが届きます。', 'drwp-daily-reports'); ?></p>
    </div>

  <?php elseif ($current === 'publish'): ?>
    <div class="card">
      <h2><?php esc_html_e('公開記事化', 'drwp-daily-reports'); ?> <span class="pill"><?php esc_html_e('CAP: publish_posts', 'drwp-daily-reports'); ?></span></h2>

      <p><?php esc_html_e('承認済みの日報を WordPress の通常投稿として公開します。', 'drwp-daily-reports'); ?></p>

      <h3><?php esc_html_e('使い方', 'drwp-daily-reports'); ?></h3>
      <ol>
        <li><?php esc_html_e('「記事作成」ページを開く', 'drwp-daily-reports'); ?></li>
        <li><?php esc_html_e('対象の日報を選び、テンプレートを選択', 'drwp-daily-reports'); ?></li>
        <li><?php esc_html_e('プレビューで内容を確認し「公開」', 'drwp-daily-reports'); ?></li>
      </ol>

      <h3><?php esc_html_e('テンプレート', 'drwp-daily-reports'); ?></h3>
      <ul>
        <li><strong><?php esc_html_e('標準', 'drwp-daily-reports'); ?></strong> — <?php esc_html_e('タイトル + 本文 + 写真。汎用。', 'drwp-daily-reports'); ?></li>
        <li><strong><?php esc_html_e('案件レポート', 'drwp-daily-reports'); ?></strong> — <?php esc_html_e('案件名・住所・担当者を冒頭に箱で表示。', 'drwp-daily-reports'); ?></li>
        <li><strong>Before / After</strong> — <?php esc_html_e('写真を2列に並べて施工前後を見せる構成。', 'drwp-daily-reports'); ?></li>
      </ul>

      <h3><?php esc_html_e('再反映', 'drwp-daily-reports'); ?></h3>
      <p><?php esc_html_e('公開後に日報側を修正した場合、記事一覧の「再反映」で連動投稿を上書きできます。一括再反映も可能。', 'drwp-daily-reports'); ?></p>

      <h3><?php esc_html_e('カテゴリ・投稿タイプ', 'drwp-daily-reports'); ?></h3>
      <p><?php esc_html_e('「公開設定」ページで、投稿タイプ（post / 任意の CPT）、デフォルトカテゴリ、アイキャッチ自動設定を切替できます。', 'drwp-daily-reports'); ?></p>
    </div>

  <?php elseif ($current === 'plans'): ?>
    <div class="card">
      <h2><?php esc_html_e('予定（先々の訪問予定）', 'drwp-daily-reports'); ?></h2>

      <p><?php esc_html_e('「いつ・どこに・誰が行くか」のカレンダー。日報が「過去にやったこと」の記録だとすれば、予定は「これからやること」の計画です。', 'drwp-daily-reports'); ?></p>

      <h3><?php esc_html_e('予定の作り方', 'drwp-daily-reports'); ?></h3>
      <ol>
        <li><code>日報マン → 予定一覧</code> を開く</li>
        <li><?php esc_html_e('カレンダーの該当日のセルをクリック', 'drwp-daily-reports'); ?></li>
        <li><?php esc_html_e('案件・担当者・メモを入力して保存', 'drwp-daily-reports'); ?></li>
      </ol>

      <h3><?php esc_html_e('編集とドラッグ', 'drwp-daily-reports'); ?></h3>
      <p><?php esc_html_e('カレンダー上のチップをドラッグで他の日付へ移動できます。タップ／クリックで詳細編集モーダルが開き、案件・担当者も差し替え可能です。', 'drwp-daily-reports'); ?></p>

      <h3><?php esc_html_e('日報との連携', 'drwp-daily-reports'); ?></h3>
      <p><?php esc_html_e('予定の対象日になったら、ワンクリックでその予定から日報を起こせます。案件・担当者は引き継がれ、入力の手間を最小化します。', 'drwp-daily-reports'); ?></p>
    </div>

  <?php elseif ($current === 'master'): ?>
    <div class="card">
      <h2><?php esc_html_e('案件・顧客マスタ', 'drwp-daily-reports'); ?> <span class="pill admin"><?php esc_html_e('管理者', 'drwp-daily-reports'); ?></span></h2>

      <h3><?php esc_html_e('顧客', 'drwp-daily-reports'); ?></h3>
      <p><?php esc_html_e('発注元の会社・個人。住所・連絡先・備考に加えて、画像（建物外観など）も添付できます。', 'drwp-daily-reports'); ?></p>

      <h3><?php esc_html_e('案件', 'drwp-daily-reports'); ?></h3>
      <p><?php esc_html_e('日報が紐づく工事・現場の単位。顧客と関連付けます。住所（都道府県・市区町村）を入れておくと、フロントの案件一覧での地図表示や案件レポートの自動見出しに使われます。', 'drwp-daily-reports'); ?></p>

      <h3><?php esc_html_e('グループ', 'drwp-daily-reports'); ?></h3>
      <p><?php esc_html_e('案件 / 顧客にタグ的なグループを付けて、絞り込み・一覧の整理に使えます。', 'drwp-daily-reports'); ?></p>

      <h3><?php esc_html_e('社員', 'drwp-daily-reports'); ?></h3>
      <ul>
        <li><?php esc_html_e('WordPress のユーザーに「社員」プロフィールを紐付けます。表示名・部署・写真・連絡先など。', 'drwp-daily-reports'); ?></li>
        <li><strong><?php esc_html_e('退職フラグ', 'drwp-daily-reports'); ?></strong> — <?php esc_html_e('オンにするとログインがブロックされます。過去の日報・コメントはそのまま残り、表示名も維持されます。', 'drwp-daily-reports'); ?></li>
      </ul>
    </div>

  <?php elseif ($current === 'ai'): ?>
    <div class="card">
      <h2><?php esc_html_e('AI 機能', 'drwp-daily-reports'); ?> <span class="pill pro">Pro</span></h2>

      <p><?php esc_html_e('OpenAI または Anthropic の API キーを「AI 設定」に入れると、以下の機能が解放されます。Pro プランのライセンスが必要です。', 'drwp-daily-reports'); ?></p>

      <h3><?php esc_html_e('1. 次回訪問ブリーフィング', 'drwp-daily-reports'); ?></h3>
      <p><?php esc_html_e('案件ページのボタンから、過去日報の「次回予定」「特記事項」を集めて、次回訪問時に持って行くべき内容を要約します。', 'drwp-daily-reports'); ?></p>

      <h3><?php esc_html_e('2. 公開記事下書き', 'drwp-daily-reports'); ?></h3>
      <p><?php esc_html_e('日報の作業内容から、一般読者向けに読みやすく整えた本文ドラフトを生成。記事化前のひと手間を肩代わりします。', 'drwp-daily-reports'); ?></p>

      <h3><?php esc_html_e('3. 案件サマリ（月次・四半期）', 'drwp-daily-reports'); ?></h3>
      <p><?php esc_html_e('案件に紐づくすべての日報を期間で絞り込み、進捗と気付きをまとめた報告書を生成します。', 'drwp-daily-reports'); ?></p>

      <h3><?php esc_html_e('4. 対応必須アラート', 'drwp-daily-reports'); ?></h3>
      <p><?php esc_html_e('「特記事項」から「これは要対応」というキーワードを AI が抽出し、ダッシュボードに警告として並べます。', 'drwp-daily-reports'); ?></p>

      <h3><?php esc_html_e('5. 振り返りアドバイス', 'drwp-daily-reports'); ?></h3>
      <p><?php esc_html_e('日報一覧で絞り込んだ範囲を AI が分析し、「過去の成功例 / 失敗例から見て、今後どう向き合うべきか」のアドバイスを提示します。', 'drwp-daily-reports'); ?></p>

      <div class="tip">
        <strong><?php esc_html_e('API キーの取り扱い', 'drwp-daily-reports'); ?></strong> —
        <?php esc_html_e('キーはサイトの DB に保管され、リクエスト時のみプロバイダに送られます。送信内容は当該プロバイダの利用規約に従って扱われます。', 'drwp-daily-reports'); ?>
      </div>
    </div>

  <?php elseif ($current === 'admin'): ?>
    <div class="card">
      <h2><?php esc_html_e('管理者向け設定', 'drwp-daily-reports'); ?> <span class="pill admin"><?php esc_html_e('管理者', 'drwp-daily-reports'); ?></span></h2>

      <h3><?php esc_html_e('ライセンス', 'drwp-daily-reports'); ?></h3>
      <ol>
        <li><?php esc_html_e('API URL と発行されたライセンスキーを入力', 'drwp-daily-reports'); ?></li>
        <li><?php esc_html_e('「公開鍵を取得」を押す', 'drwp-daily-reports'); ?></li>
        <li><?php esc_html_e('「いま照会する」を押し、signature_valid が valid になることを確認', 'drwp-daily-reports'); ?></li>
      </ol>
      <p><?php esc_html_e('ライセンスが無効になると、保存・記事化・AI が即時に停止します（読み取りは可能）。', 'drwp-daily-reports'); ?></p>

      <h3><?php esc_html_e('公開設定', 'drwp-daily-reports'); ?></h3>
      <ul>
        <li><?php esc_html_e('投稿タイプ（post / カスタム）', 'drwp-daily-reports'); ?></li>
        <li><?php esc_html_e('デフォルトカテゴリ', 'drwp-daily-reports'); ?></li>
        <li><?php esc_html_e('アイキャッチ自動設定の ON / OFF', 'drwp-daily-reports'); ?></li>
      </ul>

      <h3><?php esc_html_e('通知設定', 'drwp-daily-reports'); ?></h3>
      <ul>
        <li><?php esc_html_e('レビュー依頼 / レビュー完了 / コメント追加 のメール通知', 'drwp-daily-reports'); ?></li>
        <li><?php esc_html_e('差出人アドレス（From）の指定', 'drwp-daily-reports'); ?></li>
      </ul>

      <h3><?php esc_html_e('ログイン設定', 'drwp-daily-reports'); ?></h3>
      <ul>
        <li><?php esc_html_e('フロント側のログインページ ID（ショートコード [drwp_login_form] を貼ったページ）', 'drwp-daily-reports'); ?></li>
        <li><?php esc_html_e('/wp-login.php のリダイレクト ON/OFF', 'drwp-daily-reports'); ?></li>
        <li><?php esc_html_e('管理画面ロックダウン（社員は wp-admin に入れない）', 'drwp-daily-reports'); ?></li>
        <li><?php esc_html_e('ログインページのロゴ画像', 'drwp-daily-reports'); ?></li>
      </ul>

      <h3><?php esc_html_e('操作履歴の保存期間', 'drwp-daily-reports'); ?></h3>
      <p><?php esc_html_e('操作履歴ページ先頭の「保存期間と自動削除」パネルから、30 / 90 / 180 / 365 / 730 / 1095 日 / 永久保存を選べます。既定は 365 日。1 日 1 回、設定期間より古い行が自動削除されます。「今すぐ古い履歴を削除」ボタンで即時実行も可能。', 'drwp-daily-reports'); ?></p>
    </div>

  <?php elseif ($current === 'faq'): ?>
    <div class="card">
      <h2><?php esc_html_e('よくある質問', 'drwp-daily-reports'); ?></h2>

      <h3><?php esc_html_e('Q. 日報を保存しようとすると「ライセンスが有効ではありません」と出る', 'drwp-daily-reports'); ?></h3>
      <p><?php esc_html_e('A.「ライセンス」ページで、API URL とキーが入っているか / signature_valid が valid か / 期限切れになっていないか を確認してください。一時的にライセンスサーバへ到達できないだけなら、しばらく経つと自動的に復旧します。', 'drwp-daily-reports'); ?></p>

      <h3><?php esc_html_e('Q. 写真をアップロードしたのにフロントで表示されない', 'drwp-daily-reports'); ?></h3>
      <p><?php esc_html_e('A. WordPress のメディア権限（uploads ディレクトリの書込権限）が落ちている可能性があります。サーバ管理者に確認してください。', 'drwp-daily-reports'); ?></p>

      <h3><?php esc_html_e('Q. 社員が辞めたあと、過去の日報はどうなる？', 'drwp-daily-reports'); ?></h3>
      <p><?php esc_html_e('A. 社員管理画面で「退職」フラグを立ててください。ログインはブロックされますが、過去の日報・コメント・表示名はそのまま残ります。', 'drwp-daily-reports'); ?></p>

      <h3><?php esc_html_e('Q. AI ボタンが押せない', 'drwp-daily-reports'); ?></h3>
      <p><?php esc_html_e('A. (1) ライセンスが Pro プランか (2)「AI 設定」で API キーを入れているか (3) 利用回数の月次上限に当たっていないか を確認してください。', 'drwp-daily-reports'); ?></p>

      <h3><?php esc_html_e('Q. 公開記事を消したい', 'drwp-daily-reports'); ?></h3>
      <p><?php esc_html_e('A. WordPress の通常投稿として作成されているので、「投稿」一覧から削除してください。日報側は残ります。再度「記事作成」から公開し直すことも可能です。', 'drwp-daily-reports'); ?></p>

      <h3><?php esc_html_e('Q. CSV で過去日報を一括出力したい', 'drwp-daily-reports'); ?></h3>
      <p><?php esc_html_e('A. 日報一覧の絞り込み欄の下にある「CSV 出力」を使ってください。文字コードは UTF-8 (BOM 付き) で Excel でそのまま開けます。', 'drwp-daily-reports'); ?></p>

      <h3><?php esc_html_e('Q. プラグインをアンインストールするとデータはどうなる？', 'drwp-daily-reports'); ?></h3>
      <p><?php esc_html_e('A. プラグイン削除（「停止」ではなく「削除」）を行うと、wp_drwp_* 系のテーブルとプラグイン関連オプションがすべて削除されます。残しておきたい場合は事前に CSV エクスポートを取ってください。', 'drwp-daily-reports'); ?></p>

      <h3><?php esc_html_e('Q. 不具合を見つけた / 機能要望がある', 'drwp-daily-reports'); ?></h3>
      <p><?php esc_html_e('A. 提供元までご連絡ください。事象が再現する手順、スクリーンショット、操作履歴ページの該当行があると調査が早まります。', 'drwp-daily-reports'); ?></p>
    </div>
  <?php endif; ?>
</div>
