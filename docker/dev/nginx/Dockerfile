FROM nginx:alpine

# Копируем общую конфигурацию
COPY docker/common/nginx/conf.d/ /etc/nginx/conf.d/common/

# Копируем конфигурацию дев-хоста
COPY docker/dev/nginx/conf.d/default.conf /etc/nginx/conf.d/default.conf

WORKDIR /var/www
