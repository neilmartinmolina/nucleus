<?php
require_once __DIR__ . "/includes/core.php";

if (!isAuthenticated()) {
    header("Location: login.php");
    exit;
}

$roleManager = new RoleManager($pdo);
$isAjaxRequest = ($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "") === "XMLHttpRequest";
$success = null;
$error = null;

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["csrf_token"]) && empty($_POST["project_action"])) {
    validateCSRF($_POST["csrf_token"]);
    
    $websiteName = trim($_POST["websiteName"] ?? "");
    $url = trim($_POST["url"] ?? "");
    $repoUrl = trim($_POST["repo_url"] ?? "");
    $repoName = extractRepoNameFromGitUrl($repoUrl);
    $version = trim($_POST["version"] ?? "1.0.0");
    $folderId = $_POST["folderId"] ?? null;
    
    if (empty($websiteName) || empty($url) || empty($repoUrl)) {
        $error = "Website name, URL, and GitHub repo URL are required";
    } elseif (!validateGitRepoUrl($repoUrl) || empty($repoName)) {
        $error = "GitHub repo URL must end with .git";
    } elseif (!Security::validateVersion($version)) {
        $error = "Invalid version format. Use format like 1.0.0 or v1.0.0";
    } elseif (!empty($folderId) && !$roleManager->canAccessSubject($_SESSION["userId"], (int) $folderId)) {
        $error = "You do not have access to that subject.";
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                INSERT INTO projects (project_name, public_url, github_repo_url, github_repo_name, current_version, subject_id, owner_id, created_at, updated_at, saved_at, last_updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW(), NULL)
            ");
            $stmt->execute([$websiteName, $url, $repoUrl, $repoName, $version, $folderId ?: null, $_SESSION["userId"]]);
            $projectId = (int) $pdo->lastInsertId();
            $stmt = $pdo->prepare("INSERT INTO project_status (project_id, status, updated_by, checked_at) VALUES (?, 'initializing', ?, NOW())");
            $stmt->execute([$projectId, $_SESSION["userId"]]);
            $stmt = $pdo->prepare("INSERT INTO project_members (project_id, userId, member_role, added_by) VALUES (?, ?, 'owner', ?)");
            $stmt->execute([$projectId, $_SESSION["userId"], $_SESSION["userId"]]);
            $stmt = $pdo->prepare("INSERT INTO activity_logs (project_id, userId, action, version, note) VALUES (?, ?, 'project_created', ?, ?)");
            $subjectNote = "No subject";
            if (!empty($folderId)) {
                $subjectStmt = $pdo->prepare("SELECT subject_code FROM subjects WHERE subject_id = ?");
                $subjectStmt->execute([$folderId]);
                $subjectNote = $subjectStmt->fetchColumn() ?: $subjectNote;
            }
            $stmt->execute([$projectId, $_SESSION["userId"], $version, "Project created in {$subjectNote}"]);
            $pdo->commit();
            header("Location: dashboard.php?page=websites");
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Failed to create website: " . $e->getMessage();
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["project_action"] ?? "") === "delete") {
    validateCSRF($_POST["csrf_token"] ?? "");
    if (!hasPermission("delete_project")) {
        $error = "You do not have permission to delete projects.";
    } else {
        $id = isset($_POST["project_id"]) && is_numeric($_POST["project_id"]) ? (int) $_POST["project_id"] : 0;
        $pdo->prepare("DELETE FROM projects WHERE project_id = ?")->execute([$id]);
        $success = "Project deleted successfully.";
    }
    if (!$isAjaxRequest) {
        header("Location: dashboard.php?page=websites");
        exit;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["project_action"] ?? "") === "unlist") {
    validateCSRF($_POST["csrf_token"] ?? "");
    if (!hasPermission("update_project")) {
        $error = "You do not have permission to update projects.";
    } else {
        $id = isset($_POST["project_id"]) && is_numeric($_POST["project_id"]) ? (int) $_POST["project_id"] : 0;
        if ($id > 0 && $roleManager->canAccessProject($_SESSION["userId"], $id)) {
        $stmt = $pdo->prepare("
            SELECT p.project_name, s.subject_code
            FROM projects p
            LEFT JOIN subjects s ON s.subject_id = p.subject_id
            WHERE p.project_id = ?
        ");
        $stmt->execute([$id]);
        $project = $stmt->fetch();

        $stmt = $pdo->prepare("UPDATE projects SET subject_id = NULL, saved_at = NOW(), updated_at = NOW() WHERE project_id = ?");
        $stmt->execute([$id]);
        logActivity("project_unlisted", "Unlisted " . ($project["project_name"] ?? "project") . " from " . ($project["subject_code"] ?? "its subject"), (int) $id);
        } else {
            $error = "You do not have access to that project.";
        }
    }
    if (!$error) {
        $success = "Project unlisted successfully.";
    }
    if (!$isAjaxRequest) {
        header("Location: dashboard.php?page=websites");
        exit;
    }
}

?>
<div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
  <div>
    <h1 class="text-2xl font-bold text-slate-800">Projects</h1>
    <p class="text-sm text-slate-500">Manage academic project sites</p>
  </div>
  <div class="flex flex-wrap items-center gap-2">
    <button type="button" data-refresh-statuses class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:cursor-wait disabled:opacity-60">Refresh Status</button>
    <?php if (hasPermission("create_project")): ?>
    <a href="dashboard.php?page=create-project" class="bg-cta text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-opacity-90 transition-colors flex items-center gap-2">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
      New Project
    </a>
    <?php endif; ?>
  </div>
</div>

<?php if ($success): ?>
<div data-feedback="success" data-feedback-title="Projects updated" data-feedback-message="<?php echo htmlspecialchars($success); ?>" class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div data-feedback="error" data-feedback-title="Project action failed" data-feedback-message="<?php echo htmlspecialchars($error); ?>" class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
  <div class="border-b border-slate-100 p-6">
    <label for="projectSearch" class="mb-2 block text-sm font-medium text-slate-700">Search Projects</label>
    <input id="projectSearch" type="search" data-table-search="#projectsTable" placeholder="Search by project, subject, status, updated by, or date" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none transition focus:border-cta focus:ring-2 focus:ring-cta/20">
  </div>
  <div class="overflow-x-auto lg:overflow-x-visible">
    <div class="nucleus-table-inner px-3 sm:px-4">
    <table id="projectsTable" class="data-table w-full" data-server-side="true" data-ajax="handlers/datatables/projects.php" data-page-length="10" data-order-column="5" data-order-direction="desc" data-empty="No projects found">
      <thead class="bg-slate-50">
        <tr class="text-left text-sm text-slate-600 border-b border-slate-200">
          <th class="pb-3 pl-6 pr-4 font-semibold">Project</th>
          <th class="pb-3 pr-4 font-semibold">Subject</th>
          <th class="pb-3 pr-4 font-semibold">Status</th>
          <th class="pb-3 pr-4 font-semibold">Health</th>
          <th class="pb-3 pr-4 font-semibold">Updated By</th>
          <th class="pb-3 pr-4 font-semibold">Updated At</th>
          <th class="pb-3 pr-4 font-semibold">Saved At</th>
          <th class="no-sort pb-3 pr-6 font-semibold">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100"></tbody>
    </table>
    </div>
  </div>
</div>

<!-- Badge CSS -->
<style>
.badge-initializing { background:#e0f2fe; color:#075985; }
.badge-building { background:#fef3c7; color:#92400e; }
.badge-deployed { background:#d1fae5; color:#065f46; }
.badge-warning { background:#ffedd5; color:#9a3412; }
.badge-error { background:#fee2e2; color:#991b1b; }
</style>
