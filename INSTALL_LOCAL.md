# Local Installation

1. Clone the repository into your web root, for example `C:\xampp\htdocs\Nucleus`.
2. Run `composer install`.
3. Copy `.env.example` to `.env`.
4. Set `APP_URL`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD`.
5. Create an empty MySQL database matching `DB_DATABASE`, or let `php init_db.php` create it when the database user has permission.
6. Run `php init_db.php`.
7. Open `http://localhost/Nucleus`.
8. Log in with `admin` / `admin123`, then change seeded demo credentials before real use.

The initializer loads `migrations/nucleus_3nf_schema.sql` and the file storage migrations in `database/migrations`. It refuses to overwrite an existing incompatible `users` table.

Required PHP extensions: PDO MySQL, curl, fileinfo, JSON, OpenSSL, and FTP if FTP storage is enabled.
