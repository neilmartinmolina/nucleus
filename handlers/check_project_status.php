<?php
define("NUCLEUS_SKIP_DIRECT_ACCESS_REDIRECT", true);
require_once __DIR__ . "/../includes/core.php";

header("Content-Type: application/json");

function jsonResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if (!isAuthenticated()) {
    jsonResponse(401, ["success" => false, "message" => "Not authenticated"]);
}

$projectId = isset($_GET["projectId"]) && is_numeric($_GET["projectId"]) ? (int) $_GET["projectId"] : null;
if (!$projectId) {
    jsonResponse(400, ["success" => false, "message" => "Missing projectId"]);
}

$roleManager = new RoleManager($pdo);
if (!$roleManager->canAccessProject($_SESSION["userId"], $projectId)) {
    jsonResponse(403, ["success" => false, "message" => "Permission denied"]);
}

$snapshot = monitoringProjectSnapshot($pdo, $projectId);
if (!$snapshot) {
    jsonResponse(404, ["success" => false, "message" => "Project not found"]);
}

jsonResponse(200, $snapshot);
