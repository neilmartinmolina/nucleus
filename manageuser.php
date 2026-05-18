<?php
require_once __DIR__ . "/includes/core.php";

if (!isAuthenticated() || !hasPermission("manage_users")) {
    header("Location: dashboard.php?page=dashboard");
    exit;
}

generateCSRFToken();
$error = null;
$success = null;
$editUserId = isset($_GET["edit"]) && is_numeric($_GET["edit"]) ? (int) $_GET["edit"] : null;
$isAjaxRequest = ($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "") === "XMLHttpRequest";

if (!$editUserId) {
    echo "<div class=\"p-8 text-center text-slate-600\">Select a user to manage.</div>";
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["csrf_token"])) {
    validateCSRF($_POST["csrf_token"]);

    $targetUserId = isset($_POST["userId"]) && is_numeric($_POST["userId"]) ? (int) $_POST["userId"] : null;
    $fullName = trim($_POST["fullName"] ?? "");
    $role = trim($_POST["role"] ?? "");
    $password = trim($_POST["password"] ?? "");

    if (!$targetUserId || $fullName === "" || $role === "") {
        $error = "Full name and role are required";
    } elseif (!in_array($role, ["superadmin", "admin", "handler", "member", "visitor"], true)) {
        $error = "Invalid role selected";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT username FROM users WHERE userId = ?");
            $stmt->execute([$targetUserId]);
            $targetUsername = $stmt->fetchColumn();

            if (!$targetUsername) {
                $error = "User not found";
            } else {
                if ($password !== "") {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET fullName = ?, passwordHash = ?, role_id = (SELECT role_id FROM roles WHERE role_name = ?)
                        WHERE userId = ?
                    ");
                    $stmt->execute([$fullName, $passwordHash, $role, $targetUserId]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET fullName = ?, role_id = (SELECT role_id FROM roles WHERE role_name = ?)
                        WHERE userId = ?
                    ");
                    $stmt->execute([$fullName, $role, $targetUserId]);
                }

                logActivity("user_updated", "Updated user {$targetUsername} ({$role})");
                $success = "User updated successfully";
                $editUserId = $targetUserId;
                if (!$isAjaxRequest) {
                    header("Location: dashboard.php?page=manage-user&edit=" . $targetUserId . "&saved=1");
                    exit;
                }
            }
        } catch (Exception $e) {
            $error = "Failed to update user: " . $e->getMessage();
        }
    }
}

$stmt = $pdo->prepare("
    SELECT u.*, r.role_name AS role
    FROM users u
    JOIN roles r ON r.role_id = u.role_id
    WHERE u.userId = ?
");
$stmt->execute([$editUserId]);
$editUser = $stmt->fetch();

if (!$editUser) {
    echo "<div class=\"p-8 text-center text-slate-600\">User not found.</div>";
    exit;
}
?>
<nav class="mb-3 flex items-center gap-2 text-sm text-slate-500" aria-label="Breadcrumb">
  <a href="dashboard.php?page=usermanagement" class="font-medium text-slate-600 hover:text-navy">Users</a>
  <span>/</span>
  <span class="font-medium text-slate-900">Manage User</span>
</nav>

<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
  <div>
    <h1 class="text-2xl font-bold text-slate-800">Manage User</h1>
    <p class="text-sm text-slate-500"><?php echo htmlspecialchars($editUser["username"]); ?></p>
  </div>
  <a href="dashboard.php?page=usermanagement" class="text-sm font-medium text-slate-600 transition-colors hover:text-navy">Back to Users</a>
</div>

<div class="max-w-xl rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
  <?php if (isset($_GET["saved"])): ?><div data-feedback="success" data-feedback-title="User saved" data-feedback-message="User updated successfully" class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">User updated successfully</div><?php endif; ?>
  <?php if ($error): ?><div data-feedback="error" data-feedback-title="User not saved" data-feedback-message="<?php echo htmlspecialchars($error); ?>" class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
  <?php if ($success): ?><div data-feedback="success" data-feedback-title="User saved" data-feedback-message="<?php echo htmlspecialchars($success); ?>" class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
  <form method="POST" action="get_content.php?tab=manage-user&edit=<?php echo $editUser["userId"]; ?>" class="space-y-4">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION["csrf_token"]; ?>">
    <input type="hidden" name="userId" value="<?php echo $editUser["userId"]; ?>">
    <div>
      <label class="mb-1 block text-sm font-medium text-slate-700">Full Name *</label>
      <input type="text" name="fullName" required value="<?php echo htmlspecialchars($editUser["fullName"]); ?>" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-cta focus:ring-2 focus:ring-cta">
    </div>
    <div>
      <label class="mb-1 block text-sm font-medium text-slate-700">Role *</label>
      <select name="role" required class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-cta focus:ring-2 focus:ring-cta">
        <?php foreach (["member" => "Member", "handler" => "Handler", "admin" => "Admin", "superadmin" => "Superadmin", "visitor" => "Visitor"] as $value => $label): ?>
        <option value="<?php echo $value; ?>" <?php echo $editUser["role"] === $value ? "selected" : ""; ?>><?php echo $label; ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="mb-1 block text-sm font-medium text-slate-700">New Password</label>
      <input type="password" name="password" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-cta focus:ring-2 focus:ring-cta" placeholder="Leave blank to keep current password">
    </div>
    <div class="flex justify-end gap-3">
      <a href="dashboard.php?page=usermanagement" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</a>
      <button type="submit" class="rounded-lg bg-cta px-4 py-2 text-sm font-medium text-white hover:bg-blue-500">Save User</button>
    </div>
  </form>
</div>
