<?php
if (defined("NUCLEUS_CORE_LOADED")) {
    return;
}
if (!defined("NUCLEUS_CORE_BOOTSTRAPPING")) {
    define("NUCLEUS_CORE_BOOTSTRAPPING", true);
}

// Common functions and configuration
if (!defined("NUCLEUS_CONFIG_BOOTSTRAPPING") && !defined("NUCLEUS_CONFIG_LOADED")) {
    require_once __DIR__ . "/../config.php";
}
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/Security.php";
require_once __DIR__ . "/SweetAlert.php";
require_once __DIR__ . "/csrf.php";
require_once __DIR__ . "/RoleManager.php";
require_once __DIR__ . "/monitoring.php";
require_once __DIR__ . "/Storage/StorageManager.php";
require_once __DIR__ . "/quota.php";
require_once __DIR__ . "/DriveStorage.php";

// Secure session settings MUST come before session_start()
if (function_exists("ini_set")) {
    ini_set("session.cookie_httponly", 1);
    ini_set("session.use_only_cookies", 1);
    ini_set("session.cookie_samesite", "Strict");
    // Only set secure cookie on non-local environments
    if (!isLocal()) {
        ini_set("session.cookie_secure", 1);
    }
}

// Initialize session unless a trusted CLI/queue entry point explicitly opts out.
if (!defined("NUCLEUS_SKIP_SESSION_BOOTSTRAP")) {
    session_start();
} elseif (!isset($_SESSION)) {
    $_SESSION = [];
}

// Set security headers
if (!defined("NUCLEUS_SKIP_SESSION_BOOTSTRAP")) {
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    header("X-XSS-Protection: 1; mode=block");
}

// Session timeout check (30 minutes)
$isAuthenticated = isset($_SESSION["userId"]) && !empty($_SESSION["userId"]);
if ($isAuthenticated && isset($_SESSION["last_activity"])) {
    $inactive = time() - $_SESSION["last_activity"];
    if ($inactive >= SESSION_LIFETIME) {
        session_destroy();
        $isAjaxRequest = (($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "") === "XMLHttpRequest")
            || strpos((string) ($_SERVER["HTTP_ACCEPT"] ?? ""), "application/json") !== false;
        if ($isAjaxRequest) {
            http_response_code(401);
            header("X-Nucleus-Auth-Expired: 1");
            if (strpos((string) ($_SERVER["HTTP_ACCEPT"] ?? ""), "application/json") !== false) {
                header("Content-Type: application/json");
                echo json_encode(["success" => false, "message" => "Session expired.", "auth_expired" => true]);
            } else {
                echo "<div class=\"p-8 text-center\"><p class=\"text-slate-600\">Session expired. Redirecting to login...</p></div>";
            }
            exit;
        }
        header("Location: login.php?timeout=1");
        exit;
    }
}
$_SESSION["last_activity"] = time();

// Check if user is authenticated
function isAuthenticated() {
    return isset($_SESSION["userId"]) && !empty($_SESSION["userId"]);
}

// Check if user has permission
function hasPermission($permission) {
    if (!isAuthenticated()) return false;
    global $pdo;
    $roleManager = new RoleManager($pdo);
    $userId = $_SESSION["userId"] ?? "";
    return $roleManager->hasPermission($userId, $permission);
}

function validateGitRepoUrl($repoUrl) {
    return is_string($repoUrl) && preg_match('/\.git$/i', trim($repoUrl));
}

function extractRepoNameFromGitUrl($repoUrl) {
    $repoUrl = trim((string) $repoUrl);
    $repoUrl = preg_replace('/\.git$/i', '', $repoUrl);
    $repoUrl = rtrim($repoUrl, "/");
    $repoName = basename($repoUrl);
    return $repoName !== "." ? $repoName : "";
}

function githubHooksUrl($repoUrl) {
    $repoUrl = preg_replace('/\.git$/i', '', trim((string) $repoUrl));
    $parts = parse_url($repoUrl);
    if (($parts["host"] ?? "") !== "github.com") {
        return "";
    }

    $path = trim($parts["path"] ?? "", "/");
    if (substr_count($path, "/") !== 1) {
        return "";
    }

    return "https://github.com/" . $path . "/settings/hooks/new";
}

function projectWebhookUrl($projectId = null) {
    $baseUrl = rtrim(APP_URL ?: "", "/");
    if ($baseUrl === "") {
        $scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
        $host = $_SERVER["HTTP_HOST"] ?? "localhost";
        $baseUrl = $scheme . "://" . $host . rtrim(dirname($_SERVER["SCRIPT_NAME"] ?? "/"), "/\\");
    }

    $url = $baseUrl . "/webhook.php";
    if ($projectId !== null && $projectId !== "") {
        $url .= "?websiteId=" . urlencode((string) $projectId);
    }

    return $url;
}

function ensureProjectSavedAtColumn(PDO $pdo): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'projects'
              AND COLUMN_NAME = 'saved_at'
        ");
        $stmt->execute();
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE projects ADD COLUMN saved_at TIMESTAMP NULL AFTER updated_at");
        }
    } catch (Throwable $e) {
        error_log("Project saved_at column check failed: " . $e->getMessage());
    }
}

function ensureResourceFilesSchema(PDO $pdo): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS resource_files (
                resource_file_id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                project_id INT NOT NULL,
                uploaded_by INT NOT NULL,
                storage_driver ENUM('local','ftp') NOT NULL DEFAULT 'local',
                storage_path VARCHAR(2048) NOT NULL,
                file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
                mime_type VARCHAR(150) NULL,
                original_filename VARCHAR(255) NOT NULL,
                stored_filename VARCHAR(255) NOT NULL,
                is_deleted TINYINT(1) NOT NULL DEFAULT 0,
                deleted_at TIMESTAMP NULL,
                deleted_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE,
                FOREIGN KEY (uploaded_by) REFERENCES users(userId) ON DELETE RESTRICT,
                FOREIGN KEY (deleted_by) REFERENCES users(userId) ON DELETE SET NULL,
                INDEX idx_resource_files_project_deleted (project_id, is_deleted),
                INDEX idx_resource_files_uploaded_by (uploaded_by),
                INDEX idx_resource_files_storage (storage_driver, storage_path(191))
            )
        ");
    } catch (Throwable $e) {
        error_log("Resource files schema check failed: " . $e->getMessage());
    }
}

function ensureFeatureFlagsSchema(PDO $pdo): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS feature_flags (
                id INT PRIMARY KEY AUTO_INCREMENT,
                feature_key VARCHAR(100) NOT NULL UNIQUE,
                feature_name VARCHAR(255) NOT NULL,
                feature_group VARCHAR(100) NULL,
                is_enabled TINYINT(1) NOT NULL DEFAULT 1,
                maintenance_message TEXT NULL,
                updated_by INT NULL,
                updated_at DATETIME NULL,
                FOREIGN KEY (updated_by) REFERENCES users(userId) ON DELETE SET NULL,
                INDEX idx_feature_flags_group (feature_group),
                INDEX idx_feature_flags_enabled (is_enabled)
            )
        ");

        $defaults = [
            ["dashboard", "Dashboard", "Core"],
            ["projects", "Projects", "Projects"],
            ["files", "Files", "Storage"],
            ["subjects", "Subjects", "Subjects"],
            ["subject_resources", "Subject Resources", "Subjects"],
            ["subject_posts", "Subject Posts", "Subjects"],
            ["tutorials", "Tutorials", "Learning"],
            ["alerts", "Alerts", "Monitoring"],
            ["requests", "Requests", "Workflow"],
            ["logs", "Logs", "Admin"],
            ["settings", "Settings", "Admin"],
        ];
        $stmt = $pdo->prepare("
            INSERT INTO feature_flags (feature_key, feature_name, feature_group, is_enabled)
            VALUES (?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE feature_name = VALUES(feature_name), feature_group = VALUES(feature_group)
        ");
        foreach ($defaults as $feature) {
            $stmt->execute($feature);
        }
    } catch (Throwable $e) {
        error_log("Feature flags schema check failed: " . $e->getMessage());
    }
}

function ensureRoleCatalogSchema(PDO $pdo): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $pdo->exec("ALTER TABLE roles MODIFY role_name ENUM('superadmin', 'admin', 'handler', 'member', 'visitor') NOT NULL");
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO roles (role_name, description)
            VALUES (?, ?)
        ");
        $stmt->execute(["superadmin", "Owns all system settings, users, and emergency controls"]);
        $stmt->execute(["member", "Can join subjects and submit website requests"]);
        $stmt->execute(["visitor", "Public visitor without an account; can look up non-sensitive website status"]);
        $pdo->exec("
            UPDATE roles
            SET description = 'Public visitor without an account; can look up non-sensitive website status'
            WHERE role_name = 'visitor'
        ");
    } catch (Throwable $e) {
        error_log("Role catalog schema check failed: " . $e->getMessage());
    }
}

function ensureSubjectJoinRequestsSchema(PDO $pdo): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS subject_join_requests (
                join_request_id INT PRIMARY KEY AUTO_INCREMENT,
                subject_id INT NOT NULL,
                requested_by INT NOT NULL,
                message TEXT NULL,
                status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
                reviewed_by INT NULL,
                reviewed_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE CASCADE,
                FOREIGN KEY (requested_by) REFERENCES users(userId) ON DELETE CASCADE,
                FOREIGN KEY (reviewed_by) REFERENCES users(userId) ON DELETE SET NULL,
                UNIQUE KEY unique_pending_subject_join (subject_id, requested_by, status),
                INDEX idx_subject_join_requests_status (status),
                INDEX idx_subject_join_requests_subject_status (subject_id, status),
                INDEX idx_subject_join_requests_requested_by (requested_by)
            )
        ");
    } catch (Throwable $e) {
        error_log("Subject join requests schema check failed: " . $e->getMessage());
    }
}

function ensureSubjectArchiveColumn(PDO $pdo): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'subjects'
              AND COLUMN_NAME = 'archived_at'
        ");
        $stmt->execute();
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE subjects ADD COLUMN archived_at TIMESTAMP NULL AFTER updated_at");
        }
    } catch (Throwable $e) {
        error_log("Subject archive column check failed: " . $e->getMessage());
    }
}

function ensureUserProfileColumns(PDO $pdo): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $columns = [
        "phone" => "VARCHAR(50) NULL",
        "department" => "VARCHAR(255) NULL",
        "bio" => "TEXT NULL",
    ];

    foreach ($columns as $column => $definition) {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'users'
                  AND COLUMN_NAME = ?
            ");
            $stmt->execute([$column]);
            if ((int) $stmt->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE users ADD COLUMN {$column} {$definition}");
            }
        } catch (Throwable $e) {
            error_log("User profile column {$column} check failed: " . $e->getMessage());
        }
    }
}

function ensureEmailVerificationColumns(PDO $pdo): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $columns = [
        "isVerified" => "TINYINT(1) NOT NULL DEFAULT 0",
        "email_verified_at" => "TIMESTAMP NULL",
        "email_verification_token" => "VARCHAR(255) NULL",
        "email_verification_expires_at" => "TIMESTAMP NULL",
        "email_verification_sent_at" => "TIMESTAMP NULL",
    ];

    foreach ($columns as $column => $definition) {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'users'
                  AND COLUMN_NAME = ?
            ");
            $stmt->execute([$column]);
            if ((int) $stmt->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE users ADD COLUMN {$column} {$definition}");
            }
        } catch (Throwable $e) {
            error_log("Email verification column {$column} check failed: " . $e->getMessage());
        }
    }

    try {
        $pdo->exec("
            UPDATE users
            SET isVerified = 1,
                email_verified_at = COALESCE(email_verified_at, created_at, NOW())
            WHERE email_verified_at IS NULL
              AND COALESCE(isVerified, 0) = 0
              AND email_verification_token IS NULL
        ");
        $pdo->exec("
            UPDATE users
            SET isVerified = 1
            WHERE email_verified_at IS NOT NULL
              AND COALESCE(isVerified, 0) = 0
        ");
    } catch (Throwable $e) {
        error_log("Email verification backfill failed: " . $e->getMessage());
    }
}

function resourceProjectUsageBytes(PDO $pdo, int $projectId): int {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(file_size), 0) FROM resource_files WHERE project_id = ? AND is_deleted = 0");
    $stmt->execute([$projectId]);
    return (int) $stmt->fetchColumn();
}

function isAdminLike(): bool {
    if (!isAuthenticated()) {
        return false;
    }

    global $pdo;
    $roleManager = new RoleManager($pdo);
    return in_array($roleManager->getUserRole($_SESSION["userId"] ?? null), ["admin", "superadmin"], true);
}

function canManageFiles(): bool {
    if (!isAuthenticated()) {
        return false;
    }

    global $pdo;
    $roleManager = new RoleManager($pdo);
    return $roleManager->canManageFiles($_SESSION["userId"] ?? null);
}

function homeRedirectForRole(?string $role): string {
    return in_array($role, ["superadmin", "admin", "handler"], true) ? "dashboard.php" : "home.php";
}

function authenticatedHomeRedirect(): string {
    if (!isAuthenticated()) {
        return "login.php";
    }

    $role = $_SESSION["role"] ?? null;
    if (!$role) {
        global $pdo;
        $roleManager = new RoleManager($pdo);
        $role = $roleManager->getUserRole($_SESSION["userId"] ?? null);
        if ($role) {
            $_SESSION["role"] = $role;
        }
    }

    return homeRedirectForRole($role);
}

function getFeatureFlag(string $featureKey): ?array {
    global $pdo;
    ensureFeatureFlagsSchema($pdo);
    $stmt = $pdo->prepare("
        SELECT ff.*, u.fullName AS updated_by_name
        FROM feature_flags ff
        LEFT JOIN users u ON u.userId = ff.updated_by
        WHERE ff.feature_key = ?
    ");
    $stmt->execute([$featureKey]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function isFeatureEnabled(string $featureKey): bool {
    $flag = getFeatureFlag($featureKey);
    return !$flag || (int) $flag["is_enabled"] === 1;
}

function getFeatureMaintenanceMessage(string $featureKey): string {
    $flag = getFeatureFlag($featureKey);
    $message = trim((string) ($flag["maintenance_message"] ?? ""));
    return $message !== "" ? $message : "Sorry, this part of Nucleus is under construction.";
}

function shouldBypassMaintenance(): bool {
    return isAdminLike() && (($_GET["preview"] ?? "") === "1" || ($_GET["bypass_maintenance"] ?? "") === "1");
}

function renderMaintenanceCard(string $featureKey): void {
    $message = getFeatureMaintenanceMessage($featureKey);
    $flag = getFeatureFlag($featureKey);
    $featureName = $flag["feature_name"] ?? ucwords(str_replace("_", " ", $featureKey));
    echo "<div class=\"rounded-xl border border-amber-200 bg-amber-50 p-8 text-center shadow-sm\">";
    echo "<p class=\"text-sm font-semibold uppercase text-amber-700\">Maintenance</p>";
    echo "<h2 class=\"mt-2 text-2xl font-bold text-slate-800\">" . htmlspecialchars($featureName) . "</h2>";
    echo "<p class=\"mx-auto mt-3 max-w-xl text-sm leading-6 text-amber-800\">" . htmlspecialchars($message) . "</p>";
    if (isAdminLike()) {
        $params = $_GET;
        $params["preview"] = "1";
        echo "<a href=\"dashboard.php?" . htmlspecialchars(http_build_query($params)) . "\" class=\"mt-5 inline-flex rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white\">Preview as admin</a>";
    }
    echo "</div>";
}

function displayUpdatedBy(array $row) {
    $role = $_SESSION["role"] ?? "visitor";
    if (!in_array($role, ["admin", "handler"], true)) {
        return "Project contributor";
    }

    return $row["github_updated_by"]
        ?? $row["github_updated_by_username"]
        ?? $row["updatedByName"]
        ?? $row["fullName"]
        ?? "Unknown";
}

function deploymentModeLabel($mode) {
    return $mode === "custom_webhook" ? "Monitored via project deploy.php" : "Monitored via Hostinger Git";
}

function formatNucleusDateTime($datetime) {
    if (empty($datetime)) {
        return "Never";
    }

    try {
        $date = new DateTime((string) $datetime);
        $today = new DateTime("today");
    } catch (Exception $e) {
        return (string) $datetime;
    }

    if ($date->format("Y-m-d") === $today->format("Y-m-d")) {
        return $date->format("g:i A");
    }

    return $date->format("Y-m-d g:i A");
}

function logActivity($action, $note = null, $projectId = null, $version = null, $userId = null) {
    global $pdo;

    try {
        $actorId = $userId ?? ($_SESSION["userId"] ?? null);
        $ipAddress = $_SERVER["REMOTE_ADDR"] ?? null;
        $stmt = $pdo->prepare("
            INSERT INTO activity_logs (project_id, userId, action, version, note, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$projectId, $actorId, $action, $version, $note, $ipAddress]);
    } catch (Throwable $e) {
        error_log("Activity log failed: " . $e->getMessage());
    }
}

// Redirect to login if not authenticated (only for protected pages via index.php routing)
$currentFile = basename($_SERVER["PHP_SELF"]);
$isIndexPhp = ($currentFile === "index.php");
$isLoginPage = ($currentFile === "login.php" || $currentFile === "signup.php" || $currentFile === "password_reset.php" || $currentFile === "password_reset_complete.php");
$isPublicEndpoint = ($currentFile === "webhook.php" || $currentFile === "github-webhook.php");

if (!defined("NUCLEUS_SKIP_SESSION_BOOTSTRAP") && !defined("NUCLEUS_SKIP_DIRECT_ACCESS_REDIRECT") && !$isIndexPhp && !$isLoginPage && !$isPublicEndpoint) {
    // Direct file access - redirect to index.php routing
    if (!isAuthenticated()) {
        header("Location: index.php?page=login");
        exit;
    }
}

ensureProjectSavedAtColumn($pdo);
ensureResourceFilesSchema($pdo);
ensureFeatureFlagsSchema($pdo);
ensureRoleCatalogSchema($pdo);
ensureSubjectJoinRequestsSchema($pdo);
ensureSubjectArchiveColumn($pdo);
ensureUserProfileColumns($pdo);
ensureEmailVerificationColumns($pdo);

if (!defined("NUCLEUS_CORE_LOADED")) {
    define("NUCLEUS_CORE_LOADED", true);
}
