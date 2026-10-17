# Имя базового образа common (передается аргументом или берется по умолчанию)
ARG BASE_IMAGE=app-common:latest

# ==============================================================================
# ЭТАП 1: Builder (скачивание зависимостей и оптимизация автозагрузчика)
# ==============================================================================
FROM ${BASE_IMAGE} AS builder

# 1. Забираем Composer (он нужен только для сборки, в итоговый образ не пойдет)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 2. Копируем манифесты зависимостей
COPY composer.json composer.lock ./

# 3. Скачиваем ТОЛЬКО продакшен-зависимости (кэшируется, пока не изменен composer.lock)
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --no-autoloader \
    --no-scripts

# 4. Копируем остальной исходный код приложения
COPY . .

# 5. Генерируем оптимизированную карту классов автозагрузки (classmap)
RUN composer dump-autoload --optimize --no-dev


# ==============================================================================
# ЭТАП 2: Финальный Runtime-образ (то, что идет на сервер)
# ==============================================================================
FROM ${BASE_IMAGE}

# 1. Копируем исходный код с правами пользователя www-data
COPY --chown=www-data:www-data . .

# 2. Забираем готовую оптимизированную папку vendor из этапа builder
COPY --from=builder --chown=www-data:www-data /var/www/vendor ./vendor

# 3. Выдаем права на запись служебным папкам Laravel
RUN chmod -R 775 storage bootstrap/cache

# 4. Переключаемся на безопасного системного пользователя
USER www-data

EXPOSE 9000

CMD ["php-fpm"]
