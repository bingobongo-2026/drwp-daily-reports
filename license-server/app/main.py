from fastapi import FastAPI
from pydantic import BaseModel

app = FastAPI(title="DRWP License Server v1.8")

PUBLIC_KEY = "-----BEGIN PUBLIC KEY-----\nPROTOTYPE\n-----END PUBLIC KEY-----"

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
        "signature": "prototype-signature"
    }

@app.get("/admin/licenses")
def licenses():
    return {"items": [{"license_key": "DEMO-KEY", "status": "active", "plan": "pro"}]}
