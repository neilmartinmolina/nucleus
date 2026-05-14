# Nucleus

Nucleus is a centralized academic project tracker with role-based access, project status monitoring, alerts, feature flags, and file/resource storage.

## Current Scope

Nucleus is in stabilization mode. New modules should not be added until the existing install, monitoring, permissions, storage, and demo flows are reliable.

## Requirements

- PHP 8.x
- MySQL or MariaDB
- Composer
- PHP extensions: PDO MySQL, curl, fileinfo, JSON, OpenSSL
- PHP FTP extension when FTP-backed storage or Drive Storage is used

## Quick Start

```text
composer install
copy .env.example .env
php init_db.php
```

Then open the app in your browser and log in with:

```text
admin / admin123
```

Change seeded credentials before real use.

## Configuration

Use `.env.example` as the source of truth for required environment values. Keep real `.env` files out of git.

Important settings:

- `APP_ENV`, `APP_DEBUG`, `APP_URL`
- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- `FILE_STORAGE_DRIVER`, `STORAGE_LOCAL_ROOT`
- `FTP_HOST`, `FTP_USERNAME`, `FTP_PASSWORD`, `FTP_ROOT`
- `MONITORING_QUEUE_TOKEN`

## Database

The normalized schema is in `migrations/nucleus_3nf_schema.sql`. `php init_db.php` creates the configured database, applies the main schema, and applies file storage migrations from `database/migrations`.

The schema includes users, roles, subjects, projects, project status, deployment checks, monitoring alerts, monitoring runs/settings, feature flags, project members, activity logs, comments, notifications, resource files, and Drive Storage metadata.

## Monitoring

Nucleus monitoring is read-only. It checks public project URLs and optional metadata endpoints, stores history in `deployment_checks`, updates `project_status`, and raises `monitoring_alerts`.

Manual monitoring remains available to administrators in every scheduler mode.

See `MONITORING_SETUP.md`.

## Storage

Project resources are served only through authenticated handlers. Direct access to `storage` is denied by `storage/.htaccess`.

See `STORAGE_SETUP.md`.

## Documentation

- `INSTALL_LOCAL.md`
- `HOSTINGER_DEPLOYMENT.md`
- `MONITORING_SETUP.md`
- `STORAGE_SETUP.md`
- `DEMO_SCRIPT.md`
