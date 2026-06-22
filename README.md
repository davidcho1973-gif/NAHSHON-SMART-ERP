# NAHSHON SMART ERP

Laravel and Filament version of the SMART COMPANY ERP workflow.

This repository contains the converted application code only. Local runtime files such as `.env`, `vendor`, `node_modules`, `tools`, SQLite databases, logs, and caches are intentionally not committed.

## Stack

- Laravel 13
- Filament 5
- PHP 8.3 or newer
- SQLite for local development
- Node.js and npm for frontend build tasks

## Local Setup

Clone the repository, then run:

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

This project is now configured for PostgreSQL local development.

Default local database settings:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=nahshon_smart_erp
DB_USERNAME=postgres
DB_PASSWORD=postgres
```

On this workstation the portable PostgreSQL binaries are expected at:

```text
C:\tmp\postgresql-17.5
```

Start local PostgreSQL and create the development database:

```powershell
.\scripts\start-local-postgres.ps1
```

Then migrate and seed:

```bash
php artisan migrate --seed
npm run build
php artisan serve
```

Open:

```text
http://127.0.0.1:8000/
```

Filament admin:

```text
http://127.0.0.1:8000/admin
```

Local seeded admin account:

```text
Email: davidcho1973@gmail.com
Password: value of SMART_COMPANY_ADMIN_PASSWORD in your local .env, default 1234
```

Change this password after the owner confirms access.

Google sign-in is required for the ERP screen. Configure OAuth credentials in `.env` or Laravel Cloud environment variables:

```env
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=http://127.0.0.1:8000/auth/google/callback
GOOGLE_AUTH_PROMPT=
```

In Google Cloud Console, add the production callback URL as `https://your-domain.com/auth/google/callback`.

Employee badge photo analysis uses Gemini. Configure the API key in `.env` or Laravel Cloud environment variables:

```env
GEMINI_API_KEY=
GEMINI_MODEL=gemini-3.5-flash
GEMINI_API_ENDPOINT=https://generativelanguage.googleapis.com
GEMINI_TIMEOUT=30
```

## Development Notes

Do not commit these files or folders:

- `.env`
- `vendor/`
- `node_modules/`
- `tools/`
- `database/database.sqlite`
- `storage/logs/`
- cache files

For another device, clone the GitHub repository and run the setup commands again. Do not copy `vendor`, `node_modules`, or `.env` between machines unless you know exactly why.

## Useful Commands

```bash
php artisan test
npm run build
php artisan migrate:fresh --seed
php artisan serve
```

## Deployment

See `SMART_COMPANY_LARAVEL_DEPLOYMENT.md` for deployment notes.
