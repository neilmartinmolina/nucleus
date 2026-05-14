<?php
define("NUCLEUS_SKIP_DIRECT_ACCESS_REDIRECT", true);
require_once __DIR__ . "/../includes/core.php";

header("Content-Type: application/json");

if (!isAuthenticated()) {
    echo json_encode(["success" => false, "message" => "Not authenticated"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data["csrf_token"]) || !checkCSRF($data["csrf_token"], false)) {
    echo json_encode(["success" => false, "message" => "Invalid CSRF token"]);
    exit;
}

$websiteId = $data["websiteId"] ?? null;
$status = $data["status"] ?? null;

if (!$websiteId || !$status) {
    echo json_encode(["success" => false, "message" => "Missing parameters"]);
    exit;
}

$statusMap = [
    "updated" => "deployed",
    "updating" => "building",
    "issue" => "error",
    "working" => "deployed",
    "initializing" => "initializing",
    "building" => "building",
    "deployed" => "deployed",
    "warning" => "warning",
    "error" => "error",
];
$status = $statusMap[$status] ?? $status;
$validStatuses = ["initializing", "building", "deployed", "warning", "error"];
if (!in_array($status, $validStatuses)) {
    echo json_encode(["success" => false, "message" => "Invalid status"]);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM projects WHERE project_id = ?");
$stmt->execute([$websiteId]);
$website = $stmt->fetch();

if (!$website) {
    echo json_encode(["success" => false, "message" => "Project not found"]);
    exit;
}

$roleManager = new RoleManager($pdo);
if (!hasPermission("update_project") || !$roleManager->canAccessProject($_SESSION["userId"], (int) $websiteId)) {
    echo json_encode(["success" => false, "message" => "Permission denied"]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO project_status (project_id, status, updated_by, checked_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE status = VALUES(status), updated_by = VALUES(updated_by), checked_at = VALUES(checked_at)
    ");
    $stmt->execute([$websiteId, $status, $_SESSION["userId"]]);

    if ($status === "deployed") {
        $versionParts = explode(".", $website["current_version"]);
        $versionParts[count($versionParts) - 1] = (string)((int)$versionParts[count($versionParts) - 1] + 1);
        $newVersion = implode(".", $versionParts);
        
        $stmt = $pdo->prepare("UPDATE projects SET current_version = ?, last_updated_at = NOW(), updated_at = NOW() WHERE project_id = ?");
        $stmt->execute([$newVersion, $websiteId]);
    } else {
        $stmt = $pdo->prepare("UPDATE projects SET updated_at = NOW() WHERE project_id = ?");
        $stmt->execute([$websiteId]);
        $newVersion = $website["current_version"];
    }
    
    $stmt = $pdo->prepare("INSERT INTO activity_logs (project_id, version, note, userId, action) VALUES (?, ?, ?, ?, 'status_changed')");
    $stmt->execute([$websiteId, $newVersion, "Manually toggled update status to " . $status . " for " . $website["project_name"], $_SESSION["userId"]]);
    
    $stmt = $pdo->prepare("SELECT current_version, last_updated_at FROM projects WHERE project_id = ?");
    $stmt->execute([$websiteId]);
    $updated = $stmt->fetch();
    
    echo json_encode([
        "success" => true,
        "message" => "Status updated",
        "data" => [
            "currentVersion" => $updated["current_version"],
            "lastUpdatedAt" => $updated["last_updated_at"]
        ]
    ]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>
