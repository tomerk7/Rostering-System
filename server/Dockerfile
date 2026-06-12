FROM php:8.4-cli

WORKDIR /var/www

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libsqlite3-dev \
        libzip-dev \
        procps \
    && docker-php-ext-install \
        pdo_pgsql \
        pdo_sqlite \
        zip \
        pcntl \
        sockets \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker-start.sh /var/www/docker-start.sh
COPY docker-start.prod.sh /var/www/docker-start.prod.sh
RUN chmod +x /var/www/docker-start.sh /var/www/docker-start.prod.sh

EXPOSE 8000

CMD ["sh", "/var/www/docker-start.sh"]
