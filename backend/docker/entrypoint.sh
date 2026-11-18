#!/usr/bin/env sh
# Prepares the bind-mounted Laravel app, then runs the service command.
# Only the `api` service sets RUN_MIGRATIONS=1, so migrations/seeding happen exactly once.
set -e
cd /var/www/backend

if [ ! -f vendor/autoload.php ]; then
  echo "[buildlead] composer install…"
  composer install --no-interaction --no-progress --prefer-dist
fi

if [ ! -f .env ]; then
  echo "[buildlead] creating .env from .env.example"
  cp .env.example .env
fi
if ! grep -q '^APP_KEY=base64:' .env && [ -z "$APP_KEY" ]; then
  php artisan key:generate --force
fi

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

if [ "$RUN_MIGRATIONS" = "1" ]; then
  echo "[buildlead] waiting for MySQL at ${DB_HOST}:${DB_PORT:-3306}…"
  until php -r 'try { new PDO("mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT") ?: 3306), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); } catch (Throwable $e) { exit(1); }' 2>/dev/null; do sleep 2; done

  php artisan migrate --force
  # Seed the demo company on first start only (empty users table).
  if [ "$(php artisan tinker --execute='echo \App\Models\User::count();' 2>/dev/null | tail -n1)" = "0" ]; then
    echo "[buildlead] seeding demo data…"
    php artisan db:seed --force
  fi
fi

exec "$@"
