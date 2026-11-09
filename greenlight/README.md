# Greenlight v3 + Nginx — sakoo.hu.jz.ac.ir

Hoda-customized Greenlight behind Nginx (HTTPS). Separate from `production-direct` (HTTP :3000) and `production-caddy`.

## URL

https://sakoo.hu.jz.ac.ir

## First start

```bash
cd /home/kali/development/greenlight-v3/production-nginx

# 1) DNS: A record sakoo.hu.jz.ac.ir → this server

# 2) Self-signed cert so nginx can bind :443
./scripts/bootstrap-selfsigned.sh

# 3) Start stack
docker compose up -d

# 4) Real Let's Encrypt cert (ports 80/443 must be reachable from the internet)
CERTBOT_EMAIL=you@jz.ac.ir ./scripts/init-letsencrypt.sh

# 5) Admin user
docker exec -it gl-nginx-app bundle exec rake admin:create
```

## Useful commands

```bash
docker compose logs -f nginx greenlight-v3
docker compose restart greenlight-v3

docker cp custom/set_brand_color.rb gl-nginx-app:/tmp/
docker exec -e BRAND_PRIMARY='#a628aa' -it gl-nginx-app bundle exec rails runner /tmp/set_brand_color.rb

docker cp custom/set_default_recording_visibility.rb gl-nginx-app:/tmp/
docker exec -it gl-nginx-app bundle exec rails runner /tmp/set_default_recording_visibility.rb
```

## Layout

| Path | Role |
|------|------|
| `docker-compose.yml` | nginx + certbot + app + postgres + redis + recordings-sync |
| `nginx/` | reverse proxy for `sakoo.hu.jz.ac.ir` |
| `config/` | JoinIN BBB + recording visibility + proxy/SSL initializers |
| `custom/` | Hoda theme, locales, React, assets |
| `.env` | `URL_HOST` / `SAFE_DOMAIN` = `sakoo.hu.jz.ac.ir`, SSL on |

Do **not** run this stack on the same host ports 80/443 at the same time as `production-caddy`.
