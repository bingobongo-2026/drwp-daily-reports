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
    assert "DRWP License Server" in r.text
    assert "ライセンスがありません" in r.text


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
            "plan": "standard",
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
