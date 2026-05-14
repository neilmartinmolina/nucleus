<?php
define("NUCLEUS_SKIP_SESSION_BOOTSTRAP", true);
require_once __DIR__ . "/../includes/core.php";

$isCli = php_sapi_name() === "cli";
if (!$isCli) {
    header("Content-Type: application/json");
}

function cleanupResponse(int $statusCode, array $payload): void
{
    global $isCli;
    http_response_code($statusCode);
    echo json_encode($payload, JSON_PRETTY_PRINT) . ($isCli ? PHP_EOL : "");
    exit;
}

function cleanupSafeErrorMessage(string $message): string
{
    if (php_sapi_name() === "cli") {
        return $message;
    }

    $looksLikePath = preg_match('/[A-Za-z]:[\\\\\/]|\/(?:var|home|usr|opt|xampp|tmp)\//', $message) === 1;
    return $looksLikePath || $message === ""
        ? "Monitoring cleanup failed. Check storage/logs/monitoring.log for details."
        : "Monitoring cleanup failed.";
}

function cleanupAuthorized(): bool
{
    if (php_sapi_name() === "cli" || isLocal()) {
        return true;
    }

    $expected = (string) ($_ENV["MONITORING_QUEUE_TOKEN"] ?? "");
    $provided = (string) ($_GET["token"] ?? ($_SERVER["HTTP_X_MONITORING_TOKEN"] ?? ""));
    return $expected !== "" && hash_equals($expected, $provided);
}

if (!cleanupAuthorized()) {
    cleanupResponse(403, ["success" => false, "message" => "Cleanup is not authorized."]);
}

$settings = monitoringSettings($pdo);
$retentionDays = max(1, (int) ($_GET["retention_days"] ?? ($settings["retention_days"] ?? 30)));
if ($isCli && !empty($argv)) {
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^retention_days=(\d+)$/', $arg, $matches)) {
            $retentionDays = max(1, (int) $matches[1]);
        }
    }
}

try {
    $checksStmt = $pdo->prepare("DELETE FROM deployment_checks WHERE checked_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
    $checksStmt->execute([$retentionDays]);
    $deletedChecks = $checksStmt->rowCount();

    $runsStmt = $pdo->prepare("
        DELETE FROM monitoring_runs
        WHERE status IN ('completed', 'failed', 'skipped')
          AND started_at < DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $runsStmt->execute([$retentionDays]);
    $deletedRuns = $runsStmt->rowCount();

    $alertsStmt = $pdo->prepare("
        DELETE FROM monitoring_alerts
        WHERE is_resolved = 1
          AND resolved_at IS NOT NULL
          AND resolved_at < DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $alertsStmt->execute([$retentionDays]);
    $deletedResolvedAlerts = $alertsStmt->rowCount();

    monitoringLog("Monitoring cleanup finished.", [
        "retentionDays" => $retentionDays,
        "deletedChecks" => $deletedChecks,
        "deletedRuns" => $deletedRuns,
        "deletedResolvedAlerts" => $deletedResolvedAlerts,
    ]);

    cleanupResponse(200, [
        "success" => true,
        "retentionDays" => $retentionDays,
        "deletedChecks" => $deletedChecks,
        "deletedRuns" => $deletedRuns,
        "deletedResolvedAlerts" => $deletedResolvedAlerts,
        "message" => "Cleanup completed. Unresolved monitoring alerts were preserved.",
    ]);
} catch (Throwable $e) {
    monitoringLog("Monitoring cleanup failed.", ["error" => $e->getMessage()]);
    cleanupResponse(500, ["success" => false, "message" => cleanupSafeErrorMessage($e->getMessage())]);
}
