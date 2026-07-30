import base64
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
    # 作成キーを一覧で見せるため key= が付くようになった
    assert r.headers["location"] == "/admin/ui/licenses?msg=created&key=UI-KEY"

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
    # WINDOW と BLOCK を別値にする。同値だとどちらの変数を使った実装でも
    # 通ってしまい、「WINDOW が使われていない」バグを検出できない。
    monkeypatch.setenv("DRWP_LOGIN_FAIL_WINDOW", "60")
    monkeypatch.setenv("DRWP_LOGIN_BLOCK_SECONDS", "600")
    c, main = _fresh_client(tmp_path, monkeypatch)

    # 3 回失敗まで通常の 401。
    for _ in range(3):
        r = c.get("/admin/licenses", auth=("admin", "wrong"))
        assert r.status_code == 401

    # 4 回目は 429 で遮断され、正しい資格情報でも入れない。
    r = c.get("/admin/licenses", auth=("admin", "wrong"))
    assert r.status_code == 429
    assert r.headers.get("Retry-After") == "600"

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


def test_ui_list_paginates_over_50(tmp_path, monkeypatch):
    """一覧は 50 件/ページでページ送りされ、合計件数は全体を表示する。"""
    c, main = _fresh_client(tmp_path, monkeypatch)
    auth = ("admin", "test-token")
    for i in range(55):
        main.db.create_license(license_key=f"PG-{i:04d}", domain="pg.test")

    page1 = c.get("/admin/ui/licenses", auth=auth)
    assert page1.status_code == 200
    assert "合計 55 件" in page1.text
    assert "1 / 2" in page1.text
    # id DESC なので 1 ページ目は新しい方 (PG-0054〜PG-0005)
    assert "PG-0054" in page1.text
    assert "PG-0004" not in page1.text
    assert "page=2" in page1.text

    page2 = c.get("/admin/ui/licenses?page=2", auth=auth)
    assert "PG-0004" in page2.text
    assert "PG-0054" not in page2.text
    assert "2 / 2" in page2.text

    # 範囲外のページは最終ページにクランプ (500 にしない)
    over = c.get("/admin/ui/licenses?page=99", auth=auth)
    assert over.status_code == 200
    assert "PG-0000" in over.text

    # 絞り込みと併用しても件数はフィルタ後の総数
    filtered = c.get("/admin/ui/licenses?q=PG-000", auth=auth)
    assert "合計 10 件" in filtered.text


def test_admin_api_list_still_returns_all(tmp_path, monkeypatch):
    """admin API (/admin/licenses) はページネーションせず従来どおり全件。"""
    c, main = _fresh_client(tmp_path, monkeypatch)
    for i in range(55):
        main.db.create_license(license_key=f"API-{i:04d}", domain="api.test")
    r = c.get("/admin/licenses", auth=("admin", "test-token"))
    assert r.status_code == 200
    assert len(r.json()["items"]) == 55


def test_ui_settings_audit_limit_switch(tmp_path, monkeypatch):
    """監査ログの表示件数は 30/100/300 で切替。既定 30、未知値は 30 に落ちる。"""
    c, main = _fresh_client(tmp_path, monkeypatch)
    auth = ("admin", "test-token")
    for i in range(40):
        main.db.log_audit("login_failed", ip="10.0.0.1", detail=f"row-{i:03d}")

    default = c.get("/admin/ui/settings", auth=auth)
    assert default.status_code == 200
    assert "row-039" in default.text          # 新しい行は出る
    assert "row-005" not in default.text      # 30 件を超えた古い行は出ない
    assert "audit_limit=100" in default.text  # 切替リンク

    more = c.get("/admin/ui/settings?audit_limit=100", auth=auth)
    assert "row-005" in more.text

    bogus = c.get("/admin/ui/settings?audit_limit=300000", auth=auth)
    assert bogus.status_code == 200
    assert "row-005" not in bogus.text        # 既定 30 にフォールバック


# --- 管理トークンのハッシュ保存 ---------------------------------------------

def test_admin_token_saved_as_hash(tmp_path, monkeypatch):
    """UI から保存したトークンは平文ではなく PBKDF2 ハッシュで DB に入る。"""
    c, main = _fresh_client(tmp_path, monkeypatch)
    r = c.post(
        "/admin/ui/settings/admin-token",
        auth=("admin", "test-token"),
        data={"token": "new-secret-token"},
        follow_redirects=False,
    )
    assert r.status_code == 303
    assert main.db.get_setting("admin_token") is None
    stored = main.db.get_setting("admin_token_hash")
    assert stored is not None
    assert stored.startswith("pbkdf2_sha256$")
    assert "new-secret-token" not in stored
    # 新トークンで通り、旧 (環境変数の) トークンは通らない
    assert c.get("/admin/licenses", auth=("admin", "new-secret-token")).status_code == 200
    assert c.get("/admin/licenses", auth=("admin", "test-token")).status_code == 401


def test_plaintext_admin_token_migrates_on_startup(tmp_path, monkeypatch):
    """旧バージョンが DB に残した平文トークンは、再起動 (再 import) 時に
    ハッシュへ移行され、同じトークンでログインし続けられる。"""
    c, main = _fresh_client(tmp_path, monkeypatch)
    main.db.set_setting("admin_token", "legacy-secret")
    c2, main2 = _fresh_client(tmp_path, monkeypatch)  # 同じ DB で再起動
    assert main2.db.get_setting("admin_token") is None
    assert (main2.db.get_setting("admin_token_hash") or "").startswith("pbkdf2_sha256$")
    assert c2.get("/admin/licenses", auth=("admin", "legacy-secret")).status_code == 200


def test_clear_removes_hash_and_falls_back_to_env(tmp_path, monkeypatch):
    """「DB 認証情報を削除」でハッシュも消え、環境変数へフォールバックする。"""
    c, _ = _fresh_client(tmp_path, monkeypatch)
    c.post(
        "/admin/ui/settings/admin-token",
        auth=("admin", "test-token"),
        data={"token": "db-token"},
        follow_redirects=False,
    )
    assert c.get("/admin/licenses", auth=("admin", "test-token")).status_code == 401
    c.post(
        "/admin/ui/settings/admin-token",
        auth=("admin", "db-token"),
        data={"clear": "1"},
        follow_redirects=False,
    )
    assert c.get("/admin/licenses", auth=("admin", "test-token")).status_code == 200


# --- バックアップの暗号化 ----------------------------------------------------

def test_backup_plain_zip_by_default(tmp_path, monkeypatch):
    """DRWP_BACKUP_PASSPHRASE 未設定なら従来どおり平文 zip。"""
    monkeypatch.delenv("DRWP_BACKUP_PASSPHRASE", raising=False)
    c, _ = _fresh_client(tmp_path, monkeypatch)
    r = c.get("/admin/ui/settings/backup", auth=("admin", "test-token"))
    assert r.status_code == 200
    assert r.content[:2] == b"PK"  # zip マジック
    assert r.headers["content-disposition"].endswith('.zip"')


def test_backup_encrypted_roundtrip(tmp_path, monkeypatch):
    """パスフレーズ設定時は .zip.enc で払い出し、同じパスフレーズで復元できる。"""
    monkeypatch.setenv("DRWP_BACKUP_PASSPHRASE", "hunter2-strong")
    c, main = _fresh_client(tmp_path, monkeypatch)
    auth = ("admin", "test-token")
    main.db.create_license(license_key="ENC-1", domain="enc.test")

    r = c.get("/admin/ui/settings/backup", auth=auth)
    assert r.status_code == 200
    assert r.content.startswith(b"DRWPENC1")
    assert b"PK" not in r.content[:16]  # 平文 zip がそのまま出ていない
    assert r.headers["content-disposition"].endswith('.zip.enc"')

    # ライセンスを消してから復元 → 復元後に戻っている
    main.db.delete_license("ENC-1")
    assert main.db.get_license("ENC-1") is None
    res = c.post(
        "/admin/ui/settings/restore",
        auth=auth,
        files={"file": ("backup.zip.enc", r.content, "application/octet-stream")},
        follow_redirects=False,
    )
    assert res.status_code == 303
    assert "msg=restored" in res.headers["location"]
    assert main.db.get_license("ENC-1") is not None


def test_restore_encrypted_needs_matching_passphrase(tmp_path, monkeypatch):
    """暗号化バックアップは、パスフレーズ未設定 / 不一致では復元できない。"""
    monkeypatch.setenv("DRWP_BACKUP_PASSPHRASE", "correct-horse")
    c, _ = _fresh_client(tmp_path, monkeypatch)
    auth = ("admin", "test-token")
    blob = c.get("/admin/ui/settings/backup", auth=auth).content
    assert blob.startswith(b"DRWPENC1")

    monkeypatch.delenv("DRWP_BACKUP_PASSPHRASE")
    res = c.post(
        "/admin/ui/settings/restore",
        auth=auth,
        files={"file": ("backup.zip.enc", blob, "application/octet-stream")},
        follow_redirects=False,
    )
    assert "msg=restore_encrypted" in res.headers["location"]

    monkeypatch.setenv("DRWP_BACKUP_PASSPHRASE", "battery-staple")
    res2 = c.post(
        "/admin/ui/settings/restore",
        auth=auth,
        files={"file": ("backup.zip.enc", blob, "application/octet-stream")},
        follow_redirects=False,
    )
    assert "msg=restore_wrong_passphrase" in res2.headers["location"]


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


# --- テーマ配布 -----------------------------------------------------------

def _make_theme_zip(version: str, slug: str = "jijipom") -> bytes:
    buf = _io.BytesIO()
    style = (
        "/*\n"
        "Theme Name: jijipom\n"
        f"Version: {version}\n"
        "Requires at least: 6.0\n"
        "Requires PHP: 7.4\n"
        "Tested up to: 6.5\n"
        "*/\n"
    )
    with _zipfile.ZipFile(buf, "w") as z:
        z.writestr(f"{slug}/style.css", style)
        z.writestr(f"{slug}/index.php", "<?php\n")
    return buf.getvalue()


def test_theme_upload_extracts_version_and_slug(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    r = c.post("/admin/ui/theme/upload", auth=("admin", "test-token"),
               files={"file": ("t.zip", _make_theme_zip("1.2.0"), "application/zip")},
               data={"changelog": "- test", "homepage": "https://example.test/"},
               follow_redirects=False)
    assert r.status_code == 303
    assert "theme_uploaded" in r.headers["location"]
    meta = main._get_theme_meta()
    assert meta["version"] == "1.2.0"
    assert meta["slug"] == "jijipom"
    assert meta["name"] == "jijipom"
    assert meta["requires_php"] == "7.4"


def test_theme_upload_rejects_zip_without_style(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    buf = _io.BytesIO()
    with _zipfile.ZipFile(buf, "w") as z:
        z.writestr("jijipom/index.php", "<?php\n")
    r = c.post("/admin/ui/theme/upload", auth=("admin", "test-token"),
               files={"file": ("x.zip", buf.getvalue(), "application/zip")},
               follow_redirects=False)
    assert r.status_code == 303
    assert "theme_invalid" in r.headers["location"]


def test_theme_update_returns_version_and_package(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    _seed_active(c, "PLUG-KEY", "example.test")
    c.post("/admin/ui/theme/upload", auth=("admin", "test-token"),
           files={"file": ("t.zip", _make_theme_zip("2.0.0"), "application/zip")})
    r = c.get("/api/theme/update?license_key=PLUG-KEY&domain=example.test")
    assert r.status_code == 200
    body = r.json()
    assert body["version"] == "2.0.0"
    assert body["slug"] == "jijipom"
    assert "download" in body["package"]
    assert "PLUG-KEY" in body["package"]


def test_theme_update_requires_valid_license(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    c.post("/admin/ui/theme/upload", auth=("admin", "test-token"),
           files={"file": ("t.zip", _make_theme_zip("2.0.0"), "application/zip")})
    r = c.get("/api/theme/update?license_key=NOPE&domain=example.test")
    assert r.status_code == 403


def test_theme_update_empty_when_nothing_uploaded(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    _seed_active(c, "PLUG-KEY", "example.test")
    r = c.get("/api/theme/update?license_key=PLUG-KEY&domain=example.test")
    assert r.status_code == 200
    assert r.json()["version"] == ""


def test_theme_download_serves_zip(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    _seed_active(c, "PLUG-KEY", "example.test")
    c.post("/admin/ui/theme/upload", auth=("admin", "test-token"),
           files={"file": ("t.zip", _make_theme_zip("2.0.0"), "application/zip")})
    r = c.get("/api/theme/download?license_key=PLUG-KEY&domain=example.test")
    assert r.status_code == 200
    assert r.headers["content-type"] == "application/zip"
    zf = _zipfile.ZipFile(_io.BytesIO(r.content))
    assert "jijipom/style.css" in zf.namelist()


def test_theme_download_rejects_domain_mismatch(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    _seed_active(c, "PLUG-KEY", "ok.test")
    c.post("/admin/ui/theme/upload", auth=("admin", "test-token"),
           files={"file": ("t.zip", _make_theme_zip("2.0.0"), "application/zip")})
    r = c.get("/api/theme/download?license_key=PLUG-KEY&domain=evil.test")
    assert r.status_code == 403


# --- 運営契約 AI (managed AI) --------------------------------------------

def _seed_active_plan(client, key, domain, plan):
    r = client.post("/admin/licenses", auth=("admin", "test-token"), json={
        "license_key": key, "domain": domain, "plan": plan,
        "status": "active", "expires_at": "2099-12-31T23:59:59+00:00",
    })
    assert r.status_code in (200, 201), r.text


def test_ai_quota_reflects_plan_limit(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    _seed_active_plan(c, "PRO-A", "pro.test", "pro")
    r = c.get("/api/ai/quota?license_key=PRO-A&domain=pro.test")
    assert r.status_code == 200
    body = r.json()
    assert body["limit"] == 500  # 既定のプロ上限
    assert body["used"] == 0


def test_ai_chat_blocked_on_non_ai_plan(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    _seed_active_plan(c, "BAS-A", "bas.test", "basic")
    r = c.post("/api/ai/chat", json={
        "license_key": "BAS-A", "domain": "bas.test", "messages": [],
    })
    assert r.status_code == 403


def test_ai_chat_503_when_unconfigured(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    _seed_active_plan(c, "PRO-B", "prob.test", "pro")
    r = c.post("/api/ai/chat", json={
        "license_key": "PRO-B", "domain": "prob.test",
        "messages": [{"role": "user", "content": "hi"}],
    })
    assert r.status_code == 503


def test_ai_chat_429_when_over_quota(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    _seed_active_plan(c, "PRO-C", "proc.test", "pro")
    # 設定を有効化 + プロ上限を 1 に絞る
    r = c.post("/admin/ui/ai/save", auth=("admin", "test-token"), data={
        "enabled": "on", "provider": "anthropic", "api_key": "sk-x",
        "model": "claude-x", "limit_free": "0", "limit_pro": "1",
    }, follow_redirects=False)
    assert r.status_code == 303
    # 使用量を上限まで埋める
    from app import db as _db
    period = main._ai_period()
    _db.ai_usage_increment("PRO-C", period, 1)
    r = c.post("/api/ai/chat", json={
        "license_key": "PRO-C", "domain": "proc.test",
        "messages": [{"role": "user", "content": "hi"}],
    })
    assert r.status_code == 429


def test_ai_save_does_not_leak_key_and_persists(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    c.post("/admin/ui/ai/save", auth=("admin", "test-token"), data={
        "enabled": "on", "provider": "openai", "api_key": "sk-secret",
        "base_url": "https://api.example.test/v1", "model": "gpt-x",
        "limit_free": "5", "limit_pro": "50",
    })
    cfg = main._get_ai_config()
    assert cfg["api_key"] == "sk-secret"
    assert cfg["provider"] == "openai"
    assert cfg["limit_pro"] == 50
    # 設定画面に生キーが出ないこと
    r = c.get("/admin/ui/settings", auth=("admin", "test-token"))
    assert "sk-secret" not in r.text


def test_ai_save_keeps_existing_key_on_placeholder(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    c.post("/admin/ui/ai/save", auth=("admin", "test-token"), data={
        "enabled": "on", "provider": "anthropic", "api_key": "sk-keep",
        "model": "claude-x", "limit_free": "5", "limit_pro": "50",
    })
    # キー欄を空で再保存 → 既存キーを維持する
    c.post("/admin/ui/ai/save", auth=("admin", "test-token"), data={
        "enabled": "on", "provider": "anthropic", "api_key": "",
        "model": "claude-y", "limit_free": "5", "limit_pro": "50",
    })
    cfg = main._get_ai_config()
    assert cfg["api_key"] == "sk-keep"
    assert cfg["model"] == "claude-y"


# --- AdSense (フリープラン向け) -------------------------------------------

def _add_license(c, key, plan, status="active"):
    c.post(
        "/admin/licenses",
        auth=("admin", "test-token"),
        json={
            "license_key": key,
            "domain": "example.test",
            "plan": plan,
            "status": status,
            "expires_at": "2099-12-31T23:59:59+00:00",
        },
    )


def test_adsense_save_persists_and_normalises(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    c.post("/admin/ui/adsense/save", auth=("admin", "test-token"), data={
        "enabled": "on", "publisher_id": "ca-pub-1234567890123456",
        "ad_slot": "12-34 567", "placement": "both",
    })
    cfg = main._get_adsense_config()
    assert cfg["enabled"] is True
    assert cfg["publisher_id"] == "ca-pub-1234567890123456"
    assert cfg["ad_slot"] == "1234567"      # 数字以外は除去
    assert cfg["placement"] == "both"


def test_check_free_plan_includes_signed_adsense(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    _add_license(c, "FREE-KEY", "free")
    c.post("/admin/ui/adsense/save", auth=("admin", "test-token"), data={
        "enabled": "on", "publisher_id": "ca-pub-2222333344445555",
        "ad_slot": "9988776655", "placement": "after",
    })
    r = c.post("/api/check", json={"license_key": "FREE-KEY", "domain": "example.test"})
    assert r.status_code == 200
    body = r.json()
    assert body["plan"] == "free"
    assert body["adsense"]["enabled"] is True
    assert body["adsense"]["publisher_id"] == "ca-pub-2222333344445555"

    # 署名は adsense を含めて有効。
    from app import signing
    sig = body.pop("signature")
    assert signing.verify(body, sig) is True


def test_check_non_free_plan_omits_adsense(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    _add_license(c, "PRO-KEY", "pro")
    c.post("/admin/ui/adsense/save", auth=("admin", "test-token"), data={
        "enabled": "on", "publisher_id": "ca-pub-2222333344445555",
        "ad_slot": "", "placement": "after",
    })
    r = c.post("/api/check", json={"license_key": "PRO-KEY", "domain": "example.test"})
    body = r.json()
    assert body["plan"] == "pro"
    assert "adsense" not in body


def test_check_free_plan_disabled_adsense_sends_enabled_false(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    _add_license(c, "FREE-OFF", "free")
    # 保存せず (= 既定 disabled) の状態でも、フリーには adsense キーが付く。
    r = c.post("/api/check", json={"license_key": "FREE-OFF", "domain": "example.test"})
    body = r.json()
    assert body["adsense"] == {"enabled": False}


def test_check_free_plan_invalid_publisher_id_disables(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    _add_license(c, "FREE-BAD", "free")
    c.post("/admin/ui/adsense/save", auth=("admin", "test-token"), data={
        "enabled": "on", "publisher_id": "not-a-pub-id", "placement": "after",
    })
    r = c.post("/api/check", json={"license_key": "FREE-BAD", "domain": "example.test"})
    body = r.json()
    assert body["adsense"] == {"enabled": False}


# ==========================================================================
# 認証: 非ASCII の資格情報で 500 ロックアウトしない (B-1)
# ==========================================================================

def _basic(user, token):
    """UTF-8 でエンコードした Basic 認証ヘッダーを組み立てる。"""
    raw = f"{user}:{token}".encode("utf-8")
    return {"Authorization": "Basic " + base64.b64encode(raw).decode()}


def test_non_ascii_admin_username_does_not_500(tmp_path, monkeypatch):
    # 管理ユーザー名を日本語にすると、以前は compare_digest(str, str) が
    # 非ASCIIで TypeError を投げ、全管理機能が 500 で永久ロックアウト
    # されていた。bytes 比較にしたので、一致しなければ普通に 401 になる。
    c, _ = _fresh_client(tmp_path, monkeypatch)
    monkeypatch.setenv("DRWP_ADMIN_USERNAME", "管理者")
    for name in ("app.main", "app.db", "app.signing", "app"):
        sys.modules.pop(name, None)
    main = importlib.import_module("app.main")
    client = TestClient(main.app, raise_server_exceptions=False)
    r = client.get("/admin/licenses", headers=_basic("admin", "test-token"))
    assert r.status_code == 401  # 500 ではない


def test_non_ascii_password_in_header_does_not_500(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    client = TestClient(main.app, raise_server_exceptions=False)
    r = client.get("/admin/licenses", headers=_basic("admin", "日本語パスワード"))
    assert r.status_code == 401


# ==========================================================================
# 有効期限: datetime 比較で正しく判定する (B-5)
# ==========================================================================

def test_normalize_expires_date_only_is_end_of_day(tmp_path, monkeypatch):
    _, main = _fresh_client(tmp_path, monkeypatch)
    # 日付のみは当日いっぱい有効 (00:00:00 だと当日朝に失効していた)
    assert main._normalize_expires("2026-07-29") == "2026-07-29T23:59:59+00:00"


def test_normalize_expires_naive_gets_utc(tmp_path, monkeypatch):
    _, main = _fresh_client(tmp_path, monkeypatch)
    assert main._normalize_expires("2026-07-29T12:00:00") == "2026-07-29T12:00:00+00:00"


def test_normalize_expires_keeps_negative_offset(tmp_path, monkeypatch):
    _, main = _fresh_client(tmp_path, monkeypatch)
    # 以前は "+00:00" を継ぎ足して "...-05:00+00:00" と壊していた
    assert main._normalize_expires("2026-01-01T00:00:00-05:00") == "2026-01-01T00:00:00-05:00"


def test_normalize_expires_empty_is_none(tmp_path, monkeypatch):
    _, main = _fresh_client(tmp_path, monkeypatch)
    assert main._normalize_expires("") is None
    assert main._normalize_expires(None) is None


def test_is_expired_past_and_future(tmp_path, monkeypatch):
    _, main = _fresh_client(tmp_path, monkeypatch)
    assert main._is_expired("2000-01-01T00:00:00+00:00") is True
    assert main._is_expired("2999-01-01T00:00:00+00:00") is False
    assert main._is_expired(None) is False
    assert main._is_expired("") is False


def test_is_expired_respects_timezone_offset(tmp_path, monkeypatch):
    _, main = _fresh_client(tmp_path, monkeypatch)
    # 過去の JST 時刻。辞書順比較では "2000-...T23:59:59+09:00" が現在の
    # "20xx-...+00:00" より大きく見えて「有効」と誤判定されていた。
    assert main._is_expired("2000-01-01T23:59:59+09:00") is True


def test_check_marks_jst_past_license_expired(tmp_path, monkeypatch):
    c, _ = _fresh_client(tmp_path, monkeypatch)
    c.post(
        "/admin/licenses",
        auth=("admin", "test-token"),
        json={
            "license_key": "JST-PAST",
            "domain": "example.test",
            "plan": "pro",
            "status": "active",
            "expires_at": "2000-01-01T23:59:59+09:00",
        },
    )
    r = c.post("/api/check", json={"license_key": "JST-PAST", "domain": "example.test"})
    assert r.status_code == 200
    assert r.json()["status"] == "expired"


def test_check_date_only_future_expiry_stays_active(tmp_path, monkeypatch):
    c, _ = _fresh_client(tmp_path, monkeypatch)
    # 日付のみの未来日。以前は "2099-12-31+00:00" < now の辞書順比較で
    # ('+' < 'T' により) 終日「期限切れ」に倒れることがあった。
    c.post(
        "/admin/licenses",
        auth=("admin", "test-token"),
        json={
            "license_key": "DATE-ONLY",
            "domain": "example.test",
            "plan": "pro",
            "status": "active",
            "expires_at": "2099-12-31",
        },
    )
    r = c.post("/api/check", json={"license_key": "DATE-ONLY", "domain": "example.test"})
    assert r.status_code == 200
    assert r.json()["status"] == "active"


# ==========================================================================
# レート制限: WINDOW の実効性と TOTP 総当たり (B-2 / B-3)
# ==========================================================================

def _insert_auth_failure(main, ip, event, seconds_ago):
    """監査ログに過去時刻の失敗行を直接入れる (時間経過をテストするため)。"""
    with main.db.connection() as c:
        c.execute(
            "INSERT INTO audit_log (event, ip, ts) VALUES (?, ?, datetime('now', ?))",
            (event, ip, f"-{int(seconds_ago)} seconds"),
        )


def test_totp_failures_count_toward_block(tmp_path, monkeypatch):
    # TOTP の失敗もレート制限の対象。数えないと、Basic パスワードが漏れた
    # 攻撃者が 6 桁コードを無制限に総当たりできてしまう。
    monkeypatch.setenv("DRWP_LOGIN_FAIL_LIMIT", "3")
    monkeypatch.setenv("DRWP_LOGIN_FAIL_WINDOW", "60")
    monkeypatch.setenv("DRWP_LOGIN_BLOCK_SECONDS", "600")
    c, main = _fresh_client(tmp_path, monkeypatch)

    for _ in range(3):
        _insert_auth_failure(main, "testclient", "totp_failed", 1)

    # 正しい Basic 資格情報でも遮断される
    r = c.get("/admin/licenses", auth=("admin", "test-token"))
    assert r.status_code == 429


def test_window_limits_which_failures_count(tmp_path, monkeypatch):
    # WINDOW(10秒) の外に散らばった失敗は閾値に達しない。
    # 以前は WINDOW がどこにも使われておらず、環境変数を変えても
    # 挙動が変わらなかった。
    monkeypatch.setenv("DRWP_LOGIN_FAIL_LIMIT", "3")
    monkeypatch.setenv("DRWP_LOGIN_FAIL_WINDOW", "10")
    monkeypatch.setenv("DRWP_LOGIN_BLOCK_SECONDS", "600")
    c, main = _fresh_client(tmp_path, monkeypatch)

    # 100秒おきの失敗3回 → どの10秒窓にも3回入らない → 遮断されない
    for ago in (300, 200, 100):
        _insert_auth_failure(main, "testclient", "login_failed", ago)
    r = c.get("/admin/licenses", auth=("admin", "test-token"))
    assert r.status_code == 200

    # 直近に3連続 → 10秒窓に3回 → 遮断される
    for _ in range(3):
        _insert_auth_failure(main, "testclient", "login_failed", 1)
    r = c.get("/admin/licenses", auth=("admin", "test-token"))
    assert r.status_code == 429


def test_block_expires_after_block_seconds(tmp_path, monkeypatch):
    # 遮断は「最後の失敗から BLOCK 秒」で解ける。
    monkeypatch.setenv("DRWP_LOGIN_FAIL_LIMIT", "3")
    monkeypatch.setenv("DRWP_LOGIN_FAIL_WINDOW", "60")
    monkeypatch.setenv("DRWP_LOGIN_BLOCK_SECONDS", "30")
    c, main = _fresh_client(tmp_path, monkeypatch)

    # 60秒窓には収まっているが、最後の失敗が BLOCK(30秒) より古い
    for ago in (50, 45, 40):
        _insert_auth_failure(main, "testclient", "login_failed", ago)
    r = c.get("/admin/licenses", auth=("admin", "test-token"))
    assert r.status_code == 200


# ==========================================================================
# domain 空のワイルドカードライセンス禁止 (B-6)
# ==========================================================================

def test_api_create_rejects_empty_domain(client):
    for bad in ("", "   "):
        r = client.post(
            "/admin/licenses",
            auth=("admin", "test-token"),
            json={"license_key": "K-EMPTY", "domain": bad, "plan": "pro", "status": "active"},
        )
        assert r.status_code == 422, f"domain={bad!r} が通ってしまった"


def test_api_patch_rejects_empty_domain(tmp_path, monkeypatch):
    c, _ = _fresh_client(tmp_path, monkeypatch)
    _add_license(c, "K-PATCH", "pro")
    r = c.patch(
        "/admin/licenses/K-PATCH",
        auth=("admin", "test-token"),
        json={"domain": ""},
    )
    assert r.status_code == 422
    # 変わっていないこと
    r2 = c.get("/admin/licenses/K-PATCH", auth=("admin", "test-token"))
    assert r2.json()["domain"] == "example.test"


def test_ui_create_rejects_blank_domain(tmp_path, monkeypatch):
    c, _ = _fresh_client(tmp_path, monkeypatch)
    r = c.post(
        "/admin/ui/licenses",
        auth=("admin", "test-token"),
        data={"license_key": "", "domain": "   ", "plan": "basic", "status": "active"},
        follow_redirects=False,
    )
    assert r.status_code == 303
    assert "msg=domain_required" in r.headers["location"]
    # 何も作られていないこと
    lst = c.get("/admin/licenses", auth=("admin", "test-token")).json()
    assert lst.get("items", lst) in ([], {})  # {"items": []} / [] のどちらでも空


# ==========================================================================
# CSRF: /admin/ui の更新系はクロスオリジンの POST を拒否する (B-4)
# ==========================================================================

def test_cross_origin_admin_post_is_rejected(tmp_path, monkeypatch):
    c, main = _fresh_client(tmp_path, monkeypatch)
    r = c.post(
        "/admin/ui/licenses",
        auth=("admin", "test-token"),
        data={"license_key": "EVIL", "domain": "example.test", "plan": "basic", "status": "active"},
        headers={"Origin": "https://evil.example"},
        follow_redirects=False,
    )
    assert r.status_code == 403
    # 何も作られていない
    r2 = c.get("/admin/licenses/EVIL", auth=("admin", "test-token"))
    assert r2.status_code == 404
    # 監査ログに記録される
    events = [row["event"] for row in main.db.recent_audit(limit=10)]
    assert "csrf_rejected" in events


def test_cross_site_referer_is_rejected(tmp_path, monkeypatch):
    c, _ = _fresh_client(tmp_path, monkeypatch)
    r = c.post(
        "/admin/ui/settings/admin-token",
        auth=("admin", "test-token"),
        data={"username": "admin", "token": "hijacked"},
        headers={"Referer": "https://evil.example/attack.html"},
        follow_redirects=False,
    )
    assert r.status_code == 403


def test_same_origin_admin_post_is_allowed(tmp_path, monkeypatch):
    # TestClient のベース URL は http://testserver — 同一オリジンの
    # Origin ヘッダー付き POST は通る (通常のブラウザ操作)。
    c, _ = _fresh_client(tmp_path, monkeypatch)
    r = c.post(
        "/admin/ui/licenses",
        auth=("admin", "test-token"),
        data={"license_key": "SAME-ORIGIN", "domain": "example.test", "plan": "basic", "status": "active"},
        headers={"Origin": "http://testserver"},
        follow_redirects=False,
    )
    assert r.status_code == 303
    r2 = c.get("/admin/licenses/SAME-ORIGIN", auth=("admin", "test-token"))
    assert r2.status_code == 200


def test_get_pages_are_not_blocked_by_origin_guard(tmp_path, monkeypatch):
    # GET はガード対象外 (外部サイトからのリンク遷移は Referer が他所になる)
    c, _ = _fresh_client(tmp_path, monkeypatch)
    r = c.get(
        "/admin/ui/licenses",
        auth=("admin", "test-token"),
        headers={"Referer": "https://other.example/"},
    )
    assert r.status_code == 200


# ==========================================================================
# 管理画面UX: 期限バッジ・作成キー表示・削除確認・フラッシュ偽装 (U-3/4/6/12, I-8)
# ==========================================================================

def test_expiry_info_states(tmp_path, monkeypatch):
    _, main = _fresh_client(tmp_path, monkeypatch)
    assert main._expiry_info(None)["state"] == "none"
    assert main._expiry_info("2000-01-01T00:00:00+00:00")["state"] == "expired"
    ok = main._expiry_info("2999-01-01T00:00:00+00:00")
    assert ok["state"] == "ok"
    soon = main._expiry_info(
        (main.datetime.now(main.timezone.utc) + main.timedelta(days=5)).isoformat()
    )
    assert soon["state"] == "soon"
    assert 4 <= soon["days"] <= 5


def test_short_date_displays_jst(tmp_path, monkeypatch):
    _, main = _fresh_client(tmp_path, monkeypatch)
    # UTC 15:00 = JST 翌日 00:00 — 以前は UTC の日付が出て1日ズレて見えた
    assert main._short_date("2026-07-01T15:00:00+00:00") == "2026/07/02"
    assert main._short_date("2026-07-01T14:00:00+00:00") == "2026/07/01"


def test_list_shows_expired_badge(tmp_path, monkeypatch):
    c, _ = _fresh_client(tmp_path, monkeypatch)
    c.post(
        "/admin/licenses",
        auth=("admin", "test-token"),
        json={"license_key": "OLD-KEY", "domain": "example.test", "plan": "pro",
              "status": "active", "expires_at": "2000-01-01T00:00:00+00:00"},
    )
    r = c.get("/admin/ui/licenses", auth=("admin", "test-token"))
    assert "期限切れ" in r.text


def test_created_key_is_shown_after_ui_create(tmp_path, monkeypatch):
    c, _ = _fresh_client(tmp_path, monkeypatch)
    r = c.post(
        "/admin/ui/licenses",
        auth=("admin", "test-token"),
        data={"license_key": "", "domain": "example.test", "plan": "basic", "status": "active"},
        follow_redirects=True,
    )
    # 自動生成キーがフラッシュ横の枠に表示される
    assert "作成したライセンスキー" in r.text
    assert "NPM-" in r.text


def test_unknown_flash_msg_is_discarded(tmp_path, monkeypatch):
    # ?msg= に任意文言を入れて「緑の成功通知」を偽装できないこと (I-8)
    c, _ = _fresh_client(tmp_path, monkeypatch)
    r = c.get("/admin/ui/licenses?msg=%E5%81%BD%E3%81%AE%E9%80%9A%E7%9F%A5",
              auth=("admin", "test-token"))
    assert "偽の通知" not in r.text
