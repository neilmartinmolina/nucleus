<?php
require_once __DIR__ . "/includes/core.php";

if (!isAuthenticated() || !hasPermission("manage_users")) {
    header("Location: dashboard.php?page=dashboard");
    exit;
}

generateCSRFToken();
$error = null;
$success = null;
$isAjaxRequest = ($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "") === "XMLHttpRequest";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["csrf_token"])) {
    validateCSRF($_POST["csrf_token"]);

    $username = trim($_POST["username"] ?? "");
    $password = trim($_POST["password"] ?? "");
    $fullName = trim($_POST["fullName"] ?? "");
    $role = trim($_POST["role"] ?? "");

    if ($username === "" || $password === "" || $fullName === "" || $role === "") {
        $error = "All fields are required";
    } elseif (!in_array($role, ["superadmin", "admin", "handler", "member", "visitor"], true)) {
        $error = "Invalid role selected";
    } else {
        $stmt = $pdo->prepare("SELECT userId FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $error = "Username already exists";
        } else {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, passwordHash, fullName, role_id) SELECT ?, ?, ?, role_id FROM roles WHERE role_name = ?");
            $stmt->execute([$username, $passwordHash, $fullName, $role]);
            logActivity("user_created", "Created user {$username} ({$role})");
            $success = "User created successfully";
            if (!$isAjaxRequest) {
                header("Location: dashboard.php?page=usermanagement");
                exit;
            }
        }
    }
}
?>
<nav class="mb-3 flex items-center gap-2 text-sm text-slate-500" aria-label="Breadcrumb">
  <a href="dashboard.php?page=usermanagement" class="font-medium text-slate-600 hover:text-navy">Users</a>
  <span>/</span>
  <span class="font-medium text-slate-900">Create New User</span>
</nav>

<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
  <div>
    <h1 class="text-2xl font-bold text-slate-800">Create New User</h1>
    <p class="text-sm text-slate-500">Add a system account and assign its starting role.</p>
  </div>
  <a href="dashboard.php?page=usermanagement" class="text-sm font-medium text-slate-600 transition-colors hover:text-navy">Back to Users</a>
</div>

<div class="max-w-xl rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
  <?php if ($error): ?><div data-feedback="error" data-feedback-title="User not saved" data-feedback-message="<?php echo htmlspecialchars($error); ?>" class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
  <?php if ($success): ?><div data-feedback="success" data-feedback-title="User saved" data-feedback-message="<?php echo htmlspecialchars($success); ?>" class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
  <form method="POST" action="get_content.php?tab=create-user" class="space-y-4">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION["csrf_token"]; ?>">
    <div>
      <label class="mb-1 block text-sm font-medium text-slate-700">Username *</label>
      <input type="text" name="username" required class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-cta focus:ring-2 focus:ring-cta" placeholder="johndoe">
    </div>
    <div>
      <label class="mb-1 block text-sm font-medium text-slate-700">Password *</label>
      <input type="password" name="password" required class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-cta focus:ring-2 focus:ring-cta">
    </div>
    <div>
      <label class="mb-1 block text-sm font-medium text-slate-700">Full Name *</label>
      <input type="text" name="fullName" required class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-cta focus:ring-2 focus:ring-cta" placeholder="John Doe">
    </div>
    <div>
      <label class="mb-1 block text-sm font-medium text-slate-700">Role *</label>
      <select name="role" required class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-cta focus:ring-2 focus:ring-cta">
        <option value="member">Member</option>
        <option value="handler">Handler</option>
        <option value="admin">Admin</option>
        <option value="superadmin">Superadmin</option>
        <option value="visitor">Visitor</option>
      </select>
    </div>
    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
      <a href="dashboard.php?page=usermanagement" class="rounded-lg border border-slate-200 px-4 py-2 text-center text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</a>
      <button type="submit" class="rounded-lg bg-cta px-4 py-2 text-sm font-medium text-white hover:bg-blue-500">Create User</button>
    </div>
  </form>
</div>
