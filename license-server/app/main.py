import os
import secrets

from fastapi import Depends, FastAPI, HTTPException, status
from fastapi.security import HTTPBasic, HTTPBasicCredentials
from pydantic import BaseModel

app = FastAPI(title="DRWP License Server v1.8")

PUBLIC_KEY = "-----BEGIN PUBLIC KEY-----\nPROTOTYPE\n-----END PUBLIC KEY-----"

security = HTTPBasic()


def require_admin(credentials: HTTPBasicCredentials = Depends(security)) -> str:
    expected = os.environ.get("DRWP_ADMIN_TOKEN")
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
    site_token: str | None = None


@app.get("/api/public-key")
def public_key():
    return {"public_key": PUBLIC_KEY}


@app.post("/api/check")
def check_license(payload: CheckRequest):
    return {
        "status": "active",
        "plan": "pro",
        "expires_at": "2027-12-31T23:59:59+09:00",
        "allowed_domain": payload.domain,
        "signature": "prototype-signature",
    }


@app.get("/healthz")
def healthz():
    return {"ok": True}


@app.get("/admin/licenses")
def licenses(_: str = Depends(require_admin)):
    return {"items": [{"license_key": "DEMO-KEY", "status": "active", "plan": "pro"}]}
