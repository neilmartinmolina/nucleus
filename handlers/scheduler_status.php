<?php
define("NUCLEUS_SKIP_DIRECT_ACCESS_REDIRECT", true);
require_once __DIR__ . "/../includes/core.php";

header("Content-Type: application/json");

function schedulerStatusResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if (!isAuthenticated()) {
    schedulerStatusResponse(401, ["success" => false, "message" => "Not authenticated."]);
}

$roleManager = new RoleManager($pdo);
$role = $roleManager->getUserRole($_SESSION["userId"] ?? null);
$canRunMonitoring = in_array($role, ["admin", "superadmin"], true);

schedulerStatusResponse(200, monitoringSchedulerStatus($pdo, $canRunMonitoring));
