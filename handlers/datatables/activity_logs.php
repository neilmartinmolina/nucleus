<?php
define("NUCLEUS_SKIP_DIRECT_ACCESS_REDIRECT", true);
require_once __DIR__ . "/../../includes/core.php";

header("Content-Type: application/json");

if (!isAuthenticated() || !hasPermission("view_activity_logs")) {
    http_response_code(403);
    echo json_encode(["draw" => (int) ($_GET["draw"] ?? 0), "recordsTotal" => 0, "recordsFiltered" => 0, "data" => []]);
    exit;
}

function dtLike(string $value): string
{
    return "%" . str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], $value) . "%";
}

$draw = max(0, (int) ($_GET["draw"] ?? 0));
$start = max(0, (int) ($_GET["start"] ?? 0));
$length = (int) ($_GET["length"] ?? 10);
$length = $length > 0 ? min($length, 100) : 10;
$search = trim((string) ($_GET["search"]["value"] ?? ""));
$orderColumn = (int) ($_GET["order"][0]["column"] ?? 5);
$orderDir = strtolower((string) ($_GET["order"][0]["dir"] ?? "desc")) === "asc" ? "ASC" : "DESC";

$orderMap = [
    0 => "al.action",
    1 => "p.project_name",
    2 => "s.subject_code",
    3 => "u.fullName",
    4 => "al.note",
    5 => "al.created_at",
];
$orderBy = $orderMap[$orderColumn] ?? "al.created_at";

$fromSql = "
    FROM activity_logs al
    LEFT JOIN projects p ON al.project_id = p.project_id
    LEFT JOIN subjects s ON p.subject_id = s.subject_id
    LEFT JOIN users u ON al.userId = u.userId
    LEFT JOIN roles r ON u.role_id = r.role_id
";

$whereSql = "";
$params = [];
if ($search !== "") {
    $whereSql = "
        WHERE al.action LIKE ? ESCAPE '\\\\'
           OR p.project_name LIKE ? ESCAPE '\\\\'
           OR s.subject_code LIKE ? ESCAPE '\\\\'
           OR u.fullName LIKE ? ESCAPE '\\\\'
           OR r.role_name LIKE ? ESCAPE '\\\\'
           OR al.note LIKE ? ESCAPE '\\\\'
           OR al.created_at LIKE ? ESCAPE '\\\\'
    ";
    $like = dtLike($search);
    $params = array_fill(0, 7, $like);
}

$recordsTotal = (int) $pdo->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();

$filteredStmt = $pdo->prepare("SELECT COUNT(*) {$fromSql} {$whereSql}");
$filteredStmt->execute($params);
$recordsFiltered = (int) $filteredStmt->fetchColumn();

$dataStmt = $pdo->prepare("
    SELECT al.*, p.project_name, s.subject_code, u.fullName AS updatedByDisplay, r.role_name AS actorRole
    {$fromSql}
    {$whereSql}
    ORDER BY {$orderBy} {$orderDir}, al.activity_id DESC
    LIMIT {$length} OFFSET {$start}
");
$dataStmt->execute($params);

$rows = [];
foreach ($dataStmt->fetchAll() as $log) {
    $actorRole = $log["actorRole"] ? " <span class=\"text-slate-400\">(" . htmlspecialchars($log["actorRole"]) . ")</span>" : "";
    $rows[] = [
        "<span class=\"px-2 py-1 rounded bg-slate-100 text-slate-700 text-sm font-medium\">" . htmlspecialchars(str_replace("_", " ", $log["action"])) . "</span>",
        "<span class=\"font-medium text-slate-800\">" . htmlspecialchars($log["project_name"] ?? "System") . "</span>",
        "<span class=\"text-sm text-slate-600\">" . htmlspecialchars($log["subject_code"] ?? "-") . "</span>",
        "<span class=\"text-sm text-slate-600\">" . htmlspecialchars($log["updatedByDisplay"] ?? "System") . $actorRole . "</span>",
        "<span class=\"text-sm text-slate-600\">" . htmlspecialchars($log["note"] ?? "") . "</span>",
        "<span class=\"text-sm text-slate-500\">" . htmlspecialchars(formatNucleusDateTime($log["created_at"])) . "</span>",
    ];
}

echo json_encode([
    "draw" => $draw,
    "recordsTotal" => $recordsTotal,
    "recordsFiltered" => $recordsFiltered,
    "data" => $rows,
]);
