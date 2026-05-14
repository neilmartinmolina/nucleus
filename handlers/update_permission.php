<?php
define("NUCLEUS_SKIP_DIRECT_ACCESS_REDIRECT", true);
require_once __DIR__ . "/../includes/core.php";

header("Content-Type: application/json");

if (!isAuthenticated() || !hasPermission("manage_users")) {
    echo json_encode(["success" => false, "message" => "Permission denied"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data["csrf_token"]) || !checkCSRF($data["csrf_token"], false)) {
    echo json_encode(["success" => false, "message" => "Invalid CSRF token"]);
    exit;
}

$userId = $data["userId"] ?? null;
$permission = $data["permission"] ?? null;
$granted = $data["granted"] ?? false;

if (!$userId || !$permission) {
    echo json_encode(["success" => false, "message" => "Missing parameters"]);
    exit;
}

try {
    echo json_encode([
        "success" => false,
        "message" => "Individual permissions were removed. Change the user's role instead."
    ]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => "Database error"]);
}
?>
