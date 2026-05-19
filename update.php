<?php
require "includes/core.php";

// Check if user is authenticated
if (!isAuthenticated()) {
    echo SweetAlert::error("Access Denied", "Please login first");
    exit;
}

// Validate CSRF token
validateCSRF($_POST["csrf_token"] ?? "");

// Sanitize input
$websiteId = $_POST["websiteId"] ?? null;
$version = trim($_POST["version"] ?? "");
$status = $_POST["status"] ?? "";
$repoUrl = trim($_POST["repo_url"] ?? "");
$repoName = extractRepoNameFromGitUrl($repoUrl);
$folderId = $_POST["folderId"] ?? null;
$note = $_POST["note"] ?? "";
$userId = $_SESSION["userId"];

// Validate input
if (empty($version)) {
    echo SweetAlert::error("Validation Error", "Version is required");
    exit;
}

if ($repoUrl !== "" && (!validateGitRepoUrl($repoUrl) || empty($repoName))) {
    echo SweetAlert::error("Validation Error", "GitHub repo URL must end with .git");
    exit;
}

// Validate version format
if (!Security::validateVersion($version)) {
    echo SweetAlert::error("Validation Error", "Version must be in format like 1.0.0 or v1.0.0");
    exit;
}

// Validate status
$statusMap = ["updated" => "deployed", "updating" => "building", "issue" => "error", "working" => "deployed", "initializing" => "initializing", "building" => "building", "deployed" => "deployed", "warning" => "warning", "error" => "error"];
$status = $statusMap[$status] ?? $status;
$validStatuses = ["initializing", "building", "deployed", "warning", "error"];
if (!in_array($status, $validStatuses)) {
    echo SweetAlert::error("Validation Error", "Invalid status selected");
    exit;
}

// Use prepared statements for update
try {
    $pdo->beginTransaction();
    $roleManager = new RoleManager($pdo);
    if (!$roleManager->canAccessProject($userId, (int) $websiteId)) {
        throw new Exception("Project access denied");
    }
    if (!empty($folderId) && !$roleManager->canAccessSubject($userId, (int) $folderId)) {
        throw new Exception("Subject access denied");
    }
    
    // Update project
    $update = $pdo->prepare("
        UPDATE projects
        SET current_version = ?, github_repo_url = ?, github_repo_name = ?, subject_id = ?, saved_at = NOW(), updated_at = NOW()
        WHERE project_id = ?
    ");
    
    $update->execute([$version, $repoUrl, $repoName, $folderId ?: null, $websiteId]);

    $statusUpdate = $pdo->prepare("
        INSERT INTO project_status (project_id, status, updated_by, checked_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE status = VALUES(status), updated_by = VALUES(updated_by), checked_at = VALUES(checked_at)
    ");
    $statusUpdate->execute([$websiteId, $status, $userId]);
    
    // Log update
    $log = $pdo->prepare("
        INSERT INTO activity_logs (project_id, version, note, userId, action)
        VALUES (?,?,?,?, 'project_updated')
    ");
    
    $log->execute([$websiteId, $version, $note, $userId]);
    
    $pdo->commit();
    
    echo SweetAlert::success("Success", "Project updated successfully", "dashboard.php");
    exit;
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo SweetAlert::error("Database Error", "Failed to update project");
    error_log("Project update error: " . $e->getMessage());
}

