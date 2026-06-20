#!/bin/bash
# ===============================================================
# 日報マン ライセンスサーバ — ローカルバックアップスクリプト
# ===============================================================
#
# 目的:
#   `signing.key` (Ed25519 秘密鍵) と `data.sqlite3` (ライセンス
#   DB) を失うとプラグイン側の署名検証が壊れて全顧客サイトが
#   止まる。これを毎日 tar.gz でローカル退避する。
#
# 使い方:
#   1) 配置 (root 所有 + 実行権限):
#        sudo cp scripts/backup.sh /usr/local/bin/drwp-backup.sh
#        sudo chmod 700 /usr/local/bin/drwp-backup.sh
#
#   2) 単発実行で動作確認:
#        sudo /usr/local/bin/drwp-backup.sh
#
#   3) cron に登録 (root の crontab に):
#        sudo crontab -e
#      末尾に追加:
#        # 毎日 03:00 UTC (= JST 12:00) にバックアップ
#        0 3 * * * /usr/local/bin/drwp-backup.sh
#
#   4) 動作確認:
#        ls -la /home/ubuntu/backups/
#        tail -20 /home/ubuntu/backups/backup.log
#
# 復元方法:
#   tar xzf license-server-YYYYMMDD-HHMMSS.tar.gz \
#       -C /home/ubuntu/drwp-daily-reports/license-server/data/
#   cd /home/ubuntu/drwp-daily-reports/license-server
#   docker compose restart license
#
# オフサイトバックアップ (推奨):
#   このスクリプトは VPS 内のローカル保管だけなので、VPS 自体が
#   消えた時はバックアップも一緒に消える。週 1 回は手元の PC か
#   S3 / B2 / R2 などのクラウドにダウンロードして二重化すること。
#   末尾の OPTIONAL セクションにテンプレを置いてある。
# ===============================================================

set -euo pipefail

# ---- 設定 ------------------------------------------------------
# 必要に応じてここを書き換える。
LICENSE_DATA_DIR="/home/ubuntu/drwp-daily-reports/license-server/data"
BACKUP_DIR="/home/ubuntu/backups"
RETENTION_DAYS=14
OWNER="ubuntu:ubuntu"  # バックアップファイルの所有者
LOG_FILE="${BACKUP_DIR}/backup.log"

# ---- 前準備 ----------------------------------------------------
mkdir -p "${BACKUP_DIR}"
chown "${OWNER}" "${BACKUP_DIR}"
chmod 700 "${BACKUP_DIR}"

if [ ! -d "${LICENSE_DATA_DIR}" ]; then
    echo "[$(date -u '+%Y-%m-%d %H:%M:%S UTC')] ERROR: ${LICENSE_DATA_DIR} not found" >> "${LOG_FILE}"
    exit 1
fi

TIMESTAMP=$(date -u +"%Y%m%d-%H%M%S")
BACKUP_FILE="${BACKUP_DIR}/license-server-${TIMESTAMP}.tar.gz"

# ---- バックアップ作成 ------------------------------------------
{
    echo "==== $(date -u '+%Y-%m-%d %H:%M:%S UTC') start ===="
    tar czf "${BACKUP_FILE}" -C "${LICENSE_DATA_DIR}" .
    chown "${OWNER}" "${BACKUP_FILE}"
    chmod 600 "${BACKUP_FILE}"
    SIZE=$(du -h "${BACKUP_FILE}" | cut -f1)
    echo "created: ${BACKUP_FILE} (${SIZE})"

    # 保持期間を超えた古いバックアップを削除
    DELETED=$(find "${BACKUP_DIR}" -name "license-server-*.tar.gz" \
        -mtime "+${RETENTION_DAYS}" -delete -print | wc -l)
    REMAINING=$(find "${BACKUP_DIR}" -name "license-server-*.tar.gz" | wc -l)
    echo "rotated: ${DELETED} deleted, ${REMAINING} retained (keep ${RETENTION_DAYS}d)"
} >> "${LOG_FILE}" 2>&1

# ===============================================================
# OPTIONAL: クラウドストレージへのオフサイト同期
# ===============================================================
# 以下を有効化すると、バックアップ完了後にクラウドへもアップロード
# する。事前に rclone を入れて remote を設定しておくこと:
#
#   sudo apt install rclone
#   rclone config         # remote 名を "s3" などで作る
#
# 設定したら下の if 文の `false` を `true` に変えるだけ。
# ---------------------------------------------------------------
if false; then
    rclone copy "${BACKUP_FILE}" s3:my-drwp-bucket/license-server/ \
        --quiet >> "${LOG_FILE}" 2>&1 \
        && echo "uploaded to remote: $(basename ${BACKUP_FILE})" >> "${LOG_FILE}" \
        || echo "ERROR: rclone upload failed" >> "${LOG_FILE}"
fi
