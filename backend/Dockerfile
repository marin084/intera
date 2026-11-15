FROM php:8.4-fpm-alpine

# Install system dependencies & PostgreSQL / Redis PHP extensions + Nginx + Supervisor
RUN apk add --no-cache \
    postgresql-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    curl \
    netcat-openbsd \
    nginx \
    supervisor \
    $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis opcache \
    && docker-php-ext-install pdo pdo_pgsql pgsql zip pcntl bcmath \
    && echo 'variables_order = "EGPCS"' > /usr/local/etc/php/conf.d/docker-vars.ini \
    && apk del $PHPIZE_DEPS \
    && mkdir -p /run/nginx /run/supervisord /etc/supervisor/conf.d

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy application files
COPY . .

# Ensure runtime directories exist with proper permissions before package discovery
RUN touch /var/www/html/.env \
    && mkdir -p /var/www/html/storage/framework/cache/data \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/views \
    /var/www/html/storage/logs \
    /var/www/html/bootstrap/cache \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Install dependencies (runs @php artisan package:discover with bootstrap/cache guaranteed)
RUN composer install --optimize-autoloader --no-interaction \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Server configurations
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-conexa.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/docker-php.ini
COPY docker/opcache.ini /usr/local/etc/php/conf.d/docker-opcache.ini

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
