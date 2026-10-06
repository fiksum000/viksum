FROM php:8.4-cli
RUN apt-get update && apt-get install -y git unzip libzip-dev libicu-dev libpng-dev libonig-dev curl mariadb-client && docker-php-ext-install pdo_mysql intl bcmath zip && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .
RUN composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private storage/app/public && chmod -R ug+rw storage bootstrap/cache
EXPOSE 8000
CMD ["php","artisan","serve","--host=0.0.0.0","--port=8000"]
