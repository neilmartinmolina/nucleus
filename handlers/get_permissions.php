<?php
define("NUCLEUS_SKIP_DIRECT_ACCESS_REDIRECT", true);
require_once __DIR__ . "/../includes/core.php";

header("Content-Type: application/json");

if (!isAuthenticated() || !hasPermission("manage_users")) {
    echo json_encode([]);
    exit;
}

$userId = $_GET["userId"] ?? null;

if (!$userId) {
    echo json_encode([]);
    exit;
}

$roleManager = new RoleManager($pdo);
$permissions = $roleManager->getUserPermissions($userId);

echo json_encode($permissions);
?>
