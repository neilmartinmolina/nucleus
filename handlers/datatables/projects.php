<?php
define("NUCLEUS_SKIP_DIRECT_ACCESS_REDIRECT", true);
require_once __DIR__ . "/../../includes/core.php";

header("Content-Type: application/json");

if (!isAuthenticated()) {
    http_response_code(403);
    echo json_encode(["draw" => (int) ($_GET["draw"] ?? 0), "recordsTotal" => 0, "recordsFiltered" => 0, "data" => []]);
    exit;
}

function dtProjectLike(string $value): string
{
    return "%" . str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], $value) . "%";
}

function dtProjectWhere(string $accessWhere, string $search, array $accessParams, array &$params): string
{
    $parts = [];
    if ($accessWhere !== "") {
        $parts[] = preg_replace('/^\s*WHERE\s+/i', '', $accessWhere);
        $params = array_merge($params, $accessParams);
    }

    if ($search !== "") {
        $parts[] = "(
            p.project_name LIKE ? ESCAPE '\\\\'
            OR s.subject_code LIKE ? ESCAPE '\\\\'
            OR dc.commit_hash LIKE ? ESCAPE '\\\\'
            OR ps.status LIKE ? ESCAPE '\\\\'
            OR u.fullName LIKE ? ESCAPE '\\\\'
            OR p.last_updated_at LIKE ? ESCAPE '\\\\'
            OR p.saved_at LIKE ? ESCAPE '\\\\'
        )";
        $like = dtProjectLike($search);
        $params = array_merge($params, array_fill(0, 7, $like));
    }

    return $parts ? "WHERE " . implode(" AND ", $parts) : "";
}

$draw = max(0, (int) ($_GET["draw"] ?? 0));
$start = max(0, (int) ($_GET["start"] ?? 0));
$length = (int) ($_GET["length"] ?? 10);
$length = $length > 0 ? min($length, 100) : 10;
$search = trim((string) ($_GET["search"]["value"] ?? ""));
$orderColumn = (int) ($_GET["order"][0]["column"] ?? 5);
$orderDir = strtolower((string) ($_GET["order"][0]["dir"] ?? "desc")) === "asc" ? "ASC" : "DESC";

$orderMap = [
    0 => "p.project_name",
    1 => "s.subject_code",
    2 => "ps.status",
    3 => "dc.response_time_ms",
    4 => "u.fullName",
    5 => "p.last_updated_at",
    6 => "COALESCE(p.saved_at, p.created_at)",
];
$orderBy = $orderMap[$orderColumn] ?? "p.last_updated_at";

$roleManager = new RoleManager($pdo);
[$accessWhere, $accessParams] = $roleManager->projectAccessSql("p");

$fromSql = "
    FROM projects p
    LEFT JOIN project_status ps ON ps.project_id = p.project_id
    LEFT JOIN users u ON ps.updated_by = u.userId
    LEFT JOIN subjects s ON p.subject_id = s.subject_id
    LEFT JOIN (
        SELECT dc1.*
        FROM deployment_checks dc1
        INNER JOIN (
            SELECT project_id, MAX(id) AS latest_check_id
            FROM deployment_checks
            GROUP BY project_id
        ) latest_dc ON latest_dc.latest_check_id = dc1.id
    ) dc ON dc.project_id = p.project_id
";

$totalParams = [];
$totalWhere = dtProjectWhere($accessWhere, "", $accessParams, $totalParams);
$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM projects p {$totalWhere}");
$totalStmt->execute($totalParams);
$recordsTotal = (int) $totalStmt->fetchColumn();

$filteredParams = [];
$filteredWhere = dtProjectWhere($accessWhere, $search, $accessParams, $filteredParams);
$filteredStmt = $pdo->prepare("SELECT COUNT(*) {$fromSql} {$filteredWhere}");
$filteredStmt->execute($filteredParams);
$recordsFiltered = (int) $filteredStmt->fetchColumn();

$dataStmt = $pdo->prepare("
    SELECT p.*, ps.status, ps.status_note, ps.updated_by AS updatedBy, u.fullName, s.subject_code AS folderName,
           dc.response_time_ms, dc.status_source, dc.version AS check_version, dc.remote_updated_at,
           dc.commit_hash, dc.branch, dc.checked_at AS latest_check_at,
           ps.last_successful_check_at AS last_successful_check,
           COALESCE(ps.consecutive_failures, 0) AS consecutive_failures
    {$fromSql}
    {$filteredWhere}
    ORDER BY {$orderBy} {$orderDir}, p.project_id DESC
    LIMIT {$length} OFFSET {$start}
");
$dataStmt->execute($filteredParams);

$canUpdate = hasPermission("update_project");
$canDelete = hasPermission("delete_project");
$csrfToken = htmlspecialchars($_SESSION["csrf_token"] ?? "");
$rows = [];

foreach ($dataStmt->fetchAll() as $project) {
    $projectId = (int) $project["project_id"];
    $status = htmlspecialchars($project["status"] ?? "initializing");
    $statusTitle = htmlspecialchars($project["status_note"] ?? "");
    $responseTime = $project["response_time_ms"] ? htmlspecialchars($project["response_time_ms"] . " ms") : "-";
    $statusSource = htmlspecialchars($project["status_source"] ?? "-");
    $freshness = monitoringFreshness($project["last_successful_check"] ?? null, $project["remote_updated_at"] ?? null);
    $uptime = monitoringUptimePercent24h($pdo, $projectId);
    $snapshot = monitoringProjectSnapshot($pdo, $projectId);
    $healthScore = monitoringHealthScore($pdo, $projectId, $snapshot);
    $uptimeText = $uptime === null ? "No checks" : htmlspecialchars($uptime . "%");
    $latest = htmlspecialchars($project["check_version"] ?? "-");
    if (!empty($project["commit_hash"])) {
        $latest .= " &middot; " . htmlspecialchars(substr($project["commit_hash"], 0, 12));
    }

    $actions = [];
    if ($canUpdate) {
        $actions[] = "<a href=\"dashboard.php?page=create-project&websiteId={$projectId}\" class=\"px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm transition-colors\">Edit</a>";
        $actions[] = "<a href=\"dashboard.php?page=project-details&projectId={$projectId}\" class=\"px-3 py-1.5 rounded-lg bg-blue-50 hover:bg-blue-100 text-blue-700 text-sm transition-colors\">Details</a>";
        if (!empty($project["subject_id"])) {
            $actions[] = "<form method=\"POST\" action=\"get_content.php?tab=websites\" data-confirm=\"This removes the project from its subject without deleting it.\" data-confirm-title=\"Unlist this project?\" data-confirm-button=\"Unlist\" data-return-page=\"websites\" class=\"inline\"><input type=\"hidden\" name=\"csrf_token\" value=\"{$csrfToken}\"><input type=\"hidden\" name=\"project_action\" value=\"unlist\"><input type=\"hidden\" name=\"project_id\" value=\"{$projectId}\"><button type=\"submit\" class=\"px-3 py-1.5 rounded-lg bg-amber-50 hover:bg-amber-100 text-amber-700 text-sm transition-colors\">Unlist</button></form>";
        }
    }
    if ($canDelete) {
        $actions[] = "<form method=\"POST\" action=\"get_content.php?tab=websites\" data-confirm=\"This permanently deletes the project record.\" data-confirm-title=\"Delete this project?\" data-confirm-button=\"Delete\" data-return-page=\"websites\" class=\"inline\"><input type=\"hidden\" name=\"csrf_token\" value=\"{$csrfToken}\"><input type=\"hidden\" name=\"project_action\" value=\"delete\"><input type=\"hidden\" name=\"project_id\" value=\"{$projectId}\"><button type=\"submit\" class=\"px-3 py-1.5 rounded-lg bg-red-50 hover:bg-red-100 text-red-600 text-sm transition-colors\">Delete</button></form>";
    }

    $rows[] = [
        "<span class=\"font-medium text-slate-800\">" . htmlspecialchars($project["project_name"]) . "</span>",
        "<span class=\"text-sm text-slate-600\">" . htmlspecialchars($project["folderName"] ?? "-") . "</span>",
        "<span data-project-status-id=\"{$projectId}\" title=\"{$statusTitle}\" class=\"px-2 py-1 rounded text-sm font-medium badge-{$status}\">" . ucfirst($status) . "</span><div class=\"mt-1 text-xs text-slate-500\">" . htmlspecialchars(deploymentModeLabel($project["deployment_mode"] ?? "hostinger_git")) . "</div>",
        "<div class=\"text-xs text-slate-500\"><div class=\"mb-2\"><span data-health-state=\"" . htmlspecialchars($freshness["state"]) . "\" title=\"" . htmlspecialchars($freshness["message"]) . "\" class=\"inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset " . monitoringHealthBadgeClass($freshness["state"]) . "\">" . htmlspecialchars($freshness["label"]) . "</span></div><div class=\"mb-2\"><span class=\"inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset " . monitoringHealthScoreBadgeClass($healthScore["state"]) . "\">Score " . (int) $healthScore["score"] . " &middot; " . htmlspecialchars($healthScore["label"]) . "</span></div><div><span data-status-response-time>{$responseTime}</span> &middot; <span data-status-source>{$statusSource}</span></div><div>Last OK: <span data-last-successful-check>" . htmlspecialchars(formatNucleusDateTime($project["last_successful_check"])) . "</span></div><div>Failures: <span data-consecutive-failures>" . (int) ($project["consecutive_failures"] ?? 0) . "</span></div><div>Uptime 24h: <span data-uptime-24h>{$uptimeText}</span></div><div>Latest: {$latest}</div></div>",
        "<span class=\"text-sm text-slate-600\">" . htmlspecialchars(displayUpdatedBy($project)) . "</span>",
        "<span class=\"text-sm text-slate-500\">" . htmlspecialchars(formatNucleusDateTime($project["last_updated_at"])) . "</span>",
        "<span class=\"text-sm text-slate-500\">" . htmlspecialchars(formatNucleusDateTime($project["saved_at"] ?? $project["created_at"])) . "</span>",
        "<div class=\"flex items-center gap-2\">" . implode("", $actions) . "</div>",
    ];
}

echo json_encode([
    "draw" => $draw,
    "recordsTotal" => $recordsTotal,
    "recordsFiltered" => $recordsFiltered,
    "data" => $rows,
]);
