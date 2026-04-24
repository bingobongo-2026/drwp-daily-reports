import os

import pytest
from fastapi.testclient import TestClient


@pytest.fixture
def client(monkeypatch):
    monkeypatch.setenv("DRWP_ADMIN_TOKEN", "test-token")
    from app import main
    return TestClient(main.app)


def test_public_key(client):
    r = client.get("/api/public-key")
    assert r.status_code == 200
    assert "PUBLIC KEY" in r.json()["public_key"]


def test_healthz(client):
    r = client.get("/healthz")
    assert r.status_code == 200
    assert r.json() == {"ok": True}


def test_check_returns_active(client):
    r = client.post(
        "/api/check",
        json={"license_key": "DEMO-KEY", "domain": "example.test"},
    )
    assert r.status_code == 200
    body = r.json()
    assert body["status"] == "active"
    assert body["allowed_domain"] == "example.test"


def test_check_rejects_missing_fields(client):
    r = client.post("/api/check", json={"domain": "example.test"})
    assert r.status_code == 422


def test_admin_requires_auth(client):
    r = client.get("/admin/licenses")
    assert r.status_code == 401


def test_admin_rejects_bad_token(client):
    r = client.get("/admin/licenses", auth=("admin", "wrong"))
    assert r.status_code == 401


def test_admin_accepts_good_token(client):
    r = client.get("/admin/licenses", auth=("admin", "test-token"))
    assert r.status_code == 200
    assert r.json()["items"][0]["license_key"] == "DEMO-KEY"


def test_admin_503_when_token_unset(monkeypatch):
    monkeypatch.delenv("DRWP_ADMIN_TOKEN", raising=False)
    from app import main
    c = TestClient(main.app)
    r = c.get("/admin/licenses", auth=("admin", "anything"))
    assert r.status_code == 503
