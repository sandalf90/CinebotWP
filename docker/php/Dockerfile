ARG PHP_VERSION=7.4
FROM php:${PHP_VERSION}-cli
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip zip libzip-dev default-mysql-client \
 && docker-php-ext-install mysqli zip \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /plugin
