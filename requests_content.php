<?php
require_once __DIR__ . "/includes/core.php";

if (!isAuthenticated()) {
    echo "<div class=\"p-8 text-center\"><p class=\"text-slate-600\">Please login to continue</p></div>";
    exit;
}

function ensureProjectRequestsTable(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS project_requests (
            request_id INT PRIMARY KEY AUTO_INCREMENT,
            requested_by INT NOT NULL,
            subject_id INT NOT NULL,
            project_name VARCHAR(255) NOT NULL,
            public_url VARCHAR(2048) NOT NULL,
            github_repo_url VARCHAR(2048) NOT NULL,
            github_repo_name VARCHAR(255) NULL,
            requested_version VARCHAR(50) NOT NULL DEFAULT '1.0.0',
            message TEXT NULL,
            status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
            reviewed_by INT NULL,
            reviewed_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (requested_by) REFERENCES users(userId) ON DELETE CASCADE,
            FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE CASCADE,
            FOREIGN KEY (reviewed_by) REFERENCES users(userId) ON DELETE SET NULL,
            INDEX idx_project_requests_status (status),
            INDEX idx_project_requests_subject_status (subject_id, status),
            INDEX idx_project_requests_requested_by (requested_by)
        )
    ");
}

function canReviewProjectRequest(RoleManager $roleManager, int $subjectId): bool {
    $role = $_SESSION["role"] ?? "visitor";
    if ($role === "admin") {
        return true;
    }

    return $role === "handler" && $roleManager->canAccessSubject($_SESSION["userId"], $subjectId);
}

ensureProjectRequestsTable($pdo);
generateCSRFToken();

$roleManager = new RoleManager($pdo);
$error = null;
$success = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    validateCSRF($_POST["csrf_token"] ?? "");
    $action = $_POST["request_action"] ?? "";

    try {
        if ($action === "create_request" && hasPermission("request_subject")) {
            $subjectCode = strtoupper(trim($_POST["subject_code"] ?? ""));
            $subjectName = trim($_POST["subject_name"] ?? $subjectCode);
            $description = trim($_POST["description"] ?? "");

            if ($subjectCode === "") {
                throw new Exception("Subject code is required.");
            }

            $stmt = $pdo->prepare("SELECT subject_id FROM subjects WHERE subject_code = ?");
            $stmt->execute([$subjectCode]);
            if ($stmt->fetch()) {
                throw new Exception("That subject already exists.");
            }

            $stmt = $pdo->prepare("
                SELECT request_id
                FROM subject_requests
                WHERE requested_by = ? AND subject_code = ? AND status = 'pending'
            ");
            $stmt->execute([$_SESSION["userId"], $subjectCode]);
            if ($stmt->fetch()) {
                throw new Exception("You already have a pending request for this subject.");
            }

            $stmt = $pdo->prepare("
                INSERT INTO subject_requests (requested_by, subject_code, subject_name, description)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$_SESSION["userId"], $subjectCode, $subjectName ?: $subjectCode, $description]);
            logActivity("subject_requested", "Requested new subject {$subjectCode}");
            $success = "Subject request submitted.";
        } elseif ($action === "create_project_request" && hasPermission("request_project")) {
            $subjectId = isset($_POST["subject_id"]) && is_numeric($_POST["subject_id"]) ? (int) $_POST["subject_id"] : null;
            $projectName = trim($_POST["project_name"] ?? "");
            $publicUrl = trim($_POST["public_url"] ?? "");
            $repoUrl = trim($_POST["github_repo_url"] ?? "");
            $repoName = extractRepoNameFromGitUrl($repoUrl);
            $version = trim($_POST["version"] ?? "1.0.0");
            $message = trim($_POST["message"] ?? "");

            if (!$subjectId) {
                throw new Exception("Please choose a subject folder.");
            }
            if ($projectName === "" || $publicUrl === "" || $repoUrl === "") {
                throw new Exception("Website name, URL, and GitHub repo URL are required.");
            }
            if (!filter_var($publicUrl, FILTER_VALIDATE_URL)) {
                throw new Exception("Please enter a valid website URL.");
            }
            if (!validateGitRepoUrl($repoUrl) || $repoName === "") {
                throw new Exception("GitHub repo URL must end with .git.");
            }
            if (!Security::validateVersion($version)) {
                throw new Exception("Invalid version format. Use format like 1.0.0 or v1.0.0.");
            }

            $stmt = $pdo->prepare("SELECT subject_code FROM subjects WHERE subject_id = ?");
            $stmt->execute([$subjectId]);
            $subjectCode = $stmt->fetchColumn();
            if (!$subjectCode) {
                throw new Exception("Selected subject folder was not found.");
            }

            $stmt = $pdo->prepare("
                SELECT request_id
                FROM project_requests
                WHERE requested_by = ? AND subject_id = ? AND github_repo_url = ? AND status = 'pending'
                LIMIT 1
            ");
            $stmt->execute([$_SESSION["userId"], $subjectId, $repoUrl]);
            if ($stmt->fetch()) {
                throw new Exception("You already have a pending request for this website in that subject.");
            }

            $stmt = $pdo->prepare("
                INSERT INTO project_requests
                    (requested_by, subject_id, project_name, public_url, github_repo_url, github_repo_name, requested_version, message)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$_SESSION["userId"], $subjectId, $projectName, $publicUrl, $repoUrl, $repoName, $version, $message]);
            logActivity("project_requested", "Requested {$projectName} for subject {$subjectCode}");
            $success = "Website request submitted to the subject handlers.";
        } elseif ($action === "approve_project_request") {
            $requestId = isset($_POST["request_id"]) && is_numeric($_POST["request_id"]) ? (int) $_POST["request_id"] : null;
            if (!$requestId) {
                throw new Exception("Invalid website request.");
            }

            $stmt = $pdo->prepare("
                SELECT pr.*, s.subject_code, requester.fullName AS requesterName
                FROM project_requests pr
                JOIN subjects s ON s.subject_id = pr.subject_id
                JOIN users requester ON requester.userId = pr.requested_by
                WHERE pr.request_id = ? AND pr.status = 'pending'
            ");
            $stmt->execute([$requestId]);
            $request = $stmt->fetch();
            if (!$request) {
                throw new Exception("Website request is no longer pending.");
            }
            if (!canReviewProjectRequest($roleManager, (int) $request["subject_id"])) {
                throw new Exception("You do not have permission to approve this subject's website requests.");
            }

            $webhookSecret = bin2hex(random_bytes(32));
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                INSERT INTO projects
                    (project_name, public_url, github_repo_url, github_repo_name, webhook_secret, current_version, subject_id, owner_id, created_at, updated_at, saved_at, last_updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW(), NULL)
            ");
            $stmt->execute([
                $request["project_name"],
                $request["public_url"],
                $request["github_repo_url"],
                $request["github_repo_name"],
                $webhookSecret,
                $request["requested_version"],
                $request["subject_id"],
                $request["requested_by"],
            ]);
            $projectId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare("INSERT INTO project_status (project_id, status, updated_by, checked_at) VALUES (?, 'initializing', ?, NOW())");
            $stmt->execute([$projectId, $_SESSION["userId"]]);

            $stmt = $pdo->prepare("INSERT INTO project_members (project_id, userId, member_role, added_by) VALUES (?, ?, 'owner', ?)");
            $stmt->execute([$projectId, $request["requested_by"], $_SESSION["userId"]]);

            $stmt = $pdo->prepare("UPDATE project_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE request_id = ?");
            $stmt->execute([$_SESSION["userId"], $requestId]);

            $stmt = $pdo->prepare("INSERT INTO activity_logs (project_id, userId, action, version, note) VALUES (?, ?, 'project_request_approved', ?, ?)");
            $stmt->execute([$projectId, $_SESSION["userId"], $request["requested_version"], "Approved {$request["requesterName"]}'s website request for {$request["subject_code"]}"]);
            $pdo->commit();
            $success = "Website request approved and project created.";
        } elseif ($action === "reject_project_request") {
            $requestId = isset($_POST["request_id"]) && is_numeric($_POST["request_id"]) ? (int) $_POST["request_id"] : null;
            if (!$requestId) {
                throw new Exception("Invalid website request.");
            }

            $stmt = $pdo->prepare("
                SELECT pr.*, s.subject_code, requester.fullName AS requesterName
                FROM project_requests pr
                JOIN subjects s ON s.subject_id = pr.subject_id
                JOIN users requester ON requester.userId = pr.requested_by
                WHERE pr.request_id = ? AND pr.status = 'pending'
            ");
            $stmt->execute([$requestId]);
            $request = $stmt->fetch();
            if (!$request) {
                throw new Exception("Website request is no longer pending.");
            }
            if (!canReviewProjectRequest($roleManager, (int) $request["subject_id"])) {
                throw new Exception("You do not have permission to reject this subject's website requests.");
            }

            $stmt = $pdo->prepare("UPDATE project_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW() WHERE request_id = ?");
            $stmt->execute([$_SESSION["userId"], $requestId]);
            logActivity("project_request_rejected", "Rejected {$request["requesterName"]}'s website request for {$request["subject_code"]}");
            $success = "Website request rejected.";
        } elseif ($action === "approve_request" && hasPermission("manage_requests")) {
            $requestId = isset($_POST["request_id"]) && is_numeric($_POST["request_id"]) ? (int) $_POST["request_id"] : null;
            if (!$requestId) {
                throw new Exception("Invalid request.");
            }

            $stmt = $pdo->prepare("
                SELECT sr.*, u.fullName AS requesterName
                FROM subject_requests sr
                JOIN users u ON u.userId = sr.requested_by
                WHERE sr.request_id = ? AND sr.status = 'pending'
            ");
            $stmt->execute([$requestId]);
            $request = $stmt->fetch();
            if (!$request) {
                throw new Exception("Request is no longer pending.");
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                INSERT INTO subjects (subject_code, subject_name, description, created_by)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$request["subject_code"], $request["subject_name"], $request["description"], $_SESSION["userId"]]);
            $subjectId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare("
                INSERT INTO subject_members (subject_id, userId, access_level, added_by)
                VALUES (?, ?, 'manager', ?)
                ON DUPLICATE KEY UPDATE access_level = VALUES(access_level), added_by = VALUES(added_by)
            ");
            $stmt->execute([$subjectId, $request["requested_by"], $_SESSION["userId"]]);

            $stmt = $pdo->prepare("UPDATE subject_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE request_id = ?");
            $stmt->execute([$_SESSION["userId"], $requestId]);
            $pdo->commit();

            logActivity("subject_request_approved", "Approved {$request["requesterName"]}'s request for {$request["subject_code"]}");
            logActivity("subject_created", "Created subject {$request["subject_code"]} from request and granted requester access");
            $success = "Request approved and subject created.";
        } elseif ($action === "reject_request" && hasPermission("manage_requests")) {
            $requestId = isset($_POST["request_id"]) && is_numeric($_POST["request_id"]) ? (int) $_POST["request_id"] : null;
            if (!$requestId) {
                throw new Exception("Invalid request.");
            }

            $stmt = $pdo->prepare("
                SELECT sr.*, u.fullName AS requesterName
                FROM subject_requests sr
                JOIN users u ON u.userId = sr.requested_by
                WHERE sr.request_id = ? AND sr.status = 'pending'
            ");
            $stmt->execute([$requestId]);
            $request = $stmt->fetch();
            if (!$request) {
                throw new Exception("Request is no longer pending.");
            }

            $stmt = $pdo->prepare("UPDATE subject_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW() WHERE request_id = ?");
            $stmt->execute([$_SESSION["userId"], $requestId]);
            logActivity("subject_request_rejected", "Rejected {$request["requesterName"]}'s request for {$request["subject_code"]}");
            $success = "Request rejected.";
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

if (hasPermission("manage_requests")) {
    $stmt = $pdo->query("
        SELECT sr.*, requester.fullName AS requesterName, reviewer.fullName AS reviewerName
        FROM subject_requests sr
        JOIN users requester ON requester.userId = sr.requested_by
        LEFT JOIN users reviewer ON reviewer.userId = sr.reviewed_by
        ORDER BY FIELD(sr.status, 'pending', 'approved', 'rejected'), sr.created_at DESC
        LIMIT 250
    ");
    $subjectRequests = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare("
        SELECT sr.*, reviewer.fullName AS reviewerName
        FROM subject_requests sr
        LEFT JOIN users reviewer ON reviewer.userId = sr.reviewed_by
        WHERE sr.requested_by = ?
        ORDER BY sr.created_at DESC
        LIMIT 250
    ");
    $stmt->execute([$_SESSION["userId"]]);
    $subjectRequests = $stmt->fetchAll();
}

$canReviewProjectRequests = in_array($_SESSION["role"] ?? "", ["admin", "handler"], true);
if ($canReviewProjectRequests) {
    if (($_SESSION["role"] ?? "") === "admin") {
        $stmt = $pdo->query("
            SELECT pr.*, s.subject_code, s.subject_name, requester.fullName AS requesterName, reviewer.fullName AS reviewerName
            FROM project_requests pr
            JOIN subjects s ON s.subject_id = pr.subject_id
            JOIN users requester ON requester.userId = pr.requested_by
            LEFT JOIN users reviewer ON reviewer.userId = pr.reviewed_by
            ORDER BY FIELD(pr.status, 'pending', 'approved', 'rejected'), pr.created_at DESC
            LIMIT 250
        ");
        $projectRequests = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare("
            SELECT pr.*, s.subject_code, s.subject_name, requester.fullName AS requesterName, reviewer.fullName AS reviewerName
            FROM project_requests pr
            JOIN subjects s ON s.subject_id = pr.subject_id
            JOIN subject_members sm ON sm.subject_id = pr.subject_id AND sm.userId = ?
            JOIN users requester ON requester.userId = pr.requested_by
            LEFT JOIN users reviewer ON reviewer.userId = pr.reviewed_by
            ORDER BY FIELD(pr.status, 'pending', 'approved', 'rejected'), pr.created_at DESC
            LIMIT 250
        ");
        $stmt->execute([$_SESSION["userId"]]);
        $projectRequests = $stmt->fetchAll();
    }
} else {
    $stmt = $pdo->prepare("
        SELECT pr.*, s.subject_code, s.subject_name, reviewer.fullName AS reviewerName
        FROM project_requests pr
        JOIN subjects s ON s.subject_id = pr.subject_id
        LEFT JOIN users reviewer ON reviewer.userId = pr.reviewed_by
        WHERE pr.requested_by = ?
        ORDER BY pr.created_at DESC
        LIMIT 250
    ");
    $stmt->execute([$_SESSION["userId"]]);
    $projectRequests = $stmt->fetchAll();
}

$subjects = $pdo->query("SELECT subject_id, subject_code, subject_name FROM subjects ORDER BY subject_code ASC")->fetchAll();
?>
<div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
  <div>
    <h1 class="text-2xl font-bold text-slate-800">Requests</h1>
    <p class="text-sm text-slate-500">Request website placement and approve subject changes</p>
  </div>
</div>

<?php if ($error): ?><div data-feedback="error" data-feedback-title="Request not saved" data-feedback-message="<?php echo htmlspecialchars($error); ?>" class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($success): ?><div data-feedback="success" data-feedback-title="Request saved" data-feedback-message="<?php echo htmlspecialchars($success); ?>" class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

<?php if (hasPermission("request_project")): ?>
<section class="mb-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
  <h2 class="text-lg font-semibold text-slate-800">Request Website Placement</h2>
  <form method="POST" action="get_content.php?tab=requests" class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION["csrf_token"]; ?>">
    <input type="hidden" name="request_action" value="create_project_request">
    <div class="md:col-span-2">
      <label class="mb-1 block text-sm font-medium text-slate-700">Subject Folder *</label>
      <select name="subject_id" required class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-cta focus:ring-2 focus:ring-cta/20">
        <option value="">Choose subject</option>
        <?php foreach ($subjects as $subject): ?>
        <option value="<?php echo $subject["subject_id"]; ?>"><?php echo htmlspecialchars($subject["subject_code"] . " - " . $subject["subject_name"]); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="mb-1 block text-sm font-medium text-slate-700">Website Name *</label>
      <input type="text" name="project_name" required class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-cta focus:ring-2 focus:ring-cta/20">
    </div>
    <div>
      <label class="mb-1 block text-sm font-medium text-slate-700">Version *</label>
      <input type="text" name="version" required value="1.0.0" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-cta focus:ring-2 focus:ring-cta/20">
    </div>
    <div>
      <label class="mb-1 block text-sm font-medium text-slate-700">Website URL *</label>
      <input type="url" name="public_url" required placeholder="https://example.com" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-cta focus:ring-2 focus:ring-cta/20">
    </div>
    <div>
      <label class="mb-1 block text-sm font-medium text-slate-700">GitHub Repo URL (.git) *</label>
      <input type="url" name="github_repo_url" required pattern="https?://.+\.git$" placeholder="https://github.com/owner/repo.git" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-cta focus:ring-2 focus:ring-cta/20">
    </div>
    <div class="md:col-span-2">
      <label class="mb-1 block text-sm font-medium text-slate-700">Message to Handlers</label>
      <input type="text" name="message" placeholder="Short note about the website or subject placement" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-cta focus:ring-2 focus:ring-cta/20">
    </div>
    <div class="md:col-span-2">
      <button type="submit" class="rounded-lg bg-cta px-4 py-2 text-sm font-medium text-white transition hover:bg-blue-500">Submit Website Request</button>
    </div>
  </form>
</section>
<?php endif; ?>

<?php if (hasPermission("request_subject")): ?>
<section class="mb-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
  <h2 class="text-lg font-semibold text-slate-800">Request Subject Folder</h2>
  <form method="POST" action="get_content.php?tab=requests" class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION["csrf_token"]; ?>">
    <input type="hidden" name="request_action" value="create_request">
    <div>
      <label class="mb-1 block text-sm font-medium text-slate-700">Subject Code *</label>
      <input type="text" name="subject_code" required placeholder="ITPROFEL1" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-cta focus:ring-2 focus:ring-cta/20">
    </div>
    <div>
      <label class="mb-1 block text-sm font-medium text-slate-700">Subject Name</label>
      <input type="text" name="subject_name" placeholder="IT Professional Elective 1" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-cta focus:ring-2 focus:ring-cta/20">
    </div>
    <div class="md:col-span-2">
      <label class="mb-1 block text-sm font-medium text-slate-700">Reason or Description</label>
      <input type="text" name="description" placeholder="Needed for this term's project submissions" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-cta focus:ring-2 focus:ring-cta/20">
    </div>
    <div class="md:col-span-2">
      <button type="submit" class="rounded-lg bg-cta px-4 py-2 text-sm font-medium text-white transition hover:bg-blue-500">Submit Subject Request</button>
    </div>
  </form>
</section>
<?php endif; ?>

<section class="mb-6 rounded-xl border border-slate-200 bg-white shadow-sm">
  <div class="border-b border-slate-100 p-6">
    <h2 class="text-lg font-semibold text-slate-800"><?php echo $canReviewProjectRequests ? "Website Requests" : "My Website Requests"; ?></h2>
    <div class="mt-4">
      <label for="websiteRequestSearch" class="mb-2 block text-sm font-medium text-slate-700">Search Website Requests</label>
      <input id="websiteRequestSearch" type="search" data-table-search="#websiteRequestsTable" placeholder="Search by website, subject, requester, status, or message" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none transition focus:border-cta focus:ring-2 focus:ring-cta/20">
    </div>
  </div>
  <div class="overflow-x-auto lg:overflow-x-visible">
    <div class="nucleus-table-inner px-3 sm:px-4">
    <table id="websiteRequestsTable" class="data-table w-full" data-page-length="10" data-order-column="<?php echo $canReviewProjectRequests ? 5 : 4; ?>" data-order-direction="desc" data-empty="No website requests found">
      <thead class="bg-slate-50">
        <tr class="text-left text-sm text-slate-600 border-b border-slate-200">
          <th class="pb-3 pl-6 pr-4 font-semibold">Website</th>
          <th class="pb-3 pr-4 font-semibold">Subject</th>
          <?php if ($canReviewProjectRequests): ?><th class="pb-3 pr-4 font-semibold">Requested By</th><?php endif; ?>
          <th class="pb-3 pr-4 font-semibold">Status</th>
          <th class="pb-3 pr-4 font-semibold">Message</th>
          <th class="pb-3 pr-4 font-semibold">Requested</th>
          <?php if ($canReviewProjectRequests): ?><th class="no-sort pb-3 pr-6 font-semibold">Action</th><?php endif; ?>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        <?php foreach ($projectRequests as $request): ?>
        <tr class="hover:bg-slate-50">
          <td class="py-4 pl-6 pr-4">
            <div class="font-medium text-slate-800"><?php echo htmlspecialchars($request["project_name"]); ?></div>
            <a href="<?php echo htmlspecialchars($request["public_url"]); ?>" target="_blank" rel="noopener noreferrer" class="block max-w-xs truncate text-sm text-cta"><?php echo htmlspecialchars($request["public_url"]); ?></a>
            <div class="max-w-xs truncate text-xs text-slate-500"><?php echo htmlspecialchars($request["github_repo_url"]); ?></div>
          </td>
          <td class="py-4 pr-4">
            <div class="font-medium text-slate-700"><?php echo htmlspecialchars($request["subject_code"]); ?></div>
            <div class="text-sm text-slate-500"><?php echo htmlspecialchars($request["subject_name"]); ?></div>
          </td>
          <?php if ($canReviewProjectRequests): ?><td class="py-4 pr-4 text-sm text-slate-600"><?php echo htmlspecialchars($request["requesterName"]); ?></td><?php endif; ?>
          <td class="py-4 pr-4"><span class="rounded px-2 py-1 text-sm font-medium status-<?php echo htmlspecialchars($request["status"]); ?>"><?php echo ucfirst($request["status"]); ?></span></td>
          <td class="py-4 pr-4 text-sm text-slate-600"><?php echo htmlspecialchars($request["message"] ?? ""); ?></td>
          <td class="py-4 pr-4 text-sm text-slate-500"><?php echo htmlspecialchars(formatNucleusDateTime($request["created_at"])); ?></td>
          <?php if ($canReviewProjectRequests): ?>
          <td class="py-4 pr-6">
            <?php if ($request["status"] === "pending" && canReviewProjectRequest($roleManager, (int) $request["subject_id"])): ?>
            <div class="flex flex-wrap gap-2">
              <form method="POST" action="get_content.php?tab=requests">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION["csrf_token"]; ?>">
                <input type="hidden" name="request_action" value="approve_project_request">
                <input type="hidden" name="request_id" value="<?php echo $request["request_id"]; ?>">
                <button type="submit" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white">Approve</button>
              </form>
              <form method="POST" action="get_content.php?tab=requests">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION["csrf_token"]; ?>">
                <input type="hidden" name="request_action" value="reject_project_request">
                <input type="hidden" name="request_id" value="<?php echo $request["request_id"]; ?>">
                <button type="submit" class="rounded-lg bg-red-50 px-3 py-1.5 text-sm font-medium text-red-600">Reject</button>
              </form>
            </div>
            <?php else: ?>
            <span class="text-sm text-slate-500"><?php echo htmlspecialchars($request["reviewerName"] ?? "Reviewed"); ?></span>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</section>

<?php if (hasPermission("request_subject") || hasPermission("manage_requests")): ?>
<section class="rounded-xl border border-slate-200 bg-white shadow-sm">
  <div class="border-b border-slate-100 p-6">
    <h2 class="text-lg font-semibold text-slate-800"><?php echo hasPermission("manage_requests") ? "All Subject Requests" : "My Subject Requests"; ?></h2>
    <div class="mt-4">
      <label for="subjectRequestSearch" class="mb-2 block text-sm font-medium text-slate-700">Search Subject Requests</label>
      <input id="subjectRequestSearch" type="search" data-table-search="#subjectRequestsTable" placeholder="Search by subject, requester, status, description, or date" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none transition focus:border-cta focus:ring-2 focus:ring-cta/20">
    </div>
  </div>
  <div class="overflow-x-auto lg:overflow-x-visible">
    <div class="nucleus-table-inner px-3 sm:px-4">
    <table id="subjectRequestsTable" class="data-table w-full" data-page-length="10" data-order-column="4" data-order-direction="desc" data-empty="No subject requests found">
      <thead class="bg-slate-50">
        <tr class="text-left text-sm text-slate-600 border-b border-slate-200">
          <th class="pb-3 pl-6 pr-4 font-semibold">Subject</th>
          <?php if (hasPermission("manage_requests")): ?><th class="pb-3 pr-4 font-semibold">Requested By</th><?php endif; ?>
          <th class="pb-3 pr-4 font-semibold">Status</th>
          <th class="pb-3 pr-4 font-semibold">Description</th>
          <th class="pb-3 pr-4 font-semibold">Requested</th>
          <?php if (hasPermission("manage_requests")): ?><th class="no-sort pb-3 pr-6 font-semibold">Action</th><?php endif; ?>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        <?php foreach ($subjectRequests as $request): ?>
        <tr class="hover:bg-slate-50">
          <td class="py-4 pl-6 pr-4">
            <div class="font-medium text-slate-800"><?php echo htmlspecialchars($request["subject_code"]); ?></div>
            <div class="text-sm text-slate-500"><?php echo htmlspecialchars($request["subject_name"]); ?></div>
          </td>
          <?php if (hasPermission("manage_requests")): ?><td class="py-4 pr-4 text-sm text-slate-600"><?php echo htmlspecialchars($request["requesterName"]); ?></td><?php endif; ?>
          <td class="py-4 pr-4"><span class="rounded px-2 py-1 text-sm font-medium status-<?php echo htmlspecialchars($request["status"]); ?>"><?php echo ucfirst($request["status"]); ?></span></td>
          <td class="py-4 pr-4 text-sm text-slate-600"><?php echo htmlspecialchars($request["description"] ?? ""); ?></td>
          <td class="py-4 pr-4 text-sm text-slate-500"><?php echo htmlspecialchars(formatNucleusDateTime($request["created_at"])); ?></td>
          <?php if (hasPermission("manage_requests")): ?>
          <td class="py-4 pr-6">
            <?php if ($request["status"] === "pending"): ?>
            <div class="flex flex-wrap gap-2">
              <form method="POST" action="get_content.php?tab=requests">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION["csrf_token"]; ?>">
                <input type="hidden" name="request_action" value="approve_request">
                <input type="hidden" name="request_id" value="<?php echo $request["request_id"]; ?>">
                <button type="submit" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white">Approve</button>
              </form>
              <form method="POST" action="get_content.php?tab=requests">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION["csrf_token"]; ?>">
                <input type="hidden" name="request_action" value="reject_request">
                <input type="hidden" name="request_id" value="<?php echo $request["request_id"]; ?>">
                <button type="submit" class="rounded-lg bg-red-50 px-3 py-1.5 text-sm font-medium text-red-600">Reject</button>
              </form>
            </div>
            <?php else: ?>
            <span class="text-sm text-slate-500"><?php echo htmlspecialchars($request["reviewerName"] ?? "Reviewed"); ?></span>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</section>
<?php endif; ?>

<style>
.status-pending { background:#fef3c7; color:#92400e; }
.status-approved { background:#d1fae5; color:#065f46; }
.status-rejected { background:#fee2e2; color:#991b1b; }
</style>
