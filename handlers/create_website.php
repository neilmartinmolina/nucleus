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

if (!hasPermission("create_project")) {
    echo json_encode(["success" => false, "message" => "Permission denied"]);
    exit;
}

$websiteName = trim($data["websiteName"] ?? "");
$url = trim($data["url"] ?? "");
$repoUrl = trim($data["repo_url"] ?? "");
$repoName = extractRepoNameFromGitUrl($repoUrl);
$version = trim($data["version"] ?? "1.0.0");
$folderId = $data["folderId"] ?? null;

if (empty($websiteName) || empty($url)) {
    echo json_encode(["success" => false, "message" => "Website name and URL are required"]);
    exit;
}

if ($repoUrl !== "" && (!validateGitRepoUrl($repoUrl) || empty($repoName))) {
    echo json_encode(["success" => false, "message" => "GitHub repo URL must end with .git"]);
    exit;
}

if (!Security::validateVersion($version)) {
    echo json_encode(["success" => false, "message" => "Invalid version format. Use format like 0.1, 1.0.0, or v1.0.0"]);
    exit;
}

$roleManager = new RoleManager($pdo);
if (!empty($folderId) && !$roleManager->canAccessSubject($_SESSION["userId"], (int) $folderId)) {
    echo json_encode(["success" => false, "message" => "You do not have access to that subject"]);
    exit;
}

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("
        INSERT INTO projects (project_name, public_url, github_repo_url, github_repo_name, current_version, subject_id, owner_id, created_at, updated_at, saved_at, last_updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW(), NULL)
    ");
    $stmt->execute([$websiteName, $url, $repoUrl, $repoName, $version, $folderId ?: null, $_SESSION["userId"]]);

    $newId = $pdo->lastInsertId();
    $stmt = $pdo->prepare("INSERT INTO project_status (project_id, status, updated_by, checked_at) VALUES (?, 'initializing', ?, NOW())");
    $stmt->execute([$newId, $_SESSION["userId"]]);
    $stmt = $pdo->prepare("INSERT INTO project_members (project_id, userId, member_role, added_by) VALUES (?, ?, 'owner', ?)");
    $stmt->execute([$newId, $_SESSION["userId"], $_SESSION["userId"]]);
    $subjectNote = "No subject";
    if (!empty($folderId)) {
        $subjectStmt = $pdo->prepare("SELECT subject_code FROM subjects WHERE subject_id = ?");
        $subjectStmt->execute([$folderId]);
        $subjectNote = $subjectStmt->fetchColumn() ?: $subjectNote;
    }
    $stmt = $pdo->prepare("INSERT INTO activity_logs (project_id, userId, action, version, note) VALUES (?, ?, 'project_created', ?, ?)");
    $stmt->execute([$newId, $_SESSION["userId"], $version, "Project created in {$subjectNote}"]);
    $pdo->commit();

    // Fetch the newly created project with aliases used by existing card JavaScript.
    $stmt = $pdo->prepare("
        SELECT p.project_id AS websiteId, p.project_name AS websiteName, p.public_url AS url,
               p.current_version AS currentVersion, p.last_updated_at AS lastUpdatedAt,
               COALESCE(ps.status, 'initializing') AS deployStatus, ps.updated_by AS updatedBy, u.fullName as updatedByName
        FROM projects p
        LEFT JOIN project_status ps ON ps.project_id = p.project_id
        LEFT JOIN users u ON ps.updated_by = u.userId
        WHERE p.project_id = ?
    ");
    $stmt->execute([$newId]);
    $website = $stmt->fetch();

    // Compute display fields (status label, relative time)
    function computeStatus($lastUpdatedAt, $deployStatus = "deployed") {
        if (in_array($deployStatus, ["initializing", "building", "error"], true)) {
            return ucfirst($deployStatus);
        }
        if (!$lastUpdatedAt) return "30d+ old";
        $diffDays = (new DateTime())->diff(new DateTime($lastUpdatedAt))->days;
        if ($diffDays <= 14) return "Up to date";
        if ($diffDays <= 29) return "Needs update";
        return "30d+ old";
    }

    function timeAgo($datetime) {
        if (!$datetime) return "Never";
        $diff = (new DateTime())->diff(new DateTime($datetime));
        if ($diff->d == 0 && $diff->h == 0) return "Just now";
        if ($diff->d == 0) return $diff->h . "h ago";
        if ($diff->d == 1) return "Yesterday";
        return $diff->d . "d ago";
    }

    $statusLabel = computeStatus($website["lastUpdatedAt"], $website["deployStatus"]);
    if ($statusLabel === "Initializing") {
        $statusClass = "badge-initializing";
    } elseif ($statusLabel === "Up to date") {
        $statusClass = "badge-deployed";
    } elseif ($statusLabel === "Needs update" || $statusLabel === "Building") {
        $statusClass = "badge-building";
    } else {
        $statusClass = "badge-error";
    }

    // Return HTML for the new card (to be inserted)
    ob_start();
    ?>
    <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm project-card" data-name="<?php echo strtolower(htmlspecialchars($website["websiteName"])); ?>" data-status="<?php echo $statusLabel; ?>" data-updated="<?php echo strtotime($website["lastUpdatedAt"]); ?>">
        <div class="flex justify-between items-start gap-4">
            <div>
                <h2 class="text-2xl font-bold text-slate-800"><?php echo htmlspecialchars($website["websiteName"]); ?></h2>
                <p class="text-sm text-slate-500"><?php echo htmlspecialchars($website["url"]); ?></p>
            </div>
            <span class="px-3 py-1 text-xs font-semibold rounded-full <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span>
        </div>

        <div class="mt-2 text-sm text-slate-500">
            Last updated <span class="text-slate-700 font-medium"><?php echo timeAgo($website["lastUpdatedAt"]); ?></span>
        </div>

        <div class="mt-4">
            <button class="mark-updated w-full flex items-center justify-center p-4 px-6 rounded-xl bg-navy text-white font-medium border border-navy hover:bg-navy/90 transition" data-website-id="<?php echo $website["websiteId"]; ?>">
                Mark updated now
            </button>
        </div>
    </div>
    <?php
    $cardHtml = ob_get_clean();

    echo json_encode([
        "success" => true,
        "message" => "Project created successfully",
        "website" => $website,
        "cardHtml" => $cardHtml
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
