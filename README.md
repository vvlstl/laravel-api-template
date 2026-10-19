# Laravel API Template

Шаблон API-бэкенда Laravel под SPA-фронтенд: аутентификация на Sanctum, админ-панель Orchid, очереди с мониторингом Horizon, автогенерация OpenAPI-документации.

## Стек

| Задача | Инструмент |
|---|---|
| Фреймворк | Laravel 13, PHP 8.5 |
| Аутентификация API | [Sanctum](https://laravel.com/docs/sanctum) — cookie для SPA + персональные токены |
| Админ-панель | [Orchid](https://orchid.software) |
| Очереди и мониторинг | [Horizon](https://laravel.com/docs/horizon) (Redis) |
| Документация API | [Scramble](https://scramble.dedoc.com) — OpenAPI на `/api/documentation` |
| Тесты | [Pest](https://pestphp.com) |
| Стиль кода | [Pint](https://laravel.com/docs/pint) |
| Статический анализ | [Larastan](https://github.com/larastan/larastan) (PHPStan) |
| Git-хуки | [CaptainHook](https://captainhookphp.github.io) — формат коммитов, Pint, тесты на push |
| CI | GitHub Actions — Pint + PHPStan + Pest |
| Инфраструктура | Docker (nginx + php-fpm + PostgreSQL 16 + Redis 7), dev и prod конфигурации |

## Быстрый старт

Локально:

```sh
composer setup          # install + .env + key + migrate + npm build
php artisan orchid:admin  # первый админ для панели
composer dev            # dev-серверы (laravel dev)
```

Через Docker (nginx на `:8000`, отдельный контейнер Horizon):

```sh
docker compose -f compose.dev.yaml up -d --build
docker compose -f compose.dev.yaml exec app php artisan orchid:admin
```

Основные адреса в dev-окружении:

- API — `/api/v1/...`
- Админ-панель — `/admin` (Orchid)
- Документация API — `/api/documentation` (Scramble)
- Мониторинг очередей — `/horizon` (доступ по пермишену `platform.horizon`)

## Аутентификация

SPA-сценарий (cookie + CSRF):

1. `GET /sanctum/csrf-cookie` — получить `XSRF-TOKEN`;
2. далее запросы с credentials, домен фронтенда указать в `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN` (`.env`).

Модель `App\Models\Customer` — клиент с авторизацией по телефону (`phone`, `phone_verified_at`). Расширяется под конкретный проект.

Базовое поведение API (JSON-ошибки 404/401, CORS, CSRF-cookie) покрыто тестами в `tests/Feature/ApiBaseTest.php`.

## Качество кода

```sh
composer test                       # Pest
./vendor/bin/pint --test            # стиль
./vendor/bin/phpstan analyse        # статический анализ
```

Хуки CaptainHook активируются автоматически при `composer install` (плагин `captainhook/plugin-composer`): заголовок коммита — Conventional Commits, pre-commit — Pint, pre-push — Pest.

## Новый проект из шаблона

1. GitHub → **Use this template** → создать репозиторий проекта.
2. `composer.json`: поменять `name`, `description`.
3. `.env`: `APP_NAME`, `APP_URL`, `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN` — под домены проекта.
4. Переписать этот README под проект.
5. Если phone-авторизация клиентов не нужна — удалить `app/Models/Customer.php` и миграцию `create_customers_table`; базовые тесты от неё не зависят.
6. Демо-экраны Orchid (`app/Orchid/Screens/Examples`, `app/Orchid/Layouts/Examples`, `routes/platform.php`, меню в `app/Orchid/PlatformProvider.php`) — справочник; удалить, когда неактуальны.
