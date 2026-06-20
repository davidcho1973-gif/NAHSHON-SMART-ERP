# SMART COMPANY Laravel + Filament

This project converts the original Google Apps Script SMART COMPANY ERP screen into a Laravel application while preserving the dark enterprise dashboard design.

## What is included

- `/` - SMART COMPANY ERP dashboard with the original sidebar/topbar/card/table layout.
- `/api/smart-company/{method}` - Laravel JSON adapter for the former `google.script.run` API methods.
- `/admin` - Filament admin panel for unified ERP record management.
- `smart_records` table - generic ERP record store for HR, WBS, equipment, finance, safety, vendors, vehicle, rental, housing, and inventory data.
- Seeder - creates a default admin user and sample ERP data.

## Requirements

- PHP 8.3+
- Composer 2+
- Node.js + npm
- SQLite, MySQL, or PostgreSQL

Filament 5 requires PHP 8.2+, Laravel v11.28+, and Tailwind CSS v4.1+. This app uses Laravel 13 and Filament 5.

## Local setup

```bash
cd smart-company-laravel
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install
npm run build
php artisan serve
```

Open:

- ERP screen: http://127.0.0.1:8000
- Filament admin: http://127.0.0.1:8000/admin

Default seeded admin:

- Email: `admin@nahshonmep.com`
- Password: value of `SMART_COMPANY_ADMIN_PASSWORD` in the deployment environment

Change these in `.env` before production:

```dotenv
SMART_COMPANY_ADMIN_NAME="Admin User"
SMART_COMPANY_ADMIN_EMAIL=admin@nahshonmep.com
SMART_COMPANY_ADMIN_PASSWORD="a-long-random-password"
```

## Production deployment checklist

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
cp .env.example .env
php artisan key:generate
php artisan migrate --seed --force
npm ci
npm run build
php artisan storage:link
php artisan optimize
```

Set the web server document root to the Laravel `public/` directory only. Never expose the project root.

For updates after the first deploy:

```bash
php artisan down --render="errors::503" --retry=60
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan migrate --force
npm ci
npm run build
php artisan optimize
php artisan up
```

## Migration notes from GAS

The original app used Google Apps Script functions like `api_getHRData` through `google.script.run`. The converted frontend now calls Laravel at `/api/smart-company/{method}` through a compatibility shim, so the large existing UI can remain visually stable while backend methods are replaced module by module.

The first Laravel data layer uses `SmartRecord` as a generic store. This is deliberate: it makes the conversion deployable before all original Google Sheet tabs are normalized into separate relational tables.

## Next recommended hardening

- Replace sample data in `App\Support\SmartCompanyData` with real database queries module by module.
- Split `smart_records` into dedicated tables once each module workflow is stable.
- Move AI keys, Telegram tokens, webhook URLs, and Google credentials into `.env`; never store them in source files.
- Add auth/roles for admin, PM, safety, accounting, and field users.

## Docker deployment

```bash
docker build -t smart-company-laravel .
docker run --rm -p 8080:80 --env-file .env smart-company-laravel
```

For production Docker, provide persistent database and storage volumes. If using SQLite, mount `database/database.sqlite`. For MySQL/PostgreSQL, set `DB_CONNECTION`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in `.env`.
