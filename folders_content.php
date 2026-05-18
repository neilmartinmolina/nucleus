<?php
require_once __DIR__ . "/includes/core.php";

if (!isAuthenticated()) {
    header("Location: login.php");
    exit;
}

generateCSRFToken();
$roleManager = new RoleManager($pdo);
$isAdmin = ($_SESSION["role"] ?? "") === "admin";
$isAjaxRequest = ($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "") === "XMLHttpRequest";
$success = null;

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["csrf_token"])) {
    validateCSRF($_POST["csrf_token"]);
    
$formAction = $_POST["form_action"] ?? "create_subject";
    $folderName = trim($_POST["folderName"] ?? "");
    $subjectName = trim($_POST["subjectName"] ?? $folderName);
    $description = trim($_POST["description"] ?? "");
    $requestId = isset($_POST["subject_request_id"]) && is_numeric($_POST["subject_request_id"]) ? (int) $_POST["subject_request_id"] : null;
    $handlerIds = array_values(array_filter(array_map("intval", $_POST["handlerIds"] ?? [])));
    
    if ($formAction === "update_subject" && hasPermission("manage_groups")) {
        $subjectId = isset($_POST["subject_id"]) && is_numeric($_POST["subject_id"]) ? (int) $_POST["subject_id"] : null;
        if (!$subjectId || $folderName === "") {
            $error = "Subject code is required";
        } else {
            try {
                $stmt = $pdo->prepare("SELECT * FROM subjects WHERE subject_id = ?");
                $stmt->execute([$subjectId]);
                $existing = $stmt->fetch();
                if (!$existing) {
                    $error = "Subject not found";
                } else {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("UPDATE subjects SET subject_code = ?, subject_name = ?, description = ? WHERE subject_id = ?");
                    $stmt->execute([strtoupper($folderName), $subjectName ?: strtoupper($folderName), $description, $subjectId]);

                    $stmt = $pdo->prepare("DELETE FROM subject_members WHERE subject_id = ?");
                    $stmt->execute([$subjectId]);
                    if ($handlerIds) {
                        $stmt = $pdo->prepare("
                            INSERT INTO subject_members (subject_id, userId, access_level, added_by)
                            SELECT ?, u.userId, 'manager', ?
                            FROM users u
                            JOIN roles r ON r.role_id = u.role_id
                            WHERE u.userId = ? AND r.role_name = 'handler'
                        ");
                        foreach ($handlerIds as $handlerId) {
                            $stmt->execute([$subjectId, $_SESSION["userId"], $handlerId]);
                        }
                    }

                    logActivity("subject_updated", "Subject {$existing["subject_code"]} was edited to " . strtoupper($folderName));
                    $pdo->commit();
                    $success = "Subject updated successfully.";
                    if (!$isAjaxRequest) {
                        header("Location: dashboard.php?page=folders");
                        exit;
                    }
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = "Failed to update subject: " . $e->getMessage();
            }
        }
    } elseif (empty($folderName) && !$requestId) {
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

$subjectRows = $roleManager->getUserSubjects($_SESSION["userId"]);
$subjectIds = array_column($subjectRows, "subject_id");
$folders = [];
if (!empty($subjectIds)) {
    $placeholders = implode(",", array_fill(0, count($subjectIds), "?"));
    $stmt = $pdo->prepare("
    SELECT s.*, u.fullName as createdByName, COUNT(p.project_id) as projectCount,
           MAX(sm.userId IS NOT NULL) AS hasSubjectAccess
    FROM subjects s
    LEFT JOIN users u ON s.created_by = u.userId
    LEFT JOIN projects p ON s.subject_id = p.subject_id
    LEFT JOIN subject_members sm ON sm.subject_id = s.subject_id AND sm.userId = ?
    WHERE s.subject_id IN ({$placeholders})
    GROUP BY s.subject_id
    ORDER BY s.created_at DESC
    ");
    $stmt->execute(array_merge([$_SESSION["userId"]], $subjectIds));
    $folders = $stmt->fetchAll();
}

$handlers = $pdo->query("
    SELECT u.userId, u.fullName, u.username
    FROM users u
    JOIN roles r ON r.role_id = u.role_id
    WHERE r.role_name = 'handler'
    ORDER BY u.fullName ASC
")->fetchAll();

$subjectHandlerMap = [];
if (!empty($subjectIds)) {
    $placeholders = implode(",", array_fill(0, count($subjectIds), "?"));
    $stmt = $pdo->prepare("
        SELECT subject_id, userId
        FROM subject_members
        WHERE subject_id IN ({$placeholders})
    ");
    $stmt->execute($subjectIds);
    foreach ($stmt->fetchAll() as $member) {
        $subjectHandlerMap[(int) $member["subject_id"]][] = (int) $member["userId"];
    }
}

?>
<div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
  <div>
    <h1 class="text-2xl font-bold text-slate-800">Subjects</h1>
    <p class="text-sm text-slate-500">Organize projects by academic subject</p>
  </div>
  <?php if (hasPermission("manage_groups")): ?>
  <a href="dashboard.php?page=create-subject" class="rounded-lg bg-[#0050D8] px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-[#003FA8] flex items-center gap-2">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
    Add Subject
  </a>
  <?php endif; ?>
</div>

<div class="mb-6 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
  <label for="subjectSearch" class="mb-2 block text-sm font-medium text-slate-700">Search Subjects</label>
  <input id="subjectSearch" type="search" data-subject-search placeholder="Search by code, name, description, creator, or project count" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none transition focus:border-cta focus:ring-2 focus:ring-cta/20">
</div>

<div id="subjectGrid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
  <?php foreach($folders as $f): ?>
  <div class="bg-white rounded-xl shadow-sm border border-slate-200 hover:shadow-md transition-all duration-200" data-subject-card data-search-text="<?php echo htmlspecialchars(strtolower($f["subject_code"] . " " . $f["subject_name"] . " " . ($f["description"] ?? "") . " " . ($f["createdByName"] ?? "") . " " . $f["projectCount"])); ?>">
    <div class="p-6">
      <div class="flex items-start justify-between mb-3">
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-cta to-navy flex items-center justify-center text-white font-bold text-xl">
          <?php echo strtoupper(substr($f['subject_code'], 0, 1)); ?>
        </div>
        <?php if (hasPermission("manage_groups")): ?>
        <form method="POST" action="delete-folder.php" data-confirm="Projects will be unlinked but not deleted." data-confirm-title="Delete this subject?" data-confirm-button="Delete" data-return-page="folders">
          <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
          <input type="hidden" name="id" value="<?php echo $f['subject_id']; ?>">
          <button type="submit" class="p-1.5 hover:bg-red-50 rounded-lg transition-colors text-red-500">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
          </button>
        </form>
        <?php endif; ?>
      </div>
      <h3 class="text-lg font-semibold text-slate-800 mb-1"><?php echo htmlspecialchars($f['subject_code']); ?></h3>
      <p class="text-sm font-medium text-slate-600 mb-1"><?php echo htmlspecialchars($f['subject_name']); ?></p>
      <p class="text-sm text-slate-500 mb-4"><?php echo nl2br(htmlspecialchars($f['description'] ?: "No description")); ?></p>
      <div class="flex items-center justify-between pt-4 border-t border-slate-100">
        <div class="text-sm">
          <span class="font-medium text-slate-800"><?php echo $f['projectCount']; ?></span>
          <span class="text-slate-500"> projects</span>
        </div>
        <a href="dashboard.php?page=view-folder&folderId=<?php echo $f['subject_id']; ?>" class="text-cta text-sm font-medium hover:text-cta-600 transition-colors">View Projects →</a>
      </div>
      <div class="mt-3 pt-3 border-t border-slate-100">
        <div class="flex items-center gap-2 text-xs text-slate-400">
          <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
          Created by <?php echo htmlspecialchars(($_SESSION["role"] ?? "visitor") === "admin" ? $f['createdByName'] : "Nucleus"); ?>
        </div>
      </div>
      <?php if (hasPermission("manage_groups")): ?>
      <details class="mt-3 pt-3 border-t border-slate-100">
        <summary class="cursor-pointer text-sm font-medium text-cta">Edit Subject</summary>
        <form method="POST" action="get_content.php?tab=folders" class="mt-3 space-y-3">
          <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
          <input type="hidden" name="form_action" value="update_subject">
          <input type="hidden" name="subject_id" value="<?php echo $f['subject_id']; ?>">
          <input type="text" name="folderName" value="<?php echo htmlspecialchars($f['subject_code']); ?>" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
          <input type="text" name="subjectName" value="<?php echo htmlspecialchars($f['subject_name']); ?>" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
          <input type="text" name="description" value="<?php echo htmlspecialchars($f['description'] ?? ""); ?>" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
          <select name="handlerIds[]" multiple size="4" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
            <?php $selectedHandlers = $subjectHandlerMap[(int) $f["subject_id"]] ?? []; ?>
            <?php foreach ($handlers as $handler): ?>
            <option value="<?php echo $handler["userId"]; ?>" <?php echo in_array((int) $handler["userId"], $selectedHandlers, true) ? "selected" : ""; ?>><?php echo htmlspecialchars($handler["fullName"] . " (" . $handler["username"] . ")"); ?></option>
            <?php endforeach; ?>
          </select>
          <p class="text-xs text-slate-500">Selected handlers can add, edit, and unlist projects in this subject.</p>
          <button type="submit" class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white">Save Changes</button>
        </form>
      </details>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<div id="subjectEmptyState" class="hidden rounded-xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">No subjects match your search.</div>

<script>
(function() {
  const input = document.getElementById('subjectSearch');
  const cards = Array.from(document.querySelectorAll('[data-subject-card]'));
  const empty = document.getElementById('subjectEmptyState');
  if (!input || !cards.length) return;

  input.addEventListener('input', function() {
    const query = this.value.trim().toLowerCase();
    let visible = 0;

    cards.forEach(card => {
      const matches = !query || (card.dataset.searchText || '').includes(query);
      card.classList.toggle('hidden', !matches);
      if (matches) visible++;
    });

    if (empty) empty.classList.toggle('hidden', visible !== 0);
  });
})();
</script>
