import base64
import json
import os
from typing import Any, Optional

from cryptography.exceptions import InvalidSignature
from cryptography.hazmat.primitives.asymmetric.ed25519 import (
    Ed25519PrivateKey,
    Ed25519PublicKey,
)
from cryptography.hazmat.primitives.serialization import (
    Encoding,
    NoEncryption,
    PrivateFormat,
    PublicFormat,
)


def _key_path() -> str:
    return os.environ.get("DRWP_SIGNING_KEY", "./signing.key")


_cached_key: Optional[Ed25519PrivateKey] = None
_cached_key_path: Optional[str] = None


def _load_or_create() -> Ed25519PrivateKey:
    global _cached_key, _cached_key_path
    path = _key_path()
    if _cached_key is not None and _cached_key_path == path:
        return _cached_key

    if os.path.exists(path):
        with open(path, "rb") as f:
            raw = f.read()
        key = Ed25519PrivateKey.from_private_bytes(raw)
    else:
        key = Ed25519PrivateKey.generate()
        raw = key.private_bytes(Encoding.Raw, PrivateFormat.Raw, NoEncryption())
        parent = os.path.dirname(path) or "."
        os.makedirs(parent, exist_ok=True)
        with open(path, "wb") as f:
            f.write(raw)
        os.chmod(path, 0o600)

    _cached_key = key
    _cached_key_path = path
    return key


def public_key_b64() -> str:
    pub = _load_or_create().public_key().public_bytes(Encoding.Raw, PublicFormat.Raw)
    return base64.b64encode(pub).decode("ascii")


def canonical(payload: dict[str, Any]) -> bytes:
    return json.dumps(
        payload,
        sort_keys=True,
        separators=(",", ":"),
        ensure_ascii=False,
    ).encode("utf-8")


def sign(payload: dict[str, Any]) -> str:
    signature = _load_or_create().sign(canonical(payload))
    return base64.b64encode(signature).decode("ascii")


def verify(payload: dict[str, Any], signature_b64: str) -> bool:
    pub_bytes = base64.b64decode(public_key_b64())
    pub = Ed25519PublicKey.from_public_bytes(pub_bytes)
    try:
        pub.verify(base64.b64decode(signature_b64), canonical(payload))
        return True
    except InvalidSignature:
        return False
