<?php
define("NUCLEUS_SKIP_DIRECT_ACCESS_REDIRECT", true);
require_once __DIR__ . "/../includes/core.php";

header("Content-Type: application/json");

function monitoringSettingsResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if (!isAuthenticated()) {
    monitoringSettingsResponse(401, ["success" => false, "message" => "Not authenticated."]);
}

if (!isAdminLike()) {
    monitoringSettingsResponse(403, ["success" => false, "message" => "Only administrators can update monitoring settings."]);
}

if (!checkCSRF($_POST["csrf_token"] ?? "")) {
    monitoringSettingsResponse(403, ["success" => false, "message" => "Invalid security token."]);
}

function postIntSetting(string $key, int $default, int $min, int $max): int
{
    $value = filter_input(INPUT_POST, $key, FILTER_VALIDATE_INT);
    if ($value === false || $value === null) {
        return $default;
    }
    return max($min, min((int) $value, $max));
}

$current = monitoringSettings($pdo);
$schedulerMode = monitoringNormalizeSchedulerMode((string) ($_POST["scheduler_mode"] ?? ""));
$schedulerEnabled = isset($_POST["scheduler_enabled"]) && $schedulerMode !== "manual" ? 1 : 0;
$schedulerInterval = postIntSetting("scheduler_interval_minutes", (int) ($current["scheduler_interval_minutes"] ?? 2), 1, 1440);
$schedulerBatchSize = postIntSetting("scheduler_batch_size", (int) ($current["scheduler_batch_size"] ?? 3), 1, 100);
$settings = [
    "scheduler_mode" => $schedulerMode,
    "scheduler_enabled" => $schedulerEnabled,
    "scheduler_interval_minutes" => $schedulerInterval,
    "scheduler_batch_size" => $schedulerBatchSize,
    "scheduler_force" => isset($_POST["scheduler_force"]) ? 1 : 0,
    "lock_timeout_seconds" => postIntSetting("lock_timeout_seconds", (int) ($current["lock_timeout_seconds"] ?? 300), 60, 3600),
    "check_interval_minutes" => $schedulerInterval,
    "stale_after_minutes" => postIntSetting("stale_after_minutes", (int) ($current["stale_after_minutes"] ?? 10), 1, 1440),
    "failure_threshold" => postIntSetting("failure_threshold", (int) ($current["failure_threshold"] ?? 3), 1, 25),
    "batch_size" => $schedulerBatchSize,
    "response_slow_ms" => postIntSetting("response_slow_ms", (int) ($current["response_slow_ms"] ?? 3000), 100, 60000),
];

$stmt = $pdo->prepare("
    INSERT INTO monitoring_settings (setting_key, setting_value)
    VALUES (?, ?)
    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
");

foreach ($settings as $key => $value) {
    $stmt->execute([$key, (string) $value]);
}

logActivity("monitoring_settings_updated", "Updated monitoring scheduler settings");

monitoringSettingsResponse(200, [
    "success" => true,
    "page" => "settings",
    "message" => "Monitoring settings updated.",
]);
