#!/usr/bin/env bash
# Issue a Let's Encrypt certificate for sakoo.hu.jz.ac.ir
# Prerequisites: DNS A record → this host, ports 80/443 open.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

DOMAIN="${DOMAIN:-sakoo.hu.jz.ac.ir}"
EMAIL="${CERTBOT_EMAIL:-admin@${DOMAIN}}"

mkdir -p data/certbot/conf data/certbot/www
./scripts/bootstrap-selfsigned.sh

echo "==> Ensuring stack is up (HTTP must answer ACME challenges)"
docker compose up -d nginx greenlight-v3

echo "==> Requesting certificate for $DOMAIN"
docker compose run --rm --entrypoint certbot certbot certonly \
  --webroot -w /var/www/certbot \
  --email "$EMAIL" \
  --agree-tos \
  --no-eff-email \
  --force-renewal \
  -d "$DOMAIN"

echo "==> Reloading nginx"
docker compose exec nginx nginx -s reload

echo "Done. Open https://$DOMAIN"
