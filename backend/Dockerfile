# BuildLead CRM API: PHP 8.3 CLI image used by the api, queue and scheduler services.
FROM php:8.3-cli-bookworm

RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip libzip-dev libicu-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev default-mysql-client \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql zip intl bcmath gd pcntl \
 && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/backend

# Dependencies first for layer caching; the source is bind-mounted in docker-compose for live editing.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --optimize --no-scripts

COPY docker/entrypoint.sh /usr/local/bin/buildlead-entrypoint
RUN chmod +x /usr/local/bin/buildlead-entrypoint

ENTRYPOINT ["buildlead-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8020", "--no-reload"]
