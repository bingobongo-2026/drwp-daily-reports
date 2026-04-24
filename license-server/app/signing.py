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


# Number of retired public keys we keep alongside the active one so verifiers
# that haven't refreshed yet can still validate signatures issued under the
# previous private key.
MAX_PREVIOUS_KEYS = 3


def _key_path() -> str:
    return os.environ.get("DRWP_SIGNING_KEY", "./signing.key")


def _previous_path() -> str:
    return _key_path() + ".previous.json"


_cached_key: Optional[Ed25519PrivateKey] = None
_cached_key_path: Optional[str] = None


def _invalidate_cache() -> None:
    global _cached_key, _cached_key_path
    _cached_key = None
    _cached_key_path = None


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


def _public_bytes(key: Ed25519PrivateKey) -> bytes:
    return key.public_key().public_bytes(Encoding.Raw, PublicFormat.Raw)


def public_key_b64() -> str:
    return base64.b64encode(_public_bytes(_load_or_create())).decode("ascii")


def previous_public_keys_b64() -> list[str]:
    path = _previous_path()
    if not os.path.exists(path):
        return []
    try:
        with open(path, "r", encoding="utf-8") as f:
            data = json.load(f)
        keys = data.get("keys", []) if isinstance(data, dict) else []
        return [str(k) for k in keys if isinstance(k, str)]
    except (json.JSONDecodeError, OSError):
        return []


def _write_previous(keys: list[str]) -> None:
    path = _previous_path()
    parent = os.path.dirname(path) or "."
    os.makedirs(parent, exist_ok=True)
    with open(path, "w", encoding="utf-8") as f:
        json.dump({"keys": keys[:MAX_PREVIOUS_KEYS]}, f)
    os.chmod(path, 0o600)


def rotate() -> dict:
    """Generate a fresh signing key, archive the current public key as
    'previous', and return the new active public key plus the updated
    previous list."""
    current = _load_or_create()
    previous_pub_b64 = base64.b64encode(_public_bytes(current)).decode("ascii")

    # Generate replacement and atomically swap the on-disk key.
    new_key = Ed25519PrivateKey.generate()
    raw = new_key.private_bytes(Encoding.Raw, PrivateFormat.Raw, NoEncryption())
    path = _key_path()
    parent = os.path.dirname(path) or "."
    os.makedirs(parent, exist_ok=True)
    tmp_path = path + ".tmp"
    with open(tmp_path, "wb") as f:
        f.write(raw)
    os.chmod(tmp_path, 0o600)
    os.replace(tmp_path, path)

    # Push the previous key to the front; cap the list.
    archived = [previous_pub_b64] + previous_public_keys_b64()
    seen = set()
    deduped: list[str] = []
    for k in archived:
        if k in seen:
            continue
        seen.add(k)
        deduped.append(k)
    _write_previous(deduped)

    _invalidate_cache()
    return {
        "public_key": public_key_b64(),
        "previous_keys": previous_public_keys_b64(),
    }


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
    """Verify against the active key and any archived previous keys."""
    candidates = [public_key_b64()] + previous_public_keys_b64()
    sig = base64.b64decode(signature_b64)
    msg = canonical(payload)
    for pub_b64 in candidates:
        pub = Ed25519PublicKey.from_public_bytes(base64.b64decode(pub_b64))
        try:
            pub.verify(sig, msg)
            return True
        except InvalidSignature:
            continue
    return False
