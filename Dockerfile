FROM dunglas/frankenphp:1-php8.3

# PHP extensions: zip for Composer, pdo_sqlite for the content database (week 2)
RUN install-php-extensions zip intl opcache pdo_sqlite

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
