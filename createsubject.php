<?php
require_once __DIR__ . "/includes/core.php";

if (!isAuthenticated() || !hasPermission("manage_groups")) {
    header("Location: dashboard.php?page=folders");
    exit;
}

generateCSRFToken();
$error = null;
$success = null;
$isAjaxRequest = ($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "") === "XMLHttpRequest";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["csrf_token"])) {
    validateCSRF($_POST["csrf_token"]);

    $folderName = trim($_POST["folderName"] ?? "");
    $subjectName = trim($_POST["subjectName"] ?? $folderName);
    $description = trim($_POST["description"] ?? "");
    $requestId = isset($_POST["subject_request_id"]) && is_numeric($_POST["subject_request_id"]) ? (int) $_POST["subject_request_id"] : null;
    $handlerIds = array_values(array_filter(array_map("intval", $_POST["handlerIds"] ?? [])));

    if ($folderName === "" && !$requestId) {
        $error = "Subject code is required";
    } else {
        try {
            $request = null;
            if ($requestId && hasPermission("manage_requests")) {
                $stmt = $pdo->prepare("
                    SELECT sr.*, u.fullName AS requesterName
                    FROM subject_requests sr
                    JOIN users u ON u.userId = sr.requested_by
                    WHERE sr.request_id = ? AND sr.status = 'pending'
                ");
                $stmt->execute([$requestId]);
                $request = $stmt->fetch();
                if (!$request) {
                    throw new Exception("Selected request is no longer pending.");
                }

                $folderName = $request["subject_code"];
                $subjectName = $request["subject_name"];
                $description = $request["description"] ?? "";
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO subjects (subject_code, subject_name, description, created_by) VALUES (?, ?, ?, ?)");
            $stmt->execute([strtoupper($folderName), $subjectName ?: strtoupper($folderName), $description, $_SESSION["userId"]]);
            $subjectId = (int) $pdo->lastInsertId();

            if ($request) {
                $stmt = $pdo->prepare("
                    INSERT INTO subject_members (subject_id, userId, access_level, added_by)
                    VALUES (?, ?, 'manager', ?)
                    ON DUPLICATE KEY UPDATE access_level = VALUES(access_level), added_by = VALUES(added_by)
                ");
                $stmt->execute([$subjectId, $request["requested_by"], $_SESSION["userId"]]);
                $handlerIds[] = (int) $request["requested_by"];

                $stmt = $pdo->prepare("UPDATE subject_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE request_id = ?");
                $stmt->execute([$_SESSION["userId"], $requestId]);
                logActivity("subject_request_approved", "Approved {$request["requesterName"]}'s request for " . strtoupper($folderName));
                logActivity("subject_created", "Created subject " . strtoupper($folderName) . " from request and granted requester access");
            } else {
                logActivity("subject_created", "Created subject " . strtoupper($folderName));
            }

            $handlerIds = array_values(array_unique($handlerIds));
            if ($handlerIds) {
                $stmt = $pdo->prepare("
                    INSERT INTO subject_members (subject_id, userId, access_level, added_by)
                    SELECT ?, u.userId, 'manager', ?
                    FROM users u
                    JOIN roles r ON r.role_id = u.role_id
                    WHERE u.userId = ? AND r.role_name = 'handler'
                    ON DUPLICATE KEY UPDATE access_level = VALUES(access_level), added_by = VALUES(added_by)
                ");
                foreach ($handlerIds as $handlerId) {
                    $stmt->execute([$subjectId, $_SESSION["userId"], $handlerId]);
                }
            }

            $pdo->commit();
            $success = "Subject created successfully.";
            if (!$isAjaxRequest) {
                header("Location: dashboard.php?page=folders");
                exit;
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Failed to create subject: " . $e->getMessage();
        }
    }
}

$pendingRequests = [];
if (hasPermission("manage_requests")) {
    $pendingRequests = $pdo->query("
        SELECT sr.*, u.fullName AS requesterName
        FROM subject_requests sr
        JOIN users u ON u.userId = sr.requested_by
        WHERE sr.status = 'pending'
        ORDER BY sr.created_at ASC
    ")->fetchAll();
}

$handlers = $pdo->query("
    SELECT u.userId, u.fullName, u.username
    FROM users u
    JOIN roles r ON r.role_id = u.role_id
    WHERE r.role_name = 'handler'
    ORDER BY u.fullName ASC
")->fetchAll();
?>
<nav class="mb-3 flex items-center gap-2 text-sm text-slate-500" aria-label="Breadcrumb">
  <a href="dashboard.php?page=folders" class="font-medium text-slate-600 hover:text-navy">Subjects</a>
  <span>/</span>
  <span class="font-medium text-slate-900">Create New Subject</span>
</nav>

<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
  <div>
    <h1 class="text-2xl font-bold text-slate-800">Create New Subject</h1>
    <p class="text-sm text-slate-500">Add a subject and assign handlers who can manage its projects.</p>
  </div>
  <a href="dashboard.php?page=folders" class="text-sm font-medium text-slate-600 transition-colors hover:text-navy">Back to Subjects</a>
</div>

<div class="max-w-3xl rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
  <?php if ($error): ?><div data-feedback="error" data-feedback-title="Subject not saved" data-feedback-message="<?php echo htmlspecialchars($error); ?>" class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
  <?php if ($success): ?><div data-feedback="success" data-feedback-title="Subject saved" data-feedback-message="<?php echo htmlspecialchars($success); ?>" class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
  <form method="POST" action="get_content.php?tab=create-subject" class="grid grid-cols-1 gap-4 md:grid-cols-2">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION["csrf_token"]; ?>">
    <?php if ($pendingRequests): ?>
    <div class="md:col-span-2">
      <label class="mb-1 block text-sm font-medium text-slate-700">Create From Request</label>
      <select name="subject_request_id" id="subjectRequestSelect" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-cta focus:ring-2 focus:ring-cta">
        <option value="">Manual subject</option>
        <?php foreach ($pendingRequests as $request): ?>
        <option value="<?php echo $request["request_id"]; ?>" data-code="<?php echo htmlspecialchars($request["subject_code"]); ?>" data-name="<?php echo htmlspecialchars($request["subject_name"]); ?>" data-description="<?php echo htmlspecialchars($request["description"] ?? ""); ?>">
          <?php echo htmlspecialchars($request["subject_code"] . " requested by " . $request["requesterName"]); ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div>
      <label class="mb-1 block text-sm font-medium text-slate-700">Subject Code *</label>
      <input type="text" name="folderName" id="subjectCodeInput" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-cta focus:ring-2 focus:ring-cta" placeholder="CC104">
    </div>
    <div>
      <label class="mb-1 block text-sm font-medium text-slate-700">Subject Name</label>
      <input type="text" name="subjectName" id="subjectNameInput" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-cta focus:ring-2 focus:ring-cta" placeholder="IT Professional Elective 1">
    </div>
    <div class="md:col-span-2">
      <label class="mb-1 block text-sm font-medium text-slate-700">Description</label>
      <input type="text" name="description" id="subjectDescriptionInput" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-cta focus:ring-2 focus:ring-cta" placeholder="Optional description">
    </div>
    <div class="md:col-span-2">
      <label class="mb-1 block text-sm font-medium text-slate-700">Handlers With Project Access</label>
      <select name="handlerIds[]" multiple size="4" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-cta focus:ring-2 focus:ring-cta">
        <?php foreach ($handlers as $handler): ?>
        <option value="<?php echo $handler["userId"]; ?>"><?php echo htmlspecialchars($handler["fullName"] . " (" . $handler["username"] . ")"); ?></option>
        <?php endforeach; ?>
      </select>
      <p class="mt-1 text-xs text-slate-500">Selected handlers can add, edit, and unlist projects in this subject.</p>
    </div>
    <div class="flex flex-col-reverse gap-3 md:col-span-2 sm:flex-row sm:justify-end">
      <a href="dashboard.php?page=folders" class="rounded-lg border border-slate-200 px-4 py-2 text-center text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</a>
      <button type="submit" class="rounded-lg bg-cta px-4 py-2 text-sm font-medium text-white hover:bg-blue-500">Create Subject</button>
    </div>
  </form>
</div>

<script>
(function() {
  const select = document.getElementById('subjectRequestSelect');
  if (!select) return;
  const code = document.getElementById('subjectCodeInput');
  const name = document.getElementById('subjectNameInput');
  const description = document.getElementById('subjectDescriptionInput');
  select.addEventListener('change', function() {
    const option = this.selectedOptions[0];
    if (!option || !option.value) return;
    code.value = option.dataset.code || '';
    name.value = option.dataset.name || '';
    description.value = option.dataset.description || '';
  });
})();
</script>
