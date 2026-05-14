<?php
if (defined("NUCLEUS_CONFIG_LOADED")) {
    return;
}
if (!defined("NUCLEUS_CONFIG_BOOTSTRAPPING")) {
    define("NUCLEUS_CONFIG_BOOTSTRAPPING", true);
}

date_default_timezone_set("Asia/Manila");
// config.php - Environment detection and configuration

require_once __DIR__ . "/vendor/autoload.php";

if (class_exists(Dotenv\Dotenv::class)) {
    Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
    Dotenv\Dotenv::createImmutable(__DIR__ . "/../../")->safeLoad();
} else {
    $envFile = __DIR__ . "/.env";
    if (is_readable($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === "" || str_starts_with($line, "#") || !str_contains($line, "=")) {
                continue;
            }
            [$key, $value] = array_map("trim", explode("=", $line, 2));
            $value = trim($value, "\"'");
            $_ENV[$key] = $_ENV[$key] ?? $value;
            $_SERVER[$key] = $_SERVER[$key] ?? $value;
            putenv($key . "=" . $value);
        }
    }
}

$nucleusConfig = require __DIR__ . "/config/app.php";

/**
 * Check if running on local environment
 * @return bool
 */
if (!function_exists("isLocal")) {
    function isLocal() {
        // CLI is always local
        if (php_sapi_name() === 'cli') {
            return true;
        }
        $whitelist = ["127.0.0.1", "::1", "localhost"];
        $remoteAddr = $_SERVER["REMOTE_ADDR"] ?? "";
        return in_array($remoteAddr, $whitelist);
    }
}

// Application settings
defined("APP_ENV") || define("APP_ENV", $nucleusConfig["app"]["env"]);
defined("APP_DEBUG") || define("APP_DEBUG", (bool) $nucleusConfig["app"]["debug"]);
defined("APP_URL") || define("APP_URL", $nucleusConfig["app"]["url"]);
defined("SESSION_LIFETIME") || define("SESSION_LIFETIME", 1800); // 30 minutes inactivity timeout
defined("DB_CONNECTION") || define("DB_CONNECTION", $nucleusConfig["database"]["connection"]);
defined("DB_HOST") || define("DB_HOST", $nucleusConfig["database"]["host"]);
defined("DB_PORT") || define("DB_PORT", (int) $nucleusConfig["database"]["port"]);
defined("DB_DATABASE") || define("DB_DATABASE", $nucleusConfig["database"]["database"]);
defined("DB_USERNAME") || define("DB_USERNAME", $nucleusConfig["database"]["username"]);
defined("DB_PASSWORD") || define("DB_PASSWORD", $nucleusConfig["database"]["password"]);
defined("DB_CHARSET") || define("DB_CHARSET", $nucleusConfig["database"]["charset"]);
defined("DB_NAME") || define("DB_NAME", DB_DATABASE);
defined("DB_USER") || define("DB_USER", DB_USERNAME);
defined("DB_PASS") || define("DB_PASS", DB_PASSWORD);
defined("STORAGE_DEFAULT_DRIVER") || define("STORAGE_DEFAULT_DRIVER", $nucleusConfig["files"]["driver"]);
defined("STORAGE_LOCAL_ROOT") || define("STORAGE_LOCAL_ROOT", $nucleusConfig["files"]["local_root"]);
defined("RESOURCE_MAX_FILE_SIZE") || define("RESOURCE_MAX_FILE_SIZE", (int) $nucleusConfig["files"]["upload_max_bytes"]);
defined("UPLOAD_MAX_BYTES") || define("UPLOAD_MAX_BYTES", (int) $nucleusConfig["files"]["upload_max_bytes"]);
$adminQuota = (int) $nucleusConfig["files"]["admin_quota_bytes"];
$handlerQuota = (int) $nucleusConfig["files"]["handler_quota_bytes"];
if (strtolower($nucleusConfig["files"]["driver"]) === "local") {
    $adminQuota = (int) floor($adminQuota / 2);
    $handlerQuota = (int) floor($handlerQuota / 2);
}
defined("RESOURCE_PROJECT_QUOTA_BYTES") || define("RESOURCE_PROJECT_QUOTA_BYTES", $adminQuota);
defined("ADMIN_QUOTA_BYTES") || define("ADMIN_QUOTA_BYTES", $adminQuota);
defined("HANDLER_QUOTA_BYTES") || define("HANDLER_QUOTA_BYTES", $handlerQuota);
defined("FTP_STORAGE_HOST") || define("FTP_STORAGE_HOST", $nucleusConfig["ftp"]["host"]);
defined("FTP_STORAGE_PORT") || define("FTP_STORAGE_PORT", (int) $nucleusConfig["ftp"]["port"]);
defined("FTP_STORAGE_USERNAME") || define("FTP_STORAGE_USERNAME", $nucleusConfig["ftp"]["username"]);
defined("FTP_STORAGE_PASSWORD") || define("FTP_STORAGE_PASSWORD", $nucleusConfig["ftp"]["password"]);
defined("FTP_STORAGE_ROOT_PATH") || define("FTP_STORAGE_ROOT_PATH", $nucleusConfig["ftp"]["root"]);
defined("FTP_STORAGE_PASSIVE_MODE") || define("FTP_STORAGE_PASSIVE_MODE", (bool) $nucleusConfig["ftp"]["passive"]);
defined("FTP_STORAGE_TIMEOUT") || define("FTP_STORAGE_TIMEOUT", (int) $nucleusConfig["ftp"]["timeout"]);

if (!defined("NUCLEUS_CONFIG_LOADED")) {
    define("NUCLEUS_CONFIG_LOADED", true);
}

if (
    !defined("NUCLEUS_SKIP_CORE_BOOTSTRAP")
    && !defined("NUCLEUS_CORE_BOOTSTRAPPING")
    && !defined("NUCLEUS_CORE_LOADED")
) {
    require_once __DIR__ . "/includes/core.php";
}
