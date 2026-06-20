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

Create the local SQLite database file:

```bash
mkdir -p database
touch database/database.sqlite
```

On Windows PowerShell:

```powershell
New-Item -ItemType File -Force database/database.sqlite
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
Email: admin@nahshonmep.com
Password: value of SMART_COMPANY_ADMIN_PASSWORD in your local .env
```

Change this password before using the app outside local development.

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
