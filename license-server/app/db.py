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
                created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            """
        )


def _row_to_dict(row: Optional[sqlite3.Row]) -> Optional[dict]:
    return dict(row) if row is not None else None


def list_licenses() -> list[dict]:
    with connection() as c:
        rows: Iterable[sqlite3.Row] = c.execute(
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


def create_license(
    *,
    license_key: str,
    domain: str,
    plan: str = "standard",
    status: str = "active",
    expires_at: Optional[str] = None,
) -> dict:
    with connection() as c:
        c.execute(
            """
            INSERT INTO licenses (license_key, domain, plan, status, expires_at)
            VALUES (?, ?, ?, ?, ?)
            """,
            (license_key, domain, plan, status, expires_at),
        )
    return get_license(license_key)  # type: ignore[return-value]


def update_license(license_key: str, **fields) -> Optional[dict]:
    allowed = {"domain", "plan", "status", "expires_at"}
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
