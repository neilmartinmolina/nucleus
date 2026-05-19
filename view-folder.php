<?php
require_once __DIR__ . "/config.php";

if (!hasPermission("view_projects")) {
    http_response_code(401);
    echo "<div class=\"p-8 text-center\"><p class=\"text-slate-600\">Access Denied - You do not have permission to view subjects</p></div>";
    exit;
}

$folderId = $_GET["folderId"] ?? null;
if (!$folderId || !is_numeric($folderId)) {
    http_response_code(400);
    echo "<div class=\"p-8 text-center\"><p class=\"text-slate-600\">Invalid request - no subject specified</p></div>";
    exit;
}

$stmt = $pdo->prepare("SELECT subject_id AS id, subject_code AS name, description, created_by FROM subjects WHERE subject_id = ?");
$stmt->execute([$folderId]);
$folder = $stmt->fetch();

if (!$folder) {
    http_response_code(404);
    echo "<div class=\"p-8 text-center\"><p class=\"text-slate-600\">Subject not found</p></div>";
    exit;
}

$roleManager = new RoleManager($pdo);
if (!$roleManager->canAccessSubject($_SESSION["userId"], (int) $folderId)) {
    http_response_code(403);
    echo "<div class=\"p-8 text-center\"><p class=\"text-slate-600\">You do not have access to this subject.</p></div>";
    exit;
}
[$accessWhere, $accessParams] = $roleManager->projectAccessSql("p");
$subjectWhere = $accessWhere ? $accessWhere . " AND p.subject_id = ?" : " WHERE p.subject_id = ?";
$stmt = $pdo->prepare("
    SELECT p.project_id AS websiteId, p.project_name AS websiteName, p.public_url AS url,
           p.current_version AS currentVersion, p.last_updated_at AS lastUpdatedAt,
           COALESCE(p.deployment_mode, 'hostinger_git') AS deploymentMode,
           COALESCE(ps.status, 'initializing') AS deployStatus, ps.status_note AS statusNote,
           dc.response_time_ms AS responseTimeMs, dc.status_source AS statusSource,
           dc.version AS checkVersion, dc.commit_hash AS commitHash,
           (SELECT MAX(checked_at) FROM deployment_checks WHERE project_id = p.project_id AND status = 'deployed') AS lastSuccessfulCheck,
           (SELECT COUNT(*) FROM deployment_checks dcf WHERE dcf.project_id = p.project_id AND dcf.status IN ('warning','error') AND dcf.checked_at > COALESCE((SELECT MAX(dcs.checked_at) FROM deployment_checks dcs WHERE dcs.project_id = p.project_id AND dcs.status = 'deployed'), '1970-01-01')) AS consecutiveFailures,
           ps.updated_by AS updatedBy, u.fullName as updatedByName
    FROM projects p
    LEFT JOIN project_status ps ON ps.project_id = p.project_id
    LEFT JOIN users u ON ps.updated_by = u.userId
    LEFT JOIN deployment_checks dc ON dc.id = (SELECT id FROM deployment_checks WHERE project_id = p.project_id ORDER BY checked_at DESC, id DESC LIMIT 1)
    {$subjectWhere}
    ORDER BY p.project_name ASC
");
$stmt->execute(array_merge($accessParams, [$folderId]));
$websites = $stmt->fetchAll();

$totalProjects = count($websites);

$updatedThisWeek = $pdo->prepare("SELECT COUNT(*) as c FROM projects WHERE subject_id = ? AND last_updated_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
$updatedThisWeek->execute([$folderId]);
$updatedThisWeek = $updatedThisWeek->fetch()["c"];

$needsUpdate = $pdo->prepare("
    SELECT COUNT(*) as c
    FROM projects p
    LEFT JOIN project_status ps ON ps.project_id = p.project_id
    WHERE p.subject_id = ? AND (ps.status IN ('initializing','building','error') OR p.last_updated_at < DATE_SUB(CURDATE(), INTERVAL 15 DAY))
");
$needsUpdate->execute([$folderId]);
$needsUpdate = $needsUpdate->fetch()["c"];

generateCSRFToken();

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
    return formatNucleusDateTime($datetime);
}

$isEmbedded = basename($_SERVER["PHP_SELF"]) === "get_content.php";
if (!$isEmbedded):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($folder["name"]); ?> | Nucleus</title>
    <link rel="stylesheet" href="assets/css/nucleus.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        navy: '#043873',
                        accent: '#FFE492',
                        cta: '#4F9CF9'
                    }
                }
            }
        }
    </script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-800 font-sans">
<header class="border-b border-slate-200 bg-white">
    <div class="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
        <a href="dashboard.php?page=dashboard" class="text-xl font-bold tracking-tight text-navy">NUCLEUS</a>
        <nav class="flex items-center gap-4 text-sm">
            <a href="dashboard.php?page=folders" class="font-medium text-slate-600 transition-colors hover:text-navy">Subjects</a>
            <a href="logout.php" class="font-medium text-slate-500 transition-colors hover:text-navy">Logout</a>
        </nav>
    </div>
</header>
<?php endif; ?>

<main class="<?php echo $isEmbedded ? "" : "min-h-screen"; ?>">
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <div class="mb-3 flex items-center gap-2 text-sm text-slate-500">
                    <a href="dashboard.php?page=folders" class="inline-flex items-center gap-2 font-medium text-slate-600 transition-colors hover:text-navy">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"></path>
                        </svg>
                        Subjects
                    </a>
                    <span>/</span>
                    <span class="truncate"><?php echo htmlspecialchars($folder["name"]); ?></span>
                </div>
                <div class="flex items-center gap-4">
                    <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-cta to-navy text-xl font-bold text-white shadow-sm">
                        <?php echo strtoupper(substr($folder["name"], 0, 1)); ?>
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl"><?php echo htmlspecialchars($folder["name"]); ?></h1>
                        <p class="mt-1 max-w-3xl text-sm text-slate-500"><?php echo nl2br(htmlspecialchars($folder["description"] ?: "No description")); ?></p>
                    </div>
                </div>
            </div>

            <?php if (hasPermission("manage_groups")): ?>
            <a href="delete-folder.php?id=<?php echo $folder["id"]; ?>" data-confirm="Projects will be unlinked but not deleted." data-confirm-title="Delete this subject?" data-confirm-button="Delete" data-return-page="folders" class="inline-flex items-center justify-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm font-medium text-red-600 transition-colors hover:bg-red-100">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                </svg>
                Delete Subject
            </a>
            <?php endif; ?>
        </div>

        <section class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition-shadow hover:shadow-md">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-slate-500">Total Projects</p>
                        <p class="mt-1 text-3xl font-bold text-slate-900"><?php echo $totalProjects; ?></p>
                    </div>
                    <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-blue-100 text-blue-600">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path>
                        </svg>
                    </div>
                </div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition-shadow hover:shadow-md">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-slate-500">Updated This Week</p>
                        <p class="mt-1 text-3xl font-bold text-emerald-600"><?php echo $updatedThisWeek; ?></p>
                    </div>
                    <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-100 text-emerald-600">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </div>
                </div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition-shadow hover:shadow-md sm:col-span-2 lg:col-span-1">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-slate-500">Needs Update</p>
                        <p class="mt-1 text-3xl font-bold text-amber-600"><?php echo $needsUpdate; ?></p>
                    </div>
                    <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-amber-100 text-amber-600">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                </div>
            </div>
        </section>

        <section class="mb-6 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                <label class="relative flex-1">
                    <span class="sr-only">Search projects</span>
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-4.35-4.35m1.35-5.15a6.5 6.5 0 11-13 0 6.5 6.5 0 0113 0z"></path>
                    </svg>
                    <input type="text" id="searchInput" placeholder="Search projects..." class="w-full rounded-lg border border-slate-200 bg-slate-50 py-2.5 pl-10 pr-3 text-sm text-slate-700 outline-none transition focus:border-cta focus:bg-white focus:ring-2 focus:ring-cta/20">
                </label>

                <select id="statusFilter" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 outline-none transition focus:border-cta focus:bg-white focus:ring-2 focus:ring-cta/20">
                    <option value="all">All statuses</option>
                    <option value="Initializing">Initializing</option>
                    <option value="Building">Building</option>
                    <option value="Error">Error</option>
                    <option value="Up to date">Up to date</option>
                    <option value="Needs update">Needs update</option>
                    <option value="30d+ old">30d+ old</option>
                </select>

                <select id="sortFilter" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 outline-none transition focus:border-cta focus:bg-white focus:ring-2 focus:ring-cta/20">
                    <option value="name">Sort: name</option>
                    <option value="updated">Sort: last updated</option>
                </select>

                <?php if (hasPermission("create_project")): ?>
                <a href="dashboard.php?page=create-project&folderId=<?php echo urlencode((string) $folderId); ?>" class="inline-flex items-center justify-center gap-2 rounded-lg bg-cta px-4 py-2.5 text-sm font-medium text-white shadow-sm transition-colors hover:bg-blue-500">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    Add Project
                </a>
                <?php endif; ?>
            </div>
        </section>

        <section id="projectGrid" class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
            <?php foreach ($websites as $website):
                $statusLabel = computeStatus($website["lastUpdatedAt"], $website["deployStatus"]);
                $timeAgo = timeAgo($website["lastUpdatedAt"]);
                if ($statusLabel === "Initializing") {
                    $statusClass = "bg-sky-50 text-sky-700 ring-sky-600/20";
                } elseif ($statusLabel === "Up to date") {
                    $statusClass = "bg-emerald-50 text-emerald-700 ring-emerald-600/20";
                } elseif ($statusLabel === "Needs update" || $statusLabel === "Building") {
                    $statusClass = "bg-amber-50 text-amber-700 ring-amber-600/20";
                } elseif ($statusLabel === "Warning") {
                    $statusClass = "bg-orange-50 text-orange-700 ring-orange-600/20";
                } else {
                    $statusClass = "bg-red-50 text-red-700 ring-red-600/20";
                }
                $updatedTimestamp = $website["lastUpdatedAt"] ? strtotime($website["lastUpdatedAt"]) : 0;
            ?>
            <article class="project-card flex min-h-64 flex-col rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition-all hover:-translate-y-0.5 hover:border-cta/40 hover:shadow-md" data-name="<?php echo strtolower(htmlspecialchars($website["websiteName"])); ?>" data-status="<?php echo $statusLabel; ?>" data-updated="<?php echo $updatedTimestamp; ?>">
                <div class="mb-4 flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h2 class="truncate text-lg font-semibold text-slate-900"><?php echo htmlspecialchars($website["websiteName"]); ?></h2>
                        <a href="<?php echo htmlspecialchars($website["url"]); ?>" target="_blank" rel="noopener noreferrer" class="mt-1 block truncate text-sm text-slate-500 transition-colors hover:text-cta"><?php echo htmlspecialchars($website["url"]); ?></a>
                    </div>
                    <span data-project-status-id="<?php echo (int) $website["websiteId"]; ?>" title="<?php echo htmlspecialchars($website["statusNote"] ?? ""); ?>" class="status-badge shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span>
                </div>

                <div class="space-y-3 rounded-lg bg-slate-50 p-4 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-slate-500">Version</span>
                        <span data-latest-version class="font-medium text-slate-800"><?php echo htmlspecialchars($website["checkVersion"] ?? $website["currentVersion"] ?? "1.0.0"); ?></span>
                    </div>
                    <?php if (!empty($website["commitHash"])): ?>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-slate-500">Commit</span>
                        <span data-latest-commit class="font-medium text-slate-800"><?php echo htmlspecialchars(substr($website["commitHash"], 0, 12)); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-slate-500">Last updated</span>
                        <span class="project-time font-medium text-slate-800"><?php echo $timeAgo; ?></span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-slate-500">Updated by</span>
                        <span class="truncate font-medium text-slate-800"><?php echo htmlspecialchars(displayUpdatedBy($website)); ?></span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-slate-500">Monitoring</span>
                        <span class="truncate font-medium text-slate-800"><?php echo htmlspecialchars(deploymentModeLabel($website["deploymentMode"])); ?></span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-slate-500">Health</span>
                        <span class="truncate font-medium text-slate-800"><span data-status-response-time><?php echo $website["responseTimeMs"] ? htmlspecialchars($website["responseTimeMs"] . " ms") : "—"; ?></span> · <span data-status-source><?php echo htmlspecialchars($website["statusSource"] ?? "—"); ?></span></span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-slate-500">Last OK</span>
                        <span data-last-successful-check class="truncate font-medium text-slate-800"><?php echo htmlspecialchars(formatNucleusDateTime($website["lastSuccessfulCheck"])); ?></span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-slate-500">Failures</span>
                        <span data-consecutive-failures class="font-medium text-slate-800"><?php echo (int) ($website["consecutiveFailures"] ?? 0); ?></span>
                    </div>
                </div>

                <?php if (hasPermission("update_project")): ?>
                <div class="mt-auto pt-5">
                    <button class="mark-updated inline-flex w-full items-center justify-center gap-2 rounded-lg bg-navy px-4 py-2.5 text-sm font-medium text-white transition-colors hover:bg-navy/90" data-website-id="<?php echo $website["websiteId"]; ?>">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                        Mark Updated
                    </button>
                    <a href="dashboard.php?page=project-details&projectId=<?php echo $website["websiteId"]; ?>" class="mt-2 inline-flex w-full items-center justify-center rounded-lg bg-slate-100 px-4 py-2.5 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-200">View Checks</a>
                </div>
                <?php endif; ?>
            </article>
            <?php endforeach; ?>
        </section>

        <section id="emptyState" class="hidden rounded-xl border border-dashed border-slate-300 bg-white py-14 text-center shadow-sm">
            <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-xl bg-slate-100 text-slate-400">
                <svg class="h-7 w-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
            </div>
            <p class="text-base font-medium text-slate-700">No projects found</p>
            <p class="mt-1 text-sm text-slate-500">Try another search or add a new project to this subject.</p>
            <?php if (hasPermission("create_project")): ?>
            <a href="dashboard.php?page=create-project&folderId=<?php echo urlencode((string) $folderId); ?>" class="mt-5 inline-flex items-center justify-center gap-2 rounded-lg bg-cta px-4 py-2.5 text-sm font-medium text-white transition-colors hover:bg-blue-500">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                Add Project
            </a>
            <?php endif; ?>
        </section>
    </div>

</main>

<script>
(function() {
    const projects = Array.from(document.querySelectorAll('.project-card'));
    const emptyState = document.getElementById('emptyState');
    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter');
    const sortFilter = document.getElementById('sortFilter');
    const projectGrid = document.getElementById('projectGrid');

    function filterAndSort() {
        const search = searchInput.value.toLowerCase();
        const status = statusFilter.value;
        const sort = sortFilter.value;

        const filtered = projects.filter(card => {
            const matchesSearch = card.dataset.name.includes(search);
            const matchesStatus = status === 'all' || card.dataset.status === status;
            return matchesSearch && matchesStatus;
        });

        filtered.sort((a, b) => {
            if (sort === 'name') return a.dataset.name.localeCompare(b.dataset.name);
            if (sort === 'updated') return Number(b.dataset.updated) - Number(a.dataset.updated);
            return 0;
        });

        projects.forEach(card => card.classList.add('hidden'));
        filtered.forEach(card => {
            card.classList.remove('hidden');
            projectGrid.appendChild(card);
        });

        emptyState.classList.toggle('hidden', filtered.length > 0);
    }

    searchInput.addEventListener('input', filterAndSort);
    statusFilter.addEventListener('change', filterAndSort);
    sortFilter.addEventListener('change', filterAndSort);
    filterAndSort();

    document.querySelectorAll('.mark-updated').forEach(btn => {
        btn.addEventListener('click', async function() {
            const websiteId = this.dataset.websiteId;
            const confirmation = await Swal.fire({
                icon: 'question',
                title: 'Mark this project as updated?',
                text: 'Version will be auto-incremented.',
                showCancelButton: true,
                confirmButtonText: 'Update',
                confirmButtonColor: '#3085d6'
            });
            if (!confirmation.isConfirmed) return;

            try {
                const response = await fetch('handlers/update_website.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        csrf_token: '<?php echo $_SESSION["csrf_token"]; ?>',
                        websiteId: websiteId,
                        status: 'updated'
                    })
                });
                const result = await response.json();
                if (result.success) {
                    const card = this.closest('.project-card');
                    const badge = card.querySelector('.status-badge');
                    badge.className = 'status-badge shrink-0 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20';
                    badge.textContent = 'Up to date';
                    card.dataset.status = 'Up to date';
                    card.dataset.updated = Math.floor(Date.now() / 1000);
                    card.querySelector('.project-time').textContent = 'Just now';
                    filterAndSort();
                    Swal.fire({ icon: 'success', title: 'Project updated', confirmButtonColor: '#3085d6' });
                } else {
                    Swal.fire({ icon: 'error', title: 'Update failed', text: result.message, confirmButtonColor: '#3085d6' });
                }
            } catch (err) {
                Swal.fire({ icon: 'error', title: 'Request failed', text: err.message, confirmButtonColor: '#3085d6' });
            }
        });
    });

})();
</script>

<?php if (!$isEmbedded): ?>
</body>
</html>
<?php endif; ?>
