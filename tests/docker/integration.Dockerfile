# End-to-end integration image: a minimal Symfony app that uses the bundle, served by the
# pinned Rapira binary over a PHP-embed library built from source on Debian trixie (the OS
# the nightly binary is built against). Extensions are trimmed to what this test app needs —
# the full production extension set is verified separately by the base image (IT-009).
#
# Build context is the bundle repo root.

# --- Build the PHP embed library + CLI from source ---
FROM debian:trixie-slim AS php-build

ARG PHP_VERSION=8.5.10
ARG PHP_SHA256=f5c0ac99b85b3d677de475c2e4f509f9b4f54663f3ee5a84d6d9481a521d4100

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl build-essential pkg-config autoconf bison re2c \
        libxml2-dev libssl-dev libcurl4-openssl-dev libsqlite3-dev libonig-dev zlib1g-dev libicu-dev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /usr/src/php
RUN curl -fsSL "https://www.php.net/distributions/php-${PHP_VERSION}.tar.gz" -o php.tar.gz \
    && echo "${PHP_SHA256}  php.tar.gz" | sha256sum -c - \
    && tar xzf php.tar.gz --strip-components=1 \
    && rm php.tar.gz

RUN ./configure \
        --prefix=/usr/local \
        --with-config-file-path=/usr/local/etc/php \
        --with-config-file-scan-dir=/usr/local/etc/php/conf.d \
        --enable-embed=shared --enable-cli --enable-opcache \
        --enable-session --enable-mbstring --enable-tokenizer --enable-ctype \
        --enable-filter --enable-fileinfo --enable-phar --enable-posix --enable-pcntl \
        --with-openssl --with-curl --with-zlib --with-pcre-jit --with-iconv \
        --enable-dom --enable-xml --enable-simplexml --enable-xmlreader --enable-xmlwriter \
        --enable-intl --with-pdo-sqlite --with-sqlite3 \
    && make -j"$(nproc)" && make install \
    && mkdir -p /usr/local/etc/php/conf.d

# --- Assemble the runtime with the Rapira binary and the app ---
FROM debian:trixie-slim AS runtime

RUN apt-get update && apt-get install -y --no-install-recommends \
        libxml2 libssl3t64 libcurl4t64 libsqlite3-0 libonig5 zlib1g libicu76 ca-certificates git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=php-build /usr/local/ /usr/local/
COPY --from=ghcr.io/rapira-rs/rapira:nightly-php8.5 /usr/local/bin/rapira /usr/local/bin/rapira
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN printf 'opcache.enable=1\nopcache.enable_cli=1\nmemory_limit=256M\nvariables_order = "EGPCS"\n' > /usr/local/etc/php/php.ini \
    && rapira --version && php -v

RUN groupadd --gid 1000 app && useradd --uid 1000 --gid 1000 --create-home app

COPY composer.json /bundle/composer.json
COPY worker.php /bundle/worker.php
COPY src /bundle/src
COPY config /bundle/config
COPY tests/docker/app /app

WORKDIR /app
RUN composer install --no-interaction --no-progress --no-dev \
    && APP_ENV=prod php bin/console cache:warmup

COPY tests/docker/integration-entrypoint.sh /usr/local/bin/integration-entrypoint.sh
RUN chmod +x /usr/local/bin/integration-entrypoint.sh \
    && chown -R app:app /app \
    && chown app:app /usr/local/etc/php/conf.d

USER app
EXPOSE 8000
ENTRYPOINT ["/usr/local/bin/integration-entrypoint.sh"]
