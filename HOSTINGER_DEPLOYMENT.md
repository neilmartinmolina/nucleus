# Hostinger Deployment

1. Upload the application files to the Hostinger web root.
2. Run `composer install --no-dev` on the server or upload the generated `vendor` directory if Composer is unavailable.
3. Create a production `.env` from `.env.example`.
4. Set `APP_ENV=production`, `APP_DEBUG=false`, and `APP_URL` to the public HTTPS URL.
5. Configure the MySQL values and run `php init_db.php` once.
6. Make `storage/logs`, `storage/locks`, and the configured local storage root writable by PHP.
7. Keep `.env` private. The included `.htaccess` blocks `.env*` and `storage/.htaccess` blocks direct web reads from storage.

For Hostinger Git projects, configure each project in Nucleus as `hostinger_git`. Nucleus monitors the public URL and optional status files; it should not be added as a second deployment webhook.
