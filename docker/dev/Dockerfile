# Базовый образ common, собранный ранее
ARG BASE_IMAGE=app-common:latest
FROM ${BASE_IMAGE}

# 1. Забираем Composer для работы внутри контейнера (установка dev-пакетов, генерация классов)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 2. Аргументы для синхронизации прав
ARG UID=1000
ARG GID=1000

# 3. Меняем идентификаторы системного пользователя www-data под хост
# Пакет shadow уже установлен в common/Dockerfile
RUN usermod -u ${UID} www-data \
    && groupmod -g ${GID} www-data

# 4. Создаем домашнюю директорию для www-data (Composer хранит там глобальный кэш и токены)
RUN mkdir -p /home/www-data/.composer \
    && chown -R www-data:www-data /home/www-data

# 5. Переключаемся на пользователя www-data
USER www-data

EXPOSE 9000

CMD ["php-fpm"]
