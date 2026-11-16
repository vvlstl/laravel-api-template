# Архитектура и развертывание Laravel REST API в Docker

Комплексное руководство по устройству, настройке и эксплуатации модульного контейнеризированного окружения для Laravel API на базе PHP-FPM, Nginx, PostgreSQL, Redis и Horizon.

## 1. Архитектурная модель

Инфраструктура построена на принципах **слоистости (layering)**, **микросервисной изоляции (single-responsibility)** и **наименьших привилегий (least privilege)**.

```
                    ┌─────────────────────────┐
                    │      docker/common      │
                    │ PHP-FPM базовый + Nginx │
                    └────────────┬────────────┘
                                 │
                 ┌───────────────┴───────────────┐
                 ▼                               ▼
       ┌───────────────────┐           ┌───────────────────┐
       │      docker/dev   │           │     docker/prod   │
       │  (Composer, UID,  │           │   (Multi-stage,   │
       │ volumes, таймауты)│           │ без Composer, r/o)│
       └───────────────────┘           └───────────────────┘

```

### Ключевые принципы:

1. **Слои (Layers):**

   * `common`: неизменяемый фундамент (системные библиотеки, расширения ядра PHP `pdo_pgsql`, `redis`, `zip`, `pcntl`, `posix`, `opcache`, общие сниппеты Nginx).

   * `dev`: локальное удобство (Composer, динамическое монтирование кода, подстройка UID/GID под пользователя хоста, увеличенные таймауты под Xdebug).

   * `prod`: максимальная безопасность (сборка `builder`, запекание кода внутрь, отсутствие Composer и лишних утилит в финальном рантайме).

2. **Изоляция сети:**

   * Контейнеры общаются исключительно внутри изолированной bridge-сети по сетевым алиасам: `app` (9000), `nginx` (80), `db` (5432), `redis` (6379).

3. **Единый источник правды:**

   * Все параметры и секреты хранятся в `.env`. Docker Compose считывает `.env` и безопасно передает конфигурацию в системные переменные контейнеров.

4. **Очереди:**

   * Сервис `horizon` использует тот же образ, что и `app`, но запускает `php artisan horizon` вместо `php-fpm`.

## 2. Карта файлов и директорий проекта

```
.
├── .dockerignore                           # Фильтрация контекста сборки
├── .env                                    # Конфигурация локального окружения
├── .env.example                            # Эталонный шаблон переменных
├── compose.dev.yaml                        # Оркестрация локальной разработки
├── docker-compose.yml                      # Оркестрация боевого окружения
└── docker/
    ├── common/
    │   ├── Dockerfile                      # Базовый образ PHP-FPM
    │   └── nginx/
    │       └── conf.d/
    │           ├── 20-status-path.conf     # Эндпоинт проверки статуса /healthz
    │           └── laravel.conf            # Общий роутинг Laravel и безопасность
    ├── dev/
    │   ├── Dockerfile                      # Dev-образ PHP (Composer + синхронизация UID)
    │   └── nginx/
    │       ├── Dockerfile                  # Сборка dev Nginx
    │       └── conf.d/
    │           └── default.conf            # Dev-хост (таймауты для отладки)
    └── prod/
        ├── Dockerfile                      # Multi-stage сборка (builder -> чистый рантайм)
        └── nginx/
            ├── Dockerfile                  # Prod Nginx с запеканием public/
            └── conf.d/
                └── default.conf            # Prod-хост (кэширование ассетов)

```

## 3. Конфигурационные файлы

### 3.1. `.dockerignore` (Корень проекта)

Отсекает лишние данные от демона Docker при вызове `COPY . .`:

```
# Git
.git
.gitignore
.gitattributes
.github

# Docker
compose*.yaml
Dockerfile*
docker-compose.yml
.dockerignore
docker/*.md

# Documentation
README.md
CHANGELOG.md
LICENSE
docs/

# Dependencies (будут установлены через composer)
node_modules
vendor
src/node_modules
src/vendor

# Environment files
.env
.env.*
.env.example

# Logs
*.log
logs/
storage/logs/*
!storage/logs/.gitignore
npm-debug.log*
yarn-debug.log*
yarn-error.log*

# Cache and temp
bootstrap/cache/*.php
!bootstrap/cache/.gitignore
.cache/
temp/
tmp/
*.tmp
*.swp
*.swo
*~

# IDE
.vscode/
.idea/
*.sublime-project
*.sublime-workspace
.DS_Store
_ide_helper.php
_ide_helper_models.php
.phpstorm.meta.php
.phpunit.cache

# Testing
tests/
phpunit.xml
.phpunit.result.cache
coverage/
phpstan.neon
captainhook.json

# Build artifacts
public/hot
public/storage
public/build
public/css
public/js
public/mix-manifest.json

# Other
.editorconfig
.php-cs-fixer.php
.styleci.yml
Homestead.json
Homestead.yaml
Thumbs.db
.claude
.serena
.codex
.cursor
.ai-factory

```

### 3.2. Общий слой (`docker/common/`)

#### `docker/common/Dockerfile`

Базовый образ PHP-FPM. Собирается отдельно и один раз, наследуется слоями `dev` и `prod`:

```
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

```

#### `docker/common/nginx/conf.d/20-status-path.conf`

Маршрут для мониторинга живости сервиса:

```
location = /healthz {
    access_log off;
    default_type text/plain;
    return 200 "healthy\n";
}

```

#### `docker/common/nginx/conf.d/laravel.conf`

Базовые правила для Laravel и защита от утечки скрытых файлов:

```
# Корневой роутинг Laravel
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

# Отключение лишних логов для служебных запросов
location = /favicon.ico {
    access_log off;
    log_not_found off;
}

location = /robots.txt {
    access_log off;
    log_not_found off;
}

# Запрет доступа к любым скрытым файлам (.env, .git, etc.)
location ~ /\.(?!well-known).* {
    deny all;
}

```

### 3.3. Слой разработки (`docker/dev/`)

#### `docker/dev/Dockerfile`

Наследуется от базового образа `common`, добавляет Composer и синхронизирует UID/GID пользователя `www-data` с пользователем хоста:

```
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

```

#### `docker/dev/nginx/conf.d/default.conf`

```
server {
    listen 80;
    server_name localhost;
    root /var/www/public;

    index index.php index.html;
    charset utf-8;
    client_max_body_size 20M;

    # Подключаем общие правила Laravel
    include /etc/nginx/conf.d/common/laravel.conf;

    # Подключаем проверку статуса
    include /etc/nginx/conf.d/common/20-status-path.conf;

    # Обработка PHP для локальной среды
    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass app:9000;
        fastcgi_index index.php;

        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;

        # Увеличенные таймауты под Xdebug / шаги отладки
        fastcgi_connect_timeout 300;
        fastcgi_send_timeout 300;
        fastcgi_read_timeout 300;

        fastcgi_hide_header X-Powered-By;
    }
}

```

#### `docker/dev/nginx/Dockerfile`

```
FROM nginx:alpine

# Копируем общую конфигурацию
COPY docker/common/nginx/conf.d/ /etc/nginx/conf.d/common/

# Копируем конфигурацию дев-хоста
COPY docker/dev/nginx/conf.d/default.conf /etc/nginx/conf.d/default.conf

WORKDIR /var/www

```

### 3.4. Слой продакшена (`docker/prod/`)

#### `docker/prod/Dockerfile`

Multi-stage сборка: Composer работает только на этапе `builder`, в финальный образ переносится только `vendor/`:

```
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

```

#### `docker/prod/nginx/conf.d/default.conf`

```
server {
    listen 80;
    server_name _;
    root /var/www/public;

    index index.php;
    charset utf-8;
    client_max_body_size 20M;

    # Кэширование статических файлов (картинки, шрифты, иконки)
    location ~* \.(ico|css|js|gif|jpe?g|png|woff2?|eot|ttf|svg)$ {
        expires 1y;
        add_header Cache-Control "public, no-transform, immutable";
        access_log off;
    }

    # Подключаем общие правила Laravel
    include /etc/nginx/conf.d/common/laravel.conf;

    # Подключаем проверку статуса
    include /etc/nginx/conf.d/common/20-status-path.conf;

    # Обработка PHP
    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass app:9000;
        fastcgi_index index.php;

        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;

        # Стандартные продакшен-таймауты
        fastcgi_connect_timeout 60;
        fastcgi_send_timeout 60;
        fastcgi_read_timeout 60;

        fastcgi_hide_header X-Powered-By;
    }
}

```

#### `docker/prod/nginx/Dockerfile`

```
FROM nginx:alpine

# Копируем публичную статику внутрь веб-сервера
COPY public /var/www/public

# Копируем общие и боевые правила Nginx
COPY docker/common/nginx/conf.d/ /etc/nginx/conf.d/common/
COPY docker/prod/nginx/conf.d/default.conf /etc/nginx/conf.d/default.conf

WORKDIR /var/www

```

### 3.5. Переменные окружения: `.env`

Конфигурация для локальной работы без использования `localhost` или `127.0.0.1` для связанных сервисов (листинг сокращен до переменных, значимых для Docker-окружения):

```
APP_NAME=Laravel
APP_ENV=local
APP_KEY=base64:<генерируется командой php artisan key:generate>
APP_DEBUG=true
APP_URL=http://localhost

LOG_CHANNEL=stack
LOG_LEVEL=debug

# Подключение к PostgreSQL по имени контейнера
DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=laravel
DB_USERNAME=postgres
DB_PASSWORD=secret

# Подключение к Redis по имени контейнера
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379

```

### 3.6. Оркестрация: `compose.dev.yaml`

```
services:
  app:
    build:
      context: .
      dockerfile: docker/dev/Dockerfile
      args:
        UID: ${UID:-1000}
        GID: ${GID:-1000}
    container_name: api_app_dev
    restart: unless-stopped
    working_dir: /var/www
    volumes:
      - .:/var/www
    environment: &app-env
      APP_ENV: local
      APP_DEBUG: "true"
      DB_CONNECTION: pgsql
      DB_HOST: db
      DB_PORT: 5432
      DB_DATABASE: ${DB_DATABASE:-laravel}
      DB_USERNAME: ${DB_USERNAME:-postgres}
      DB_PASSWORD: ${DB_PASSWORD:-secret}
      REDIS_HOST: redis
      REDIS_PORT: 6379
      REDIS_PASSWORD: ${REDIS_PASSWORD:-null}
      QUEUE_CONNECTION: redis
    depends_on:
      db:
        condition: service_healthy
      redis:
        condition: service_healthy
    networks:
      - api-dev-network

  horizon:
    build:
      context: .
      dockerfile: docker/dev/Dockerfile
      args:
        UID: ${UID:-1000}
        GID: ${GID:-1000}
    container_name: api_horizon_dev
    restart: unless-stopped
    working_dir: /var/www
    command: php artisan horizon
    volumes:
      - .:/var/www
    environment: *app-env
    depends_on:
      db:
        condition: service_healthy
      redis:
        condition: service_healthy
    networks:
      - api-dev-network

  nginx:
    build:
      context: .
      dockerfile: docker/dev/nginx/Dockerfile
    container_name: api_nginx_dev
    restart: unless-stopped
    ports:
      - "${FORWARD_NGINX_PORT:-8000}:80"
    volumes:
      - .:/var/www:ro
      - ./docker/dev/nginx/conf.d/default.conf:/etc/nginx/conf.d/default.conf:ro
    healthcheck:
      test: [ "CMD-SHELL", "wget -qO- http://localhost/healthz || exit 1" ]
      interval: 10s
      timeout: 3s
      retries: 3
      start_period: 5s
    depends_on:
      - app
    networks:
      - api-dev-network

  db:
    image: postgres:16-alpine
    container_name: api_db_dev
    restart: unless-stopped
    ports:
      - "${FORWARD_DB_PORT:-5432}:5432"
    environment:
      POSTGRES_DB: ${DB_DATABASE:-laravel}
      POSTGRES_USER: ${DB_USERNAME:-postgres}
      POSTGRES_PASSWORD: ${DB_PASSWORD:-secret}
    volumes:
      - pgdata_dev:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U ${DB_USERNAME:-postgres} -d ${DB_DATABASE:-laravel}"]
      interval: 5s
      timeout: 5s
      retries: 5
      start_period: 10s
    networks:
      - api-dev-network

  redis:
    image: redis:7-alpine
    container_name: api_redis_dev
    restart: unless-stopped
    ports:
      - "${FORWARD_REDIS_PORT:-6379}:6379"
    volumes:
      - redisdata_dev:/data
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 5s
      timeout: 3s
      retries: 5
    networks:
      - api-dev-network

volumes:
  pgdata_dev:
    driver: local
  redisdata_dev:
    driver: local

networks:
  api-dev-network:
    driver: bridge

```

### 3.7. Оркестрация: `docker-compose.yml`

```
services:
    app:
        build:
            context: .
            dockerfile: docker/prod/Dockerfile
        container_name: api_app_prod
        restart: always
        environment: &app-env
            APP_ENV: production
            APP_DEBUG: "false"
            DB_CONNECTION: pgsql
            DB_HOST: db
            DB_PORT: 5432
            DB_DATABASE: ${DB_DATABASE}
            DB_USERNAME: ${DB_USERNAME}
            DB_PASSWORD: ${DB_PASSWORD}
            REDIS_HOST: redis
            REDIS_PORT: 6379
            REDIS_PASSWORD: ${REDIS_PASSWORD}
            QUEUE_CONNECTION: redis
        depends_on:
            db:
                condition: service_healthy
            redis:
                condition: service_healthy
        networks:
            - api-prod-network

    horizon:
        build:
            context: .
            dockerfile: docker/prod/Dockerfile
        container_name: api_horizon_prod
        restart: always
        command: php artisan horizon
        stop_grace_period: 60s
        environment: *app-env
        depends_on:
            db:
                condition: service_healthy
            redis:
                condition: service_healthy
        healthcheck:
            test: ["CMD-SHELL", "php artisan horizon:status | grep -q running"]
            interval: 30s
            timeout: 10s
            retries: 3
            start_period: 20s
        networks:
            - api-prod-network

    nginx:
        build:
            context: .
            dockerfile: docker/prod/nginx/Dockerfile
        container_name: api_nginx_prod
        restart: always
        ports:
            - "80:80"
        healthcheck:
            test: [ "CMD-SHELL", "wget -qO- http://localhost/healthz || exit 1" ]
            interval: 10s
            timeout: 3s
            retries: 3
            start_period: 5s
        depends_on:
            - app
        networks:
            - api-prod-network

    db:
        image: postgres:16-alpine
        container_name: api_db_prod
        restart: always
        environment:
            POSTGRES_DB: ${DB_DATABASE}
            POSTGRES_USER: ${DB_USERNAME}
            POSTGRES_PASSWORD: ${DB_PASSWORD}
        volumes:
            - pgdata_prod:/var/lib/postgresql/data
        healthcheck:
            test: ["CMD-SHELL", "pg_isready -U ${DB_USERNAME} -d ${DB_DATABASE}"]
            interval: 10s
            timeout: 5s
            retries: 5
            start_period: 20s
        networks:
            - api-prod-network

    redis:
        image: redis:7-alpine
        container_name: api_redis_prod
        restart: always
        command: >
            sh -c '
              if [ -n "$$REDIS_PASSWORD" ]; then
                redis-server --requirepass "$$REDIS_PASSWORD" --appendonly yes
              else
                redis-server --appendonly yes
              fi
            '
        environment:
            REDIS_PASSWORD: ${REDIS_PASSWORD:-}
        volumes:
            - redisdata_prod:/data
        healthcheck:
            test: ["CMD-SHELL", "redis-cli ping | grep -q PONG || redis-cli -a \"$$REDIS_PASSWORD\" ping | grep -q PONG"]
            interval: 10s
            timeout: 5s
            retries: 5
        networks:
            - api-prod-network

volumes:
    pgdata_prod:
        driver: local
    redisdata_prod:
        driver: local

networks:
    api-prod-network:
        driver: bridge

```

## 4. Разбор ключевых механизмов

### Почему Composer не выносится в `docker/common`

1. **Уменьшение поверхности атаки (Attack Surface):** На боевом сервере не должно быть инструментов, способных загружать или компилировать код из внешних источников.

2. **Multi-stage сборка:** В `prod/Dockerfile` Composer работает только на промежуточном этапе `builder`. В финальный рантайм переносится только папка `vendor/`.

3. **Размер образа:** Финальный продакшен-образ не содержит лишних бинарников и библиотек.

### Механика переменных `${VARIABLE:-default}`

Синтаксис подстановки Compose связывает конфигурацию хоста с контейнерами:

* `${DB_DATABASE}`: считывает значение из локального файла `.env`.

* `:-laravel`: запасное значение (fallback), предотвращающее падение сервиса при отсутствии строки в `.env`.

* Docker Compose считывает `.env` на этапе инициализации и пробрасывает параметры в контейнер базы данных (`POSTGRES_DB`), который сам по себе не умеет работать с файлами `.env`.

### Якоря Compose (`&app-env` / `*app-env`)

Набор переменных окружения приложения объявляется один раз в сервисе `app` (якорь `&app-env`) и переиспользуется сервисом `horizon` (ссылка `*app-env`). Очереди должны видеть то же окружение, что и веб-контейнер — иначе рассинхрон конфигурации.

### Изоляция Nginx и PHP

В микросервисной архитектуре Nginx и PHP разделены по разным контейнерам:

* Nginx отвечает за прием TCP-соединений, TLS/SSL, отдачу статики и буферизацию.

* PHP-FPM выполняет только вычислительную логику приложения через сокет `app:9000`.

## 5. Пошаговый запуск и эксплуатация

### Шаг 1. Предварительная сборка базового слоя

Слои `dev` и `prod` наследуются от базового образа. Тег должен совпадать с `ARG BASE_IMAGE` в Dockerfile'ах — `app-common:latest`:

```
docker build -f docker/common/Dockerfile -t app-common:latest .

```

### Шаг 2. Запуск локального окружения

```
docker compose -f compose.dev.yaml up -d --build

```

### Шаг 3. Первичная инициализация приложения

```
# Установка vendor зависимостей
docker compose -f compose.dev.yaml exec app composer install

# Генерация ключа приложения
docker compose -f compose.dev.yaml exec app php artisan key:generate

# Применение миграций базы данных
docker compose -f compose.dev.yaml exec app php artisan migrate

```

### Шаг 4. Проверка состояния здоровья контейнеров

```
docker compose -f compose.dev.yaml ps

```

Все контейнеры (`app`, `horizon`, `nginx`, `db`, `redis`) должны отображать статус `Up (healthy)`.

Проверка маршрута healthcheck:

```
curl -i http://localhost:8000/healthz
# HTTP/1.1 200 OK
# healthy

```

Проверка доступности Redis из PHP:

```
docker compose -f compose.dev.yaml exec app php artisan tinker --execute="Illuminate\Support\Facades\Redis::ping();"
# true

```

Проверка воркеров Horizon:

```
docker compose -f compose.dev.yaml exec app php artisan horizon:status
# Horizon is powered on.

```

### Шаг 5. Остановка сервисов

```
docker compose -f compose.dev.yaml down

```

*(Тома `pgdata_dev` и `redisdata_dev` сохраняют данные на диске между перезапусками).*

## 6. Развертывание продакшена

Продакшен-оркестрация описана в `docker-compose.yml`. Все обязательные переменные (`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `REDIS_PASSWORD`) берутся из `.env` без fallback-значений — их отсутствие уронит запуск.

```
# 1. Собрать базовый слой (тег = ARG BASE_IMAGE из prod/Dockerfile)
docker build -f docker/common/Dockerfile -t app-common:latest .

# 2. Собрать и запустить все сервисы
docker compose -f docker-compose.yml up -d --build

# 3. Применить миграции и закешировать конфигурацию
docker compose -f docker-compose.yml exec app php artisan migrate --force
docker compose -f docker-compose.yml exec app php artisan config:cache
docker compose -f docker-compose.yml exec app php artisan route:cache

```

Порт 5432 PostgreSQL наружу не открывается — база доступна только внутри сети `api-prod-network`.
