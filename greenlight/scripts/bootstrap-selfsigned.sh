#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DOMAIN="${DOMAIN:-sakoo.hu.jz.ac.ir}"
CERT_DIR="$ROOT/data/certbot/conf/live/$DOMAIN"

if [[ -f "$CERT_DIR/fullchain.pem" && -f "$CERT_DIR/privkey.pem" ]]; then
  echo "Cert already exists at $CERT_DIR — skipping."
  exit 0
fi

mkdir -p "$CERT_DIR" "$ROOT/data/certbot/www"
openssl req -x509 -nodes -newkey rsa:2048 -days 7 \
  -keyout "$CERT_DIR/privkey.pem" \
  -out "$CERT_DIR/fullchain.pem" \
  -subj "/CN=$DOMAIN"
echo "Self-signed cert created for $DOMAIN (replace with ./scripts/init-letsencrypt.sh)"
