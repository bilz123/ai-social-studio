#!/usr/bin/env bash
# Creates the root .env from .env.example and fills in freshly generated secrets.
# Safe to re-run: existing non-empty values are never overwritten.
set -euo pipefail

cd "$(dirname "$0")/.."

if [ ! -f .env ]; then
  cp .env.example .env
  echo "Created .env from .env.example"
fi

rand_hex() { openssl rand -hex "$1"; }

# Sets KEY=value only when KEY currently has an empty value.
fill() {
  local key="$1" value="$2"
  if grep -qE "^${key}=$" .env; then
    # '|' as sed delimiter; generated values contain only [A-Za-z0-9+/=].
    sed -i.bak "s|^${key}=$|${key}=${value}|" .env && rm -f .env.bak
    echo "Generated ${key}"
  fi
}

fill APP_KEY "base64:$(openssl rand -base64 32)"
fill MYSQL_PASSWORD "$(rand_hex 24)"
fill MYSQL_ROOT_PASSWORD "$(rand_hex 24)"
fill REDIS_PASSWORD "$(rand_hex 24)"
fill AI_HMAC_SECRET_LARAVEL_TO_AI "$(rand_hex 32)"
fill AI_HMAC_SECRET_AI_TO_LARAVEL "$(rand_hex 32)"

chmod 600 .env
echo "Done. .env is git-ignored; keep it private."
