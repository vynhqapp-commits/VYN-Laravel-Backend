#!/bin/sh
set -e

# Create .env from example if not exists
cp .env.example .env

# Generate app key and cache configs
php artisan key:generate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Run migrations
php artisan migrate --force

exec php artisan serve --host=0.0.0.0 --port=8080
