FROM python:3.11-slim

WORKDIR /app

# Install build deps for cryptography wheels (most have prebuilt wheels for slim,
# but keep gcc available for edge cases). Cleaned up after.
RUN apt-get update \
 && apt-get install -y --no-install-recommends curl \
 && rm -rf /var/lib/apt/lists/*

COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

COPY app ./app

RUN mkdir -p /data
ENV DRWP_LICENSE_DB=/data/data.sqlite3 \
    DRWP_SIGNING_KEY=/data/signing.key

EXPOSE 8000

HEALTHCHECK --interval=10s --timeout=3s --start-period=10s --retries=3 \
  CMD curl -fsS http://127.0.0.1:8000/healthz || exit 1

# --proxy-headers / --forwarded-allow-ips: 本番は TLS 終端リバースプロキシ
# (nginx / Caddy 等) の背後で動く前提。これが無いと (1) request.client.host が
# 常にプロキシの内部IPになり、ログイン失敗のIPロックが全顧客で共有され連鎖
# ロックする (2) X-Forwarded-Proto を無視して http と誤認し Secure Cookie の
# 判定がずれる。直背後のプロキシのみを信頼する構成なら "*" を実IPに絞ること。
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000", "--proxy-headers", "--forwarded-allow-ips", "*"]
