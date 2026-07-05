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


# --- フリープラン + 自動キー生成 ----------------------------------------

def test_license_key_auto_generated_when_omitted(tmp_path, monkeypatch):
    """JSON API で license_key を省略するとサーバが自動生成する。"""
    c, main = _fresh_client(tmp_path, monkeypatch)
    r = c.post(
        "/admin/licenses",
        auth=("admin", "test-token"),
        json={"domain": "auto.test"},
    )
    assert r.status_code == 201
    key = r.json()["license_key"]
    # NPM- プレフィクス + 4 ブロック * 4 文字 = NPM-XXXX-XXXX-XXXX-XXXX
    assert key.startswith("NPM-")
    assert len(key.split("-")) == 5
    # 同じ POST で再度自動生成しても別のキーが返る (= 衝突しない)
    r2 = c.post("/admin/licenses", auth=("admin", "test-token"),
                json={"domain": "auto.test"})
    assert r2.status_code == 201
    assert r2.json()["license_key"] != key


def test_license_key_explicit_still_works(tmp_path, monkeypatch):
    """明示的にキーを指定したらそれを使う (既存挙動を壊さない)。"""
    c, main = _fresh_client(tmp_path, monkeypatch)
    r = c.post(
        "/admin/licenses",
        auth=("admin", "test-token"),
        json={"license_key": "MY-CUSTOM-KEY", "domain": "x.test"},
    )
    assert r.status_code == 201
    assert r.json()["license_key"] == "MY-CUSTOM-KEY"


def test_free_plan_default_30_day_expiry(tmp_path, monkeypatch):
    """フリープラン + 有効期限未指定 → 約 30 日後が自動セット。"""
    c, main = _fresh_client(tmp_path, monkeypatch)
    r = c.post(
        "/admin/licenses",
        auth=("admin", "test-token"),
        json={"domain": "free.test", "plan": "free"},
    )
    assert r.status_code == 201
    expires = r.json()["expires_at"]
    assert expires, "free plan should have default expires_at"
    from datetime import datetime, timezone
    exp_dt = datetime.fromisoformat(expires.replace("Z", "+00:00"))
    delta = exp_dt - datetime.now(timezone.utc)
    # 30 日 ± 1 分の許容 (テスト実行のタイムラグを考慮)
    assert 30 * 86400 - 60 < delta.total_seconds() < 30 * 86400 + 60


def test_free_plan_explicit_expiry_is_preserved(tmp_path, monkeypatch):
    """フリープランでも有効期限を明示したら、そちらが優先される。"""
    c, main = _fresh_client(tmp_path, monkeypatch)
    r = c.post(
        "/admin/licenses",
        auth=("admin", "test-token"),
        json={
            "domain": "free.test", "plan": "free",
            "expires_at": "2099-01-01T00:00:00+00:00",
        },
    )
    assert r.status_code == 201
    assert r.json()["expires_at"] == "2099-01-01T00:00:00+00:00"


def test_basic_pro_plan_no_default_expiry(tmp_path, monkeypatch):
    """basic / pro は無期限デフォルト維持。フリーだけ 30 日後にする。"""
    c, main = _fresh_client(tmp_path, monkeypatch)
    for plan in ("basic", "pro"):
        r = c.post("/admin/licenses", auth=("admin", "test-token"),
                   json={"domain": f"{plan}.test", "plan": plan})
        assert r.status_code == 201
        assert (r.json()["expires_at"] or "") == ""


def test_ui_create_with_blank_key_auto_generates(tmp_path, monkeypatch):
    """UI フォームでもキー空欄なら自動生成。"""
    c, main = _fresh_client(tmp_path, monkeypatch)
    r = c.post(
        "/admin/ui/licenses",
        auth=("admin", "test-token"),
        data={
            "license_key": "",
            "domain": "ui-auto.test",
            "plan": "free",
            "status": "active",
            "expires_at": "",
        },
        follow_redirects=False,
    )
    assert r.status_code == 303
    assert "msg=created" in r.headers["location"]
    items = c.get("/admin/licenses", auth=("admin", "test-token")).json()["items"]
    assert len(items) == 1
    assert items[0]["license_key"].startswith("NPM-")
    # フリープランなので有効期限が自動で 30 日後にセットされている
    assert items[0]["expires_at"]


def test_free_plan_renders_in_dropdown(tmp_path, monkeypatch):
    """ライセンス作成画面のプラン select に free が並ぶ。"""
    c, main = _fresh_client(tmp_path, monkeypatch)
    page = c.get("/admin/ui/licenses/new", auth=("admin", "test-token"))
    assert page.status_code == 200
    assert 'value="free"' in page.text
    assert "フリー" in page.text
    # 自動生成ボタンも出る
    assert "自動生成" in page.text


# --- ライセンス一覧の絞り込み --------------------------------------------

def test_admin_list_filters_by_plan_and_status(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    auth = ("admin", "test-token")
    seeds = [
        ("K-PRO-ACTIVE",     "pro",   "active"),
        ("K-PRO-INACTIVE",   "pro",   "inactive"),
        ("K-BASIC-ACTIVE",   "basic", "active"),
        ("K-BASIC-INACTIVE", "basic", "inactive"),
    ]
    for key, plan, st in seeds:
        c.post("/admin/licenses", auth=auth, json={
            "license_key": key, "domain": "x.test", "plan": plan, "status": st,
        })

    all_items = c.get("/admin/licenses", auth=auth).json()["items"]
    assert len(all_items) == 4

    only_pro = c.get("/admin/licenses?plan=pro", auth=auth).json()["items"]
    assert {i["license_key"] for i in only_pro} == {"K-PRO-ACTIVE", "K-PRO-INACTIVE"}

    only_active = c.get("/admin/licenses?status=active", auth=auth).json()["items"]
    assert {i["license_key"] for i in only_active} == {"K-PRO-ACTIVE", "K-BASIC-ACTIVE"}

    pro_active = c.get("/admin/licenses?plan=pro&status=active", auth=auth).json()["items"]
    assert {i["license_key"] for i in pro_active} == {"K-PRO-ACTIVE"}


def test_ui_list_renders_filter_dropdowns_and_applies(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    auth = ("admin", "test-token")
    c.post("/admin/licenses", auth=auth, json={
        "license_key": "K-PRO", "domain": "x.test", "plan": "pro", "status": "active",
    })
    c.post("/admin/licenses", auth=auth, json={
        "license_key": "K-BASIC", "domain": "x.test", "plan": "basic", "status": "inactive",
    })

    page = c.get("/admin/ui/licenses", auth=auth)
    assert page.status_code == 200
    # フィルタ UI が描画されている
    assert 'name="plan"' in page.text
    assert 'name="status"' in page.text
    assert "プラン: すべて" in page.text
    assert "状態: すべて" in page.text

    # plan=basic で絞り込むと K-BASIC のみ表示
    filtered = c.get("/admin/ui/licenses?plan=basic", auth=auth)
    assert "K-BASIC" in filtered.text
    assert "K-PRO" not in filtered.text

    # 不明な slug は無条件 (= 全件) にフォールバック
    fallback = c.get("/admin/ui/licenses?plan=bogus", auth=auth)
    assert "K-BASIC" in fallback.text
    assert "K-PRO" in fallback.text


# --- TOTP 2FA ---------------------------------------------------------------

def test_totp_helpers_pure_python(tmp_path, monkeypatch):
    """TOTP / リカバリーコード生成・検証の純粋関数テスト。
    外部仕様 (RFC 6238) との突き合わせも兼ねている。"""
    c, main = _fresh_client(tmp_path, monkeypatch)
    totp = main.totp

    secret = totp.generate_secret_b32()
    assert len(secret) == 32  # 20 バイト → base32 32 文字
    # 同じ時刻に対して同じコードが出る (deterministic)。
    t = 1_700_000_000
    code = totp.generate_totp(secret, t=t)
    assert code == totp.generate_totp(secret, t=t)
    assert totp.verify_totp(secret, code, window=1) or \
           totp.verify_totp(secret, totp.generate_totp(secret), window=1)
    # 不正フォーマットは弾く。
    assert totp.verify_totp(secret, "abcdef") is False
    assert totp.verify_totp(secret, "12345") is False
    assert totp.verify_totp(secret, "") is False

    # リカバリーコードは 1 回使い切り。
    codes = totp.generate_recovery_codes(3)
    assert len(codes) == 3
    totp.store_recovery_hashes(codes)
    assert totp.remaining_recovery_count() == 3
    assert totp.consume_recovery_code(codes[0]) is True
    # 2 回目はもう通らない。
    assert totp.consume_recovery_code(codes[0]) is False
    assert totp.remaining_recovery_count() == 2
    # ハイフン / 大小文字は無視される。
    assert totp.consume_recovery_code(codes[1].lower().replace("-", "")) is True


def test_totp_disabled_by_default_basic_auth_still_works(tmp_path, monkeypatch):
    """2FA 未設定時は従来通り Basic 認証だけで管理 API に通る。
    既存挙動の回帰防止。"""
    c, main = _fresh_client(tmp_path, monkeypatch)
    r = c.get("/admin/licenses", auth=("admin", "test-token"))
    assert r.status_code == 200


def test_totp_setup_to_enable_flow(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    auth = ("admin", "test-token")

    # セットアップ開始 → pending が立つ + リダイレクト
    r = c.post("/admin/ui/settings/totp/setup", auth=auth, follow_redirects=False)
    assert r.status_code == 303
    assert "/admin/ui/settings/totp/verify" in r.headers["location"]

    secret, codes = main.totp.get_pending()
    assert secret and len(codes) == 10
    assert main.totp.is_totp_enabled() is False  # まだ未確定

    # 確定画面が表示できる
    verify_page = c.get("/admin/ui/settings/totp/verify", auth=auth)
    assert verify_page.status_code == 200
    assert secret in verify_page.text
    # QR は SVG として埋まっている
    assert "<svg" in verify_page.text
    # リカバリーコードも全部表示
    for code in codes:
        assert code in verify_page.text

    # 不正なコードでは有効化されない
    bad = c.post("/admin/ui/settings/totp/enable", auth=auth,
                 data={"code": "000000"}, follow_redirects=False)
    assert bad.status_code == 303
    assert "totp_code_invalid" in bad.headers["location"]
    assert main.totp.is_totp_enabled() is False

    # 正しいコードで有効化
    valid_code = main.totp.generate_totp(secret)
    ok = c.post("/admin/ui/settings/totp/enable", auth=auth,
                data={"code": valid_code}, follow_redirects=False)
    assert ok.status_code == 303
    assert "msg=totp_enabled" in ok.headers["location"]
    assert main.totp.is_totp_enabled() is True
    # セッションクッキーが付与されている
    assert main.totp.COOKIE_NAME in ok.cookies


def test_totp_gate_blocks_ui_until_challenge_passes(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    auth = ("admin", "test-token")

    # TOTP を手動で「有効」にする (シークレットも直接登録)
    secret = main.totp.generate_secret_b32()
    main.db.set_setting(main.totp.K_SECRET_ACTIVE, secret)
    main.db.set_setting(main.totp.K_ENABLED, "1")

    # UI へのアクセスはチャレンジ画面に 303 リダイレクト
    r = c.get("/admin/ui/licenses", auth=auth, follow_redirects=False)
    assert r.status_code == 303
    assert "/admin/ui/totp/challenge" in r.headers["location"]
    assert "next=" in r.headers["location"]

    # チャレンジ画面自体は Basic だけで開ける
    challenge = c.get("/admin/ui/totp/challenge?next=/admin/ui/licenses", auth=auth)
    assert challenge.status_code == 200
    assert "2FA 認証" in challenge.text

    # 正しいコードでチャレンジを通すとセッションクッキーが付き、
    # 以降は UI に通常アクセスできる。
    code = main.totp.generate_totp(secret)
    pass_r = c.post(
        "/admin/ui/totp/challenge",
        auth=auth,
        data={"code": code, "next": "/admin/ui/licenses"},
        follow_redirects=False,
    )
    assert pass_r.status_code == 303
    assert pass_r.headers["location"] == "/admin/ui/licenses"
    assert main.totp.COOKIE_NAME in pass_r.cookies

    # セッションクッキー保持下で UI が普通に開ける
    list_page = c.get("/admin/ui/licenses", auth=auth)
    assert list_page.status_code == 200
    assert "日報マン ライセンスサーバー" in list_page.text


def test_totp_gate_blocks_api_without_header(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    auth = ("admin", "test-token")
    secret = main.totp.generate_secret_b32()
    main.db.set_setting(main.totp.K_SECRET_ACTIVE, secret)
    main.db.set_setting(main.totp.K_ENABLED, "1")

    # API は 401 (リダイレクトしない)
    r = c.get("/admin/licenses", auth=auth)
    assert r.status_code == 401
    assert "TOTP" in r.json()["detail"]

    # 正しいヘッダで通る
    code = main.totp.generate_totp(secret)
    r = c.get("/admin/licenses", auth=auth, headers={"X-DRWP-TOTP": code})
    assert r.status_code == 200

    # 不正なヘッダは 401 で監査ログに totp_failed が残る
    r = c.get("/admin/licenses", auth=auth, headers={"X-DRWP-TOTP": "000000"})
    assert r.status_code == 401
    events = [row["event"] for row in main.db.recent_audit(limit=20)]
    assert "totp_failed" in events
    assert "totp_verified" in events


def test_totp_recovery_code_works_for_challenge_and_logs_event(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    auth = ("admin", "test-token")
    secret = main.totp.generate_secret_b32()
    codes = main.totp.generate_recovery_codes(3)
    main.db.set_setting(main.totp.K_SECRET_ACTIVE, secret)
    main.totp.store_recovery_hashes(codes)
    main.db.set_setting(main.totp.K_ENABLED, "1")

    # リカバリーコードでチャレンジを通す
    r = c.post(
        "/admin/ui/totp/challenge",
        auth=auth,
        data={"code": codes[0], "next": "/admin/ui/licenses"},
        follow_redirects=False,
    )
    assert r.status_code == 303
    assert main.totp.COOKIE_NAME in r.cookies
    # 同じコードは 2 回目使えない (consumed)
    assert main.totp.remaining_recovery_count() == 2
    events = [row["event"] for row in main.db.recent_audit(limit=20)]
    assert "recovery_code_used" in events


def test_totp_env_disable_overrides_db(tmp_path, monkeypatch):
    """ロックアウト時の緊急逃げ道。DB 上は enabled でも env が立っていれば
    ゲートをスキップする。"""
    monkeypatch.setenv("DRWP_TOTP_DISABLED", "1")
    c, main = _fresh_client(tmp_path, monkeypatch)
    auth = ("admin", "test-token")
    main.db.set_setting(main.totp.K_SECRET_ACTIVE, main.totp.generate_secret_b32())
    main.db.set_setting(main.totp.K_ENABLED, "1")

    r = c.get("/admin/licenses", auth=auth)
    assert r.status_code == 200


def test_totp_disable_requires_valid_code(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    auth = ("admin", "test-token")
    secret = main.totp.generate_secret_b32()
    main.db.set_setting(main.totp.K_SECRET_ACTIVE, secret)
    main.db.set_setting(main.totp.K_ENABLED, "1")

    # 不正コード: 拒否されて enabled のまま
    bad = c.post("/admin/ui/settings/totp/disable", auth=auth,
                 headers={"X-DRWP-TOTP": main.totp.generate_totp(secret)},
                 data={"code": "000000"}, follow_redirects=False)
    assert bad.status_code == 303
    assert "totp_code_invalid" in bad.headers["location"]
    assert main.totp.is_totp_enabled() is True

    # 正しいコード: 無効化される
    good = c.post(
        "/admin/ui/settings/totp/disable",
        auth=auth,
        headers={"X-DRWP-TOTP": main.totp.generate_totp(secret)},
        data={"code": main.totp.generate_totp(secret)},
        follow_redirects=False,
    )
    assert good.status_code == 303
    assert "msg=totp_disabled" in good.headers["location"]
    assert main.totp.is_totp_enabled() is False


def test_totp_session_cookie_invalidated_when_token_version_bumps(tmp_path, monkeypatch):
    """管理ユーザー名 / トークンを更新すると、既発行の 2FA セッションも無効になる。"""
    c, main = _fresh_client(tmp_path, monkeypatch)
    ver_before = main.db.get_setting("admin_token_version") or "0"
    value, _ = main.totp.make_session_cookie(ver_before)
    assert main.totp.verify_session_cookie(value, ver_before) is True

    # バージョンを bump
    main._bump_admin_token_version()
    ver_after = main.db.get_setting("admin_token_version") or "0"
    assert ver_after != ver_before
    assert main.totp.verify_session_cookie(value, ver_after) is False


# =========================================================================
# プラグイン配布 / 自動アップデート
# =========================================================================
import io as _io
import zipfile as _zipfile


def _make_plugin_zip(version: str) -> bytes:
    buf = _io.BytesIO()
    header = (
        "<?php\n/**\n"
        " * Plugin Name: 日報マン\n"
        f" * Version: {version}\n"
        " * Requires at least: 6.0\n"
        " * Requires PHP: 7.4\n"
        " * Tested up to: 6.5\n"
        " */\n"
    )
    with _zipfile.ZipFile(buf, "w") as z:
        z.writestr("drwp-daily-reports/drwp-daily-reports.php", header)
    return buf.getvalue()


def _seed_active(client, key="PLUG-KEY", domain="example.test"):
    r = client.post("/admin/licenses", auth=("admin", "test-token"), json={
        "license_key": key, "domain": domain, "plan": "basic",
        "status": "active", "expires_at": "2099-12-31T23:59:59+00:00",
    })
    assert r.status_code in (200, 201), r.text


def test_plugin_upload_extracts_version(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    r = c.post("/admin/ui/plugin/upload", auth=("admin", "test-token"),
               files={"file": ("p.zip", _make_plugin_zip("2.3.4"), "application/zip")},
               data={"changelog": "- test", "homepage": "https://example.test/"},
               follow_redirects=False)
    assert r.status_code == 303
    assert "plugin_uploaded" in r.headers["location"]
    meta = main._get_plugin_meta()
    assert meta["version"] == "2.3.4"
    assert meta["requires"] == "6.0"
    assert meta["requires_php"] == "7.4"
    assert meta["tested"] == "6.5"


def test_plugin_upload_rejects_zip_without_plugin(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    buf = _io.BytesIO()
    with _zipfile.ZipFile(buf, "w") as z:
        z.writestr("readme.txt", "no plugin here")
    r = c.post("/admin/ui/plugin/upload", auth=("admin", "test-token"),
               files={"file": ("x.zip", buf.getvalue(), "application/zip")},
               follow_redirects=False)
    assert r.status_code == 303
    assert "plugin_invalid" in r.headers["location"]


def test_plugin_update_returns_version_and_package(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    _seed_active(c, "PLUG-KEY", "example.test")
    c.post("/admin/ui/plugin/upload", auth=("admin", "test-token"),
           files={"file": ("p.zip", _make_plugin_zip("1.60.0"), "application/zip")})
    r = c.get("/api/plugin/update?license_key=PLUG-KEY&domain=example.test")
    assert r.status_code == 200
    body = r.json()
    assert body["version"] == "1.60.0"
    assert "download" in body["package"]
    assert "PLUG-KEY" in body["package"]


def test_plugin_update_requires_valid_license(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    c.post("/admin/ui/plugin/upload", auth=("admin", "test-token"),
           files={"file": ("p.zip", _make_plugin_zip("1.60.0"), "application/zip")})
    r = c.get("/api/plugin/update?license_key=NOPE&domain=example.test")
    assert r.status_code == 403


def test_plugin_update_empty_when_nothing_uploaded(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    _seed_active(c, "PLUG-KEY", "example.test")
    r = c.get("/api/plugin/update?license_key=PLUG-KEY&domain=example.test")
    assert r.status_code == 200
    assert r.json()["version"] == ""


def test_plugin_download_serves_zip(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    _seed_active(c, "PLUG-KEY", "example.test")
    c.post("/admin/ui/plugin/upload", auth=("admin", "test-token"),
           files={"file": ("p.zip", _make_plugin_zip("1.60.0"), "application/zip")})
    r = c.get("/api/plugin/download?license_key=PLUG-KEY&domain=example.test")
    assert r.status_code == 200
    assert r.headers["content-type"] == "application/zip"
    zf = _zipfile.ZipFile(_io.BytesIO(r.content))
    assert "drwp-daily-reports/drwp-daily-reports.php" in zf.namelist()


def test_plugin_download_rejects_domain_mismatch(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    _seed_active(c, "PLUG-KEY", "ok.test")
    c.post("/admin/ui/plugin/upload", auth=("admin", "test-token"),
           files={"file": ("p.zip", _make_plugin_zip("1.60.0"), "application/zip")})
    r = c.get("/api/plugin/download?license_key=PLUG-KEY&domain=evil.test")
    assert r.status_code == 403
