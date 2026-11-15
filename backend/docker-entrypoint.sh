#!/bin/sh
set -e

# Apply PHP-FPM dynamic pool tuning if customized via environment
if [ -f "/usr/local/etc/php-fpm.d/zz-conexa.conf" ]; then
  [ -n "$PHP_FPM_MAX_CHILDREN" ] && sed -i "s/pm.max_children = .*/pm.max_children = $PHP_FPM_MAX_CHILDREN/" /usr/local/etc/php-fpm.d/zz-conexa.conf
  [ -n "$PHP_FPM_START_SERVERS" ] && sed -i "s/pm.start_servers = .*/pm.start_servers = $PHP_FPM_START_SERVERS/" /usr/local/etc/php-fpm.d/zz-conexa.conf
  [ -n "$PHP_FPM_MIN_SPARE_SERVERS" ] && sed -i "s/pm.min_spare_servers = .*/pm.min_spare_servers = $PHP_FPM_MIN_SPARE_SERVERS/" /usr/local/etc/php-fpm.d/zz-conexa.conf
  [ -n "$PHP_FPM_MAX_SPARE_SERVERS" ] && sed -i "s/pm.max_spare_servers = .*/pm.max_spare_servers = $PHP_FPM_MAX_SPARE_SERVERS/" /usr/local/etc/php-fpm.d/zz-conexa.conf
  [ -n "$PHP_FPM_MAX_REQUESTS" ] && sed -i "s/pm.max_requests = .*/pm.max_requests = $PHP_FPM_MAX_REQUESTS/" /usr/local/etc/php-fpm.d/zz-conexa.conf
fi

# Wait for database connection
if [ -n "$DB_HOST" ] && [ -n "$DB_PORT" ]; then
  until nc -z -v -w30 "$DB_HOST" "$DB_PORT"; do
    echo "Waiting for database connection ($DB_HOST:$DB_PORT)..."
    sleep 2
  done
fi

# Run migrations and seed roles/permissions if starting the primary backend web service
if [ "$1" = "/usr/bin/supervisord" ]; then
  echo "Applying database migrations..."
  php artisan migrate --force --no-interaction
  echo "Seeding initial roles and baseline data..."
  php artisan db:seed --force --no-interaction

  if [ -n "$INITIAL_ADMIN_EMAIL" ] && [ -n "$INITIAL_ADMIN_PASSWORD" ]; then
    echo "Initializing super admin user..."
    php artisan conexa:init-admin --no-interaction || true
  fi

  # Auto-import packaged flows if available and not yet loaded
  for flow_file in database/seeders/data/flows/*.json; do
    if [ -f "$flow_file" ]; then
      echo "Checking flow package: $flow_file..."
      php artisan conexa:import-flow "$flow_file" --with-channels --no-interaction || true
    fi
  done

  mkdir -p storage/app/public
  echo "Linking public storage..."
  php artisan storage:link --no-interaction || true

  if [ "$APP_ENV" = "production" ]; then
    echo "Caching configuration and routes for production..."
    php artisan config:clear --no-interaction || true
    php artisan config:cache --no-interaction
    php artisan route:cache --no-interaction
  fi
fi

# Ensure correct permissions for web server (www-data) on storage and cache directories
mkdir -p storage/app/public
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
mkdir -p storage/logs
touch storage/logs/laravel.log
chown www-data:www-data storage/logs/laravel.log
chmod 666 storage/logs/laravel.log

# Execute main process
exec "$@"
