<?php
require_once __DIR__ . "/includes/core.php";

if (!isAuthenticated()) {
    header("Location: dashboard.php?page=dashboard");
    exit;
}

$websiteId = isset($_GET["websiteId"]) && is_numeric($_GET["websiteId"]) ? (int) $_GET["websiteId"] : null;
$isEdit = $websiteId !== null;
$roleManager = new RoleManager($pdo);

if ($isEdit && !hasPermission("update_project")) {
    echo "<div class=\"p-8 text-center\"><p class=\"text-slate-600\">You do not have permission to edit projects.</p></div>";
    exit;
}

if (!$isEdit && !hasPermission("create_project")) {
    echo "<div class=\"p-8 text-center\"><p class=\"text-slate-600\">You do not have permission to create projects.</p></div>";
    exit;
}

$folders = $roleManager->getUserSubjects($_SESSION["userId"]);
generateCSRFToken();
$website = [
    "websiteName" => "",
    "url" => "",
    "repo_url" => "",
    "repo_name" => "",
    "deployment_mode" => "hostinger_git",
    "deploy_path" => "",
    "webhook_secret" => bin2hex(random_bytes(32)),
    "currentVersion" => "1.0.0",
    "status" => "initializing",
    "folder_id" => $_GET["folderId"] ?? "",
];

if ($isEdit) {
    if (!$roleManager->canAccessProject($_SESSION["userId"], $websiteId)) {
        echo "<div class=\"p-8 text-center\"><p class=\"text-slate-600\">Project not found or access denied.</p></div>";
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT p.project_id AS websiteId, p.project_name AS websiteName, p.public_url AS url,
               p.github_repo_url AS repo_url, p.github_repo_name AS repo_name,
               COALESCE(p.deployment_mode, 'hostinger_git') AS deployment_mode,
               p.deploy_path, p.webhook_secret, p.current_version AS currentVersion,
               COALESCE(ps.status, 'initializing') AS status, p.subject_id AS folder_id
        FROM projects p
        LEFT JOIN project_status ps ON ps.project_id = p.project_id
        WHERE p.project_id = ?
    ");
    $stmt->execute([$websiteId]);
    $existing = $stmt->fetch();
    if (!$existing) {
        echo "<div class=\"p-8 text-center\"><p class=\"text-slate-600\">Project not found.</p></div>";
        exit;
    }
    $website = array_merge($website, $existing);
    if (empty($website["webhook_secret"])) {
        $website["webhook_secret"] = bin2hex(random_bytes(32));
    }
}

$error = null;
$success = null;
$isAjaxRequest = ($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "") === "XMLHttpRequest";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    validateCSRF($_POST["csrf_token"] ?? "");

    $website["websiteName"] = trim($_POST["websiteName"] ?? "");
    $website["url"] = trim($_POST["url"] ?? "");
    $website["repo_url"] = trim($_POST["repo_url"] ?? "");
    $website["repo_name"] = extractRepoNameFromGitUrl($website["repo_url"]);
    $website["deployment_mode"] = $_POST["deployment_mode"] ?? "hostinger_git";
    $website["deploy_path"] = trim($_POST["deploy_path"] ?? "");
    $website["webhook_secret"] = trim($_POST["webhook_secret"] ?? "");
    $website["currentVersion"] = trim($_POST["version"] ?? "1.0.0");
    $statusMap = ["working" => "deployed", "updated" => "deployed"];
    $website["status"] = $statusMap[$_POST["status"] ?? "initializing"] ?? ($_POST["status"] ?? "initializing");
    $website["folder_id"] = $_POST["folderId"] ?? null;

    if ($website["websiteName"] === "" || $website["url"] === "" || $website["repo_url"] === "") {
        $error = "Website name, URL, and GitHub repo URL are required.";
    } elseif (!validateGitRepoUrl($website["repo_url"]) || $website["repo_name"] === "") {
        $error = "GitHub repo URL must end with .git.";
    } elseif (!in_array($website["deployment_mode"], ["hostinger_git", "custom_webhook"], true)) {
        $error = "Invalid deployment mode selected.";
    } elseif ($website["deployment_mode"] === "custom_webhook" && $website["webhook_secret"] === "") {
        $error = "Webhook secret is required.";
    } elseif (!Security::validateVersion($website["currentVersion"])) {
        $error = "Version must be in format like 1.0.0 or v1.0.0.";
    } elseif (!in_array($website["status"], ["initializing", "building", "deployed", "warning", "error"], true)) {
        $error = "Invalid status selected.";
    } elseif (!empty($website["folder_id"]) && !$roleManager->canAccessSubject($_SESSION["userId"], (int) $website["folder_id"])) {
        $error = "You do not have access to that subject.";
    } else {
        try {
            $pdo->beginTransaction();
            if ($isEdit) {
                $stmt = $pdo->prepare("
                    UPDATE projects
                    SET project_name = ?, public_url = ?, github_repo_url = ?, github_repo_name = ?, deployment_mode = ?, deploy_path = ?, webhook_secret = ?,
                        current_version = ?, subject_id = ?, saved_at = NOW(), updated_at = NOW()
                    WHERE project_id = ?
                ");
                $stmt->execute([
                    $website["websiteName"],
                    $website["url"],
                    $website["repo_url"],
                    $website["repo_name"],
                    $website["deployment_mode"],
                    $website["deploy_path"] !== "" ? $website["deploy_path"] : null,
                    $website["webhook_secret"],
                    $website["currentVersion"],
                    $website["folder_id"] ?: null,
                    $websiteId,
                ]);

                $stmt = $pdo->prepare("
                    INSERT INTO project_status (project_id, status, updated_by, checked_at)
                    VALUES (?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE status = VALUES(status), updated_by = VALUES(updated_by), checked_at = VALUES(checked_at)
                ");
                $stmt->execute([$websiteId, $website["status"], $_SESSION["userId"]]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO projects
                        (project_name, public_url, github_repo_url, github_repo_name, deployment_mode, deploy_path, webhook_secret, current_version, subject_id, owner_id, created_at, updated_at, saved_at, last_updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW(), NULL)
                ");
                $stmt->execute([
                    $website["websiteName"],
                    $website["url"],
                    $website["repo_url"],
                    $website["repo_name"],
                    $website["deployment_mode"],
                    $website["deploy_path"] !== "" ? $website["deploy_path"] : null,
                    $website["webhook_secret"],
                    $website["currentVersion"],
                    $website["folder_id"] ?: null,
                    $_SESSION["userId"],
                ]);
                $websiteId = (int) $pdo->lastInsertId();

                $stmt = $pdo->prepare("INSERT INTO project_members (project_id, userId, member_role, added_by) VALUES (?, ?, 'owner', ?)");
                $stmt->execute([$websiteId, $_SESSION["userId"], $_SESSION["userId"]]);

                $stmt = $pdo->prepare("INSERT INTO project_status (project_id, status, updated_by, checked_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$websiteId, $website["status"], $_SESSION["userId"]]);
            }

            $stmt = $pdo->prepare("INSERT INTO activity_logs (project_id, userId, action, version, note) VALUES (?, ?, ?, ?, ?)");
            $subjectNote = "No subject";
            if (!empty($website["folder_id"])) {
                $subjectStmt = $pdo->prepare("SELECT subject_code FROM subjects WHERE subject_id = ?");
                $subjectStmt->execute([$website["folder_id"]]);
                $subjectNote = $subjectStmt->fetchColumn() ?: $subjectNote;
            }
            $stmt->execute([$websiteId, $_SESSION["userId"], $isEdit ? "project_updated" : "project_created", $website["currentVersion"], "Project saved in {$subjectNote}"]);

            $pdo->commit();
            $success = "Project saved. The deployment monitor is ready to use.";
            $isEdit = true;
            if (!$isAjaxRequest) {
                header("Location: dashboard.php?page=create-project&websiteId=" . $websiteId . "&saved=1");
                exit;
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Failed to save project: " . $e->getMessage();
        }
    }
}

$githubHookUrl = githubHooksUrl($website["repo_url"]);
$tutorialUrl = rtrim(APP_URL, "/") . "/tutorial/setting-up-your-project";
$projectPublicBase = rtrim($website["url"], "/");
$deployWebhookUrl = $projectPublicBase !== "" ? $projectPublicBase . "/deploy.php" : "";
$statusEndpointUrl = $projectPublicBase !== "" ? $projectPublicBase . "/status.json" : "";
$versionJson = json_encode([
    "project" => $website["websiteName"] !== "" ? $website["websiteName"] : "ProjectName",
    "version" => $website["currentVersion"] !== "" ? $website["currentVersion"] : "1.0.0",
    "commit" => "manual-or-github-hash",
    "branch" => "main",
    "updated_at" => date("Y-m-d H:i:s"),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$isCustomWebhook = $website["deployment_mode"] === "custom_webhook";
$formAction = "get_content.php?tab=create-project";
if ($websiteId) {
    $formAction .= "&websiteId=" . urlencode((string) $websiteId);
} elseif (!empty($website["folder_id"])) {
    $formAction .= "&folderId=" . urlencode((string) $website["folder_id"]);
}
?>
<nav class="mb-3 flex items-center gap-2 text-sm text-slate-500" aria-label="Breadcrumb">
  <a href="dashboard.php?page=websites" class="font-medium text-slate-600 hover:text-navy">Projects</a>
  <span>/</span>
  <span class="font-medium text-slate-900"><?php echo $isEdit ? "Manage Project" : "Create New Project"; ?></span>
</nav>
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
  <div>
    <h1 class="text-2xl font-bold text-slate-800"><?php echo $isEdit ? "Edit Project" : "Create Project"; ?></h1>
    <p class="text-sm text-slate-500">Project details and deployment monitoring live together here.</p>
  </div>
  <a href="dashboard.php?page=websites" class="text-sm font-medium text-slate-600 transition-colors hover:text-navy">Back to Projects</a>
</div>

<?php if (isset($_GET["saved"])): ?>
<div data-feedback="success" data-feedback-title="Project saved" data-feedback-message="Project saved. The deployment monitor is ready to use." class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700">Project saved. The deployment monitor below is ready to use.</div>
<?php endif; ?>
<?php if ($success): ?>
<div data-feedback="success" data-feedback-title="Project saved" data-feedback-message="<?php echo htmlspecialchars($success); ?>" class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div data-feedback="error" data-feedback-title="Project not saved" data-feedback-message="<?php echo htmlspecialchars($error); ?>" class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
  <form method="POST" action="<?php echo htmlspecialchars($formAction); ?>" class="xl:col-span-2 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION["csrf_token"]; ?>">
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
      <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">Website Name *</label>
        <input type="text" name="websiteName" required value="<?php echo htmlspecialchars($website["websiteName"]); ?>" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none transition focus:border-cta focus:ring-2 focus:ring-cta/20">
      </div>
      <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">Website URL *</label>
        <input type="url" name="url" required value="<?php echo htmlspecialchars($website["url"]); ?>" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none transition focus:border-cta focus:ring-2 focus:ring-cta/20">
      </div>
      <div class="md:col-span-2">
        <label class="mb-1 block text-sm font-medium text-slate-700">GitHub Repo URL (.git) *</label>
        <input type="url" name="repo_url" id="repoUrl" required pattern="https?://.+\.git$" value="<?php echo htmlspecialchars($website["repo_url"]); ?>" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none transition focus:border-cta focus:ring-2 focus:ring-cta/20" placeholder="https://github.com/owner/repo.git">
      </div>
      <div class="md:col-span-2">
        <label class="mb-1 block text-sm font-medium text-slate-700">Deploy Path</label>
        <input type="text" name="deploy_path" value="<?php echo htmlspecialchars($website["deploy_path"]); ?>" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none transition focus:border-cta focus:ring-2 focus:ring-cta/20" placeholder="/home/username/domains/example.com/public_html">
        <p class="mt-1 text-xs text-slate-500">Optional absolute path to the Git checkout. Leave blank to use SITES_BASE_PATH/repo-name.</p>
      </div>
      <div class="md:col-span-2">
        <label class="mb-1 block text-sm font-medium text-slate-700">Deployment Mode</label>
        <select name="deployment_mode" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none transition focus:border-cta focus:ring-2 focus:ring-cta/20">
          <option value="hostinger_git" <?php echo $website["deployment_mode"] === "hostinger_git" ? "selected" : ""; ?>>Hostinger Git</option>
          <option value="custom_webhook" <?php echo $website["deployment_mode"] === "custom_webhook" ? "selected" : ""; ?>>Custom Webhook</option>
        </select>
        <p class="mt-1 text-xs text-slate-500">Hostinger Git is monitored by URL checks. Custom Webhook expects the project deploy.php to write status.json.</p>
      </div>
      <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">Version *</label>
        <input type="text" name="version" required value="<?php echo htmlspecialchars($website["currentVersion"]); ?>" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none transition focus:border-cta focus:ring-2 focus:ring-cta/20">
      </div>
      <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">Status</label>
        <select name="status" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none transition focus:border-cta focus:ring-2 focus:ring-cta/20">
          <?php foreach (["initializing" => "Initializing", "building" => "Building", "deployed" => "Deployed", "warning" => "Warning", "error" => "Error"] as $value => $label): ?>
          <option value="<?php echo $value; ?>" <?php echo $website["status"] === $value ? "selected" : ""; ?>><?php echo $label; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">Subject</label>
        <select name="folderId" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none transition focus:border-cta focus:ring-2 focus:ring-cta/20">
          <option value="">No Subject</option>
          <?php foreach ($folders as $folder): ?>
          <option value="<?php echo $folder["subject_id"]; ?>" <?php echo (string) $website["folder_id"] === (string) $folder["subject_id"] ? "selected" : ""; ?>><?php echo htmlspecialchars($folder["subject_code"] . " - " . $folder["subject_name"]); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">Webhook Secret *</label>
        <div class="flex flex-col gap-2 sm:flex-row">
          <input type="text" name="webhook_secret" id="webhookSecret" required value="<?php echo htmlspecialchars($website["webhook_secret"]); ?>" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none transition focus:border-cta focus:ring-2 focus:ring-cta/20">
          <button type="button" id="generateSecret" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 sm:w-auto">Generate</button>
        </div>
      </div>
    </div>
    <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
      <a href="dashboard.php?page=websites" class="rounded-lg border border-slate-200 px-4 py-2 text-center text-sm font-medium text-slate-700 transition hover:bg-slate-50">Cancel</a>
      <button type="submit" class="rounded-lg bg-[#0050D8] px-4 py-2 text-sm font-medium text-white transition hover:bg-[#003FA8]">Save Project</button>
    </div>
  </form>

  <aside class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <h2 class="text-lg font-semibold text-slate-900">Deployment Monitor</h2>
    <div class="mt-5 space-y-5">
      <div>
        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Deploy Webhook URL</label>
        <div class="flex flex-col gap-2 sm:flex-row">
          <input id="deployWebhookUrl" readonly value="<?php echo htmlspecialchars($deployWebhookUrl); ?>" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
          <button type="button" class="copy-btn rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 sm:w-auto" data-copy-target="deployWebhookUrl">Copy</button>
        </div>
        <p class="mt-1 text-xs text-slate-500"><?php echo $isCustomWebhook ? "Use this as the single GitHub webhook target when deploy.php exists in the deployed project." : "Not required for Hostinger Git mode. Hostinger owns deployment; Nucleus only monitors."; ?></p>
      </div>
      <div>
        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Deploy Secret</label>
        <button type="button" class="copy-btn w-full rounded-lg border border-slate-200 px-3 py-2 text-left text-sm font-medium text-slate-700 transition hover:bg-slate-50" data-copy-target="webhookSecret">Copy webhook secret</button>
      </div>
      <div>
        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Status Endpoint</label>
        <div class="flex flex-col gap-2 sm:flex-row">
          <input id="statusEndpointUrl" readonly value="<?php echo htmlspecialchars($statusEndpointUrl); ?>" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
          <button type="button" class="copy-btn rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 sm:w-auto" data-copy-target="statusEndpointUrl">Copy</button>
        </div>
      </div>
      <div>
        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Optional version.json</label>
        <textarea id="versionJsonTemplate" readonly rows="7" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-xs text-slate-700"><?php echo htmlspecialchars($versionJson); ?></textarea>
        <button type="button" class="copy-btn mt-2 w-full rounded-lg border border-slate-200 px-3 py-2 text-left text-sm font-medium text-slate-700 transition hover:bg-slate-50" data-copy-target="versionJsonTemplate">Copy version.json template</button>
      </div>
      <div>
        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Setup Webhook On</label>
        <?php if ($githubHookUrl): ?>
        <a href="<?php echo htmlspecialchars($githubHookUrl); ?>" target="_blank" rel="noopener noreferrer" class="block truncate rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-cta transition hover:bg-slate-50"><?php echo htmlspecialchars($githubHookUrl); ?></a>
        <?php else: ?>
        <p class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500">Save a GitHub repo URL to generate this link.</p>
        <?php endif; ?>
      </div>
      <div>
        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Setup Tutorial</label>
        <a href="<?php echo htmlspecialchars($tutorialUrl); ?>" target="_blank" rel="noopener noreferrer" class="block rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-cta transition hover:bg-slate-50">nucleus/tutorial/setting-up-your-project</a>
      </div>
      <div class="rounded-lg bg-slate-50 p-4 text-sm text-slate-600">
        <?php echo $isCustomWebhook ? "GitHub should call only the deploy script. Nucleus reads status.json or /api/status and never receives a second deployment webhook." : "Monitored via Hostinger Git. Nucleus checks the public URL, status.json or /api/status if present, optional version.json, and HTTP reachability."; ?>
      </div>
    </div>
  </aside>
</div>

<script>
(function() {
  const secretInput = document.getElementById('webhookSecret');

  function randomSecret() {
    const bytes = new Uint8Array(32);
    crypto.getRandomValues(bytes);
    return Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
  }

  document.getElementById('generateSecret')?.addEventListener('click', function() {
    secretInput.value = randomSecret();
  });

  document.querySelectorAll('.copy-btn').forEach(button => {
    button.addEventListener('click', async function() {
      const target = document.getElementById(this.dataset.copyTarget);
      if (!target) return;
      await navigator.clipboard.writeText(target.value);
      const original = this.textContent;
      this.textContent = 'Copied';
      setTimeout(() => this.textContent = original, 1200);
    });
  });
})();
</script>
