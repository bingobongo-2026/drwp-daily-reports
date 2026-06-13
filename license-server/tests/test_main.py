import importlib
import sys

import pytest
from fastapi.testclient import TestClient


def _fresh_client(tmp_path, monkeypatch, *, admin_token: str | None = "test-token"):
    if admin_token is None:
        monkeypatch.delenv("DRWP_ADMIN_TOKEN", raising=False)
    else:
        monkeypatch.setenv("DRWP_ADMIN_TOKEN", admin_token)
    monkeypatch.setenv("DRWP_LICENSE_DB", str(tmp_path / "test.sqlite"))
    monkeypatch.setenv("DRWP_SIGNING_KEY", str(tmp_path / "test.key"))
    # 自動ローテートと監査ログ purge のバックグラウンドタスクは
    # テスト中は走らせない（タイミング依存になるので）。
    monkeypatch.setenv("DRWP_ROTATION_INTERVAL_DAYS", "0")
    monkeypatch.setenv("DRWP_AUDIT_RETENTION_DAYS", "0")
    for name in ("app.main", "app.db", "app.signing", "app"):
        sys.modules.pop(name, None)
    main = importlib.import_module("app.main")
    return TestClient(main.app), main


@pytest.fixture
def client(tmp_path, monkeypatch):
    c, _ = _fresh_client(tmp_path, monkeypatch)
    return c


@pytest.fixture
def seeded(tmp_path, monkeypatch):
    c, _ = _fresh_client(tmp_path, monkeypatch)
    c.post(
        "/admin/licenses",
        auth=("admin", "test-token"),
        json={
            "license_key": "ACTIVE-KEY",
            "domain": "example.test",
            "plan": "pro",
            "status": "active",
            "expires_at": "2099-12-31T23:59:59+00:00",
        },
    )
    c.post(
        "/admin/licenses",
        auth=("admin", "test-token"),
        json={
            "license_key": "EXPIRED-KEY",
            "domain": "example.test",
            "plan": "pro",
            "status": "active",
            "expires_at": "2000-01-01T00:00:00+00:00",
        },
    )
    c.post(
        "/admin/licenses",
        auth=("admin", "test-token"),
        json={
            "license_key": "INACTIVE-KEY",
            "domain": "example.test",
            "plan": "pro",
            "status": "inactive",
        },
    )
    return c


def test_healthz(client):
    r = client.get("/healthz")
    assert r.status_code == 200
    assert r.json() == {"ok": True}


def test_public_key_is_ed25519(client):
    r = client.get("/api/public-key")
    assert r.status_code == 200
    body = r.json()
    assert body["algorithm"] == "ed25519"
    assert len(body["public_key"]) > 10


def test_check_unknown_key_returns_not_found_with_valid_signature(client):
    r = client.post(
        "/api/check",
        json={"license_key": "UNKNOWN", "domain": "example.test"},
    )
    assert r.status_code == 200
    body = r.json()
    assert body["status"] == "not_found"

    from app import signing
    sig = body.pop("signature")
    assert signing.verify(body, sig) is True


def test_check_active_signature_round_trip(seeded):
    r = seeded.post(
        "/api/check",
        json={"license_key": "ACTIVE-KEY", "domain": "example.test"},
    )
    assert r.status_code == 200
    body = r.json()
    assert body["status"] == "active"
    assert body["plan"] == "pro"

    from app import signing
    sig = body.pop("signature")
    assert signing.verify(body, sig) is True

    # Tampering with any field invalidates the signature.
    body["status"] = "expired"
    assert signing.verify(body, sig) is False


def test_check_rejects_expired(seeded):
    r = seeded.post(
        "/api/check",
        json={"license_key": "EXPIRED-KEY", "domain": "example.test"},
    )
    assert r.status_code == 200
    assert r.json()["status"] == "expired"


def test_check_rejects_domain_mismatch(seeded):
    r = seeded.post(
        "/api/check",
        json={"license_key": "ACTIVE-KEY", "domain": "other.test"},
    )
    assert r.status_code == 200
    assert r.json()["status"] == "domain_mismatch"


def test_check_reflects_inactive_status(seeded):
    r = seeded.post(
        "/api/check",
        json={"license_key": "INACTIVE-KEY", "domain": "example.test"},
    )
    assert r.status_code == 200
    assert r.json()["status"] == "inactive"


def test_admin_requires_auth(client):
    assert client.get("/admin/licenses").status_code == 401
    assert client.get("/admin/licenses", auth=("admin", "wrong")).status_code == 401


def test_admin_503_when_token_unset(tmp_path, monkeypatch):
    c, _ = _fresh_client(tmp_path, monkeypatch, admin_token=None)
    r = c.get("/admin/licenses", auth=("admin", "anything"))
    assert r.status_code == 503


def test_admin_crud_roundtrip(client):
    auth = ("admin", "test-token")

    create = client.post(
        "/admin/licenses",
        auth=auth,
        json={"license_key": "NEW-KEY", "domain": "a.test"},
    )
    assert create.status_code == 201
    assert create.json()["license_key"] == "NEW-KEY"

    # duplicate key is rejected
    dup = client.post(
        "/admin/licenses",
        auth=auth,
        json={"license_key": "NEW-KEY", "domain": "a.test"},
    )
    assert dup.status_code == 409

    listed = client.get("/admin/licenses", auth=auth)
    assert listed.status_code == 200
    assert any(item["license_key"] == "NEW-KEY" for item in listed.json()["items"])

    read = client.get("/admin/licenses/NEW-KEY", auth=auth)
    assert read.status_code == 200
    assert read.json()["status"] == "active"

    patch = client.patch(
        "/admin/licenses/NEW-KEY",
        auth=auth,
        json={"status": "inactive", "plan": "pro"},
    )
    assert patch.status_code == 200
    assert patch.json()["status"] == "inactive"
    assert patch.json()["plan"] == "pro"

    delete = client.delete("/admin/licenses/NEW-KEY", auth=auth)
    assert delete.status_code == 204

    missing = client.get("/admin/licenses/NEW-KEY", auth=auth)
    assert missing.status_code == 404

    patch_missing = client.patch(
        "/admin/licenses/NOPE",
        auth=auth,
        json={"status": "inactive"},
    )
    assert patch_missing.status_code == 404

    delete_missing = client.delete("/admin/licenses/NOPE", auth=auth)
    assert delete_missing.status_code == 404


def test_ui_requires_auth(client):
    # Unauthenticated UI requests are rejected with 401 (no redirect loop).
    assert client.get("/admin/ui/licenses").status_code == 401
    assert client.get("/admin/ui/licenses/new").status_code == 401
    assert client.post(
        "/admin/ui/licenses",
        data={"license_key": "X", "domain": "y.test"},
    ).status_code == 401


def test_ui_root_redirects_to_list(client):
    auth = ("admin", "test-token")
    r = client.get("/admin/ui", auth=auth, follow_redirects=False)
    assert r.status_code == 303
    assert r.headers["location"] == "/admin/ui/licenses"


def test_ui_list_renders_html(client):
    auth = ("admin", "test-token")
    r = client.get("/admin/ui/licenses", auth=auth)
    assert r.status_code == 200
    assert r.headers["content-type"].startswith("text/html")
    assert "日報マン ライセンスサーバー" in r.text
    assert "ライセンスがありません" in r.text


def test_ui_pages_expose_contextual_help(client):
    auth = ("admin", "test-token")

    # List page: the ? button is in the header, and the dialog body
    # talks about the columns ("キー" / "状態") that page actually shows.
    list_page = client.get("/admin/ui/licenses", auth=auth)
    assert 'class="help-button"' in list_page.text
    assert 'id="help-dialog"' in list_page.text
    assert "ライセンス一覧の使い方" in list_page.text
    assert "各カラムの意味" in list_page.text

    # New / edit pages: the help is form-field specific, so it must NOT
    # be the list-page copy.
    new_page = client.get("/admin/ui/licenses/new", auth=auth)
    assert "ライセンス作成の使い方" in new_page.text
    assert "各項目の入力ルール" in new_page.text
    assert "各カラムの意味" not in new_page.text

    client.post(
        "/admin/ui/licenses",
        auth=auth,
        data={"license_key": "HELP-KEY", "domain": "ui.test"},
    )
    edit_page = client.get("/admin/ui/licenses/HELP-KEY/edit", auth=auth)
    assert "ライセンス編集の使い方" in edit_page.text


def test_ui_create_via_form(client):
    auth = ("admin", "test-token")
    r = client.post(
        "/admin/ui/licenses",
        auth=auth,
        data={
            "license_key": "UI-KEY",
            "domain": "ui.test",
            "plan": "pro",
            "status": "active",
            "expires_at": "2099-12-31T23:59:59+00:00",
        },
        follow_redirects=False,
    )
    assert r.status_code == 303
    assert r.headers["location"] == "/admin/ui/licenses?msg=created"

    listed = client.get("/admin/ui/licenses?msg=created", auth=auth)
    assert listed.status_code == 200
    assert "UI-KEY" in listed.text
    assert "作成しました" in listed.text


def test_ui_create_duplicate_flashes_conflict(client):
    auth = ("admin", "test-token")
    client.post(
        "/admin/ui/licenses",
        auth=auth,
        data={"license_key": "DUP", "domain": "ui.test"},
    )
    r = client.post(
        "/admin/ui/licenses",
        auth=auth,
        data={"license_key": "DUP", "domain": "ui.test"},
        follow_redirects=False,
    )
    assert r.status_code == 303
    assert "msg=conflict" in r.headers["location"]


def test_ui_edit_and_update(client):
    auth = ("admin", "test-token")
    client.post(
        "/admin/ui/licenses",
        auth=auth,
        data={"license_key": "EDIT-KEY", "domain": "before.test"},
    )

    form = client.get("/admin/ui/licenses/EDIT-KEY/edit", auth=auth)
    assert form.status_code == 200
    assert 'value="before.test"' in form.text
    assert 'value="EDIT-KEY"' in form.text

    update = client.post(
        "/admin/ui/licenses/EDIT-KEY/edit",
        auth=auth,
        data={
            "license_key": "EDIT-KEY",
            "domain": "after.test",
            "plan": "basic",
            "status": "inactive",
            "expires_at": "",
        },
        follow_redirects=False,
    )
    assert update.status_code == 303
    assert "msg=updated" in update.headers["location"]

    reread = client.get("/admin/licenses/EDIT-KEY", auth=auth)
    assert reread.json()["domain"] == "after.test"
    assert reread.json()["status"] == "inactive"


def test_ui_edit_missing_redirects_with_flash(client):
    auth = ("admin", "test-token")
    r = client.get("/admin/ui/licenses/NOPE/edit", auth=auth, follow_redirects=False)
    assert r.status_code == 303
    assert "msg=not_found" in r.headers["location"]


def test_ui_delete_roundtrip(client):
    auth = ("admin", "test-token")
    client.post(
        "/admin/ui/licenses",
        auth=auth,
        data={"license_key": "DEL", "domain": "ui.test"},
    )
    r = client.post(
        "/admin/ui/licenses/DEL/delete",
        auth=auth,
        follow_redirects=False,
    )
    assert r.status_code == 303
    assert "msg=deleted" in r.headers["location"]
    assert client.get("/admin/licenses/DEL", auth=auth).status_code == 404


def test_rotate_archives_previous_and_keeps_old_signatures_valid(client):
    auth = ("admin", "test-token")

    # Capture original public key and a signature minted under it.
    original_pub = client.get("/api/public-key").json()["public_key"]
    client.post(
        "/admin/licenses",
        auth=auth,
        json={"license_key": "ROT-KEY", "domain": "rot.test"},
    )
    first = client.post(
        "/api/check",
        json={"license_key": "ROT-KEY", "domain": "rot.test"},
    ).json()
    first_sig = first["signature"]

    # Rotate. The new public key differs and the old one moves to previous.
    rot = client.post("/admin/rotate-signing-key", auth=auth)
    assert rot.status_code == 200
    body = rot.json()
    assert body["public_key"] != original_pub
    assert original_pub in body["previous_keys"]

    pk = client.get("/api/public-key").json()
    assert pk["public_key"] == body["public_key"]
    assert original_pub in pk["previous_keys"]

    # New signatures use the new key.
    second = client.post(
        "/api/check",
        json={"license_key": "ROT-KEY", "domain": "rot.test"},
    ).json()
    assert second["signature"] != first_sig

    # Old signatures still verify because the previous key is archived.
    from app import signing
    payload_first = {k: v for k, v in first.items() if k != "signature"}
    payload_second = {k: v for k, v in second.items() if k != "signature"}
    assert signing.verify(payload_first, first_sig) is True
    assert signing.verify(payload_second, second["signature"]) is True


def test_rotate_caps_previous_keys(client):
    auth = ("admin", "test-token")
    pubs = [client.get("/api/public-key").json()["public_key"]]
    # Rotate enough times to overflow the cap.
    from app.signing import MAX_PREVIOUS_KEYS

    for _ in range(MAX_PREVIOUS_KEYS + 2):
        body = client.post("/admin/rotate-signing-key", auth=auth).json()
        pubs.append(body["public_key"])

    pk = client.get("/api/public-key").json()
    assert len(pk["previous_keys"]) == MAX_PREVIOUS_KEYS
    # Most recent rotations are kept; the oldest got evicted.
    assert pubs[-2] in pk["previous_keys"]
    assert pubs[0] not in pk["previous_keys"]


def test_rotate_requires_admin(client):
    assert client.post("/admin/rotate-signing-key").status_code == 401
    assert client.post(
        "/admin/rotate-signing-key", auth=("admin", "wrong")
    ).status_code == 401


def test_init_db_migrates_legacy_standard_plan_to_basic(tmp_path, monkeypatch):
    """旧 `standard` plan 値を持つ DB が `init_db` で `basic` に
    マイグレートされること。プラグイン側 (#129) は basic / pro
    の 2 値だけを認識するので、過去サーバから上げた環境でも
    名前が自動で合うように。"""
    import sqlite3

    db_path = tmp_path / "legacy.sqlite"
    # 旧スキーマ相当の最小限のテーブル + standard プランのレコー
    # ドを直接書き込んでから init_db を走らせる。
    conn = sqlite3.connect(str(db_path))
    conn.executescript(
        """
        CREATE TABLE licenses (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            license_key TEXT NOT NULL UNIQUE,
            domain      TEXT NOT NULL,
            plan        TEXT NOT NULL DEFAULT 'standard',
            status      TEXT NOT NULL DEFAULT 'active',
            expires_at  TEXT,
            created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        INSERT INTO licenses (license_key, domain, plan, status)
            VALUES ('LEGACY-1', 'old.test', 'standard', 'active'),
                   ('LEGACY-2', 'newer.test', 'pro',    'active');
        """
    )
    conn.commit()
    conn.close()

    # `_fresh_client` 経由で init_db を実行(import 副作用)。
    monkeypatch.setenv("DRWP_LICENSE_DB", str(db_path))
    monkeypatch.setenv("DRWP_SIGNING_KEY", str(tmp_path / "t.key"))
    for name in ("app.main", "app.db", "app.signing", "app"):
        sys.modules.pop(name, None)
    importlib.import_module("app.main")

    conn = sqlite3.connect(str(db_path))
    conn.row_factory = sqlite3.Row
    rows = {r["license_key"]: r["plan"] for r in conn.execute("SELECT license_key, plan FROM licenses")}
    conn.close()
    assert rows["LEGACY-1"] == "basic"
    # pro 行はそのまま。
    assert rows["LEGACY-2"] == "pro"


# --- 監査ログ + レート制限 + 自動ローテーション -----------------------

def test_failed_login_writes_audit_row(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    r = c.get("/admin/licenses", auth=("admin", "wrong-pass"))
    assert r.status_code == 401

    rows = main.db.recent_audit(limit=10)
    events = [row["event"] for row in rows]
    assert "login_failed" in events
    failed = next(row for row in rows if row["event"] == "login_failed")
    assert failed["username"] == "admin"  # the attempted user


def test_successful_login_writes_audit_row(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    r = c.get("/admin/licenses", auth=("admin", "test-token"))
    assert r.status_code == 200

    rows = main.db.recent_audit(limit=10)
    events = [row["event"] for row in rows]
    assert "login_success" in events


def test_anonymous_probe_is_not_audited(tmp_path, monkeypatch):
    # ブラウザは Basic ダイアログを出す前に必ず 1 回ノークレデンシャル
    # でアクセスしてくる。ここを記録するとログがゴミで溢れるので、
    # credentials=None の 401 は audit に残さない仕様。
    c, main = _fresh_client(tmp_path, monkeypatch)
    r = c.get("/admin/licenses")
    assert r.status_code == 401

    rows = main.db.recent_audit(limit=10)
    assert rows == []


def test_rate_limiter_blocks_after_threshold(tmp_path, monkeypatch):
    # しきい値・ウィンドウを小さくしてテスト時間を短縮。
    monkeypatch.setenv("DRWP_LOGIN_FAIL_LIMIT", "3")
    monkeypatch.setenv("DRWP_LOGIN_FAIL_WINDOW", "60")
    monkeypatch.setenv("DRWP_LOGIN_BLOCK_SECONDS", "60")
    c, main = _fresh_client(tmp_path, monkeypatch)

    # 3 回失敗まで通常の 401。
    for _ in range(3):
        r = c.get("/admin/licenses", auth=("admin", "wrong"))
        assert r.status_code == 401

    # 4 回目は 429 で遮断され、正しい資格情報でも入れない。
    r = c.get("/admin/licenses", auth=("admin", "wrong"))
    assert r.status_code == 429
    assert r.headers.get("Retry-After") == "60"

    r2 = c.get("/admin/licenses", auth=("admin", "test-token"))
    assert r2.status_code == 429

    rows = main.db.recent_audit(limit=20)
    events = [row["event"] for row in rows]
    assert "login_blocked" in events


def test_manual_signing_rotation_writes_audit_row(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    r = c.post("/admin/ui/settings/rotate-signing",
               auth=("admin", "test-token"), follow_redirects=False)
    assert r.status_code in (200, 303)

    rows = main.db.recent_audit(limit=10)
    events = [row["event"] for row in rows]
    assert "signing_rotated_manual" in events


def test_audit_retention_purge_drops_old_rows(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    # 直接 SQL で「100 日前の行」を捏造して purge_audit が落とすことを確認。
    with main.db.connection() as conn:
        conn.execute(
            "INSERT INTO audit_log (ts, event, ip, username, detail) "
            "VALUES (datetime('now', '-100 days'), 'login_failed', '1.1.1.1', 'old', '')"
        )
        conn.execute(
            "INSERT INTO audit_log (ts, event, ip, username, detail) "
            "VALUES (datetime('now', '-1 days'), 'login_failed', '1.1.1.1', 'recent', '')"
        )
    n = main.db.purge_audit(90)
    assert n == 1
    remaining = main.db.recent_audit(limit=10)
    usernames = [row["username"] for row in remaining]
    assert "old" not in usernames
    assert "recent" in usernames


def test_purge_with_zero_days_is_noop(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    with main.db.connection() as conn:
        conn.execute(
            "INSERT INTO audit_log (ts, event, ip, username) "
            "VALUES (datetime('now', '-9999 days'), 'login_failed', '1.1.1.1', 'x')"
        )
    assert main.db.purge_audit(0) == 0
    assert len(main.db.recent_audit()) == 1


def test_canonical_form_is_sorted_compact_utf8(tmp_path, monkeypatch):
    # The canonical form is the bytes PHP (or any verifier) must reproduce:
    # keys sorted by string order, no whitespace, unescaped UTF-8 and slashes.
    # A PHP verifier gets the same bytes by doing
    # ksort($arr, SORT_STRING) and json_encode($arr,
    # JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).
    monkeypatch.setenv("DRWP_SIGNING_KEY", str(tmp_path / "t.key"))
    for name in ("app.signing", "app"):
        sys.modules.pop(name, None)
    from app import signing

    payload = {"b": "2", "a": "1", "c": "日本", "url": "https://example.test/x"}
    bytes_ = signing.canonical(payload)
    assert bytes_ == b'{"a":"1","b":"2","c":"\xe6\x97\xa5\xe6\x9c\xac","url":"https://example.test/x"}'
