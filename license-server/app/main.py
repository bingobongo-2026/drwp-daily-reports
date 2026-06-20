import asyncio
import io
import logging
import os
import secrets
import zipfile
from datetime import datetime, timezone
from typing import Optional

from fastapi import Depends, FastAPI, File, Form, HTTPException, Query, Request, UploadFile, status
from fastapi.responses import HTMLResponse, RedirectResponse, Response
from fastapi.security import HTTPBasic, HTTPBasicCredentials
from fastapi.templating import Jinja2Templates
from pydantic import BaseModel

from . import db, signing, totp

log = logging.getLogger("drwp.license")

app = FastAPI(title="Nippoman License Server v1.9")

db.init_db()

templates = Jinja2Templates(
    directory=os.path.join(os.path.dirname(__file__), "templates")
)

# プラン slug → 日本語ラベルへの変換。テンプレートでは
# `{{ plan|plan_label }}` で参照する。slug 側は英小文字の
# `basic` / `pro` をそのまま使い、ユーザー向けの表示だけ
# 日本語化することで API レスポンスや DB 値は触らずに済む。
_PLAN_LABELS = {
    "free": "フリー",
    "basic": "ベーシック",
    "pro": "プロ",
}

# 同様に status slug → 日本語ラベルへ。DB 値は英小文字の
# `active` / `inactive` をそのまま保持し、画面表示だけ日本語化する。
_STATUS_LABELS = {
    "active": "有効",
    "inactive": "停止",
}


def _plan_label(slug: str) -> str:
    if slug is None:
        return ""
    s = str(slug).strip().lower()
    return _PLAN_LABELS.get(s, str(slug))


def _status_label(slug: str) -> str:
    if slug is None:
        return ""
    s = str(slug).strip().lower()
    return _STATUS_LABELS.get(s, str(slug))


# 監査ログイベント slug → 日本語ラベル。テンプレート側は
# `{{ event|audit_label }}` で参照する。未知の slug は素通し。
_AUDIT_EVENT_LABELS = {
    "login_failed":           "ログイン失敗",
    "login_blocked":          "ログイン遮断（連続失敗）",
    "login_success":          "ログイン成功",
    "signing_rotated_auto":   "署名鍵 自動ローテート",
    "signing_rotated_manual": "署名鍵 手動ローテート",
    "totp_enabled":           "2FA 有効化",
    "totp_disabled":          "2FA 無効化",
    "totp_verified":          "2FA 認証成功",
    "totp_failed":            "2FA 認証失敗",
    "recovery_code_used":     "リカバリーコード使用",
}


def _audit_label(slug: str) -> str:
    if slug is None:
        return ""
    s = str(slug).strip().lower()
    return _AUDIT_EVENT_LABELS.get(s, str(slug))


templates.env.filters["plan_label"] = _plan_label
templates.env.filters["status_label"] = _status_label
templates.env.filters["audit_label"] = _audit_label

_FLASH = {
    "created": ("作成しました。", "ok"),
    "updated": ("更新しました。", "ok"),
    "deleted": ("削除しました。", "ok"),
    "conflict": ("そのライセンスキーは既に存在します。", "err"),
    "not_found": ("ライセンスが見つかりませんでした。", "err"),
    "token_saved": ("管理トークンを保存しました。", "ok"),
    "token_cleared": ("管理トークンを削除しました（環境変数の値が使われます）。", "ok"),
    "creds_saved": ("ユーザー名と管理トークンを保存しました。", "ok"),
    "username_saved": ("ユーザー名を保存しました。", "ok"),
    "rotated": ("署名鍵をローテートしました。", "ok"),
    "restored": ("バックアップから復元しました。", "ok"),
    "restore_invalid": ("バックアップファイルが不正です（zip ではない / 中身が想定外）。", "err"),
    "restore_failed": ("復元処理に失敗しました。詳細はサーバーログを確認してください。", "err"),
    "totp_setup_started": ("2FA セットアップを開始しました。QR コードをスキャンし、表示された 6 桁コードで確定してください。", "ok"),
    "totp_enabled": ("2FA を有効化しました。次回以降のログインで 6 桁コードの入力が必要になります。", "ok"),
    "totp_disabled": ("2FA を無効化しました。", "ok"),
    "totp_setup_cancelled": ("2FA セットアップをキャンセルしました。", "ok"),
    "totp_code_invalid": ("コードが一致しませんでした。Authenticator の時刻ズレや入力ミスを確認してください。", "err"),
    "totp_not_pending": ("セットアップ中の状態ではありません。最初からやり直してください。", "err"),
}


def _flash_ctx(msg: Optional[str]) -> dict:
    if not msg:
        return {"flash": None, "flash_class": None}
    text, cls = _FLASH.get(msg, (msg, "ok"))
    return {"flash": text, "flash_class": cls}

security = HTTPBasic(auto_error=False)

# Default admin username. Mostly cosmetic — it just gives operators a
# real value to type alongside the password instead of "anything works".
_DEFAULT_ADMIN_USERNAME = "admin"


def _resolve_admin_username() -> str:
    """DB value wins; env var DRWP_ADMIN_USERNAME is a bootstrap
    fallback; "admin" is the ultimate default so a fresh install
    always has a valid pair to type."""
    return (
        db.get_setting("admin_username")
        or os.environ.get("DRWP_ADMIN_USERNAME")
        or _DEFAULT_ADMIN_USERNAME
    )


def _resolve_admin_token() -> Optional[str]:
    """DB-stored token wins (manageable from the admin UI); env var is
    the bootstrap fallback so an operator can reach the UI on a fresh
    install before any DB-stored token exists."""
    return db.get_setting("admin_token") or os.environ.get("DRWP_ADMIN_TOKEN")


def _current_realm() -> str:
    """Realm name advertised in WWW-Authenticate. We append a version
    counter so that when the operator changes the admin username or
    token, the realm string changes too — browsers treat that as a
    separate auth domain and stop silently retrying with the cached
    old credentials."""
    version = db.get_setting("admin_token_version") or "0"
    return f"DRWP-Admin-v{version}"


def _bump_admin_token_version() -> None:
    v = int(db.get_setting("admin_token_version") or "0") + 1
    db.set_setting("admin_token_version", str(v))


# --- 失敗ログイン用のレート制限 -----------------------------------------
# Fail2ban 風の挙動: 同一 IP からの失敗ログインが LIMITER_THRESHOLD 回 /
# LIMITER_WINDOW_SEC 秒を超えたら、LIMITER_BLOCK_SEC 秒間は require_admin
# が即 429 を返すようにする。SQLite に既に書き込んでいる失敗ログを
# そのままカウンタとして再利用するので、別途キャッシュは要らない。
LIMITER_THRESHOLD = int(os.environ.get("DRWP_LOGIN_FAIL_LIMIT", "10"))
LIMITER_WINDOW_SEC = int(os.environ.get("DRWP_LOGIN_FAIL_WINDOW", "300"))
LIMITER_BLOCK_SEC = int(os.environ.get("DRWP_LOGIN_BLOCK_SECONDS", "600"))


def _client_ip(request: Optional[Request]) -> str:
    """Best-effort caller IP. Honors X-Forwarded-For only when the
    operator opts in via DRWP_TRUST_PROXY=1, since blindly trusting it
    when not behind a proxy lets an attacker spoof the audit log."""
    if request is None:
        return ""
    if os.environ.get("DRWP_TRUST_PROXY", "0") == "1":
        xff = request.headers.get("x-forwarded-for")
        if xff:
            return xff.split(",")[0].strip()
    return request.client.host if request.client else ""


def _is_ip_blocked(ip: str) -> bool:
    if not ip or LIMITER_THRESHOLD <= 0:
        return False
    n = db.count_failed_logins_since(ip, LIMITER_BLOCK_SEC)
    return n >= LIMITER_THRESHOLD


def require_admin_basic(
    request: Request,
    credentials: Optional[HTTPBasicCredentials] = Depends(security),
) -> str:
    """Basic 認証 + レート制限のみ。TOTP セットアップ / チャレンジ
    ページ自身がこちらを使う (TOTP ゲートに入ると無限ループする)。"""
    expected_user = _resolve_admin_username()
    expected_pass = _resolve_admin_token()
    ip = _client_ip(request)
    www_auth = {"WWW-Authenticate": f'Basic realm="{_current_realm()}"'}

    if not expected_pass:
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail="Admin token not configured",
        )

    # Block before doing any cred comparison so an attacker can't keep
    # the DB busy with timing-attack attempts after the cap is reached.
    if _is_ip_blocked(ip):
        db.log_audit("login_blocked", ip=ip,
                     detail=f"threshold={LIMITER_THRESHOLD}/{LIMITER_WINDOW_SEC}s")
        raise HTTPException(
            status_code=status.HTTP_429_TOO_MANY_REQUESTS,
            detail="Too many failed login attempts. Try again later.",
            headers={"Retry-After": str(LIMITER_BLOCK_SEC)},
        )

    if credentials is None:
        # Anonymous probe / first hit — don't log, browsers spam this
        # on every page navigation before the dialog is filled in.
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Authentication required",
            headers=www_auth,
        )

    # Compare both sides with constant-time digest to avoid timing
    # leaks on the username (rare but cheap to defend against).
    user_ok = secrets.compare_digest(credentials.username, expected_user)
    pass_ok = secrets.compare_digest(credentials.password, expected_pass)
    if not (user_ok and pass_ok):
        db.log_audit("login_failed", ip=ip, username=credentials.username[:64])
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid admin credentials",
            headers=www_auth,
        )

    # Success — log too, so an operator can see "who logged in when" in
    # the audit pane. Skip if DRWP_AUDIT_SUCCESS=0 in case someone wants
    # to keep the table small.
    if os.environ.get("DRWP_AUDIT_SUCCESS", "1") != "0":
        db.log_audit("login_success", ip=ip, username=credentials.username[:64])
    return credentials.username


def _is_ui_path(request: Request) -> bool:
    return request.url.path.startswith("/admin/ui/")


def _totp_session_valid(request: Request) -> bool:
    cookie = request.cookies.get(totp.COOKIE_NAME, "")
    ver = db.get_setting("admin_token_version") or "0"
    return totp.verify_session_cookie(cookie, ver)


def require_admin(
    request: Request,
    username: str = Depends(require_admin_basic),
) -> str:
    """Basic 認証成功後、2FA が有効ならセッションクッキー (UI) または
    X-DRWP-TOTP ヘッダ (API) を要求する。"""
    if not totp.is_totp_enabled():
        return username
    # ヘッダ経由 (API クライアント用)
    code = request.headers.get("x-drwp-totp", "").strip()
    if code:
        ok, used_recovery = totp.verify_code_or_recovery(code)
        if ok:
            db.log_audit(
                "recovery_code_used" if used_recovery else "totp_verified",
                ip=_client_ip(request), username=username,
            )
            return username
        db.log_audit("totp_failed", ip=_client_ip(request), username=username,
                     detail="header")
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid TOTP code",
        )
    # セッションクッキー (ブラウザ UI 用)
    if _totp_session_valid(request):
        return username
    # UI 経路ならチャレンジページへ 303。API 経路なら 401。
    if _is_ui_path(request):
        next_url = request.url.path
        if request.url.query:
            next_url += "?" + request.url.query
        from urllib.parse import quote
        raise HTTPException(
            status_code=status.HTTP_303_SEE_OTHER,
            headers={"Location": f"/admin/ui/totp/challenge?next={quote(next_url, safe='/?=&')}"},
        )
    raise HTTPException(
        status_code=status.HTTP_401_UNAUTHORIZED,
        detail="TOTP code required (X-DRWP-TOTP header)",
    )


class CheckRequest(BaseModel):
    license_key: str
    domain: str
    site_token: Optional[str] = None


class LicenseIn(BaseModel):
    # `license_key` 空 → サーバ側で自動生成。発行運用が楽になるよう、
    # JSON API でもキー入力を省略できる。
    license_key: Optional[str] = None
    domain: str
    plan: str = "basic"
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


# フリー (= 体験) プランの初期有効期限。発行日から 30 日後。
# 環境変数で上書きできるので、トライアル期間を運用で変えたくなった
# ときコードを触らずに調整できる。
FREE_PLAN_TRIAL_DAYS = int(os.environ.get("DRWP_FREE_PLAN_DAYS", "30"))


def _default_expires_for_plan(plan: str) -> Optional[str]:
    """プランごとの有効期限デフォルト。`free` だけ 30 日後 (= 体験期間)。
    `basic` / `pro` は無期限 (None)。"""
    if (plan or "").strip().lower() == "free":
        from datetime import timedelta
        dt = datetime.now(timezone.utc) + timedelta(days=FREE_PLAN_TRIAL_DAYS)
        return dt.isoformat(timespec="seconds")
    return None


# ライセンスキー自動生成用の英大文字+数字の集合。
# I / O / 0 / 1 など見分けにくい字は除外して、印刷物や口頭伝達でも
# 取り違えが起きにくいようにする (Crockford's Base32 と同じ思想)。
_KEY_ALPHABET = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789"


def _generate_license_key() -> str:
    """`NPM-XXXX-XXXX-XXXX-XXXX` 形式のランダムキー。30^16 ≒ 4e23 通り
    あるので衝突確率は実質ゼロだが、念のため呼び出し側で重複チェック。"""
    blocks = []
    for _ in range(4):
        blocks.append("".join(secrets.choice(_KEY_ALPHABET) for _ in range(4)))
    return "NPM-" + "-".join(blocks)


def _generate_unique_license_key(max_tries: int = 5) -> str:
    """既存ライセンスと衝突しない `_generate_license_key()` の値を返す。"""
    for _ in range(max_tries):
        key = _generate_license_key()
        if db.get_license(key) is None:
            return key
    raise HTTPException(
        status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
        detail="license_key auto-generation collided repeatedly",
    )


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
def admin_list(
    search: str = "",
    plan: str = "",
    status: str = "",
    _: str = Depends(require_admin),
):
    return {"items": db.list_licenses(search=search, plan=plan, status=status)}


@app.post("/admin/licenses", status_code=status.HTTP_201_CREATED)
def admin_create(payload: LicenseIn, _: str = Depends(require_admin)):
    data = payload.model_dump()
    # キー未指定 → 自動生成 (衝突しないものが当たるまで数回試行)
    key = (data.get("license_key") or "").strip()
    if not key:
        key = _generate_unique_license_key()
    elif db.get_license(key) is not None:
        raise HTTPException(status.HTTP_409_CONFLICT, detail="License key already exists")
    data["license_key"] = key
    # フリー プランで有効期限未指定 → 30 日後にデフォルトする
    if not data.get("expires_at"):
        data["expires_at"] = _default_expires_for_plan(data.get("plan") or "")
    return db.create_license(**data)


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
def ui_list(
    request: Request,
    msg: Optional[str] = None,
    q: str = "",
    plan: str = "",
    status_: str = Query("", alias="status"),
    _: str = Depends(require_admin),
):
    # 未知の slug は黙って空 (= 絞り込まない) に落として、自由入力で
    # 通常リストが消える事故を防ぐ。
    plan_f = plan if plan in _PLAN_LABELS else ""
    status_f = status_ if status_ in _STATUS_LABELS else ""
    return templates.TemplateResponse(
        request,
        "licenses.html",
        {
            "items": db.list_licenses(search=q, plan=plan_f, status=status_f),
            "search": q,
            "plan_filter": plan_f,
            "status_filter": status_f,
            "plan_options": _PLAN_LABELS,
            "status_options": _STATUS_LABELS,
            **_flash_ctx(msg),
        },
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
    license_key: str = Form(""),
    domain: str = Form(...),
    plan: str = Form("basic"),
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
    if not key:
        # 空欄なら自動生成 (NPM-XXXX-XXXX-XXXX-XXXX 形式)
        key = _generate_unique_license_key()
    elif db.get_license(key) is not None:
        return RedirectResponse(
            "/admin/ui/licenses/new?msg=conflict",
            status_code=status.HTTP_303_SEE_OTHER,
        )
    plan_v = plan.strip() or "basic"
    # フリープランで有効期限未指定 → 30 日後を自動セット
    expires_v = _normalize_expires(expires_at) or _default_expires_for_plan(plan_v)
    db.create_license(
        license_key=key,
        domain=domain.strip(),
        plan=plan_v,
        status=status_.strip() or "active",
        expires_at=expires_v,
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
    plan: str = Form("basic"),
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
        plan=plan.strip() or "basic",
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
    db_username = db.get_setting("admin_username") or ""
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
            "current_username": _resolve_admin_username(),
            "has_db_username": db_username != "",
            "env_username_set": bool(os.environ.get("DRWP_ADMIN_USERNAME")),
            "default_username": _DEFAULT_ADMIN_USERNAME,
            "public_key_b64": signing.public_key_b64(),
            "key_created_at": key_created_at,
            "last_rotated_at": db.get_setting("last_rotated_at"),
            "last_backup_at": db.get_setting("last_backup_at"),
            "rotation_interval_days": ROTATION_INTERVAL_DAYS,
            "next_rotation_due_at": next_rotation_due_at(),
            "audit_retention_days": AUDIT_RETENTION_DAYS,
            "audit_rows": db.recent_audit(limit=30),
            "limiter_threshold": LIMITER_THRESHOLD,
            "limiter_window_sec": LIMITER_WINDOW_SEC,
            "limiter_block_sec": LIMITER_BLOCK_SEC,
            "totp_enabled": totp.is_totp_enabled(),
            "totp_env_disabled": os.environ.get("DRWP_TOTP_DISABLED", "0") == "1",
            "totp_recovery_remaining": totp.remaining_recovery_count(),
            "totp_pending_active": bool(db.get_setting(totp.K_SECRET_PENDING)),
            **_flash_ctx(msg),
        },
    )


@app.post("/admin/ui/settings/admin-token", include_in_schema=False)
def ui_set_admin_token(
    username: str = Form(""),
    token: str = Form(""),
    clear: Optional[str] = Form(None),
    _: str = Depends(require_admin),
):
    if clear:
        db.delete_setting("admin_token")
        db.delete_setting("admin_username")
        _bump_admin_token_version()
        return RedirectResponse(
            "/admin/ui/settings?msg=token_cleared",
            status_code=status.HTTP_303_SEE_OTHER,
        )
    username = username.strip()
    token = token.strip()
    changed = False
    if username:
        db.set_setting("admin_username", username)
        changed = True
    if token:
        db.set_setting("admin_token", token)
        changed = True
    if changed:
        # Bump the realm version so the browser stops silently retrying
        # with the cached old credentials and prompts for the new pair.
        _bump_admin_token_version()
    if username and token:
        msg = "creds_saved"
    elif username:
        msg = "username_saved"
    else:
        msg = "token_saved"
    return RedirectResponse(
        f"/admin/ui/settings?msg={msg}",
        status_code=status.HTTP_303_SEE_OTHER,
    )


@app.post("/admin/ui/settings/rotate-signing", include_in_schema=False)
def ui_rotate_signing(request: Request, _: str = Depends(require_admin)):
    signing.rotate()
    db.set_setting("last_rotated_at", _now_iso())
    db.log_audit("signing_rotated_manual", ip=_client_ip(request))
    return RedirectResponse(
        "/admin/ui/settings?msg=rotated",
        status_code=status.HTTP_303_SEE_OTHER,
    )


# --- 自動定期ローテーション + 監査ログ retention -----------------------
# 90 日に 1 回、署名鍵を自動でローテートする。チェック自体は 1 日 1 回
# 走らせて、`last_rotated_at` の経過が閾値を超えていたら rotate を実行。
# `DRWP_ROTATION_INTERVAL_DAYS=0` で完全停止できる（テスト/手動運用用）。
ROTATION_INTERVAL_DAYS = int(os.environ.get("DRWP_ROTATION_INTERVAL_DAYS", "90"))
ROTATION_CHECK_HOURS = int(os.environ.get("DRWP_ROTATION_CHECK_HOURS", "24"))
AUDIT_RETENTION_DAYS = int(os.environ.get("DRWP_AUDIT_RETENTION_DAYS", "90"))


def _last_rotated_age_days() -> float:
    """Days since last_rotated_at. Falls back to the signing key file's
    mtime when the setting hasn't been set yet (i.e. fresh install)."""
    last = db.get_setting("last_rotated_at")
    if last:
        try:
            last_dt = datetime.fromisoformat(last.replace("Z", "+00:00"))
            return (datetime.now(timezone.utc) - last_dt).total_seconds() / 86400.0
        except ValueError:
            return float("inf")
    path = signing._key_path()
    if os.path.exists(path):
        import time as _t
        return max(0.0, (_t.time() - os.path.getmtime(path)) / 86400.0)
    return 0.0


def next_rotation_due_at() -> Optional[str]:
    """ISO timestamp of when the cron will next consider rotating. Used
    by the settings page to show the operator what's queued."""
    if ROTATION_INTERVAL_DAYS <= 0:
        return None
    last = db.get_setting("last_rotated_at")
    base = None
    if last:
        try:
            base = datetime.fromisoformat(last.replace("Z", "+00:00"))
        except ValueError:
            base = None
    if base is None:
        path = signing._key_path()
        if os.path.exists(path):
            base = datetime.fromtimestamp(os.path.getmtime(path), tz=timezone.utc)
    if base is None:
        return None
    from datetime import timedelta
    return (base + timedelta(days=ROTATION_INTERVAL_DAYS)).isoformat(timespec="seconds")


async def _maintenance_loop():
    """Background loop — signing rotation check + audit log purge.
    Runs as a single asyncio task started at app startup. Wrapped in
    try/except so a SQLite hiccup on a remote-mounted disk doesn't
    silently kill the loop forever."""
    while True:
        try:
            if ROTATION_INTERVAL_DAYS > 0:
                age = _last_rotated_age_days()
                if age >= ROTATION_INTERVAL_DAYS:
                    await asyncio.to_thread(signing.rotate)
                    db.set_setting("last_rotated_at", _now_iso())
                    db.log_audit(
                        "signing_rotated_auto",
                        detail=f"auto rotation after {age:.0f} days "
                               f"(interval={ROTATION_INTERVAL_DAYS}d)",
                    )
                    log.info("signing key auto-rotated after %.0f days", age)
            if AUDIT_RETENTION_DAYS > 0:
                deleted = db.purge_audit(AUDIT_RETENTION_DAYS)
                if deleted:
                    log.info("audit purge removed %d rows", deleted)
        except Exception:
            log.exception("maintenance loop iteration failed")
        await asyncio.sleep(max(ROTATION_CHECK_HOURS, 1) * 3600)


@app.on_event("startup")
async def _on_startup() -> None:
    # 0 disables (tests / one-shot scripts). Any positive value starts
    # the background task.
    if ROTATION_INTERVAL_DAYS > 0 or AUDIT_RETENTION_DAYS > 0:
        asyncio.create_task(_maintenance_loop())


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


# --- TOTP 2FA --------------------------------------------------------------
# セットアップ → 確定 → チャレンジ → 無効化 の 4 ルートを生やす。
# ここはどれも `require_admin_basic` (= Basic 認証のみ) を使うこと。
# `require_admin` を通してしまうと、TOTP 未認証ユーザがチャレンジ
# ページに辿り着けず無限リダイレクトする。

def _safe_next(next_url: Optional[str]) -> str:
    """オープンリダイレクト防止: 内部パスのみ許可する。"""
    if not next_url:
        return "/admin/ui/settings"
    if next_url.startswith("/") and not next_url.startswith("//"):
        return next_url
    return "/admin/ui/settings"


def _set_totp_session_cookie(response, request: Request) -> None:
    ver = db.get_setting("admin_token_version") or "0"
    value, max_age = totp.make_session_cookie(ver)
    secure = request.url.scheme == "https"
    response.set_cookie(
        key=totp.COOKIE_NAME,
        value=value,
        max_age=max_age,
        httponly=True,
        samesite="lax",
        secure=secure,
        path="/admin",
    )


@app.post("/admin/ui/settings/totp/setup", include_in_schema=False)
def ui_totp_setup_start(_: str = Depends(require_admin_basic)):
    """新規シークレット + リカバリーコードを生成し、確定画面へ。
    既に有効化済みならセットアップは無効 (まず無効化が先)。"""
    if totp.is_totp_enabled():
        return RedirectResponse(
            "/admin/ui/settings", status_code=status.HTTP_303_SEE_OTHER,
        )
    totp.begin_setup()
    return RedirectResponse(
        "/admin/ui/settings/totp/verify?msg=totp_setup_started",
        status_code=status.HTTP_303_SEE_OTHER,
    )


@app.get("/admin/ui/settings/totp/verify", response_class=HTMLResponse, include_in_schema=False)
def ui_totp_setup_verify(
    request: Request,
    msg: Optional[str] = None,
    _: str = Depends(require_admin_basic),
):
    secret, codes = totp.get_pending()
    if not secret:
        return RedirectResponse(
            "/admin/ui/settings?msg=totp_not_pending",
            status_code=status.HTTP_303_SEE_OTHER,
        )
    uri = totp.otpauth_uri(secret)
    return templates.TemplateResponse(
        request,
        "totp_setup.html",
        {
            "secret": secret,
            "recovery_codes": codes,
            "otpauth_uri": uri,
            "qr_svg": totp.qr_svg(uri),
            **_flash_ctx(msg),
        },
    )


@app.post("/admin/ui/settings/totp/enable", include_in_schema=False)
def ui_totp_enable(
    request: Request,
    code: str = Form(""),
    _: str = Depends(require_admin_basic),
):
    if not db.get_setting(totp.K_SECRET_PENDING):
        return RedirectResponse(
            "/admin/ui/settings?msg=totp_not_pending",
            status_code=status.HTTP_303_SEE_OTHER,
        )
    if not totp.finalize_enable(code):
        db.log_audit("totp_failed", ip=_client_ip(request), detail="enable")
        return RedirectResponse(
            "/admin/ui/settings/totp/verify?msg=totp_code_invalid",
            status_code=status.HTTP_303_SEE_OTHER,
        )
    # Bump realm so cached Basic creds + any stale TOTP cookie both get
    # invalidated — the operator will be prompted fresh on next request.
    _bump_admin_token_version()
    db.log_audit("totp_enabled", ip=_client_ip(request))
    resp = RedirectResponse(
        "/admin/ui/settings?msg=totp_enabled",
        status_code=status.HTTP_303_SEE_OTHER,
    )
    # Set a fresh session cookie so the operator isn't kicked to the
    # challenge page immediately after enabling.
    _set_totp_session_cookie(resp, request)
    return resp


@app.post("/admin/ui/settings/totp/cancel", include_in_schema=False)
def ui_totp_setup_cancel(_: str = Depends(require_admin_basic)):
    totp.discard_pending()
    return RedirectResponse(
        "/admin/ui/settings?msg=totp_setup_cancelled",
        status_code=status.HTTP_303_SEE_OTHER,
    )


@app.post("/admin/ui/settings/totp/disable", include_in_schema=False)
def ui_totp_disable(
    request: Request,
    code: str = Form(""),
    _: str = Depends(require_admin_basic),
):
    """2FA 無効化。現行 TOTP コード or リカバリーコードで本人確認する。"""
    if not totp.is_totp_enabled():
        return RedirectResponse(
            "/admin/ui/settings", status_code=status.HTTP_303_SEE_OTHER,
        )
    ok, used_recovery = totp.verify_code_or_recovery(code)
    if not ok:
        db.log_audit("totp_failed", ip=_client_ip(request), detail="disable")
        return RedirectResponse(
            "/admin/ui/settings?msg=totp_code_invalid",
            status_code=status.HTTP_303_SEE_OTHER,
        )
    if used_recovery:
        db.log_audit("recovery_code_used", ip=_client_ip(request), detail="disable")
    totp.disable()
    db.log_audit("totp_disabled", ip=_client_ip(request))
    resp = RedirectResponse(
        "/admin/ui/settings?msg=totp_disabled",
        status_code=status.HTTP_303_SEE_OTHER,
    )
    resp.delete_cookie(totp.COOKIE_NAME, path="/admin")
    return resp


@app.get("/admin/ui/totp/challenge", response_class=HTMLResponse, include_in_schema=False)
def ui_totp_challenge(
    request: Request,
    next: str = "/admin/ui/settings",
    msg: Optional[str] = None,
    _: str = Depends(require_admin_basic),
):
    if not totp.is_totp_enabled():
        # 2FA が無効ならチャレンジ自体不要。元のページへ戻す。
        return RedirectResponse(_safe_next(next), status_code=status.HTTP_303_SEE_OTHER)
    if _totp_session_valid(request):
        return RedirectResponse(_safe_next(next), status_code=status.HTTP_303_SEE_OTHER)
    return templates.TemplateResponse(
        request,
        "totp_challenge.html",
        {
            "next": _safe_next(next),
            "recovery_remaining": totp.remaining_recovery_count(),
            **_flash_ctx(msg),
        },
    )


@app.post("/admin/ui/totp/challenge", include_in_schema=False)
def ui_totp_challenge_submit(
    request: Request,
    code: str = Form(""),
    next: str = Form("/admin/ui/settings"),
    _: str = Depends(require_admin_basic),
):
    if not totp.is_totp_enabled():
        return RedirectResponse(_safe_next(next), status_code=status.HTTP_303_SEE_OTHER)
    ok, used_recovery = totp.verify_code_or_recovery(code)
    if not ok:
        db.log_audit("totp_failed", ip=_client_ip(request), detail="challenge")
        from urllib.parse import quote
        return RedirectResponse(
            f"/admin/ui/totp/challenge?msg=totp_code_invalid&next={quote(_safe_next(next))}",
            status_code=status.HTTP_303_SEE_OTHER,
        )
    db.log_audit(
        "recovery_code_used" if used_recovery else "totp_verified",
        ip=_client_ip(request),
    )
    resp = RedirectResponse(_safe_next(next), status_code=status.HTTP_303_SEE_OTHER)
    _set_totp_session_cookie(resp, request)
    return resp


@app.post("/admin/ui/totp/logout", include_in_schema=False)
def ui_totp_logout(_: str = Depends(require_admin_basic)):
    """セッションクッキーを削除して即座にチャレンジが要求される
    状態に戻す。共有 PC で離席する前に押す想定。"""
    resp = RedirectResponse(
        "/admin/ui/settings", status_code=status.HTTP_303_SEE_OTHER,
    )
    resp.delete_cookie(totp.COOKIE_NAME, path="/admin")
    return resp
