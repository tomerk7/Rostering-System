FROM php:8.4-fpm

WORKDIR /var/www

# Postgres (and sqlite, to mirror the Laravel image) PDO drivers. The vanilla
# app talks to the same Postgres container as Laravel via PDO pgsql.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpq-dev \
        libsqlite3-dev \
    && docker-php-ext-install \
        pdo_pgsql \
        pdo_sqlite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Keep docker env_file vars visible to getenv() inside FPM workers.
COPY php-fpm.d/zz-clear-env.conf /usr/local/etc/php-fpm.d/zz-clear-env.conf

# php-fpm listens on 9000; nginx reaches it over the compose network.
EXPOSE 9000
