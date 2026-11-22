#!/usr/bin/env bash
# Deploy/update the Laravel app on shared hosting where the domain's document root
# is this repository folder (e.g. public_html/dahab) and cannot point at public/.
#
# It copies gold-scanner-laravel/* up into this folder (the root .htaccess then
# routes requests into public/). Your .env, vendor/ and uploaded Excel files are kept.
#
# Usage (from the repository folder):   bash deploy-hostinger.sh
set -euo pipefail
cd "$(dirname "$0")"

echo "==> Restoring tracked files and pulling latest code"
git checkout -- gold-scanner-laravel 2>/dev/null || true
git pull --ff-only

echo "==> Copying app files into $(pwd)"
cp -a gold-scanner-laravel/. .

if [ ! -f .env ]; then
  echo "==> No .env found: creating one from .env.example (EDIT IT: keys, APP_URL, APP_DEBUG=false)"
  cp .env.example .env
  NEED_KEY=1
fi

echo "==> Installing PHP packages"
if command -v composer >/dev/null 2>&1; then
  composer install --no-dev --optimize-autoloader --no-interaction
else
  php composer.phar install --no-dev --optimize-autoloader --no-interaction
fi

[ "${NEED_KEY:-0}" = 1 ] && php artisan key:generate --force

chmod -R ug+rwX storage bootstrap/cache
php artisan optimize:clear
php artisan config:cache
php artisan route:cache

if ! ls storage/app/private/inventory/files/*.xls* >/dev/null 2>&1 \
   && ! ls storage/app/private/inventory/inventory.xls* >/dev/null 2>&1; then
  echo "==> No inventory file yet: installing demo data (upload the real file from the UI later)"
  php artisan gold:demo || true
fi

echo "==> Status"
php artisan gold:status
echo "Done."
