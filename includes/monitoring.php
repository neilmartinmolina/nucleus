<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

const NUCLEUS_MONITORING_DEFAULT_STALE_MINUTES = 10;
const NUCLEUS_MONITORING_DEFAULT_BATCH_SIZE = 3;
const NUCLEUS_MONITORING_DEFAULT_SCHEDULER_MODE = "manual";

function monitoringStaleMinutes(): int
{
    global $pdo;
    $settings = isset($pdo) ? monitoringSettings($pdo) : [];
    $value = (int) ($_ENV["MONITORING_STALE_MINUTES"] ?? ($settings["stale_after_minutes"] ?? NUCLEUS_MONITORING_DEFAULT_STALE_MINUTES));
    return $value > 0 ? $value : NUCLEUS_MONITORING_DEFAULT_STALE_MINUTES;
}

function monitoringStoragePath(string $subPath): string
{
    return __DIR__ . "/../storage/" . ltrim($subPath, "/\\");
}

function monitoringEnsureDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }
}

function monitoringLog(string $message, array $context = []): void
{
    $logPath = monitoringStoragePath("logs/monitoring.log");
    monitoringEnsureDirectory(dirname($logPath));
    $line = "[" . date("Y-m-d H:i:s") . "] " . $message;
    if ($context) {
        $line .= " " . json_encode($context, JSON_UNESCAPED_SLASHES);
    }
    file_put_contents($logPath, $line . PHP_EOL, FILE_APPEND);
}

function monitoringSettings(PDO $pdo): array
{
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }

    $defaults = [
        "scheduler_mode" => NUCLEUS_MONITORING_DEFAULT_SCHEDULER_MODE,
        "scheduler_enabled" => 0,
        "scheduler_interval_minutes" => 2,
        "scheduler_batch_size" => NUCLEUS_MONITORING_DEFAULT_BATCH_SIZE,
        "scheduler_force" => 0,
        "lock_timeout_seconds" => 300,
        "check_interval_minutes" => 2,
        "stale_after_minutes" => 10,
        "failure_threshold" => 3,
        "batch_size" => NUCLEUS_MONITORING_DEFAULT_BATCH_SIZE,
        "response_slow_ms" => 3000,
        "retention_days" => 30,
    ];

    try {
        $rows = $pdo->query("SELECT setting_key, setting_value FROM monitoring_settings")->fetchAll();
        $stored = [];
        foreach ($rows as $row) {
            $stored[(string) $row["setting_key"]] = (string) $row["setting_value"];
        }
        foreach ($stored as $key => $value) {
            if ($key === "scheduler_mode") {
                $defaults["scheduler_mode"] = monitoringNormalizeSchedulerMode($value);
            } elseif (array_key_exists($key, $defaults)) {
                $defaults[$key] = (int) $value;
            }
        }
        $defaults["check_interval_minutes"] = max(1, (int) $defaults["scheduler_interval_minutes"]);
        $defaults["batch_size"] = max(1, (int) $defaults["scheduler_batch_size"]);
        $defaults["scheduler_enabled"] = !empty($defaults["scheduler_enabled"]) ? 1 : 0;
        $defaults["scheduler_force"] = !empty($defaults["scheduler_force"]) ? 1 : 0;
    } catch (Throwable $e) {
        error_log("Monitoring settings unavailable: " . $e->getMessage());
    }

    $settings = $defaults;
    return $settings;
}

function monitoringSchedulerModes(): array
{
    return [
        "manual" => [
            "label" => "Manual",
            "description" => "Only administrators start the global monitoring queue with the manual fallback run.",
        ],
        "browser_demo" => [
            "label" => "Browser demo",
            "description" => "An admin dashboard tab may trigger the queue on a browser timer. The manual fallback remains available.",
        ],
        "external_cron" => [
            "label" => "External cron",
            "description" => "A server cron or Windows Task Scheduler job runs the queue. The browser does not start timer runs.",
        ],
    ];
}

function monitoringNormalizeSchedulerMode(string $mode): string
{
    $mode = strtolower(trim($mode));
    return array_key_exists($mode, monitoringSchedulerModes()) ? $mode : NUCLEUS_MONITORING_DEFAULT_SCHEDULER_MODE;
}

function monitoringCronCommand(array $settings = []): string
{
    $batchSize = max(1, min((int) ($settings["scheduler_batch_size"] ?? ($settings["batch_size"] ?? NUCLEUS_MONITORING_DEFAULT_BATCH_SIZE)), 100));
    return "php handlers/run_monitoring_queue.php batch=" . $batchSize;
}

function monitoringSchedulerStatus(PDO $pdo, bool $canAutoRunRole = false): array
{
    $settings = monitoringSettings($pdo);
    $mode = monitoringNormalizeSchedulerMode((string) ($settings["scheduler_mode"] ?? ""));
    $enabled = !empty($settings["scheduler_enabled"]);
    $intervalMinutes = max(1, (int) ($settings["scheduler_interval_minutes"] ?? ($settings["check_interval_minutes"] ?? 2)));
    $lastRun = monitoringLastRun($pdo);
    $lastRunAt = $lastRun["started_at"] ?? null;
    $lastRunAgeMinutes = null;
    $lastRunAgeSeconds = null;
    if ($lastRunAt && strtotime((string) $lastRunAt) !== false) {
        $lastRunAgeSeconds = max(0, time() - strtotime((string) $lastRunAt));
        $lastRunAgeMinutes = max(0, (int) floor($lastRunAgeSeconds / 60));
    }

    $lockState = monitoringLockState($pdo);
    $cleanup = ["success" => false, "cleared" => false, "message" => ""];
    if ($canAutoRunRole && !empty($lockState["stale"]) && empty($lockState["active"])) {
        $cleanup = clearStaleMonitoringLock($pdo, "scheduler_status");
        if (!empty($cleanup["cleared"])) {
            $lockState = monitoringLockState($pdo);
        }
    }
    $nextRunInSeconds = 0;
    if ($lastRunAgeSeconds !== null) {
        $nextRunInSeconds = max(0, ($intervalMinutes * 60) - $lastRunAgeSeconds);
    }

    $queueStale = $lastRunAgeMinutes === null || (($lastRun["status"] ?? "") === "failed");
    $canAutoRun = $canAutoRunRole
        && $enabled
        && $mode === "browser_demo"
        && empty($lockState["active"])
        && empty($lockState["stale"])
        && empty($lockState["invalid"])
        && $nextRunInSeconds === 0;
    $blockedReason = null;
    $message = "Ready to run monitoring.";
    if (!$canAutoRunRole) {
        $blockedReason = "unauthorized";
        $message = "Only admins and superadmins can auto-run monitoring.";
    } elseif (!$enabled) {
        $blockedReason = "disabled";
        $message = "Scheduler is disabled.";
    } elseif ($mode !== "browser_demo") {
        $blockedReason = $mode === "manual" ? "wrong_mode" : "wrong_mode";
        $message = $mode === "external_cron" ? "External cron mode is active." : "Browser demo scheduler mode is not active.";
    } elseif (!empty($lockState["active"])) {
        $blockedReason = "lock_active";
        $message = "Monitoring queue is already running.";
    } elseif (!empty($lockState["stale"]) || !empty($lockState["invalid"])) {
        $blockedReason = "stale_lock";
        $message = "Monitoring lock needs attention before auto-run can continue.";
    } elseif ($nextRunInSeconds > 0) {
        $blockedReason = "waiting_interval";
        $message = "Waiting for the configured interval.";
    } elseif (!empty($cleanup["cleared"])) {
        $message = "Stale lock cleaned. Ready to run monitoring.";
    }

    return [
        "success" => true,
        "scheduler_mode" => $mode,
        "scheduler_enabled" => $enabled,
        "is_admin" => $canAutoRunRole,
        "last_run_at" => $lastRunAt,
        "display_last_run_at" => $lastRunAt ? formatNucleusDateTime($lastRunAt) : "Never",
        "last_run_age_minutes" => $lastRunAgeMinutes,
        "last_run_age_seconds" => $lastRunAgeSeconds,
        "interval_minutes" => $intervalMinutes,
        "interval_seconds" => $intervalMinutes * 60,
        "lock" => $lockState,
        "lock_state" => $lockState["state"],
        "lock_active" => $lockState["active"],
        "queue_stale" => $queueStale,
        "can_auto_run" => $canAutoRun,
        "blocked_reason" => $blockedReason,
        "can_auto_run_reason" => $message,
        "message" => $message,
        "stale_lock_cleanup" => $cleanup,
        "next_run_in_seconds" => $nextRunInSeconds,
    ];
}

function monitoringFailureThreshold(PDO $pdo): int
{
    $settings = monitoringSettings($pdo);
    $threshold = (int) ($settings["failure_threshold"] ?? 3);
    return $threshold > 0 ? $threshold : 3;
}

function monitoringStartRun(PDO $pdo, int $batchSize): int
{
    $stmt = $pdo->prepare("INSERT INTO monitoring_runs (batch_size, status, message) VALUES (?, 'running', 'Monitoring queue started')");
    $stmt->execute([$batchSize]);
    return (int) $pdo->lastInsertId();
}

function monitoringFinishRun(PDO $pdo, int $runId, string $status, int $checked, int $skipped, int $errors, string $message, int $startedAtMs): void
{
    $durationMs = max(0, (int) round(microtime(true) * 1000) - $startedAtMs);
    $stmt = $pdo->prepare("
        UPDATE monitoring_runs
        SET finished_at = NOW(),
            duration_ms = ?,
            checked_count = ?,
            skipped_count = ?,
            error_count = ?,
            status = ?,
            message = ?
        WHERE id = ?
    ");
    $stmt->execute([$durationMs, $checked, $skipped, $errors, $status, $message, $runId]);
}

function monitoringLastRun(PDO $pdo): ?array
{
    try {
        $row = $pdo->query("SELECT * FROM monitoring_runs ORDER BY started_at DESC, id DESC LIMIT 1")->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function monitoringNormalizeDeployStatus(string $status): string
{
    $status = strtolower(trim($status));
    $map = [
        "queued" => "initializing",
        "starting" => "initializing",
        "started" => "initializing",
        "initializing" => "initializing",
        "pulling" => "building",
        "installing" => "building",
        "building" => "building",
        "deploying" => "building",
        "online" => "deployed",
        "success" => "deployed",
        "complete" => "deployed",
        "completed" => "deployed",
        "deployed" => "deployed",
        "warning" => "warning",
        "failed" => "error",
        "failure" => "error",
        "error" => "error",
    ];

    return $map[$status] ?? "";
}

function monitoringNormalizePublicUrl(string $publicUrl): string
{
    $publicUrl = trim($publicUrl);
    if ($publicUrl === "") {
        return "";
    }

    if (!preg_match("~^https?://~i", $publicUrl)) {
        $publicUrl = "https://" . $publicUrl;
    }

    return rtrim($publicUrl, "/");
}

function monitoringEndpointCandidates(string $publicUrl): array
{
    $base = monitoringNormalizePublicUrl($publicUrl);
    if ($base === "") {
        return [];
    }

    return [
        ["source" => "status_json", "url" => $base . "/status.json", "json" => true],
        ["source" => "api_status", "url" => $base . "/api/status", "json" => true],
        ["source" => "version_json", "url" => $base . "/version.json", "json" => true],
        ["source" => "http_only", "url" => $base, "json" => false],
    ];
}

function monitoringFetchEndpoint(string $url): array
{
    $startedAt = microtime(true);
    $statusCode = null;

    try {
        $client = new Client([
            "allow_redirects" => true,
            "connect_timeout" => 4,
            "timeout" => 8,
            "headers" => [
                "Accept" => "application/json, text/html;q=0.9, */*;q=0.8",
                "User-Agent" => "Nucleus-Monitor/1.0",
            ],
            "http_errors" => false,
        ]);

        $response = $client->request("GET", $url);
        $statusCode = $response->getStatusCode();
        $body = (string) $response->getBody();
        $responseTimeMs = (int) round((microtime(true) - $startedAt) * 1000);

        return [
            "ok" => $statusCode >= 200 && $statusCode < 400,
            "statusCode" => $statusCode,
            "body" => $body,
            "responseTimeMs" => $responseTimeMs,
            "error" => $statusCode >= 200 && $statusCode < 400 ? null : "HTTP {$statusCode}",
        ];
    } catch (GuzzleException $e) {
        return [
            "ok" => false,
            "statusCode" => $statusCode,
            "body" => "",
            "responseTimeMs" => (int) round((microtime(true) - $startedAt) * 1000),
            "error" => $e->getMessage(),
        ];
    }
}

function monitoringParseJsonBody(string $body, string $source): array
{
    $json = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($json)) {
        return ["ok" => false, "error" => $source . " JSON parse failed: " . json_last_error_msg()];
    }

    return ["ok" => true, "data" => $json];
}

function monitoringParseTimestamp($value): ?string
{
    if (empty($value) || !is_string($value)) {
        return null;
    }

    try {
        return (new DateTime($value))->format("Y-m-d H:i:s");
    } catch (Throwable $e) {
        return null;
    }
}

function monitoringScalarString($value): ?string
{
    return is_scalar($value) && $value !== "" ? (string) $value : null;
}

function monitoringLatestCheck(PDO $pdo, int $projectId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM deployment_checks WHERE project_id = ? ORDER BY checked_at DESC, id DESC LIMIT 1");
    $stmt->execute([$projectId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function monitoringConsecutiveFailures(PDO $pdo, int $projectId): int
{
    $stmt = $pdo->prepare("SELECT status FROM deployment_checks WHERE project_id = ? ORDER BY checked_at DESC, id DESC LIMIT 25");
    $stmt->execute([$projectId]);
    $count = 0;
    foreach ($stmt->fetchAll() as $row) {
        if (in_array($row["status"], ["warning", "error"], true)) {
            $count++;
            continue;
        }
        break;
    }

    return $count;
}

function monitoringLastSuccessfulCheck(PDO $pdo, int $projectId): ?string
{
    $stmt = $pdo->prepare("SELECT checked_at FROM deployment_checks WHERE project_id = ? AND status = 'deployed' ORDER BY checked_at DESC, id DESC LIMIT 1");
    $stmt->execute([$projectId]);
    $checkedAt = $stmt->fetchColumn();
    return $checkedAt !== false ? (string) $checkedAt : null;
}

function monitoringUptimePercent24h(PDO $pdo, int $projectId): ?float
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total_checks,
               SUM(CASE WHEN status = 'deployed' THEN 1 ELSE 0 END) AS healthy_checks
        FROM deployment_checks
        WHERE project_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stmt->execute([$projectId]);
    $row = $stmt->fetch();
    $total = (int) ($row["total_checks"] ?? 0);
    if ($total === 0) {
        return null;
    }

    return round(((int) ($row["healthy_checks"] ?? 0) / $total) * 100, 1);
}

function monitoringUptimePercent(PDO $pdo, int $projectId, string $window = "24h"): ?float
{
    $interval = $window === "7d" ? "7 DAY" : "24 HOUR";
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total_checks,
               SUM(CASE WHEN status = 'deployed' THEN 1 ELSE 0 END) AS healthy_checks
        FROM deployment_checks
        WHERE project_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL {$interval})
    ");
    $stmt->execute([$projectId]);
    $row = $stmt->fetch();
    $total = (int) ($row["total_checks"] ?? 0);
    if ($total === 0) {
        return null;
    }

    return round(((int) ($row["healthy_checks"] ?? 0) / $total) * 100, 1);
}

function monitoringUnresolvedAlertCount(PDO $pdo, int $projectId): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM monitoring_alerts WHERE project_id = ? AND is_resolved = 0");
    $stmt->execute([$projectId]);
    return (int) $stmt->fetchColumn();
}

function monitoringAverageResponseMs(PDO $pdo, int $projectId, string $window = "24h"): ?int
{
    $interval = $window === "7d" ? "7 DAY" : "24 HOUR";
    $stmt = $pdo->prepare("
        SELECT AVG(response_time_ms)
        FROM deployment_checks
        WHERE project_id = ?
          AND response_time_ms IS NOT NULL
          AND checked_at >= DATE_SUB(NOW(), INTERVAL {$interval})
    ");
    $stmt->execute([$projectId]);
    $average = $stmt->fetchColumn();
    return $average === null ? null : (int) round((float) $average);
}

function monitoringHealthScore(PDO $pdo, int $projectId, ?array $snapshot = null): array
{
    $settings = monitoringSettings($pdo);
    $slowMs = max(1, (int) ($settings["response_slow_ms"] ?? 3000));
    $snapshot = $snapshot ?? monitoringProjectSnapshot($pdo, $projectId);
    $uptime = monitoringUptimePercent($pdo, $projectId, "24h");
    $avgResponse = monitoringAverageResponseMs($pdo, $projectId, "24h");
    $failures = (int) ($snapshot["consecutiveFailures"] ?? 0);
    $freshness = $snapshot["freshness"]["state"] ?? "unknown";
    $unresolvedAlerts = monitoringUnresolvedAlertCount($pdo, $projectId);

    $score = $uptime === null ? 70 : (float) $uptime;
    $score -= min(30, $failures * 8);
    if (in_array($freshness, ["stale", "possibly_outdated"], true)) {
        $score -= 15;
    } elseif ($freshness === "unknown") {
        $score -= 10;
    }
    if ($avgResponse !== null && $avgResponse > $slowMs) {
        $score -= min(20, (int) ceil((($avgResponse - $slowMs) / $slowMs) * 20));
    }
    $score -= min(20, $unresolvedAlerts * 5);
    $score = max(0, min(100, (int) round($score)));

    if ($score >= 90) {
        $label = "Excellent";
        $state = "excellent";
    } elseif ($score >= 75) {
        $label = "Healthy";
        $state = "healthy";
    } elseif ($score >= 50) {
        $label = "Warning";
        $state = "warning";
    } else {
        $label = "Critical";
        $state = "critical";
    }

    return [
        "score" => $score,
        "label" => $label,
        "state" => $state,
        "uptime" => $uptime,
        "averageResponseMs" => $avgResponse,
        "consecutiveFailures" => $failures,
        "freshnessState" => $freshness,
        "unresolvedAlerts" => $unresolvedAlerts,
        "slowResponseMs" => $slowMs,
    ];
}

function monitoringHealthScoreBadgeClass(string $state): string
{
    return [
        "excellent" => "bg-emerald-50 text-emerald-700 ring-emerald-600/20",
        "healthy" => "bg-sky-50 text-sky-700 ring-sky-600/20",
        "warning" => "bg-amber-50 text-amber-700 ring-amber-600/20",
        "critical" => "bg-red-50 text-red-700 ring-red-600/20",
    ][$state] ?? "bg-slate-100 text-slate-600 ring-slate-500/20";
}

function monitoringStatusBadgeClass(string $status): string
{
    return [
        "initializing" => "bg-sky-50 text-sky-700 ring-sky-600/20",
        "building" => "bg-indigo-50 text-indigo-700 ring-indigo-600/20",
        "deployed" => "bg-emerald-50 text-emerald-700 ring-emerald-600/20",
        "warning" => "bg-amber-50 text-amber-700 ring-amber-600/20",
        "error" => "bg-red-50 text-red-700 ring-red-600/20",
        "recovered" => "bg-teal-50 text-teal-700 ring-teal-600/20",
    ][$status] ?? "bg-slate-100 text-slate-600 ring-slate-500/20";
}

function monitoringSeverityBadgeClass(string $severity): string
{
    return [
        "info" => "bg-sky-50 text-sky-700 ring-sky-600/20",
        "warning" => "bg-amber-50 text-amber-700 ring-amber-600/20",
        "critical" => "bg-red-50 text-red-700 ring-red-600/20",
    ][$severity] ?? "bg-slate-100 text-slate-600 ring-slate-500/20";
}

function monitoringTransitionLabel(?string $previous, string $current): string
{
    if ($current === "deployed" && in_array($previous, ["warning", "error"], true)) {
        return "recovered";
    }

    return $current;
}

function monitoringStatusTimeline(PDO $pdo, int $projectId, string $window = "24h"): array
{
    $interval = $window === "7d" ? "7 DAY" : "24 HOUR";
    $stmt = $pdo->prepare("
        SELECT id, status, checked_at, response_time_ms, error_message
        FROM deployment_checks
        WHERE project_id = ?
          AND checked_at >= DATE_SUB(NOW(), INTERVAL {$interval})
        ORDER BY checked_at ASC, id ASC
    ");
    $stmt->execute([$projectId]);
    $rows = $stmt->fetchAll();
    $events = [];
    $previousStatus = null;
    $previousAt = null;

    foreach ($rows as $row) {
        $checkedAt = (string) $row["checked_at"];
        $durationSeconds = $previousAt ? max(0, strtotime($checkedAt) - strtotime($previousAt)) : null;
        $timelineStatus = monitoringTransitionLabel($previousStatus, (string) $row["status"]);
        $events[] = [
            "id" => (int) $row["id"],
            "status" => $timelineStatus,
            "rawStatus" => (string) $row["status"],
            "checkedAt" => $checkedAt,
            "displayCheckedAt" => formatNucleusDateTime($checkedAt),
            "durationSeconds" => $durationSeconds,
            "displayDuration" => $durationSeconds === null ? "First check" : monitoringFormatDuration($durationSeconds),
            "responseTimeMs" => $row["response_time_ms"] === null ? null : (int) $row["response_time_ms"],
            "message" => $row["error_message"] ?: ($timelineStatus === "recovered" ? "Recovered after an unhealthy check." : "Monitoring check recorded."),
        ];
        $previousStatus = (string) $row["status"];
        $previousAt = $checkedAt;
    }

    return $events;
}

function monitoringFormatDuration(int $seconds): string
{
    if ($seconds < 60) {
        return $seconds . "s";
    }
    if ($seconds < 3600) {
        return (int) floor($seconds / 60) . "m";
    }
    if ($seconds < 86400) {
        return (int) floor($seconds / 3600) . "h " . (int) floor(($seconds % 3600) / 60) . "m";
    }
    return (int) floor($seconds / 86400) . "d";
}

function monitoringLockTimeoutSeconds(?PDO $pdo = null): int
{
    $settings = $pdo ? monitoringSettings($pdo) : [];
    $timeout = (int) ($settings["lock_timeout_seconds"] ?? 300);
    return $timeout > 0 ? $timeout : 300;
}

function monitoringLockIsHeld(string $lockPath): ?bool
{
    if (!is_file($lockPath)) {
        return null;
    }

    $handle = @fopen($lockPath, "c");
    if (!$handle) {
        return null;
    }

    $canLock = @flock($handle, LOCK_EX | LOCK_NB);
    if ($canLock) {
        @flock($handle, LOCK_UN);
    }
    @fclose($handle);

    return !$canLock;
}

function monitoringLockState(?PDO $pdo = null): array
{
    $lockPath = monitoringStoragePath("locks/monitoring.lock");
    $timeoutSeconds = monitoringLockTimeoutSeconds($pdo);
    $base = [
        "exists" => false,
        "active" => false,
        "stale" => false,
        "invalid" => false,
        "age_seconds" => null,
        "age_minutes" => null,
        "metadata" => [],
        "state" => "idle",
        "label" => "Idle",
        "message" => "No active queue lock.",
    ];

    if (!file_exists($lockPath)) {
        return $base;
    }

    $base["exists"] = true;
    if (!is_file($lockPath) || !is_readable($lockPath)) {
        $mtime = @filemtime($lockPath);
        $age = $mtime ? max(0, time() - $mtime) : null;
        $stale = $age === null || $age > $timeoutSeconds;
        return array_merge($base, [
            "invalid" => true,
            "stale" => $stale,
            "active" => !$stale,
            "age_seconds" => $age,
            "age_minutes" => $age === null ? null : (int) floor($age / 60),
            "state" => $stale ? "stale" : "running",
            "label" => $stale ? "Invalid stale lock" : "Invalid active lock",
            "message" => $stale ? "Lock file is invalid and stale." : "Lock file is invalid but still within the active timeout.",
        ]);
    }

    $raw = trim((string) @file_get_contents($lockPath));
    if ($raw === "") {
        return $base;
    }

    $held = monitoringLockIsHeld($lockPath);
    $metadata = json_decode($raw, true);
    $invalid = !is_array($metadata);
    $started = !$invalid ? ($metadata["started_at"] ?? null) : null;
    $startedTs = $started ? strtotime((string) $started) : false;
    $mtime = @filemtime($lockPath);
    $age = $startedTs !== false ? max(0, time() - $startedTs) : ($mtime ? max(0, time() - $mtime) : null);
    $invalid = $invalid || $age === null;
    $timedOut = $age === null || $age > $timeoutSeconds;
    $orphaned = $held === false;
    $active = $held === true;
    $stale = $timedOut || $orphaned;
    if ($held === null) {
        $active = !$timedOut;
        $stale = $timedOut;
    }

    $label = "Queue running";
    $message = $age === null ? "Lock file is present but cannot be aged." : "Lock age: " . monitoringFormatDuration($age);
    if ($orphaned) {
        $label = $invalid ? "Invalid orphaned lock" : "Orphaned lock";
        $message = "Lock metadata exists, but no process holds the queue lock.";
    } elseif ($timedOut) {
        $label = $invalid ? "Invalid stale lock" : ($active ? "Long-running queue" : "Stale lock");
        $message = $active ? "Queue lock is still held beyond the timeout." : "Lock metadata is older than the timeout.";
    } elseif ($invalid) {
        $label = "Invalid active lock";
    }

    return [
        "exists" => true,
        "active" => $active,
        "stale" => $stale,
        "invalid" => $invalid,
        "orphaned" => $orphaned,
        "flock_held" => $held,
        "age_seconds" => $age,
        "age_minutes" => $age === null ? null : (int) floor($age / 60),
        "metadata" => is_array($metadata) ? $metadata : [],
        "state" => $active ? "running" : ($stale ? "stale" : "idle"),
        "label" => $label,
        "message" => $message,
    ];
}

function clearStaleMonitoringLock(?PDO $pdo = null, string $source = "manual"): array
{
    $state = monitoringLockState($pdo);
    if (empty($state["exists"])) {
        return ["success" => true, "cleared" => false, "message" => "No monitoring lock exists."];
    }
    if (!empty($state["active"])) {
        return ["success" => false, "cleared" => false, "reason" => "lock_active", "message" => "Active monitoring locks cannot be cleared."];
    }
    if (empty($state["stale"]) && empty($state["invalid"])) {
        return ["success" => false, "cleared" => false, "reason" => "not_stale", "message" => "Monitoring lock is not stale."];
    }

    $lockPath = monitoringStoragePath("locks/monitoring.lock");
    if (monitoringLockIsHeld($lockPath) === true) {
        return ["success" => false, "cleared" => false, "reason" => "lock_active", "message" => "Active monitoring locks cannot be cleared."];
    }

    if (is_file($lockPath)) {
        $cleared = @unlink($lockPath);
    } else {
        $cleared = false;
    }
    if (!$cleared) {
        return ["success" => false, "cleared" => false, "reason" => "clear_failed", "message" => "The monitoring lock could not be cleared."];
    }

    monitoringLog("Stale monitoring lock cleared.", [
        "source" => $source,
        "ageSeconds" => $state["age_seconds"],
        "invalid" => $state["invalid"],
        "metadata" => $state["metadata"],
    ]);

    return ["success" => true, "cleared" => true, "message" => "Stale monitoring lock cleared."];
}

function monitoringFreshness(?string $lastSuccessfulCheck, ?string $remoteUpdatedAt, int $staleMinutes = null): array
{
    $staleMinutes = $staleMinutes ?? monitoringStaleMinutes();
    if (!$lastSuccessfulCheck) {
        return ["state" => "unknown", "label" => "Unknown", "severity" => "warning", "message" => "No successful monitoring check yet."];
    }

    $lastSuccessAt = strtotime($lastSuccessfulCheck);
    if ($lastSuccessAt === false) {
        return ["state" => "unknown", "label" => "Unknown", "severity" => "warning", "message" => "Last successful check timestamp is invalid."];
    }

    $ageSeconds = time() - $lastSuccessAt;
    if ($ageSeconds > ($staleMinutes * 60)) {
        return ["state" => "stale", "label" => "Stale", "severity" => "warning", "message" => "Last successful check is older than {$staleMinutes} minutes."];
    }

    if ($remoteUpdatedAt) {
        $remoteUpdatedTs = strtotime($remoteUpdatedAt);
        if ($remoteUpdatedTs !== false && (time() - $remoteUpdatedTs) > ($staleMinutes * 60)) {
            return ["state" => "possibly_outdated", "label" => "Possibly outdated", "severity" => "warning", "message" => "Remote version timestamp is older than {$staleMinutes} minutes."];
        }
    }

    return ["state" => "fresh", "label" => "Fresh", "severity" => "info", "message" => "Latest successful check is within {$staleMinutes} minutes."];
}

function monitoringHealthBadgeClass(string $state): string
{
    return [
        "fresh" => "bg-emerald-50 text-emerald-700 ring-emerald-600/20",
        "stale" => "bg-amber-50 text-amber-700 ring-amber-600/20",
        "possibly_outdated" => "bg-orange-50 text-orange-700 ring-orange-600/20",
        "unknown" => "bg-slate-100 text-slate-600 ring-slate-500/20",
    ][$state] ?? "bg-slate-100 text-slate-600 ring-slate-500/20";
}

function monitoringDisplayStatus(string $status, string $source): string
{
    if ($status === "deployed" && $source === "http_only") {
        return "Online";
    }

    return ucfirst($status);
}

function monitoringBuildCheckFromJson(string $source, array $response, array $remote): ?array
{
    if ($source === "version_json") {
        $version = monitoringScalarString($remote["version"] ?? null);
        $commit = monitoringScalarString($remote["commit"] ?? $remote["commit_hash"] ?? null);
        $branch = monitoringScalarString($remote["branch"] ?? null);
        $remoteUpdatedAt = monitoringParseTimestamp($remote["updated_at"] ?? $remote["finished_at"] ?? null);

        return [
            "status" => "deployed",
            "http_code" => $response["statusCode"],
            "response_time_ms" => $response["responseTimeMs"],
            "status_source" => "version_json",
            "message" => "Version endpoint available" . ($version ? ": {$version}" : "."),
            "version" => $version,
            "commit_hash" => $commit,
            "branch" => $branch,
            "remote_updated_at" => $remoteUpdatedAt,
        ];
    }

    $status = monitoringNormalizeDeployStatus((string) ($remote["status"] ?? ""));
    if ($status === "") {
        return null;
    }

    $version = monitoringScalarString($remote["version"] ?? null);
    $commit = monitoringScalarString($remote["commit"] ?? $remote["commit_hash"] ?? null);
    $branch = monitoringScalarString($remote["branch"] ?? null);
    $remoteUpdatedAt = monitoringParseTimestamp($remote["updated_at"] ?? $remote["finished_at"] ?? null);
    $message = trim((string) ($remote["message"] ?? "Remote status read from {$source}."));

    return [
        "status" => $status,
        "http_code" => $response["statusCode"],
        "response_time_ms" => $response["responseTimeMs"],
        "status_source" => $source,
        "message" => $message,
        "version" => $version,
        "commit_hash" => $commit,
        "branch" => $branch,
        "remote_updated_at" => $remoteUpdatedAt,
    ];
}

function monitoringSaveCheck(PDO $pdo, int $projectId, array $check): int
{
    $stmt = $pdo->prepare("
        INSERT INTO deployment_checks
            (project_id, status, http_code, response_time_ms, status_source, error_message, version, commit_hash, branch, remote_updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $projectId,
        $check["status"],
        $check["http_code"] ?? null,
        $check["response_time_ms"] ?? null,
        $check["status_source"],
        $check["error_message"] ?? null,
        $check["version"] ?? null,
        $check["commit_hash"] ?? null,
        $check["branch"] ?? null,
        $check["remote_updated_at"] ?? null,
    ]);

    return (int) $pdo->lastInsertId();
}

function monitoringUpdateCurrentStatus(PDO $pdo, int $projectId, array $check): void
{
    $noteParts = [$check["message"] ?? ""];
    if (!empty($check["status_source"])) {
        $noteParts[] = "Source: " . $check["status_source"];
    }
    if (!empty($check["response_time_ms"])) {
        $noteParts[] = $check["response_time_ms"] . "ms";
    }
    if (!empty($check["error_message"])) {
        $noteParts[] = $check["error_message"];
    }
    $note = trim(implode(" | ", array_filter($noteParts)));

    $failures = monitoringConsecutiveFailures($pdo, $projectId);
    $lastSuccessfulCheck = monitoringLastSuccessfulCheck($pdo, $projectId);

    $stmt = $pdo->prepare("
        INSERT INTO project_status
            (project_id, status, last_commit, status_note, checked_at, last_checked_at, last_successful_check_at, consecutive_failures, status_source, response_time_ms)
        VALUES (?, ?, ?, ?, NOW(), NOW(), ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            last_commit = VALUES(last_commit),
            status_note = VALUES(status_note),
            checked_at = VALUES(checked_at),
            last_checked_at = VALUES(last_checked_at),
            last_successful_check_at = VALUES(last_successful_check_at),
            consecutive_failures = VALUES(consecutive_failures),
            status_source = VALUES(status_source),
            response_time_ms = VALUES(response_time_ms)
    ");
    $stmt->execute([
        $projectId,
        $check["status"],
        $check["commit_hash"] ?? null,
        $note,
        $lastSuccessfulCheck,
        $failures,
        $check["status_source"] ?? null,
        $check["response_time_ms"] ?? null,
    ]);

    if ($check["status"] === "deployed" && !empty($check["remote_updated_at"])) {
        $stmt = $pdo->prepare("UPDATE projects SET last_updated_at = ?, updated_at = NOW() WHERE project_id = ?");
        $stmt->execute([$check["remote_updated_at"], $projectId]);
        return;
    }

    $stmt = $pdo->prepare("UPDATE projects SET updated_at = NOW() WHERE project_id = ?");
    $stmt->execute([$projectId]);
}

function monitoringOpenAlert(PDO $pdo, int $projectId, string $type, string $message, string $severity): void
{
    $stmt = $pdo->prepare("
        SELECT id
        FROM monitoring_alerts
        WHERE project_id = ? AND alert_type = ? AND is_resolved = 0
        ORDER BY triggered_at DESC
        LIMIT 1
    ");
    $stmt->execute([$projectId, $type]);
    if ($stmt->fetchColumn()) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO monitoring_alerts (project_id, alert_type, message, severity)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$projectId, $type, $message, $severity]);
}

function monitoringResolveAlerts(PDO $pdo, int $projectId, array $types): void
{
    if (!$types) {
        return;
    }

    $placeholders = implode(",", array_fill(0, count($types), "?"));
    $stmt = $pdo->prepare("
        UPDATE monitoring_alerts
        SET is_resolved = 1, resolved_at = NOW()
        WHERE project_id = ? AND is_resolved = 0 AND alert_type IN ({$placeholders})
    ");
    $stmt->execute(array_merge([$projectId], $types));
}

function monitoringApplyAlerts(PDO $pdo, int $projectId, array $check, array $freshness): void
{
    if ($check["status"] === "deployed") {
        monitoringResolveAlerts($pdo, $projectId, ["monitoring_failure", "stale_status"]);
    } else {
        $severity = $check["status"] === "error" ? "critical" : "warning";
        monitoringOpenAlert($pdo, $projectId, "monitoring_failure", $check["message"] ?? "Project monitoring check failed.", $severity);
    }

    if (in_array($freshness["state"], ["stale", "possibly_outdated", "unknown"], true)) {
        monitoringOpenAlert($pdo, $projectId, "stale_status", $freshness["message"], "warning");
    } else {
        monitoringResolveAlerts($pdo, $projectId, ["stale_status"]);
    }
}

function monitoringRunProjectCheck(PDO $pdo, array $project): array
{
    $projectId = (int) $project["project_id"];
    $failureThreshold = monitoringFailureThreshold($pdo);
    $previous = monitoringLatestCheck($pdo, $projectId);
    $check = null;
    $lastError = "No status endpoint configured";

    foreach (monitoringEndpointCandidates((string) $project["public_url"]) as $candidate) {
        $response = monitoringFetchEndpoint($candidate["url"]);

        if ($candidate["json"]) {
            if (!$response["ok"]) {
                $lastError = $response["error"];
                continue;
            }

            $parsed = monitoringParseJsonBody($response["body"], $candidate["source"]);
            if (!$parsed["ok"]) {
                $lastError = $parsed["error"];
                continue;
            }

            if ($candidate["source"] === "version_json") {
                $homepage = monitoringFetchEndpoint(monitoringNormalizePublicUrl((string) $project["public_url"]));
                if (!$homepage["ok"] || trim($homepage["body"]) === "") {
                    $lastError = !$homepage["ok"] ? $homepage["error"] : "Homepage returned an empty response";
                    continue;
                }
                $response["statusCode"] = $homepage["statusCode"];
                $response["responseTimeMs"] = $homepage["responseTimeMs"];
            }

            $check = monitoringBuildCheckFromJson($candidate["source"], $response, $parsed["data"]);
            if ($check !== null) {
                break;
            }

            $lastError = $candidate["source"] . " did not include a recognized status";
            continue;
        }

        $hasBody = trim($response["body"]) !== "";
        if ($response["ok"] && $hasBody) {
            $check = [
                "status" => "deployed",
                "http_code" => $response["statusCode"],
                "response_time_ms" => $response["responseTimeMs"],
                "status_source" => "http_only",
                "message" => ($project["deployment_mode"] ?? "hostinger_git") === "hostinger_git"
                    ? "Hostinger Git mode: no remote status file found."
                    : "Custom webhook mode: remote status unavailable, but homepage is reachable.",
            ];
            break;
        }

        $failureCount = monitoringConsecutiveFailures($pdo, $projectId) + 1;
        $status = $failureCount >= $failureThreshold ? "error" : "warning";
        $check = [
            "status" => $status,
            "http_code" => $response["statusCode"],
            "response_time_ms" => $response["responseTimeMs"],
            "status_source" => "http_only",
            "message" => $status === "warning" ? "Homepage failed health check." : "Homepage failed {$failureThreshold} checks in a row.",
            "error_message" => $hasBody ? $response["error"] : ($response["ok"] ? "Homepage returned an empty response" : $response["error"]),
        ];
        break;
    }

    if ($check === null) {
        $failureCount = monitoringConsecutiveFailures($pdo, $projectId) + 1;
        $check = [
            "status" => $failureCount >= $failureThreshold ? "error" : "warning",
            "http_code" => null,
            "response_time_ms" => null,
            "status_source" => "none",
            "message" => "Unable to read project status.",
            "error_message" => $lastError,
        ];
    }

    if (in_array($check["status"], ["warning", "error"], true)) {
        $failureCount = monitoringConsecutiveFailures($pdo, $projectId) + 1;
        $check["status"] = $failureCount >= $failureThreshold ? "error" : "warning";
        if ($failureCount < $failureThreshold) {
            $check["message"] = $check["message"] ?? "Monitoring check failed.";
        } else {
            $check["message"] = $check["message"] ?? "Monitoring check failed {$failureThreshold} checks in a row.";
        }
    }

    $checkId = monitoringSaveCheck($pdo, $projectId, $check);
    monitoringUpdateCurrentStatus($pdo, $projectId, $check);

    if (($previous["status"] ?? null) && in_array($previous["status"], ["warning", "error"], true) && $check["status"] === "deployed") {
        logActivity("deployment_recovered", "Project recovered via " . $check["status_source"], $projectId, $check["version"] ?? null);
    }

    $lastSuccess = monitoringLastSuccessfulCheck($pdo, $projectId);
    $freshness = monitoringFreshness($lastSuccess, $check["remote_updated_at"] ?? null);
    monitoringApplyAlerts($pdo, $projectId, $check, $freshness);

    return [
        "checkId" => $checkId,
        "projectId" => $projectId,
        "status" => $check["status"],
        "message" => $check["message"] ?? "",
        "freshness" => $freshness,
    ];
}

function monitoringSelectProjectsForQueue(PDO $pdo, int $batchSize, bool $force = false): array
{
    $settings = monitoringSettings($pdo);
    $checkInterval = max(1, (int) $settings["check_interval_minutes"]);
    $staleAfter = max(1, (int) $settings["stale_after_minutes"]);
    $responseSlowMs = max(1, (int) $settings["response_slow_ms"]);

    $eligibility = $force
        ? "1=1"
        : "(
            ps.last_checked_at IS NULL
            OR ps.status IN ('warning', 'error')
            OR ps.consecutive_failures > 0
            OR ps.last_successful_check_at IS NULL
            OR ps.last_successful_check_at <= DATE_SUB(NOW(), INTERVAL {$staleAfter} MINUTE)
            OR ps.last_checked_at <= DATE_SUB(NOW(), INTERVAL {$checkInterval} MINUTE)
        )";

    $stmt = $pdo->prepare("
        SELECT p.project_id, p.project_name, p.public_url, COALESCE(p.deployment_mode, 'hostinger_git') AS deployment_mode,
               ps.status, ps.last_checked_at, ps.last_successful_check_at,
               COALESCE(ps.consecutive_failures, 0) AS consecutive_failures,
               ps.response_time_ms,
               (
                    CASE
                        WHEN ps.status = 'error' THEN 1
                        WHEN ps.status = 'warning' THEN 2
                        WHEN COALESCE(ps.consecutive_failures, 0) > 0 THEN 3
                        WHEN ps.last_successful_check_at IS NOT NULL
                             AND ps.last_successful_check_at <= DATE_SUB(NOW(), INTERVAL {$staleAfter} MINUTE) THEN 4
                        WHEN ps.last_checked_at IS NULL THEN 5
                        ELSE 6
                    END
               ) AS priority_tier,
               (
                    CASE WHEN ps.status = 'error' THEN 900 ELSE 0 END
                  + CASE WHEN ps.status = 'warning' THEN 800 ELSE 0 END
                  + CASE WHEN COALESCE(ps.consecutive_failures, 0) > 0 THEN 700 ELSE 0 END
                  + CASE WHEN ps.last_successful_check_at IS NOT NULL
                           AND ps.last_successful_check_at <= DATE_SUB(NOW(), INTERVAL {$staleAfter} MINUTE) THEN 600 ELSE 0 END
                  + CASE WHEN ps.last_checked_at IS NULL THEN 500 ELSE 0 END
                  + CASE WHEN COALESCE(ps.response_time_ms, 0) > {$responseSlowMs} THEN 20 ELSE 0 END
                  + LEAST(COALESCE(TIMESTAMPDIFF(MINUTE, ps.last_checked_at, NOW()), 0), 1440)
               ) AS priority_score
        FROM projects p
        LEFT JOIN project_status ps ON ps.project_id = p.project_id
        WHERE p.public_url IS NOT NULL
          AND p.public_url <> ''
          AND {$eligibility}
        ORDER BY priority_tier ASC, priority_score DESC, ps.last_checked_at ASC, p.project_id ASC
        LIMIT {$batchSize}
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

function monitoringProjectSnapshot(PDO $pdo, int $projectId): ?array
{
    $stmt = $pdo->prepare("
        SELECT p.project_id, p.deployment_mode, p.current_version,
               ps.status, ps.status_note,
               dc.http_code, dc.response_time_ms, dc.status_source, dc.error_message,
               dc.version, dc.commit_hash, dc.branch, dc.remote_updated_at, dc.checked_at AS latest_check_at,
               (SELECT MAX(checked_at) FROM deployment_checks WHERE project_id = p.project_id AND status = 'deployed') AS last_successful_check,
               (SELECT COUNT(*) FROM deployment_checks dcf WHERE dcf.project_id = p.project_id AND dcf.status IN ('warning','error') AND dcf.checked_at > COALESCE((SELECT MAX(dcs.checked_at) FROM deployment_checks dcs WHERE dcs.project_id = p.project_id AND dcs.status = 'deployed'), '1970-01-01')) AS consecutive_failures
        FROM projects p
        LEFT JOIN project_status ps ON ps.project_id = p.project_id
        LEFT JOIN deployment_checks dc ON dc.id = (
            SELECT id FROM deployment_checks WHERE project_id = p.project_id ORDER BY checked_at DESC, id DESC LIMIT 1
        )
        WHERE p.project_id = ?
    ");
    $stmt->execute([$projectId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $lastSuccess = $row["last_successful_check"] ?? null;
    $freshness = monitoringFreshness($lastSuccess, $row["remote_updated_at"] ?? null);
    $status = $row["status"] ?? "initializing";
    $source = $row["status_source"] ?? "none";
    $uptime = monitoringUptimePercent24h($pdo, $projectId);

    return [
        "success" => true,
        "projectId" => $projectId,
        "deploymentMode" => $row["deployment_mode"] ?? "hostinger_git",
        "status" => $status,
        "message" => $row["status_note"] ?? "",
        "httpCode" => $row["http_code"] ?? null,
        "responseTimeMs" => $row["response_time_ms"] ?? null,
        "statusSource" => $source,
        "errorMessage" => $row["error_message"] ?? null,
        "version" => $row["version"] ?? $row["current_version"] ?? null,
        "commitHash" => $row["commit_hash"] ?? null,
        "branch" => $row["branch"] ?? null,
        "remoteUpdatedAt" => $row["remote_updated_at"] ?? null,
        "latestCheckAt" => $row["latest_check_at"] ?? null,
        "lastSuccessfulCheck" => $lastSuccess,
        "displayLastSuccessfulCheck" => $lastSuccess ? formatNucleusDateTime($lastSuccess) : "Never",
        "consecutiveFailures" => (int) ($row["consecutive_failures"] ?? 0),
        "displayStatus" => monitoringDisplayStatus($status, $source),
        "displayUpdatedAt" => !empty($row["remote_updated_at"]) ? formatNucleusDateTime($row["remote_updated_at"]) : ($lastSuccess ? formatNucleusDateTime($lastSuccess) : "Never"),
        "uptimePercent24h" => $uptime,
        "displayUptimePercent24h" => $uptime === null ? "No checks" : $uptime . "%",
        "freshness" => $freshness,
        "healthState" => $freshness["state"],
        "healthLabel" => $freshness["label"],
        "healthMessage" => $freshness["message"],
    ];
}
