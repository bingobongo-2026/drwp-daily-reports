import os
import sqlite3
from contextlib import contextmanager
from datetime import datetime, timezone
from typing import Iterable, Optional


def _db_path() -> str:
    return os.environ.get("DRWP_LICENSE_DB", "./data.sqlite3")


@contextmanager
def connection():
    conn = sqlite3.connect(_db_path())
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA foreign_keys = ON")
    try:
        yield conn
        conn.commit()
    finally:
        conn.close()


def init_db() -> None:
    with connection() as c:
        c.execute(
            """
            CREATE TABLE IF NOT EXISTS licenses (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                license_key TEXT NOT NULL UNIQUE,
                domain      TEXT NOT NULL,
                plan        TEXT NOT NULL DEFAULT 'basic',
                status      TEXT NOT NULL DEFAULT 'active',
                expires_at  TEXT,
                user_name       TEXT DEFAULT '',
                postal_code     TEXT DEFAULT '',
                address         TEXT DEFAULT '',
                company_phone   TEXT DEFAULT '',
                contact_person  TEXT DEFAULT '',
                contact_phone   TEXT DEFAULT '',
                notes           TEXT DEFAULT '',
                created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            """
        )
        # Single-row key/value store for runtime-configurable settings
        # (admin token, etc.). Separated from the licenses table so the
        # value column can stay free-form.
        c.execute(
            """
            CREATE TABLE IF NOT EXISTS settings (
                key   TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            """
        )
        # Audit log — records admin auth attempts and signing-key
        # rotations. `event` is a slug (login_failed / login_success /
        # login_blocked / signing_rotated_auto / signing_rotated_manual)
        # so the UI / queries can group by it. `ip` is best-effort,
        # honoring X-Forwarded-For only when DRWP_TRUST_PROXY=1.
        c.execute(
            """
            CREATE TABLE IF NOT EXISTS audit_log (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                ts       TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                event    TEXT NOT NULL,
                ip       TEXT NOT NULL DEFAULT '',
                username TEXT NOT NULL DEFAULT '',
                detail   TEXT NOT NULL DEFAULT ''
            )
            """
        )
        c.execute("CREATE INDEX IF NOT EXISTS idx_audit_ts ON audit_log(ts)")
        c.execute("CREATE INDEX IF NOT EXISTS idx_audit_event ON audit_log(event)")
        # Migration for existing DBs: add columns if missing.
        _migrate_add_columns(c)
        # Migration for existing DBs: rename the legacy `standard`
        # plan slug to `basic`. The plugin side (#129) ships a plan
        # matrix that only recognises `basic` / `pro`; anything else
        # falls back to basic-with-a-warning. Idempotent — re-running
        # touches zero rows once everyone is on `basic`.
        c.execute("UPDATE licenses SET plan = 'basic' WHERE plan = 'standard'")


def get_setting(key: str) -> Optional[str]:
    with connection() as c:
        row = c.execute(
            "SELECT value FROM settings WHERE key = ?", (key,)
        ).fetchone()
        return row["value"] if row else None


def set_setting(key: str, value: str) -> None:
    with connection() as c:
        c.execute(
            "INSERT INTO settings (key, value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP) "
            "ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = CURRENT_TIMESTAMP",
            (key, value),
        )


def delete_setting(key: str) -> None:
    with connection() as c:
        c.execute("DELETE FROM settings WHERE key = ?", (key,))


def _migrate_add_columns(c: sqlite3.Connection) -> None:
    existing = {
        row[1] for row in c.execute("PRAGMA table_info(licenses)").fetchall()
    }
    new_cols = [
        ("user_name", "TEXT DEFAULT ''"),
        ("postal_code", "TEXT DEFAULT ''"),
        ("address", "TEXT DEFAULT ''"),
        ("company_phone", "TEXT DEFAULT ''"),
        ("contact_person", "TEXT DEFAULT ''"),
        ("contact_phone", "TEXT DEFAULT ''"),
        ("notes", "TEXT DEFAULT ''"),
    ]
    for col_name, col_def in new_cols:
        if col_name not in existing:
            c.execute(f"ALTER TABLE licenses ADD COLUMN {col_name} {col_def}")


def _row_to_dict(row: Optional[sqlite3.Row]) -> Optional[dict]:
    return dict(row) if row is not None else None


def list_licenses(search: str = "", plan: str = "", status: str = "") -> list[dict]:
    """検索キーワード + プラン + 状態の AND 絞り込み。`plan` / `status`
    は完全一致 (空文字は無条件)。検索キーワードは複数カラムの OR LIKE
    なのでサブクエリにまとめて AND する。"""
    where: list[str] = []
    args: list = []
    if search:
        like = f"%{search}%"
        where.append(
            "(license_key LIKE ? OR domain LIKE ? OR user_name LIKE ?"
            " OR address LIKE ? OR contact_person LIKE ? OR notes LIKE ?)"
        )
        args.extend([like] * 6)
    if plan:
        where.append("plan = ?")
        args.append(plan)
    if status:
        where.append("status = ?")
        args.append(status)
    sql = "SELECT * FROM licenses"
    if where:
        sql += " WHERE " + " AND ".join(where)
    sql += " ORDER BY id DESC"
    with connection() as c:
        rows: Iterable[sqlite3.Row] = c.execute(sql, args).fetchall()
        return [dict(r) for r in rows]


def get_license(license_key: str) -> Optional[dict]:
    with connection() as c:
        row = c.execute(
            "SELECT * FROM licenses WHERE license_key = ?",
            (license_key,),
        ).fetchone()
        return _row_to_dict(row)


_USER_FIELDS = {"user_name", "postal_code", "address", "company_phone",
                "contact_person", "contact_phone", "notes"}


def create_license(
    *,
    license_key: str,
    domain: str,
    plan: str = "basic",
    status: str = "active",
    expires_at: Optional[str] = None,
    **extra,
) -> dict:
    user_vals = {k: extra.get(k, "") for k in _USER_FIELDS}
    with connection() as c:
        cols = "license_key, domain, plan, status, expires_at, " + ", ".join(_USER_FIELDS)
        placeholders = "?, ?, ?, ?, ?, " + ", ".join("?" for _ in _USER_FIELDS)
        params = [license_key, domain, plan, status, expires_at] + [
            user_vals[k] for k in _USER_FIELDS
        ]
        c.execute(f"INSERT INTO licenses ({cols}) VALUES ({placeholders})", params)
    return get_license(license_key)  # type: ignore[return-value]


def update_license(license_key: str, **fields) -> Optional[dict]:
    allowed = {"domain", "plan", "status", "expires_at"} | _USER_FIELDS
    updates = {k: v for k, v in fields.items() if k in allowed and v is not None}
    if not updates:
        return get_license(license_key)
    updates["updated_at"] = datetime.now(timezone.utc).isoformat(timespec="seconds")
    cols = ", ".join(f"{k} = ?" for k in updates)
    params = list(updates.values()) + [license_key]
    with connection() as c:
        cur = c.execute(
            f"UPDATE licenses SET {cols} WHERE license_key = ?",
            params,
        )
        if cur.rowcount == 0:
            return None
    return get_license(license_key)


def delete_license(license_key: str) -> bool:
    with connection() as c:
        cur = c.execute(
            "DELETE FROM licenses WHERE license_key = ?",
            (license_key,),
        )
        return cur.rowcount > 0


# --- audit log -----------------------------------------------------------

def log_audit(event: str, ip: str = "", username: str = "", detail: str = "") -> None:
    """Append one audit row. Truncated lengths keep the table compact
    even if an attacker tries to fill the username field with junk."""
    with connection() as c:
        c.execute(
            "INSERT INTO audit_log (event, ip, username, detail) VALUES (?, ?, ?, ?)",
            (event[:64], (ip or "")[:64], (username or "")[:128], (detail or "")[:512]),
        )


def recent_audit(limit: int = 50, event: Optional[str] = None) -> list[dict]:
    limit = max(1, min(int(limit), 500))
    with connection() as c:
        if event:
            rows = c.execute(
                "SELECT id, ts, event, ip, username, detail FROM audit_log "
                "WHERE event = ? ORDER BY id DESC LIMIT ?",
                (event, limit),
            ).fetchall()
        else:
            rows = c.execute(
                "SELECT id, ts, event, ip, username, detail FROM audit_log "
                "ORDER BY id DESC LIMIT ?",
                (limit,),
            ).fetchall()
        return [dict(r) for r in rows]


def purge_audit(days: int) -> int:
    """Delete audit rows older than `days`. Returns the count removed.
    `days <= 0` is a no-op so the caller can short-circuit the cron when
    retention is disabled."""
    days = int(days)
    if days <= 0:
        return 0
    with connection() as c:
        cur = c.execute(
            f"DELETE FROM audit_log WHERE ts < datetime('now', '-{days} days')"
        )
        return cur.rowcount or 0


def count_failed_logins_since(ip: str, seconds: int) -> int:
    """Used by the rate limiter — how many login_failed rows in the
    last `seconds` for this IP. SQLite's datetime() is the simplest
    portable way to express "now minus N seconds" without bringing
    Python time into the query."""
    with connection() as c:
        row = c.execute(
            f"SELECT COUNT(*) AS n FROM audit_log "
            f"WHERE event = 'login_failed' AND ip = ? "
            f"AND ts >= datetime('now', '-{int(seconds)} seconds')",
            (ip,),
        ).fetchone()
        return int(row["n"]) if row else 0
