<?php
define("NUCLEUS_SKIP_DIRECT_ACCESS_REDIRECT", true);
require_once __DIR__ . "/../includes/core.php";

header("Content-Type: application/json");

function clearMonitoringLockResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if (!isAuthenticated()) {
    clearMonitoringLockResponse(401, ["success" => false, "message" => "Not authenticated."]);
}

if (!isAdminLike()) {
    clearMonitoringLockResponse(403, ["success" => false, "message" => "Only administrators can clear monitoring locks."]);
}

if (!checkCSRF($_POST["csrf_token"] ?? "")) {
    clearMonitoringLockResponse(403, ["success" => false, "message" => "Invalid security token."]);
}

$lockState = monitoringLockState($pdo);
if (!empty($lockState["active"])) {
    clearMonitoringLockResponse(409, [
        "success" => false,
        "message" => "Monitoring is currently running. The active queue lock was not cleared.",
        "page" => "settings",
    ]);
}

$result = clearStaleMonitoringLock($pdo, "settings");
if (empty($result["success"])) {
    clearMonitoringLockResponse(500, [
        "success" => false,
        "message" => $result["message"] ?? "The monitoring lock could not be cleared.",
        "page" => "settings",
    ]);
}

logActivity("monitoring_lock_cleared", "Cleared stale monitoring queue lock");

clearMonitoringLockResponse(200, [
    "success" => true,
    "message" => $result["message"] ?? "Monitoring lock cleared.",
    "page" => "settings",
]);
