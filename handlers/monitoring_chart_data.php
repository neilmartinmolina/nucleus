<?php
define("NUCLEUS_SKIP_DIRECT_ACCESS_REDIRECT", true);
require_once __DIR__ . "/../includes/core.php";

header("Content-Type: application/json");

function monitoringChartResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if (!isAuthenticated()) {
    monitoringChartResponse(401, ["success" => false, "message" => "Not authenticated."]);
}

$loadRows = $pdo->query("
    SELECT checked_at, response_time_ms
    FROM deployment_checks
    WHERE response_time_ms IS NOT NULL
    ORDER BY checked_at DESC, id DESC
    LIMIT 8
")->fetchAll();
$loadRows = array_reverse($loadRows);
$loadLabels = [];
$loadValues = [];
foreach ($loadRows as $row) {
    $loadLabels[] = date("g:i A", strtotime($row["checked_at"]));
    $loadValues[] = round(((int) $row["response_time_ms"]) / 1000, 2);
}

if (!$loadLabels) {
    $loadLabels = ["12:00 AM", "3:00 AM", "6:00 AM", "9:00 AM", "12:00 PM", "3:00 PM", "6:00 PM", "9:00 PM"];
    $loadValues = [0.8, 1.1, 0.9, 1.4, 1.2, 1.6, 1.3, 1.5];
}

monitoringChartResponse(200, [
    "success" => true,
    "load_time" => [
        "labels" => $loadLabels,
        "values" => $loadValues,
    ],
]);
