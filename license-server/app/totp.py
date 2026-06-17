"""TOTP (RFC 6238) + リカバリーコード + 2FA セッションクッキー。

ライセンスサーバの管理画面 (Basic 認証) に、2 段階目として
Google Authenticator 系の TOTP を上乗せするためのユーティリティ。

外部依存は QR 描画用の `segno` のみ。TOTP 本体は stdlib (hmac /
struct / base64) だけで実装してある。
"""
from __future__ import annotations

import base64
import hashlib
import hmac
import json
import os
import secrets
import struct
import time
from typing import Optional

from . import db


# ---- 設定キー -----------------------------------------------------------
# settings テーブルに置く各キー。`*_pending` は「有効化処理中」、
# `*_active` 相当が確定値。`totp_enabled` が "1" のときだけゲートが効く。
K_SECRET_ACTIVE = "totp_secret"
K_RECOVERY_ACTIVE = "totp_recovery_hashes"   # JSON list[str]
K_SECRET_PENDING = "totp_secret_pending"
K_RECOVERY_PENDING = "totp_recovery_pending"  # JSON list[str] (生コード)
K_ENABLED = "totp_enabled"
K_SESSION_SECRET = "totp_session_secret"     # HMAC 用、b64 32 バイト
K_ISSUER = "DRWP-LicenseServer"


COOKIE_NAME = "drwp_totp"
DEFAULT_SESSION_HOURS = int(os.environ.get("DRWP_TOTP_SESSION_HOURS", "12"))
RECOVERY_CODE_COUNT = 10


# ---- 有効/無効判定 -------------------------------------------------------

def is_totp_enabled() -> bool:
    """`DRWP_TOTP_DISABLED=1` は鍵を紛失した際の緊急逃げ道。env で強制
    無効化されたら DB 値に関係なくゲートを通す。"""
    if os.environ.get("DRWP_TOTP_DISABLED", "0") == "1":
        return False
    return (db.get_setting(K_ENABLED) or "") == "1"


def totp_label() -> str:
    """otpauth URI の `label` 部分。ユーザー名込みで「どのアカウントの
    2FA か」を Authenticator に分かりやすく出すため、現行ユーザー名を
    取得して付与する。"""
    user = db.get_setting("admin_username") or os.environ.get("DRWP_ADMIN_USERNAME") or "admin"
    return f"{K_ISSUER}:{user}"


# ---- シークレット生成 / TOTP 計算 ---------------------------------------

def generate_secret_b32() -> str:
    """RFC 4648 base32 の 32 文字 (= 20 バイト) のシークレット。
    Google Authenticator 含む主要 Authenticator が SHA-1 / 20 バイト
    シークレットで動くので、互換性最優先でこの長さに揃える。"""
    raw = secrets.token_bytes(20)
    return base64.b32encode(raw).decode("ascii").rstrip("=")


def _b32_decode(secret_b32: str) -> bytes:
    pad = "=" * ((8 - len(secret_b32) % 8) % 8)
    return base64.b32decode(secret_b32.upper() + pad, casefold=True)


def _hotp(secret_b32: str, counter: int, digits: int = 6) -> str:
    """RFC 4226 HOTP。TOTP は単にカウンタを time/30 にしたもの。"""
    key = _b32_decode(secret_b32)
    msg = struct.pack(">Q", counter)
    mac = hmac.new(key, msg, hashlib.sha1).digest()
    offset = mac[-1] & 0x0F
    code = (int.from_bytes(mac[offset:offset + 4], "big") & 0x7FFFFFFF) % (10 ** digits)
    return str(code).zfill(digits)


def generate_totp(secret_b32: str, t: Optional[int] = None, step: int = 30, digits: int = 6) -> str:
    if t is None:
        t = int(time.time())
    return _hotp(secret_b32, t // step, digits)


def verify_totp(secret_b32: str, code: str, *, window: int = 1, step: int = 30) -> bool:
    """前後 ±`window` ステップを許容して照合する (時計ズレ吸収)。
    既定の window=1 で ±30 秒、合計 90 秒の窓。"""
    code = (code or "").strip().replace(" ", "")
    if not code.isdigit() or len(code) != 6:
        return False
    if not secret_b32:
        return False
    now = int(time.time())
    for offset in range(-window, window + 1):
        if hmac.compare_digest(generate_totp(secret_b32, t=now + offset * step), code):
            return True
    return False


# ---- otpauth URI / QR ---------------------------------------------------

def otpauth_uri(secret_b32: str) -> str:
    """`otpauth://totp/<label>?secret=...&issuer=...` 形式の URI。
    Google Authenticator / Authy / 1Password などが QR から読み取る。"""
    from urllib.parse import quote
    label = quote(totp_label(), safe="")
    issuer = quote(K_ISSUER, safe="")
    return (f"otpauth://totp/{label}"
            f"?secret={secret_b32}&issuer={issuer}&algorithm=SHA1&digits=6&period=30")


def qr_svg(data: str) -> str:
    """segno で SVG 文字列を生成。`segno` は pure Python なので
    PIL / Pillow を要求しない (=コンテナを軽く保てる)。"""
    import io
    import segno
    qr = segno.make(data, error="m")
    buf = io.BytesIO()
    qr.save(buf, kind="svg", scale=5, dark="#111827", light="#ffffff",
            xmldecl=False, svgns=True, omitsize=False)
    return buf.getvalue().decode("utf-8")


# ---- リカバリーコード ---------------------------------------------------

def generate_recovery_codes(n: int = RECOVERY_CODE_COUNT) -> list[str]:
    """各 8 文字 + ハイフン区切り (XXXX-XXXX) の英大文字+数字コードを
    `n` 個。1 と I / 0 と O などの紛らわしい文字は除外する。"""
    alphabet = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789"
    codes = []
    for _ in range(n):
        chars = [secrets.choice(alphabet) for _ in range(8)]
        codes.append("".join(chars[:4]) + "-" + "".join(chars[4:]))
    return codes


def _hash_recovery(code: str) -> str:
    norm = code.strip().upper().replace(" ", "").replace("-", "")
    return hashlib.sha256(norm.encode("utf-8")).hexdigest()


def store_recovery_hashes(codes: list[str]) -> None:
    db.set_setting(K_RECOVERY_ACTIVE, json.dumps([_hash_recovery(c) for c in codes]))


def consume_recovery_code(code: str) -> bool:
    raw = db.get_setting(K_RECOVERY_ACTIVE) or "[]"
    try:
        hashes = list(json.loads(raw))
    except (json.JSONDecodeError, ValueError):
        return False
    target = _hash_recovery(code)
    new_hashes = []
    matched = False
    for h in hashes:
        if not matched and hmac.compare_digest(str(h), target):
            matched = True
            continue
        new_hashes.append(h)
    if matched:
        db.set_setting(K_RECOVERY_ACTIVE, json.dumps(new_hashes))
    return matched


def remaining_recovery_count() -> int:
    raw = db.get_setting(K_RECOVERY_ACTIVE) or "[]"
    try:
        return len(json.loads(raw))
    except (json.JSONDecodeError, ValueError):
        return 0


# ---- セットアップ (pending) → 有効化 ------------------------------------

def begin_setup() -> tuple[str, list[str]]:
    """新しいシークレットとリカバリーコード一式を生成し、`*_pending`
    に保存して返す。確定 (`enable`) されるまでは有効化されない。"""
    secret = generate_secret_b32()
    codes = generate_recovery_codes()
    db.set_setting(K_SECRET_PENDING, secret)
    db.set_setting(K_RECOVERY_PENDING, json.dumps(codes))
    return secret, codes


def get_pending() -> tuple[Optional[str], list[str]]:
    secret = db.get_setting(K_SECRET_PENDING) or None
    raw = db.get_setting(K_RECOVERY_PENDING) or "[]"
    try:
        codes = list(json.loads(raw))
    except (json.JSONDecodeError, ValueError):
        codes = []
    return secret, codes


def discard_pending() -> None:
    db.delete_setting(K_SECRET_PENDING)
    db.delete_setting(K_RECOVERY_PENDING)


def finalize_enable(code: str) -> bool:
    """ペンディング中のシークレットで `code` を検証し、合致したら
    本番化する。合致しなければ何も触らない (pending は保持)。"""
    secret, codes = get_pending()
    if not secret or not codes:
        return False
    if not verify_totp(secret, code):
        return False
    db.set_setting(K_SECRET_ACTIVE, secret)
    store_recovery_hashes(codes)
    db.set_setting(K_ENABLED, "1")
    discard_pending()
    return True


def disable() -> None:
    """2FA をオフにする。シークレット / リカバリーコードもクリア。
    呼び出し側で必ず本人確認 (現行 TOTP コード or リカバリーコード)
    を済ませてから呼ぶこと。"""
    db.delete_setting(K_SECRET_ACTIVE)
    db.delete_setting(K_RECOVERY_ACTIVE)
    db.delete_setting(K_ENABLED)
    discard_pending()


def verify_code_or_recovery(code: str) -> tuple[bool, bool]:
    """戻り値: (verified, used_recovery)。本番シークレットで TOTP を
    試し、駄目ならリカバリーコードを試す。リカバリーコード経由で
    成功した場合のみ used_recovery=True。"""
    secret = db.get_setting(K_SECRET_ACTIVE) or ""
    if secret and verify_totp(secret, code):
        return True, False
    if consume_recovery_code(code):
        return True, True
    return False, False


# ---- セッションクッキー (HMAC 署名) -------------------------------------

def _session_secret() -> bytes:
    """サーバ起動毎にローテートはしないが、admin_token_version の bump で
    実質無効化されるので OK。値がなければ初回に生成して保存。"""
    raw = db.get_setting(K_SESSION_SECRET)
    if not raw:
        raw = base64.urlsafe_b64encode(secrets.token_bytes(32)).decode("ascii")
        db.set_setting(K_SESSION_SECRET, raw)
    return raw.encode("ascii")


def make_session_cookie(token_version: str, hours: Optional[int] = None) -> tuple[str, int]:
    """戻り値: (cookie_value, max_age_seconds)。HMAC 署名付き。
    token_version は admin_token_version (これが変わるとセッション全滅)。"""
    if hours is None:
        hours = DEFAULT_SESSION_HOURS
    max_age = max(1, hours) * 3600
    expiry = int(time.time()) + max_age
    payload = f"{expiry}.{token_version}"
    sig = hmac.new(_session_secret(), payload.encode("ascii"), hashlib.sha256).digest()
    sig_b64 = base64.urlsafe_b64encode(sig).decode("ascii").rstrip("=")
    return f"{payload}.{sig_b64}", max_age


def verify_session_cookie(value: str, token_version: str) -> bool:
    if not value:
        return False
    try:
        expiry_str, ver, sig_b64 = value.split(".", 2)
        expiry = int(expiry_str)
    except (ValueError, AttributeError):
        return False
    if str(ver) != str(token_version):
        return False
    if expiry < int(time.time()):
        return False
    payload = f"{expiry}.{ver}".encode("ascii")
    expected = hmac.new(_session_secret(), payload, hashlib.sha256).digest()
    try:
        pad = "=" * ((4 - len(sig_b64) % 4) % 4)
        provided = base64.urlsafe_b64decode(sig_b64 + pad)
    except (ValueError, base64.binascii.Error):
        return False
    return hmac.compare_digest(expected, provided)
