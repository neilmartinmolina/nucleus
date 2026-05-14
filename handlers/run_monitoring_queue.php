<?php
define("NUCLEUS_SKIP_SESSION_BOOTSTRAP", true);
require_once __DIR__ . "/../includes/core.php";

$isCli = php_sapi_name() === "cli";
if (!$isCli) {
    header("Content-Type: application/json");
}

function queueResponse(int $statusCode, array $payload): void
{
    global $isCli;
    http_response_code($statusCode);
    echo json_encode($payload, JSON_PRETTY_PRINT) . ($isCli ? PHP_EOL : "");
    exit;
}

function queueSafeErrorMessage(string $message): string
{
    if (php_sapi_name() === "cli") {
        return $message;
    }

    $looksLikePath = preg_match('/[A-Za-z]:[\\\\\/]|\/(?:var|home|usr|opt|xampp|tmp)\//', $message) === 1;
    return $looksLikePath || $message === ""
        ? "Monitoring queue failed. Check storage/logs/monitoring.log for details."
        : $message;
}

function queueAuthorized(): bool
{
    if (php_sapi_name() === "cli" || isLocal()) {
        return true;
    }

    $expected = (string) ($_ENV["MONITORING_QUEUE_TOKEN"] ?? "");
    $provided = (string) ($_GET["token"] ?? ($_SERVER["HTTP_X_MONITORING_TOKEN"] ?? ""));
    return $expected !== "" && hash_equals($expected, $provided);
}

function queueLockMetadata(string $lockPath): array
{
    if (!file_exists($lockPath)) {
        return [];
    }

    if (!is_file($lockPath)) {
        monitoringLog("Lock metadata unreadable.", [
            "reason" => is_dir($lockPath) ? "lock_path_is_directory" : "lock_path_is_not_file",
        ]);
        return [];
    }

    if (!is_readable($lockPath)) {
        monitoringLog("Lock metadata unreadable.", ["reason" => "lock_file_not_readable"]);
        return [];
    }

    $rawContent = @file_get_contents($lockPath);
    if ($rawContent === false) {
        monitoringLog("Lock metadata unreadable.", ["reason" => "file_get_contents_failed"]);
        return [];
    }

    $raw = trim((string) $rawContent);
    if ($raw === "") {
        return [];
    }

    $metadata = json_decode($raw, true);
    return is_array($metadata) ? $metadata : ["raw" => $raw];
}

function queuePrepareLockFile(string $lockPath): bool
{
    $lockDirectory = dirname($lockPath);
    monitoringEnsureDirectory($lockDirectory);

    if (is_dir($lockPath)) {
        $items = array_diff(scandir($lockPath) ?: [], [".", ".."]);
        if (!$items && @rmdir($lockPath)) {
            monitoringLog("Replaced directory at monitoring lock path.", ["reason" => "empty_directory_removed"]);
            return true;
        }

        monitoringLog("Monitoring lock path is a directory and cannot be replaced safely.", [
            "reason" => "lock_path_is_directory",
        ]);
        return false;
    }

    return true;
}

function queueLockAgeSeconds(array $metadata): ?int
{
    if (empty($metadata["started_at"])) {
        return null;
    }

    $startedAt = strtotime((string) $metadata["started_at"]);
    if ($startedAt === false) {
        return null;
    }

    return max(0, time() - $startedAt);
}

function queueWriteLockMetadata($lockHandle, int $runId, int $batchSize, bool $force): void
{
    $metadata = [
        "pid" => function_exists("getmypid") ? getmypid() : null,
        "started_at" => date("c"),
        "run_id" => $runId,
        "batch_size" => $batchSize,
        "force" => $force,
    ];

    ftruncate($lockHandle, 0);
    rewind($lockHandle);
    fwrite($lockHandle, json_encode($metadata, JSON_UNESCAPED_SLASHES));
    fflush($lockHandle);
}

if (!queueAuthorized()) {
    queueResponse(403, ["success" => false, "message" => "Monitoring queue is not authorized."]);
}

$settings = monitoringSettings($pdo);
$batchSize = (int) ($_GET["batch"] ?? ($_ENV["MONITORING_BATCH_SIZE"] ?? ($settings["batch_size"] ?? NUCLEUS_MONITORING_DEFAULT_BATCH_SIZE)));
$batchSize = max(1, min($batchSize, 100));
$force = (string) ($_GET["force"] ?? "") === "1";

if ($isCli && !empty($argv)) {
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === "force=1" || $arg === "--force") {
            $force = true;
        } elseif (preg_match('/^batch=(\d+)$/', $arg, $matches)) {
            $batchSize = max(1, min((int) $matches[1], 100));
        }
    }
}

$lockStaleAfterSeconds = max(900, $batchSize * 30);
$startedAtMs = (int) round(microtime(true) * 1000);
$lockPath = monitoringStoragePath("locks/monitoring.lock");
if (!queuePrepareLockFile($lockPath)) {
    $runId = monitoringStartRun($pdo, $batchSize);
    monitoringFinishRun($pdo, $runId, "failed", 0, 0, 1, "Monitoring lock path is invalid.", $startedAtMs);
    queueResponse(500, [
        "success" => false,
        "status" => "failed",
        "runId" => $runId,
        "message" => "Monitoring lock path is invalid. Check storage/locks/monitoring.lock.",
        "checked" => 0,
        "errors" => 1,
    ]);
}

$lockHandle = fopen($lockPath, "c");

if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    $lockMetadata = queueLockMetadata($lockPath);
    $lockAgeSeconds = queueLockAgeSeconds($lockMetadata);
    monitoringLog("Monitoring queue already running.", [
        "lockAgeSeconds" => $lockAgeSeconds,
        "lockStaleAfterSeconds" => $lockStaleAfterSeconds,
        "lockMetadata" => $lockMetadata,
    ]);
    $runId = monitoringStartRun($pdo, $batchSize);
    $message = "Monitoring queue already running.";
    if ($lockAgeSeconds !== null && $lockAgeSeconds > $lockStaleAfterSeconds) {
        $message .= " Lock metadata appears stale, but an active filesystem lock is still held.";
    }
    monitoringFinishRun($pdo, $runId, "skipped", 0, 0, 0, $message, $startedAtMs);
    queueResponse(200, [
        "success" => true,
        "status" => "skipped",
        "message" => $message,
        "checked" => 0,
        "skipped" => 0,
        "errors" => 0,
        "lockAgeSeconds" => $lockAgeSeconds,
    ]);
}

$runId = monitoringStartRun($pdo, $batchSize);
$previousLockMetadata = queueLockMetadata($lockPath);
$previousLockAgeSeconds = queueLockAgeSeconds($previousLockMetadata);
if ($previousLockAgeSeconds !== null && $previousLockAgeSeconds > $lockStaleAfterSeconds) {
    monitoringLog("Replacing stale monitoring lock metadata.", [
        "runId" => $runId,
        "lockAgeSeconds" => $previousLockAgeSeconds,
        "lockStaleAfterSeconds" => $lockStaleAfterSeconds,
        "lockMetadata" => $previousLockMetadata,
    ]);
}
queueWriteLockMetadata($lockHandle, $runId, $batchSize, $force);
$checked = 0;
$errors = 0;
$results = [];
$selectedProjectIds = [];

try {
    monitoringLog("Monitoring queue started.", ["runId" => $runId, "batchSize" => $batchSize, "force" => $force]);

    $projects = monitoringSelectProjectsForQueue($pdo, $batchSize, $force);
    $selectedProjectIds = array_map(static fn($project) => (int) $project["project_id"], $projects);
    monitoringLog("Monitoring queue selected projects.", ["runId" => $runId, "projectIds" => $selectedProjectIds]);

    foreach ($projects as $project) {
        try {
            $result = monitoringRunProjectCheck($pdo, $project);
            $checked++;
            $results[] = [
                "projectId" => (int) $project["project_id"],
                "projectName" => $project["project_name"],
                "priorityScore" => isset($project["priority_score"]) ? (float) $project["priority_score"] : null,
                "status" => $result["status"],
                "freshness" => $result["freshness"]["state"],
                "message" => $result["message"],
            ];
        } catch (Throwable $e) {
            $errors++;
            monitoringLog("Monitoring project check failed.", [
                "runId" => $runId,
                "projectId" => (int) $project["project_id"],
                "error" => $e->getMessage(),
            ]);
            $results[] = [
                "projectId" => (int) $project["project_id"],
                "projectName" => $project["project_name"],
                "priorityScore" => isset($project["priority_score"]) ? (float) $project["priority_score"] : null,
                "status" => "error",
                "freshness" => "unknown",
                "message" => $e->getMessage(),
            ];
        }
    }

    $skipped = 0;
    $status = $errors > 0 ? "failed" : "completed";
    $message = $errors > 0 ? "Queue completed with {$errors} errors." : "Queue completed.";
    monitoringFinishRun($pdo, $runId, $status, $checked, $skipped, $errors, $message, $startedAtMs);
    $durationMs = max(0, (int) round(microtime(true) * 1000) - $startedAtMs);
    monitoringLog("Monitoring queue finished.", [
        "runId" => $runId,
        "checked" => $checked,
        "skipped" => $skipped,
        "errors" => $errors,
        "durationMs" => $durationMs,
    ]);

    queueResponse(200, [
        "success" => true,
        "status" => $status,
        "runId" => $runId,
        "checked" => $checked,
        "skipped" => $skipped,
        "errors" => $errors,
        "batchSize" => $batchSize,
        "forced" => $force,
        "selectedProjectIds" => $selectedProjectIds,
        "durationMs" => $durationMs,
        "results" => $results,
    ]);
} catch (Throwable $e) {
    $durationMs = max(0, (int) round(microtime(true) * 1000) - $startedAtMs);
    monitoringFinishRun($pdo, $runId, "failed", $checked, 0, $errors + 1, $e->getMessage(), $startedAtMs);
    monitoringLog("Monitoring queue failed.", ["runId" => $runId, "error" => $e->getMessage(), "durationMs" => $durationMs]);
    queueResponse(500, [
        "success" => false,
        "status" => "failed",
        "runId" => $runId,
        "message" => queueSafeErrorMessage($e->getMessage()),
        "checked" => $checked,
        "errors" => $errors + 1,
        "durationMs" => $durationMs,
    ]);
} finally {
    ftruncate($lockHandle, 0);
    rewind($lockHandle);
    fflush($lockHandle);
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
