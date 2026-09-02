# QA image for the bundle: PHP 8.4 with the extensions Symfony/PHPStan/PHPUnit need,
# composer, and the bundle's dependencies baked in. Source is mounted at run time so
# analysis and tests run against the working tree without a rebuild.
FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" intl zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json ./
# jcupitt/vips (dev-only, for PHPStan) declares ext-ffi; the analyser only needs the class files.
RUN composer install --no-interaction --no-progress --ignore-platform-req=ext-ffi
