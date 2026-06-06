import io
import os
import secrets
import zipfile
from datetime import datetime, timezone
from typing import Optional

from fastapi import Depends, FastAPI, File, Form, HTTPException, Request, UploadFile, status
from fastapi.responses import HTMLResponse, RedirectResponse, Response
from fastapi.security import HTTPBasic, HTTPBasicCredentials
from fastapi.templating import Jinja2Templates
from pydantic import BaseModel

from . import db, signing

app = FastAPI(title="DRWP License Server v1.9")

db.init_db()

templates = Jinja2Templates(
    directory=os.path.join(os.path.dirname(__file__), "templates")
)

_FLASH = {
    "created": ("作成しました。", "ok"),
    "updated": ("更新しました。", "ok"),
    "deleted": ("削除しました。", "ok"),
    "conflict": ("そのライセンスキーは既に存在します。", "err"),
    "not_found": ("ライセンスが見つかりませんでした。", "err"),
    "token_saved": ("管理トークンを保存しました。", "ok"),
    "token_cleared": ("管理トークンを削除しました（環境変数の値が使われます）。", "ok"),
    "rotated": ("署名鍵をローテートしました。", "ok"),
    "restored": ("バックアップから復元しました。", "ok"),
    "restore_invalid": ("バックアップファイルが不正です（zip ではない / 中身が想定外）。", "err"),
    "restore_failed": ("復元処理に失敗しました。詳細はサーバーログを確認してください。", "err"),
}


def _flash_ctx(msg: Optional[str]) -> dict:
    if not msg:
        return {"flash": None, "flash_class": None}
    text, cls = _FLASH.get(msg, (msg, "ok"))
    return {"flash": text, "flash_class": cls}

security = HTTPBasic()


def _resolve_admin_token() -> Optional[str]:
    """DB-stored token wins (manageable from the admin UI); env var is
    the bootstrap fallback so an operator can reach the UI on a fresh
    install before any DB-stored token exists."""
    return db.get_setting("admin_token") or os.environ.get("DRWP_ADMIN_TOKEN")


def require_admin(credentials: HTTPBasicCredentials = Depends(security)) -> str:
    expected = _resolve_admin_token()
    if not expected:
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail="Admin token not configured",
        )
    if not secrets.compare_digest(credentials.password, expected):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid admin token",
            headers={"WWW-Authenticate": "Basic"},
        )
    return credentials.username


class CheckRequest(BaseModel):
    license_key: str
    domain: str
    site_token: Optional[str] = None


class LicenseIn(BaseModel):
    license_key: str
    domain: str
    plan: str = "standard"
    status: str = "active"
    expires_at: Optional[str] = None
    user_name: str = ""
    postal_code: str = ""
    address: str = ""
    company_phone: str = ""
    contact_person: str = ""
    contact_phone: str = ""
    notes: str = ""


class LicenseUpdate(BaseModel):
    domain: Optional[str] = None
    plan: Optional[str] = None
    status: Optional[str] = None
    expires_at: Optional[str] = None
    user_name: Optional[str] = None
    postal_code: Optional[str] = None
    address: Optional[str] = None
    company_phone: Optional[str] = None
    contact_person: Optional[str] = None
    contact_phone: Optional[str] = None
    notes: Optional[str] = None


def _now_iso() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def _normalize_expires(raw: Optional[str]) -> Optional[str]:
    val = (raw or "").strip()
    if not val:
        return None
    if "+" not in val and "Z" not in val:
        val += "+00:00"
    return val


def _sign_response(body: dict) -> dict:
    payload = {k: v for k, v in body.items() if k != "signature"}
    body["signature"] = signing.sign(payload)
    return body


@app.get("/api/public-key")
def public_key():
    return {
        "public_key": signing.public_key_b64(),
        "previous_keys": signing.previous_public_keys_b64(),
        "algorithm": "ed25519",
    }


@app.post("/admin/rotate-signing-key")
def admin_rotate_signing_key(_: str = Depends(require_admin)):
    return signing.rotate()


@app.get("/healthz")
def healthz():
    return {"ok": True}


@app.post("/api/check")
def check_license(payload: CheckRequest):
    lic = db.get_license(payload.license_key)
    now = _now_iso()
    base = {
        "license_key": payload.license_key,
        "allowed_domain": payload.domain,
        "issued_at": now,
    }

    if lic is None:
        base.update(status="not_found", plan="", expires_at="")
        return _sign_response(base)

    if lic["status"] != "active":
        base.update(
            status=lic["status"],
            plan=lic["plan"],
            expires_at=lic["expires_at"] or "",
            allowed_domain=lic["domain"],
        )
        return _sign_response(base)

    if lic["expires_at"] and lic["expires_at"] < now:
        base.update(
            status="expired",
            plan=lic["plan"],
            expires_at=lic["expires_at"],
            allowed_domain=lic["domain"],
        )
        return _sign_response(base)

    if lic["domain"] and lic["domain"] != payload.domain:
        base.update(
            status="domain_mismatch",
            plan=lic["plan"],
            expires_at=lic["expires_at"] or "",
            allowed_domain=lic["domain"],
        )
        return _sign_response(base)

    base.update(
        status="active",
        plan=lic["plan"],
        expires_at=lic["expires_at"] or "",
        allowed_domain=lic["domain"],
    )
    return _sign_response(base)


@app.get("/admin/licenses")
def admin_list(search: str = "", _: str = Depends(require_admin)):
    return {"items": db.list_licenses(search=search)}


@app.post("/admin/licenses", status_code=status.HTTP_201_CREATED)
def admin_create(payload: LicenseIn, _: str = Depends(require_admin)):
    if db.get_license(payload.license_key) is not None:
        raise HTTPException(status.HTTP_409_CONFLICT, detail="License key already exists")
    return db.create_license(**payload.model_dump())


@app.get("/admin/licenses/{license_key}")
def admin_read(license_key: str, _: str = Depends(require_admin)):
    lic = db.get_license(license_key)
    if lic is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, detail="License not found")
    return lic


@app.patch("/admin/licenses/{license_key}")
def admin_update(
    license_key: str,
    payload: LicenseUpdate,
    _: str = Depends(require_admin),
):
    updated = db.update_license(license_key, **payload.model_dump())
    if updated is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, detail="License not found")
    return updated


@app.delete("/admin/licenses/{license_key}", status_code=status.HTTP_204_NO_CONTENT)
def admin_delete(license_key: str, _: str = Depends(require_admin)):
    if not db.delete_license(license_key):
        raise HTTPException(status.HTTP_404_NOT_FOUND, detail="License not found")
    return None


# --- HTML admin UI ---------------------------------------------------------

@app.get("/admin/ui", include_in_schema=False)
def ui_root(_: str = Depends(require_admin)):
    return RedirectResponse("/admin/ui/licenses", status_code=status.HTTP_303_SEE_OTHER)


@app.get("/admin/ui/licenses", response_class=HTMLResponse, include_in_schema=False)
def ui_list(request: Request, msg: Optional[str] = None, q: str = "", _: str = Depends(require_admin)):
    return templates.TemplateResponse(
        request,
        "licenses.html",
        {"items": db.list_licenses(search=q), "search": q, **_flash_ctx(msg)},
    )


@app.get("/admin/ui/licenses/new", response_class=HTMLResponse, include_in_schema=False)
def ui_new(request: Request, msg: Optional[str] = None, _: str = Depends(require_admin)):
    return templates.TemplateResponse(
        request,
        "license_form.html",
        {
            "license": None,
            "action_url": "/admin/ui/licenses",
            **_flash_ctx(msg),
        },
    )


@app.post("/admin/ui/licenses", include_in_schema=False)
def ui_create(
    license_key: str = Form(...),
    domain: str = Form(...),
    plan: str = Form("standard"),
    status_: str = Form("active", alias="status"),
    expires_at: Optional[str] = Form(None),
    user_name: str = Form(""),
    postal_code: str = Form(""),
    address: str = Form(""),
    company_phone: str = Form(""),
    contact_person: str = Form(""),
    contact_phone: str = Form(""),
    notes: str = Form(""),
    _: str = Depends(require_admin),
):
    key = license_key.strip()
    if not key or db.get_license(key) is not None:
        return RedirectResponse(
            "/admin/ui/licenses/new?msg=conflict",
            status_code=status.HTTP_303_SEE_OTHER,
        )
    db.create_license(
        license_key=key,
        domain=domain.strip(),
        plan=plan.strip() or "standard",
        status=status_.strip() or "active",
        expires_at=_normalize_expires(expires_at),
        user_name=user_name.strip(),
        postal_code=postal_code.strip(),
        address=address.strip(),
        company_phone=company_phone.strip(),
        contact_person=contact_person.strip(),
        contact_phone=contact_phone.strip(),
        notes=notes.strip(),
    )
    return RedirectResponse(
        "/admin/ui/licenses?msg=created",
        status_code=status.HTTP_303_SEE_OTHER,
    )


@app.get(
    "/admin/ui/licenses/{license_key}/edit",
    response_class=HTMLResponse,
    include_in_schema=False,
)
def ui_edit(
    license_key: str,
    request: Request,
    msg: Optional[str] = None,
    _: str = Depends(require_admin),
):
    lic = db.get_license(license_key)
    if lic is None:
        return RedirectResponse(
            "/admin/ui/licenses?msg=not_found",
            status_code=status.HTTP_303_SEE_OTHER,
        )
    return templates.TemplateResponse(
        request,
        "license_form.html",
        {
            "license": lic,
            "action_url": f"/admin/ui/licenses/{license_key}/edit",
            **_flash_ctx(msg),
        },
    )


@app.post("/admin/ui/licenses/{license_key}/edit", include_in_schema=False)
def ui_update(
    license_key: str,
    domain: str = Form(...),
    plan: str = Form("standard"),
    status_: str = Form("active", alias="status"),
    expires_at: Optional[str] = Form(None),
    user_name: str = Form(""),
    postal_code: str = Form(""),
    address: str = Form(""),
    company_phone: str = Form(""),
    contact_person: str = Form(""),
    contact_phone: str = Form(""),
    notes: str = Form(""),
    _: str = Depends(require_admin),
):
    updated = db.update_license(
        license_key,
        domain=domain.strip(),
        plan=plan.strip() or "standard",
        status=status_.strip() or "active",
        expires_at=_normalize_expires(expires_at) or "",
        user_name=user_name.strip(),
        postal_code=postal_code.strip(),
        address=address.strip(),
        company_phone=company_phone.strip(),
        contact_person=contact_person.strip(),
        contact_phone=contact_phone.strip(),
        notes=notes.strip(),
    )
    if updated is None:
        return RedirectResponse(
            "/admin/ui/licenses?msg=not_found",
            status_code=status.HTTP_303_SEE_OTHER,
        )
    return RedirectResponse(
        "/admin/ui/licenses?msg=updated",
        status_code=status.HTTP_303_SEE_OTHER,
    )


@app.post("/admin/ui/licenses/{license_key}/delete", include_in_schema=False)
def ui_delete(license_key: str, _: str = Depends(require_admin)):
    msg = "deleted" if db.delete_license(license_key) else "not_found"
    return RedirectResponse(
        f"/admin/ui/licenses?msg={msg}",
        status_code=status.HTTP_303_SEE_OTHER,
    )


# --- Settings UI -----------------------------------------------------------

@app.get("/admin/ui/settings", response_class=HTMLResponse, include_in_schema=False)
def ui_settings(request: Request, msg: Optional[str] = None, _: str = Depends(require_admin)):
    db_token = db.get_setting("admin_token") or ""
    key_path = signing._key_path()
    key_created_at = None
    if os.path.exists(key_path):
        # ctime on POSIX is the inode's last metadata change but is close
        # enough to "when the key file appeared" for display purposes.
        key_created_at = datetime.fromtimestamp(
            os.path.getmtime(key_path), tz=timezone.utc
        ).isoformat(timespec="seconds")
    return templates.TemplateResponse(
        request,
        "settings.html",
        {
            "has_db_token": db_token != "",
            "env_token_set": bool(os.environ.get("DRWP_ADMIN_TOKEN")),
            "public_key_b64": signing.public_key_b64(),
            "key_created_at": key_created_at,
            "last_rotated_at": db.get_setting("last_rotated_at"),
            "last_backup_at": db.get_setting("last_backup_at"),
            **_flash_ctx(msg),
        },
    )


@app.post("/admin/ui/settings/admin-token", include_in_schema=False)
def ui_set_admin_token(
    token: str = Form(""),
    clear: Optional[str] = Form(None),
    _: str = Depends(require_admin),
):
    if clear:
        db.delete_setting("admin_token")
        return RedirectResponse(
            "/admin/ui/settings?msg=token_cleared",
            status_code=status.HTTP_303_SEE_OTHER,
        )
    token = token.strip()
    if token:
        db.set_setting("admin_token", token)
    return RedirectResponse(
        "/admin/ui/settings?msg=token_saved",
        status_code=status.HTTP_303_SEE_OTHER,
    )


@app.post("/admin/ui/settings/rotate-signing", include_in_schema=False)
def ui_rotate_signing(_: str = Depends(require_admin)):
    signing.rotate()
    db.set_setting("last_rotated_at", _now_iso())
    return RedirectResponse(
        "/admin/ui/settings?msg=rotated",
        status_code=status.HTTP_303_SEE_OTHER,
    )


# --- Backup / restore ------------------------------------------------------

_BACKUP_FILES = (
    ("signing.key", lambda: signing._key_path()),
    ("signing.key.previous.json", lambda: signing._previous_path()),
    ("data.sqlite3", lambda: db._db_path()),
)


@app.get("/admin/ui/settings/backup", include_in_schema=False)
def ui_backup(_: str = Depends(require_admin)):
    """Zip up the signing key, archived public keys, and the license DB
    so the operator has a single file to put in cold storage. The DB is
    included because losing it leaves the signing key without context
    (no licenses to validate). All three files are tiny, so we hold the
    zip in memory."""
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w", zipfile.ZIP_DEFLATED) as z:
        for arcname, getter in _BACKUP_FILES:
            path = getter()
            if os.path.exists(path):
                z.write(path, arcname=arcname)
    buf.seek(0)
    db.set_setting("last_backup_at", _now_iso())
    fname = "drwp-license-backup-" + datetime.now(timezone.utc).strftime("%Y%m%d-%H%M%S") + ".zip"
    return Response(
        content=buf.getvalue(),
        media_type="application/zip",
        headers={"Content-Disposition": f'attachment; filename="{fname}"'},
    )


@app.post("/admin/ui/settings/restore", include_in_schema=False)
def ui_restore(file: UploadFile = File(...), _: str = Depends(require_admin)):
    """Restore signing key + previous-keys archive + DB from a zip
    previously produced by ui_backup. Each file is written via a
    tmp + rename to keep the on-disk state consistent if any single
    step fails."""
    try:
        raw = file.file.read()
        zf = zipfile.ZipFile(io.BytesIO(raw))
    except zipfile.BadZipFile:
        return RedirectResponse(
            "/admin/ui/settings?msg=restore_invalid",
            status_code=status.HTTP_303_SEE_OTHER,
        )
    allowed = {name for name, _ in _BACKUP_FILES}
    names = set(zf.namelist())
    if not names or not names.issubset(allowed):
        return RedirectResponse(
            "/admin/ui/settings?msg=restore_invalid",
            status_code=status.HTTP_303_SEE_OTHER,
        )
    try:
        for arcname, getter in _BACKUP_FILES:
            if arcname not in names:
                continue
            dest = getter()
            parent = os.path.dirname(dest) or "."
            os.makedirs(parent, exist_ok=True)
            tmp = dest + ".restore-tmp"
            with zf.open(arcname) as src, open(tmp, "wb") as dst:
                dst.write(src.read())
            # Owner-only on the signing key for parity with normal creation.
            if arcname == "signing.key":
                os.chmod(tmp, 0o600)
            os.replace(tmp, dest)
    except Exception:
        return RedirectResponse(
            "/admin/ui/settings?msg=restore_failed",
            status_code=status.HTTP_303_SEE_OTHER,
        )
    # Drop the cached private key so the next sign() picks up the
    # restored bytes instead of the previous in-memory copy.
    signing._invalidate_cache()
    return RedirectResponse(
        "/admin/ui/settings?msg=restored",
        status_code=status.HTTP_303_SEE_OTHER,
    )
