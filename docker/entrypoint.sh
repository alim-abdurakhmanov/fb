#!/bin/sh
set -e
cd /var/www/html

if [ ! -f .env ]; then
  if [ -f .env.docker.example ]; then
    cp .env.docker.example .env
    echo "[finbuild] Создан .env из .env.docker.example"
  elif [ -f .env.example ]; then
    cp .env.example .env
    echo "[finbuild] Создан .env из .env.example — проверьте DB_HOST=db"
  fi
fi

if [ ! -d vendor ] || [ ! -f vendor/autoload.php ]; then
  echo "[finbuild] composer install..."
  composer install --no-interaction --prefer-dist
fi

mkdir -p uploads
chown -R www-data:www-data uploads 2>/dev/null || true

exec "$@"
