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
                plan        TEXT NOT NULL DEFAULT 'standard',
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
        # Migration for existing DBs: add columns if missing.
        _migrate_add_columns(c)


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


def list_licenses(search: str = "") -> list[dict]:
    with connection() as c:
        if search:
            like = f"%{search}%"
            rows: Iterable[sqlite3.Row] = c.execute(
                """SELECT * FROM licenses
                   WHERE license_key LIKE ?
                      OR domain LIKE ?
                      OR user_name LIKE ?
                      OR address LIKE ?
                      OR contact_person LIKE ?
                      OR notes LIKE ?
                   ORDER BY id DESC""",
                (like, like, like, like, like, like),
            ).fetchall()
        else:
            rows = c.execute(
                "SELECT * FROM licenses ORDER BY id DESC"
            ).fetchall()
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
    plan: str = "standard",
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
