FROM php:8.5-fpm-alpine

# Скачиваем утилиту для расширений
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

# Ставим ВСЕ расширения, нужные приложению
RUN install-php-extensions \
    pdo_pgsql \
    redis \
    zip \
    pcntl \
    posix \
    opcache

# Ставим утилиту shadow (она позволит в dev менять UID/GID пользователя www-data)
RUN apk add --no-cache shadow

WORKDIR /var/www
