# [BRAND] — Learn Skills. Build Income.

Membership-based EdTech/LMS platform. An API-first Laravel backend serves a React SPA now and a Flutter app later.

- `backend/`: Laravel 13 API (`/api/v1`)
- `frontend/`: React 18 + Vite + TypeScript SPA
- `docs/`: [PROGRESS](docs/PROGRESS.md) · [API](docs/API.md) · [ARCHITECTURE](docs/ARCHITECTURE.md)

## Run with Docker (Sail)

```bash
cd backend && cp .env.example .env
composer install && ./vendor/bin/sail up -d      # MySQL 8.4, Redis, Mailpit (:8025), Meilisearch (:7700)
./vendor/bin/sail artisan key:generate && ./vendor/bin/sail artisan migrate --seed && ./vendor/bin/sail artisan storage:link
./vendor/bin/sail artisan horizon                 # queues (mail, notifications, ...)
cd ../frontend && cp .env.example .env && npm install && npm run dev   # http://localhost:5180
```

## Run locally (macOS, no Docker)

```bash
# Backend — http://localhost:8010
cd backend
export PATH="/opt/homebrew/opt/php@8.3/bin:$PATH"
composer install
cp .env.example .env && php artisan key:generate   # .env.example targets Sail hosts: set DB_CONNECTION=sqlite (or DB_HOST=127.0.0.1), REDIS_HOST/MAIL_HOST=127.0.0.1
php artisan migrate --seed && php artisan storage:link
php artisan serve --port=8010
php artisan queue:work --queue=mail,notifications,default   # or `php artisan horizon` once Redis is installed

# Frontend — http://localhost:5180
cd frontend
cp .env.example .env
npm install
npm run dev
```

Demo logins (password `password`): `superadmin@`, `admin@`, `instructor@`, `meera@`, `arjun@`, `starter@`, `pro@`, `elite@`, `student@example.com`.

Locally, verification emails and OTP codes are written to `backend/storage/logs/laravel.log` once the queue worker processes them.

## Checks

```bash
cd backend  && ./vendor/bin/pest && ./vendor/bin/phpstan analyse && ./vendor/bin/pint --test
cd frontend && npm run lint && npm run typecheck && npm test && npm run build
```
