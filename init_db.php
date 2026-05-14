<?php
define("NUCLEUS_SKIP_CORE_BOOTSTRAP", true);
require_once "config.php";

$host = DB_HOST;
$db   = DB_NAME;
$user = DB_USER;
$pass = DB_PASS;
$charset = "utf8mb4";

$dsn = "mysql:host=$host;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (Exception $e) {
    die("DB connection failed: " . $e->getMessage());
}

// Read the normalized Nucleus schema file
$migrationFile = __DIR__ . "/migrations/nucleus_3nf_schema.sql";

if (!file_exists($migrationFile)) {
    echo "Migration file not found.\n";
    exit;
}

try {
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$db`");

    $tableCheck = $pdo->query("SHOW TABLES LIKE 'users'");
    if ($tableCheck->rowCount() > 0) {
        $columnCheck = $pdo->query("SHOW COLUMNS FROM users LIKE 'userId'");
        if ($columnCheck->rowCount() === 0) {
            echo "The nucleus database already exists but does not match this app's 3NF schema.\n";
            echo "Expected users.userId, but found another users table shape.\n";
            echo "Use migrations/nucleus_3nf_schema.sql on a clean database, or migrate/rename the existing tables first.\n";
            exit;
        }
    }

    $migration = file_get_contents($migrationFile);
    $migration = preg_replace('/CREATE DATABASE IF NOT EXISTS\s+`?nucleus`?\s+CHARACTER SET utf8mb4\s+COLLATE utf8mb4_unicode_ci;/i', "", $migration);
    $migration = preg_replace('/USE\s+`?nucleus`?\s*;/i', "", $migration);
    $pdo->exec($migration);

    $extraMigrationDir = __DIR__ . "/database/migrations";
    foreach (glob($extraMigrationDir . "/*.sql") ?: [] as $extraMigrationFile) {
        $extraMigration = file_get_contents($extraMigrationFile);
        if ($extraMigration !== false && trim($extraMigration) !== "") {
            $pdo->exec($extraMigration);
        }
    }

    echo "Nucleus database schema initialized successfully!\n";
} catch (Exception $e) {
    echo "Schema initialization failed: " . $e->getMessage() . "\n";
}
?>



