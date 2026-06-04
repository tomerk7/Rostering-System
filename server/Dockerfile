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
        cron \
    && docker-php-ext-install \
        pdo_pgsql \
        pdo_sqlite \
        zip \
        pcntl \
        sockets \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN printf '%s\n' '* * * * * root cd /var/www && php artisan schedule:run >> /dev/null 2>&1' \
        > /etc/cron.d/laravel-scheduler \
    && chmod 0644 /etc/cron.d/laravel-scheduler

COPY docker-start.sh /var/www/docker-start.sh
RUN chmod +x /var/www/docker-start.sh

EXPOSE 8000

CMD ["sh", "/var/www/docker-start.sh"]
