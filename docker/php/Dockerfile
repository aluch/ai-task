FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
        git \
        bash \
        icu-dev \
        libzip-dev \
        postgresql-dev \
        linux-headers \
        autoconf \
        g++ \
        make \
        $PHPIZE_DEPS \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        intl \
        opcache \
        zip \
        bcmath \
        sockets \
        pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del autoconf g++ make $PHPIZE_DEPS \
    && rm -rf /tmp/* /var/cache/apk/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY php-fpm.ini /usr/local/etc/php/php.ini
COPY php-cli.ini /usr/local/etc/php/php-cli.ini

WORKDIR /var/www/app

# Заблаговременно создать var/cache и var/log с правами uid 1000 (хостовый
# пользователь). Named volumes (bot_cache/scheduler_cache/php_cache) при
# первом создании наследуют владельца этой директории в образе — так
# smoke-команды от --user 1000:1000 могут писать в cache без
# permission denied.
RUN mkdir -p /var/www/app/var/cache /var/www/app/var/log \
    && chown -R 1000:1000 /var/www/app/var

EXPOSE 9000

CMD ["php-fpm"]
