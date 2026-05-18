<?php
define("NUCLEUS_SKIP_DIRECT_ACCESS_REDIRECT", true);
require_once __DIR__ . "/../includes/core.php";
require_once __DIR__ . "/../includes/Security.php";

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
$role = $data["role"] ?? null;

if (!$userId || !$role) {
    echo json_encode(["success" => false, "message" => "Missing parameters"]);
    exit;
}

$validRoles = ["superadmin", "admin", "handler", "member", "visitor"];
if (!in_array($role, $validRoles)) {
    echo json_encode(["success" => false, "message" => "Invalid role"]);
    exit;
}

try {
    $pdo->beginTransaction();
    
    $stmt = $pdo->prepare("UPDATE users SET role_id = (SELECT role_id FROM roles WHERE role_name = ?) WHERE userId = ?");
    $stmt->execute([$role, $userId]);
    
    $pdo->commit();
    echo json_encode(["success" => true]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>
