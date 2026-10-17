FROM nginx:alpine

# Копируем публичную статику внутрь веб-сервера
COPY public /var/www/public

# Копируем общие и боевые правила Nginx
COPY docker/common/nginx/conf.d/ /etc/nginx/conf.d/common/
COPY docker/prod/nginx/conf.d/default.conf /etc/nginx/conf.d/default.conf

WORKDIR /var/www
