<?php
require_once __DIR__ . "/config.php";

if (!isAuthenticated()) {
    header("Location: login.php");
    exit;
}

generateCSRFToken();
$roleManager = new RoleManager($pdo);
$userId = (int) $_SESSION["userId"];
$role = $roleManager->getUserRole($userId) ?: ($_SESSION["role"] ?? "member");
$_SESSION["role"] = $role;
$allowedTabs = ["home", "subjects", "joined", "archived", "websites", "add-project", "settings"];
$activeTab = in_array($_GET["tab"] ?? "home", $allowedTabs, true) ? ($_GET["tab"] ?? "home") : "home";
$error = null;
$success = null;

$stmt = $pdo->prepare("SELECT username, fullName, email, phone, department, bio FROM users WHERE userId = ?");
$stmt->execute([$userId]);
$currentUser = $stmt->fetch() ?: [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    validateCSRF($_POST["csrf_token"] ?? "");
    $action = $_POST["subject_action"] ?? $_POST["account_action"] ?? "";

    try {
        if ($action === "request_join") {
            if (!hasPermission("request_subject_join")) {
                throw new Exception("You do not have permission to request subject access.");
            }

            $subjectId = isset($_POST["subject_id"]) && is_numeric($_POST["subject_id"]) ? (int) $_POST["subject_id"] : 0;
            $message = trim((string) ($_POST["message"] ?? ""));
            if ($subjectId < 1) {
                throw new Exception("Please choose a valid subject.");
            }
            if ($roleManager->canAccessSubject($userId, $subjectId)) {
                throw new Exception("You are already part of that subject.");
            }

            $stmt = $pdo->prepare("SELECT subject_code FROM subjects WHERE subject_id = ? AND archived_at IS NULL");
            $stmt->execute([$subjectId]);
            $subjectCode = $stmt->fetchColumn();
            if (!$subjectCode) {
                throw new Exception("Subject was not found or is archived.");
            }

            $stmt = $pdo->prepare("
                SELECT join_request_id
                FROM subject_join_requests
                WHERE subject_id = ? AND requested_by = ? AND status = 'pending'
                LIMIT 1
            ");
            $stmt->execute([$subjectId, $userId]);
            if ($stmt->fetch()) {
                throw new Exception("You already have a pending request for this subject.");
            }

            $stmt = $pdo->prepare("
                INSERT INTO subject_join_requests (subject_id, requested_by, message)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$subjectId, $userId, $message]);
            logActivity("subject_join_requested", "Requested to join {$subjectCode}");
            $success = "Join request sent for {$subjectCode}.";
            $activeTab = "home";
        } elseif ($action === "create_project_request") {
            if (!hasPermission("request_project")) {
                throw new Exception("You do not have permission to request project placement.");
            }

            $subjectId = isset($_POST["subject_id"]) && is_numeric($_POST["subject_id"]) ? (int) $_POST["subject_id"] : 0;
            $projectName = trim((string) ($_POST["project_name"] ?? ""));
            $publicUrl = trim((string) ($_POST["public_url"] ?? ""));
            $repoUrl = trim((string) ($_POST["github_repo_url"] ?? ""));
            $repoName = extractRepoNameFromGitUrl($repoUrl);
            $version = trim((string) ($_POST["version"] ?? "1.0.0"));
            $message = trim((string) ($_POST["message"] ?? ""));

            if ($subjectId < 1 || !$roleManager->canAccessSubject($userId, $subjectId)) {
                throw new Exception("Please choose a subject you have joined.");
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

            $stmt = $pdo->prepare("
                SELECT request_id
                FROM project_requests
                WHERE requested_by = ? AND subject_id = ? AND github_repo_url = ? AND status = 'pending'
                LIMIT 1
            ");
            $stmt->execute([$userId, $subjectId, $repoUrl]);
            if ($stmt->fetch()) {
                throw new Exception("You already have a pending request for this website in that subject.");
            }

            $stmt = $pdo->prepare("
                INSERT INTO project_requests
                    (requested_by, subject_id, project_name, public_url, github_repo_url, github_repo_name, requested_version, message)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $subjectId, $projectName, $publicUrl, $repoUrl, $repoName, $version, $message]);
            logActivity("project_requested", "Requested {$projectName} for subject {$subjectId}");
            $success = "Project request submitted.";
            $activeTab = "add-project";
        } elseif ($action === "update_account") {
            $fullName = trim((string) ($_POST["fullName"] ?? ""));
            $email = trim((string) ($_POST["email"] ?? ""));
            $phone = trim((string) ($_POST["phone"] ?? ""));
            $department = trim((string) ($_POST["department"] ?? ""));
            $bio = trim((string) ($_POST["bio"] ?? ""));

            if ($fullName === "" || strlen($fullName) > 255) {
                throw new Exception("Full name is required and must be 255 characters or less.");
            }
            if ($email !== "" && !Security::validateEmail($email)) {
                throw new Exception("Please enter a valid email address.");
            }
            if (strlen($phone) > 50 || strlen($department) > 255 || strlen($bio) > 1000) {
                throw new Exception("One of the profile fields is too long.");
            }

            if ($email !== "") {
                $stmt = $pdo->prepare("SELECT userId FROM users WHERE email = ? AND userId <> ? LIMIT 1");
                $stmt->execute([$email, $userId]);
                if ($stmt->fetch()) {
                    throw new Exception("That email address is already used by another account.");
                }
            }

            $stmt = $pdo->prepare("
                UPDATE users
                SET fullName = ?, email = ?, phone = ?, department = ?, bio = ?
                WHERE userId = ?
            ");
            $stmt->execute([$fullName, $email !== "" ? $email : null, $phone ?: null, $department ?: null, $bio ?: null, $userId]);
            $_SESSION["fullName"] = $fullName;
            $currentUser = [
                "username" => $currentUser["username"] ?? "",
                "fullName" => $fullName,
                "email" => $email,
                "phone" => $phone,
                "department" => $department,
                "bio" => $bio,
            ];
            logActivity("account_updated", "Updated account profile");
            $success = "Account settings saved.";
            $activeTab = "settings";
        } else {
            throw new Exception("Unknown action.");
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$subjectSelect = "
    SELECT s.*,
           (SELECT COUNT(*) FROM projects p WHERE p.subject_id = s.subject_id) AS projectCount
    FROM subjects s
";

if ($roleManager->isAdmin($userId)) {
    $activeSubjects = $pdo->query($subjectSelect . " WHERE s.archived_at IS NULL ORDER BY s.subject_code ASC")->fetchAll();
    $archivedSubjects = $pdo->query($subjectSelect . " WHERE s.archived_at IS NOT NULL ORDER BY s.subject_code ASC")->fetchAll();
} else {
    $stmt = $pdo->prepare($subjectSelect . "
        WHERE s.archived_at IS NULL
          AND EXISTS (
              SELECT 1 FROM subject_members sm
              WHERE sm.subject_id = s.subject_id AND sm.userId = ?
          )
        ORDER BY s.subject_code ASC
    ");
    $stmt->execute([$userId]);
    $activeSubjects = $stmt->fetchAll();

    $stmt = $pdo->prepare($subjectSelect . "
        WHERE s.archived_at IS NOT NULL
          AND EXISTS (
              SELECT 1 FROM subject_members sm
              WHERE sm.subject_id = s.subject_id AND sm.userId = ?
          )
        ORDER BY s.subject_code ASC
    ");
    $stmt->execute([$userId]);
    $archivedSubjects = $stmt->fetchAll();
}

$stmt = $pdo->prepare("
    SELECT s.*,
           (SELECT COUNT(*) FROM projects p WHERE p.subject_id = s.subject_id) AS projectCount,
           sjr.status AS joinStatus,
           sjr.created_at AS joinRequestedAt
    FROM subjects s
    LEFT JOIN subject_join_requests sjr
        ON sjr.subject_id = s.subject_id
       AND sjr.requested_by = ?
       AND sjr.status = 'pending'
    WHERE s.archived_at IS NULL
      AND NOT EXISTS (
          SELECT 1 FROM subject_members sm
          WHERE sm.subject_id = s.subject_id AND sm.userId = ?
      )
    ORDER BY s.subject_code ASC
");
$stmt->execute([$userId, $userId]);
$availableSubjects = $roleManager->isAdmin($userId) ? [] : $stmt->fetchAll();

$websiteRows = $pdo->query("
    SELECT p.project_id, p.project_name, p.public_url, p.github_repo_name, p.updated_at, p.saved_at,
           s.subject_code, s.subject_name,
           ps.status, ps.checked_at, ps.status_note
    FROM projects p
    LEFT JOIN subjects s ON s.subject_id = p.subject_id
    LEFT JOIN project_status ps ON ps.project_id = p.project_id
    ORDER BY p.project_name ASC
    LIMIT 500
")->fetchAll();

function homeTabClass(string $tab, string $activeTab): string {
    return $tab === $activeTab
        ? "home-nav-item is-active"
        : "home-nav-item";
}

function homeIcon(string $icon): string {
    $icons = [
        "home" => '<svg class="home-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 11l9-8 9 8M5 10v10h14V10M9 20v-6h6v6"></path></svg>',
        "subjects" => '<svg class="home-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7a2 2 0 012-2h5l2 2h7a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"></path></svg>',
        "websites" => '<svg class="home-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9"></path></svg>',
        "add-project" => '<svg class="home-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>',
        "settings" => '<svg class="home-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.607 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>',
    ];
    return $icons[$icon] ?? "";
}

function renderSubjectCard(array $subject, bool $archived = false): void {
    ?>
    <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
      <div class="mb-4 flex items-start justify-between gap-3">
        <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-[#0050D8] text-lg font-bold text-white"><?php echo htmlspecialchars(strtoupper(substr($subject["subject_code"], 0, 1))); ?></div>
        <?php if ($archived): ?><span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">Archived</span><?php endif; ?>
      </div>
      <h3 class="text-lg font-bold text-slate-900"><?php echo htmlspecialchars($subject["subject_code"]); ?></h3>
      <p class="mt-1 text-sm font-medium text-slate-600"><?php echo htmlspecialchars($subject["subject_name"]); ?></p>
      <p class="mt-3 min-h-10 text-sm leading-6 text-slate-500"><?php echo htmlspecialchars($subject["description"] ?: "No description"); ?></p>
      <div class="mt-5 flex items-center justify-between border-t border-slate-100 pt-4">
        <span class="text-sm text-slate-500"><strong class="text-slate-800"><?php echo (int) $subject["projectCount"]; ?></strong> projects</span>
        <?php if (!$archived): ?>
        <a href="dashboard.php?page=view-folder&folderId=<?php echo (int) $subject["subject_id"]; ?>" class="text-sm font-semibold text-[#0050D8] hover:text-[#003FA8]">Open</a>
        <?php endif; ?>
      </div>
    </article>
    <?php
}

function homePageTitle(string $tab): string {
    return [
        "home" => "Home",
        "subjects" => "Subjects",
        "joined" => "Joined Subjects",
        "archived" => "Archived Subjects",
        "websites" => "Websites",
        "add-project" => "Add Project",
        "settings" => "Account Settings",
    ][$tab] ?? "Home";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Nucleus | Home</title>
  <script src="tailwind.config.js"></script>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    .home-nav-item {
      position: relative;
      display: flex;
      align-items: center;
      gap: 0.75rem;
      border-radius: 0.5rem;
      padding: 0.75rem 1rem;
      color: #475569;
      font-size: 0.875rem;
      font-weight: 600;
      transition: background-color 150ms ease, color 150ms ease;
    }
    .home-nav-item:hover {
      background: #f8fafc;
      color: #043873;
    }
    .home-nav-item.is-active {
      background: rgba(4, 56, 115, 0.1);
      color: #043873;
    }
    .home-nav-item.is-active::before {
      content: "";
      position: absolute;
      inset: 0 auto 0 0;
      width: 4px;
      border-radius: 0 4px 4px 0;
      background: #043873;
    }
    .home-nav-icon {
      width: 1.15rem;
      height: 1.15rem;
      flex: 0 0 auto;
    }
    .home-tab-panel[hidden] {
      display: none;
    }
    .website-status-initializing { background:#e0f2fe; color:#075985; }
    .website-status-building { background:#fef3c7; color:#92400e; }
    .website-status-deployed { background:#d1fae5; color:#065f46; }
    .website-status-warning { background:#ffedd5; color:#9a3412; }
    .website-status-error { background:#fee2e2; color:#991b1b; }
    .home-sidebar {
      transition: transform 180ms ease;
    }
    .home-mobile-backdrop {
      display: none;
    }
    @media (max-width: 767px) {
      .home-sidebar {
        position: fixed;
        inset: 0 auto 0 0;
        z-index: 50;
        width: min(18rem, 86vw);
        transform: translateX(-100%);
        box-shadow: 0 20px 45px rgba(15, 23, 42, 0.25);
      }
      body.home-sidebar-open .home-sidebar {
        transform: translateX(0);
      }
      body.home-sidebar-open .home-mobile-backdrop {
        display: block;
        position: fixed;
        inset: 0;
        z-index: 40;
        background: rgba(15, 23, 42, 0.42);
      }
    }
  </style>
</head>
<body class="min-h-screen bg-slate-50 text-slate-800">
  <div class="home-mobile-backdrop" data-home-sidebar-close></div>
  <div class="flex min-h-screen">
    <aside id="homeSidebar" class="home-sidebar w-72 shrink-0 border-r border-slate-200 bg-white flex flex-col">
      <div class="flex items-center justify-between border-b border-slate-200 px-6 py-5">
        <div>
        <a href="home.php" class="text-xl font-bold tracking-tight text-[#0050D8]">NUCLEUS</a>
        <p class="mt-1 text-sm text-slate-500"><?php echo htmlspecialchars($_SESSION["fullName"] ?? "User"); ?></p>
        </div>
        <button type="button" data-home-sidebar-close class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 md:hidden" aria-label="Close navigation">
          <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
        </button>
      </div>
      <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-5" aria-label="Account navigation">
        <a href="home.php?tab=home" class="<?php echo homeTabClass("home", $activeTab); ?>" data-home-tab-link data-tab="home" <?php echo $activeTab === "home" ? 'aria-current="page"' : ""; ?>>
          <?php echo homeIcon("home"); ?>
          <span>Home</span>
        </a>
        <a href="home.php?tab=subjects" class="<?php echo homeTabClass("subjects", $activeTab); ?>" data-home-tab-link data-tab="subjects" <?php echo $activeTab === "subjects" ? 'aria-current="page"' : ""; ?>>
          <?php echo homeIcon("subjects"); ?>
          <span>Subjects</span>
        </a>
        <a href="home.php?tab=websites" class="<?php echo homeTabClass("websites", $activeTab); ?>" data-home-tab-link data-tab="websites" <?php echo $activeTab === "websites" ? 'aria-current="page"' : ""; ?>>
          <?php echo homeIcon("websites"); ?>
          <span>Websites</span>
        </a>
        <a href="home.php?tab=add-project" class="<?php echo homeTabClass("add-project", $activeTab); ?>" data-home-tab-link data-tab="add-project" <?php echo $activeTab === "add-project" ? 'aria-current="page"' : ""; ?>>
          <?php echo homeIcon("add-project"); ?>
          <span>Add Project</span>
        </a>
        <details class="rounded-lg" <?php echo in_array($activeTab, ["joined", "archived"], true) ? "open" : ""; ?>>
          <summary class="<?php echo in_array($activeTab, ["joined", "archived"], true) ? "home-nav-item is-active cursor-pointer" : "home-nav-item cursor-pointer"; ?>" data-joined-parent>
            <?php echo homeIcon("subjects"); ?>
            <span>Joined</span>
          </summary>
          <div class="ml-4 mt-1 space-y-1 border-l border-slate-200 pl-3">
            <a href="home.php?tab=joined" class="<?php echo homeTabClass("joined", $activeTab); ?>" data-home-tab-link data-tab="joined" <?php echo $activeTab === "joined" ? 'aria-current="page"' : ""; ?>>Joined subjects</a>
            <a href="home.php?tab=archived" class="<?php echo homeTabClass("archived", $activeTab); ?>" data-home-tab-link data-tab="archived" <?php echo $activeTab === "archived" ? 'aria-current="page"' : ""; ?>>Archived subjects</a>
          </div>
        </details>
        <a href="home.php?tab=settings" class="<?php echo homeTabClass("settings", $activeTab); ?>" data-home-tab-link data-tab="settings" <?php echo $activeTab === "settings" ? 'aria-current="page"' : ""; ?>>
          <?php echo homeIcon("settings"); ?>
          <span>Settings</span>
        </a>
      </nav>
      <div class="border-t border-slate-200 p-4">
        <?php if (in_array($role, ["superadmin", "admin", "handler"], true)): ?>
        <a href="dashboard.php?page=dashboard" class="mb-2 block rounded-lg border border-slate-200 px-3 py-2 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50">Dev Dashboard</a>
        <?php endif; ?>
        <a href="logout.php" class="block rounded-lg bg-slate-900 px-3 py-2 text-center text-sm font-semibold text-white hover:bg-slate-700">Logout</a>
      </div>
    </aside>

    <main class="min-w-0 flex-1">
      <header class="sticky top-0 z-30 border-b border-slate-200 bg-white px-4 py-4 sm:px-6">
        <div class="flex items-center justify-between gap-3">
          <div class="flex min-w-0 items-center gap-3">
            <button type="button" data-home-sidebar-open class="rounded-lg border border-slate-200 p-2 text-slate-600 hover:bg-slate-50 md:hidden" aria-label="Open navigation" aria-controls="homeSidebar">
              <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7h16M4 12h16M4 17h16"></path></svg>
            </button>
            <div>
              <p class="text-xs font-semibold uppercase text-[#0050D8]"><?php echo htmlspecialchars(ucfirst($role)); ?> Portal</p>
              <h1 id="homePageTitle" class="truncate text-lg font-bold text-slate-900 sm:text-xl"><?php echo htmlspecialchars(homePageTitle($activeTab)); ?></h1>
            </div>
          </div>
          <div class="flex items-center gap-3">
            <?php if (in_array($role, ["superadmin", "admin", "handler"], true)): ?>
            <a href="dashboard.php?page=dashboard" class="hidden rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 sm:block">Dev Dashboard</a>
            <?php endif; ?>
            <a href="logout.php" class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-700">Logout</a>
          </div>
        </div>
      </header>

      <div class="mx-auto max-w-7xl px-5 py-8">
        <?php if ($error): ?><div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

        <section class="home-tab-panel" data-home-tab-panel="home" <?php echo $activeTab === "home" ? "" : "hidden"; ?>>
          <div class="mb-6">
            <p class="text-sm font-semibold uppercase text-[#0050D8]"><?php echo htmlspecialchars(ucfirst($role)); ?> Home</p>
            <h1 class="mt-2 text-3xl font-bold text-slate-900">Subject access</h1>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500">Open subjects you are part of or request access to subjects you need to join.</p>
          </div>

          <div class="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
              <p class="text-sm font-semibold text-slate-500">Joined</p>
              <strong class="mt-2 block text-3xl text-slate-900"><?php echo count($activeSubjects); ?></strong>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
              <p class="text-sm font-semibold text-slate-500">Archived</p>
              <strong class="mt-2 block text-3xl text-slate-900"><?php echo count($archivedSubjects); ?></strong>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
              <p class="text-sm font-semibold text-slate-500">Available</p>
              <strong class="mt-2 block text-3xl text-slate-900"><?php echo count($availableSubjects); ?></strong>
            </div>
          </div>

          <?php if (!$roleManager->isAdmin($userId)): ?>
          <section>
            <div class="mb-4 flex items-center justify-between gap-3">
              <h2 class="text-xl font-bold text-slate-800">Available Subjects</h2>
              <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-600"><?php echo count($availableSubjects); ?></span>
            </div>
            <?php if (!$availableSubjects): ?>
              <div class="rounded-xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">No additional subjects are available.</div>
            <?php else: ?>
              <div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
                <?php foreach ($availableSubjects as $subject): ?>
                <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                  <h3 class="text-lg font-bold text-slate-900"><?php echo htmlspecialchars($subject["subject_code"]); ?></h3>
                  <p class="mt-1 text-sm font-medium text-slate-600"><?php echo htmlspecialchars($subject["subject_name"]); ?></p>
                  <p class="mt-3 min-h-10 text-sm leading-6 text-slate-500"><?php echo htmlspecialchars($subject["description"] ?: "No description"); ?></p>
                  <div class="mt-4 text-sm text-slate-500"><strong class="text-slate-800"><?php echo (int) $subject["projectCount"]; ?></strong> projects</div>
                  <?php if (($subject["joinStatus"] ?? "") === "pending"): ?>
                    <div class="mt-5 rounded-lg bg-amber-50 px-3 py-2 text-sm font-medium text-amber-700">Request pending</div>
                  <?php else: ?>
                    <form method="POST" class="mt-5 space-y-3">
                      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">
                      <input type="hidden" name="subject_action" value="request_join">
                      <input type="hidden" name="subject_id" value="<?php echo (int) $subject["subject_id"]; ?>">
                      <input type="text" name="message" placeholder="Optional note to the handler" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-[#0050D8] focus:ring-2 focus:ring-[#0050D8]/20">
                      <button type="submit" class="w-full rounded-lg bg-[#0050D8] px-4 py-2 text-sm font-semibold text-white hover:bg-[#003FA8]">Request to Join</button>
                    </form>
                  <?php endif; ?>
                </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>
          <?php endif; ?>
        </section>

        <section class="home-tab-panel" data-home-tab-panel="subjects" <?php echo $activeTab === "subjects" ? "" : "hidden"; ?>>
          <div class="mb-6">
            <h1 class="text-3xl font-bold text-slate-900">Subjects</h1>
            <p class="mt-2 text-sm text-slate-500">Browse your joined subjects and request access to new ones.</p>
          </div>
          <div class="mb-8">
            <div class="mb-4 flex items-center justify-between gap-3">
              <h2 class="text-xl font-bold text-slate-800">Joined Subjects</h2>
              <span class="rounded-full bg-blue-50 px-3 py-1 text-sm font-semibold text-[#0050D8]"><?php echo count($activeSubjects); ?></span>
            </div>
            <?php if (!$activeSubjects): ?>
              <div class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500">You are not part of any active subjects yet.</div>
            <?php else: ?>
              <div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
                <?php foreach ($activeSubjects as $subject): renderSubjectCard($subject); endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
          <?php if (!$roleManager->isAdmin($userId)): ?>
          <div>
            <div class="mb-4 flex items-center justify-between gap-3">
              <h2 class="text-xl font-bold text-slate-800">Available Subjects</h2>
              <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-600"><?php echo count($availableSubjects); ?></span>
            </div>
            <?php if (!$availableSubjects): ?>
              <div class="rounded-xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">No additional subjects are available.</div>
            <?php else: ?>
              <div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
                <?php foreach ($availableSubjects as $subject): ?>
                <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                  <h3 class="text-lg font-bold text-slate-900"><?php echo htmlspecialchars($subject["subject_code"]); ?></h3>
                  <p class="mt-1 text-sm font-medium text-slate-600"><?php echo htmlspecialchars($subject["subject_name"]); ?></p>
                  <p class="mt-3 min-h-10 text-sm leading-6 text-slate-500"><?php echo htmlspecialchars($subject["description"] ?: "No description"); ?></p>
                  <div class="mt-4 text-sm text-slate-500"><strong class="text-slate-800"><?php echo (int) $subject["projectCount"]; ?></strong> projects</div>
                  <?php if (($subject["joinStatus"] ?? "") === "pending"): ?>
                    <div class="mt-5 rounded-lg bg-amber-50 px-3 py-2 text-sm font-medium text-amber-700">Request pending</div>
                  <?php else: ?>
                    <form method="POST" class="mt-5 space-y-3">
                      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">
                      <input type="hidden" name="subject_action" value="request_join">
                      <input type="hidden" name="subject_id" value="<?php echo (int) $subject["subject_id"]; ?>">
                      <input type="text" name="message" placeholder="Optional note to the handler" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-[#0050D8] focus:ring-2 focus:ring-[#0050D8]/20">
                      <button type="submit" class="w-full rounded-lg bg-[#0050D8] px-4 py-2 text-sm font-semibold text-white hover:bg-[#003FA8]">Request to Join</button>
                    </form>
                  <?php endif; ?>
                </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </section>

        <section class="home-tab-panel" data-home-tab-panel="joined" <?php echo $activeTab === "joined" ? "" : "hidden"; ?>>
          <div class="mb-6">
            <h1 class="text-3xl font-bold text-slate-900">Joined Subjects</h1>
            <p class="mt-2 text-sm text-slate-500">Subjects currently available to your account.</p>
          </div>
          <?php if (!$activeSubjects): ?>
            <div class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500">You are not part of any active subjects yet.</div>
          <?php else: ?>
            <div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
              <?php foreach ($activeSubjects as $subject): renderSubjectCard($subject); endforeach; ?>
            </div>
          <?php endif; ?>
        </section>

        <section class="home-tab-panel" data-home-tab-panel="archived" <?php echo $activeTab === "archived" ? "" : "hidden"; ?>>
          <div class="mb-6">
            <h1 class="text-3xl font-bold text-slate-900">Archived Subjects</h1>
            <p class="mt-2 text-sm text-slate-500">Subject cards kept for historical reference.</p>
          </div>
          <?php if (!$archivedSubjects): ?>
            <div class="rounded-xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">No archived subjects found.</div>
          <?php else: ?>
            <div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
              <?php foreach ($archivedSubjects as $subject): renderSubjectCard($subject, true); endforeach; ?>
            </div>
          <?php endif; ?>
        </section>

        <section class="home-tab-panel" data-home-tab-panel="websites" <?php echo $activeTab === "websites" ? "" : "hidden"; ?>>
          <div class="mb-6">
            <h1 class="text-3xl font-bold text-slate-900">Websites</h1>
            <p class="mt-2 text-sm text-slate-500">Search websites that exist within Nucleus.</p>
          </div>
          <div class="mb-5 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <label for="websiteSearch" class="mb-2 block text-sm font-medium text-slate-700">Search Websites</label>
            <input id="websiteSearch" type="search" data-website-search placeholder="Search by website, URL, repo, subject, or status" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none transition focus:border-[#0050D8] focus:ring-2 focus:ring-[#0050D8]/20">
          </div>
          <?php if (!$websiteRows): ?>
            <div class="rounded-xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">No websites found.</div>
          <?php else: ?>
            <div id="websiteGrid" class="grid grid-cols-1 gap-5 lg:grid-cols-2">
              <?php foreach ($websiteRows as $website): ?>
              <?php
                $status = $website["status"] ?: "initializing";
                $searchText = strtolower(implode(" ", [
                    $website["project_name"] ?? "",
                    $website["public_url"] ?? "",
                    $website["github_repo_name"] ?? "",
                    $website["subject_code"] ?? "",
                    $website["subject_name"] ?? "",
                    $status,
                ]));
              ?>
              <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm" data-website-card data-search-text="<?php echo htmlspecialchars($searchText); ?>">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                  <div class="min-w-0">
                    <h3 class="truncate text-lg font-bold text-slate-900"><?php echo htmlspecialchars($website["project_name"]); ?></h3>
                    <p class="mt-1 text-sm text-slate-500"><?php echo htmlspecialchars(($website["subject_code"] ?? "No subject") . " - " . ($website["subject_name"] ?? "Unassigned")); ?></p>
                    <?php if (!empty($website["public_url"])): ?>
                    <a href="<?php echo htmlspecialchars($website["public_url"]); ?>" target="_blank" rel="noopener noreferrer" class="mt-2 block max-w-xl truncate text-sm font-semibold text-[#0050D8]"><?php echo htmlspecialchars($website["public_url"]); ?></a>
                    <?php endif; ?>
                    <?php if (!empty($website["github_repo_name"])): ?>
                    <p class="mt-1 text-xs text-slate-500">Repo: <?php echo htmlspecialchars($website["github_repo_name"]); ?></p>
                    <?php endif; ?>
                  </div>
                  <span title="<?php echo htmlspecialchars($website["status_note"] ?? ""); ?>" class="shrink-0 rounded-full px-3 py-1 text-sm font-semibold website-status-<?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span>
                </div>
                <div class="mt-4 border-t border-slate-100 pt-3 text-xs text-slate-500">
                  Checked <?php echo htmlspecialchars(formatNucleusDateTime($website["checked_at"])); ?>
                  <span class="mx-2 text-slate-300">|</span>
                  Saved <?php echo htmlspecialchars(formatNucleusDateTime($website["saved_at"] ?? $website["updated_at"])); ?>
                </div>
              </article>
              <?php endforeach; ?>
            </div>
            <div id="websiteEmptyState" class="mt-5 hidden rounded-xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">No websites match your search.</div>
          <?php endif; ?>
        </section>

        <section class="home-tab-panel" data-home-tab-panel="add-project" <?php echo $activeTab === "add-project" ? "" : "hidden"; ?>>
          <div class="mb-6">
            <h1 class="text-3xl font-bold text-slate-900">Add Project</h1>
            <p class="mt-2 text-sm text-slate-500">Submit a website placement request for a subject you have joined.</p>
          </div>
          <?php if (!$activeSubjects): ?>
            <div class="rounded-xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">Join a subject before submitting a project request.</div>
          <?php else: ?>
          <form method="POST" class="max-w-3xl rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">
            <input type="hidden" name="subject_action" value="create_project_request">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
              <div class="md:col-span-2">
                <label class="mb-1 block text-sm font-medium text-slate-700">Subject *</label>
                <select name="subject_id" required class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-[#0050D8] focus:ring-2 focus:ring-[#0050D8]/20">
                  <option value="">Choose subject</option>
                  <?php foreach ($activeSubjects as $subject): ?>
                  <option value="<?php echo (int) $subject["subject_id"]; ?>"><?php echo htmlspecialchars($subject["subject_code"] . " - " . $subject["subject_name"]); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Website Name *</label>
                <input type="text" name="project_name" required class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-[#0050D8] focus:ring-2 focus:ring-[#0050D8]/20">
              </div>
              <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Version *</label>
                <input type="text" name="version" required value="1.0.0" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-[#0050D8] focus:ring-2 focus:ring-[#0050D8]/20">
              </div>
              <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Website URL *</label>
                <input type="url" name="public_url" required placeholder="https://example.com" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-[#0050D8] focus:ring-2 focus:ring-[#0050D8]/20">
              </div>
              <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">GitHub Repo URL (.git) *</label>
                <input type="url" name="github_repo_url" required pattern="https?://.+\.git$" placeholder="https://github.com/owner/repo.git" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-[#0050D8] focus:ring-2 focus:ring-[#0050D8]/20">
              </div>
              <div class="md:col-span-2">
                <label class="mb-1 block text-sm font-medium text-slate-700">Message</label>
                <input type="text" name="message" placeholder="Short note to the subject handler" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-[#0050D8] focus:ring-2 focus:ring-[#0050D8]/20">
              </div>
            </div>
            <div class="mt-5 flex justify-end">
              <button type="submit" class="rounded-lg bg-[#0050D8] px-5 py-2 text-sm font-semibold text-white hover:bg-[#003FA8]">Submit Project Request</button>
            </div>
          </form>
          <?php endif; ?>
        </section>

        <section class="home-tab-panel" data-home-tab-panel="settings" <?php echo $activeTab === "settings" ? "" : "hidden"; ?>>
          <div class="mb-6">
            <h1 class="text-3xl font-bold text-slate-900">Account Settings</h1>
            <p class="mt-2 text-sm text-slate-500">Update your personal account details. Nucleus system settings stay in the admin dashboard.</p>
          </div>
          <form method="POST" class="max-w-2xl rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">
            <input type="hidden" name="account_action" value="update_account">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Username</label>
                <input type="text" value="<?php echo htmlspecialchars($currentUser["username"] ?? ""); ?>" disabled class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500">
              </div>
              <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Role</label>
                <input type="text" value="<?php echo htmlspecialchars(ucfirst($role)); ?>" disabled class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500">
              </div>
              <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Full Name *</label>
                <input type="text" name="fullName" required value="<?php echo htmlspecialchars($currentUser["fullName"] ?? ""); ?>" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-[#0050D8] focus:ring-2 focus:ring-[#0050D8]/20">
              </div>
              <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Email</label>
                <input type="email" name="email" value="<?php echo htmlspecialchars($currentUser["email"] ?? ""); ?>" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-[#0050D8] focus:ring-2 focus:ring-[#0050D8]/20">
              </div>
              <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Phone</label>
                <input type="text" name="phone" value="<?php echo htmlspecialchars($currentUser["phone"] ?? ""); ?>" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-[#0050D8] focus:ring-2 focus:ring-[#0050D8]/20">
              </div>
              <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">Department</label>
                <input type="text" name="department" value="<?php echo htmlspecialchars($currentUser["department"] ?? ""); ?>" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-[#0050D8] focus:ring-2 focus:ring-[#0050D8]/20">
              </div>
              <div class="sm:col-span-2">
                <label class="mb-1 block text-sm font-medium text-slate-700">More Info</label>
                <textarea name="bio" rows="4" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-[#0050D8] focus:ring-2 focus:ring-[#0050D8]/20"><?php echo htmlspecialchars($currentUser["bio"] ?? ""); ?></textarea>
              </div>
            </div>
            <div class="mt-5 flex justify-end">
              <button type="submit" class="rounded-lg bg-[#0050D8] px-5 py-2 text-sm font-semibold text-white hover:bg-[#003FA8]">Save Settings</button>
            </div>
          </form>
        </section>
      </div>
    </main>
  </div>

  <script>
  (() => {
    const links = Array.from(document.querySelectorAll('[data-home-tab-link]'));
    const panels = Array.from(document.querySelectorAll('[data-home-tab-panel]'));
    const joinedParents = Array.from(document.querySelectorAll('[data-joined-parent]'));
    const titleEl = document.getElementById('homePageTitle');
    const openSidebar = document.querySelector('[data-home-sidebar-open]');
    const closeSidebarTriggers = document.querySelectorAll('[data-home-sidebar-close]');
    const pageTitles = {
      home: 'Home',
      joined: 'Joined Subjects',
      archived: 'Archived Subjects',
      websites: 'Websites',
      settings: 'Account Settings'
    };

    function closeSidebar() {
      document.body.classList.remove('home-sidebar-open');
    }

    if (openSidebar) {
      openSidebar.addEventListener('click', () => document.body.classList.add('home-sidebar-open'));
    }
    closeSidebarTriggers.forEach(trigger => trigger.addEventListener('click', closeSidebar));

    function setHomeTab(tab, push = true) {
      panels.forEach(panel => {
        panel.hidden = panel.dataset.homeTabPanel !== tab;
      });
      links.forEach(link => {
        const active = link.dataset.tab === tab;
        link.classList.toggle('is-active', active);
        if (active) {
          link.setAttribute('aria-current', 'page');
        } else {
          link.removeAttribute('aria-current');
        }
      });
      joinedParents.forEach(parent => {
        parent.classList.toggle('is-active', tab === 'joined' || tab === 'archived');
      });
      if (titleEl) titleEl.textContent = pageTitles[tab] || 'Home';
      document.querySelectorAll('details').forEach(details => {
        if (tab === 'joined' || tab === 'archived') details.open = true;
      });
      closeSidebar();
      if (push) {
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        history.pushState({ tab }, '', url);
      }
    }

    links.forEach(link => {
      link.addEventListener('click', event => {
        event.preventDefault();
        setHomeTab(link.dataset.tab);
      });
    });

    window.addEventListener('popstate', () => {
      const tab = new URLSearchParams(window.location.search).get('tab') || 'home';
      setHomeTab(tab, false);
    });

    const websiteSearch = document.querySelector('[data-website-search]');
    if (websiteSearch) {
      const cards = Array.from(document.querySelectorAll('[data-website-card]'));
      const empty = document.getElementById('websiteEmptyState');
      websiteSearch.addEventListener('input', () => {
        const query = websiteSearch.value.trim().toLowerCase();
        let visible = 0;
        cards.forEach(card => {
          const matches = !query || (card.dataset.searchText || '').includes(query);
          card.classList.toggle('hidden', !matches);
          if (matches) visible++;
        });
        if (empty) empty.classList.toggle('hidden', visible !== 0);
      });
    }
  })();
  </script>
</body>
</html>
