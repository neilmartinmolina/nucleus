<?php

define("NUCLEUS_SKIP_DIRECT_ACCESS_REDIRECT", true);
require_once __DIR__ . "/../../includes/core.php";

function driveRequireUser(): array
{
    if (!isAuthenticated()) {
        http_response_code(401);
        exit("Not authenticated.");
    }
    $role = $_SESSION["role"] ?? (new RoleManager($GLOBALS["pdo"]))->getUserRole($_SESSION["userId"] ?? null);
    if (!canManageFiles()) {
        http_response_code(403);
        exit("Drive Storage is restricted to file managers.");
    }
    return [(int) $_SESSION["userId"], $role ?: "visitor"];
}

function driveFolderRedirect(?int $folderId, string $status, string $message): void
{
    $url = "../../dashboard.php?page=files";
    if ($folderId) {
        $url .= "&folder_id=" . urlencode((string) $folderId);
    }
    $url .= "&drive_status=" . urlencode($status) . "&drive_message=" . urlencode($message);
    header("Location: " . $url);
    exit;
}

function drivePostedFolderId(): ?int
{
    return isset($_POST["folder_id"]) && is_numeric($_POST["folder_id"]) ? (int) $_POST["folder_id"] : null;
}

function driveRequirePostAndCsrf(?int $folderId): void
{
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        driveFolderRedirect($folderId, "error", "Invalid request method.");
    }
    if (!checkCSRF($_POST["csrf_token"] ?? "")) {
        driveFolderRedirect($folderId, "error", "Invalid security token.");
    }
}
