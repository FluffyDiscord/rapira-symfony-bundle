# QA image for the bundle: PHP 8.4 with the extensions Symfony/PHPUnit need,
# composer, and the bundle's dependencies baked in. Source is mounted at run time so
# analysis and tests run against the working tree without a rebuild.
FROM php:8.4-cli@sha256:debadc2e36b857657846e2e952cbaf52702e55482dd3c2c43806f68d8c534a70

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" intl zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
# jcupitt/vips (dev-only, for Mago) declares ext-ffi; the analyser only needs the class files.
RUN composer install --no-interaction --no-progress --ignore-platform-req=ext-ffi
# @invariant Mago's first run downloads its binary, so it runs here to keep QA offline.
# Its wrapper loads Composer's autoloader, which requires this polyfill.
COPY src/Resources/services_resetter_polyfill.php src/Resources/
RUN php vendor/bin/mago --version
