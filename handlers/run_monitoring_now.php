<?php
if (php_sapi_name() === "cli") {
    define("NUCLEUS_SKIP_SESSION_BOOTSTRAP", true);
}
define("NUCLEUS_SKIP_DIRECT_ACCESS_REDIRECT", true);
require_once __DIR__ . "/../includes/core.php";

header("Content-Type: application/json");

function monitoringNowResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function monitoringNowPhpBinary(): string
{
    $candidates = [];
    $binaryName = stripos(PHP_OS_FAMILY, "Windows") === 0 ? "php.exe" : "php";

    if (defined("PHP_BINDIR")) {
        $candidates[] = rtrim(PHP_BINDIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $binaryName;
    }

    $xamppPhp = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . "php" . DIRECTORY_SEPARATOR . $binaryName;
    $candidates[] = $xamppPhp;

    if (defined("PHP_BINARY")) {
        $baseName = strtolower(basename(PHP_BINARY));
        if (strpos($baseName, "php") === 0) {
            $candidates[] = PHP_BINARY;
            $candidates[] = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . $binaryName;
        }
    }

    $candidates[] = $binaryName;

    foreach (array_unique($candidates) as $candidate) {
        if ($candidate === $binaryName || is_file($candidate)) {
            return $candidate;
        }
    }

    return PHP_BINARY;
}

function monitoringNowDecodeQueueOutput(string $rawOutput): ?array
{
    $decoded = json_decode($rawOutput, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    $firstBrace = strpos($rawOutput, "{");
    $lastBrace = strrpos($rawOutput, "}");
    if ($firstBrace === false || $lastBrace === false || $lastBrace <= $firstBrace) {
        return null;
    }

    $decoded = json_decode(substr($rawOutput, $firstBrace, $lastBrace - $firstBrace + 1), true);
    return is_array($decoded) ? $decoded : null;
}

function monitoringNowSafeMessage(string $message, bool $success): string
{
    if ($success) {
        return $message;
    }

    if ($message === "") {
        return "Monitoring queue failed. Check storage/logs/monitoring.log for details.";
    }

    $looksLikePath = preg_match('/[A-Za-z]:[\\\\\/]|\/(?:var|home|usr|opt|xampp|tmp)\//', $message) === 1;
    if ($looksLikePath) {
        return "Monitoring queue failed. Check storage/logs/monitoring.log for details.";
    }

    return $message;
}

if (!isAuthenticated()) {
    monitoringNowResponse(401, ["success" => false, "message" => "Not authenticated."]);
}

$roleManager = new RoleManager($pdo);
$role = $roleManager->getUserRole($_SESSION["userId"] ?? null);
if (!in_array($role, ["admin", "superadmin"], true)) {
    monitoringNowResponse(403, ["success" => false, "message" => "Only administrators can run monitoring manually."]);
}

$startedAtMs = (int) round(microtime(true) * 1000);
$settings = monitoringSettings($pdo);
$batchSize = max(1, min((int) ($settings["scheduler_batch_size"] ?? ($settings["batch_size"] ?? NUCLEUS_MONITORING_DEFAULT_BATCH_SIZE)), 100));
$force = !empty($settings["scheduler_force"]);
$source = preg_replace('/[^a-z0-9_]/i', "", (string) ($_POST["source"] ?? "manual"));
$source = in_array($source, ["manual", "browser_demo"], true) ? $source : "manual";
$scriptPath = __DIR__ . "/run_monitoring_queue.php";
$phpBinary = monitoringNowPhpBinary();
$command = escapeshellarg($phpBinary) . " " . escapeshellarg($scriptPath) . " batch=" . $batchSize . " " . escapeshellarg("source=" . $source);
if ($force) {
    $command .= " --force";
}
$command .= " 2>&1";

$output = [];
$exitCode = 0;
@exec($command, $output, $exitCode);
$durationMs = max(0, (int) round(microtime(true) * 1000) - $startedAtMs);

$rawOutput = trim(implode("\n", $output));
$queueResult = monitoringNowDecodeQueueOutput($rawOutput);
if (!is_array($queueResult)) {
    monitoringLog("Manual monitoring queue failed.", [
        "phpBinary" => $phpBinary,
        "exitCode" => $exitCode,
        "durationMs" => $durationMs,
        "output" => substr($rawOutput, 0, 1000),
    ]);
    monitoringNowResponse(500, [
        "success" => false,
        "message" => "Monitoring queue did not return a valid response.",
        "checked_count" => 0,
        "error_count" => 1,
        "duration_ms" => $durationMs,
        "latest_run_id" => null,
    ]);
}

$success = $exitCode === 0 && !empty($queueResult["success"]);
$skipped = !empty($queueResult["skipped"]) || (($queueResult["status"] ?? "") === "skipped");
$reason = (string) ($queueResult["reason"] ?? ($skipped ? "skipped" : ""));
$checked = (int) ($queueResult["checked"] ?? 0);
$errors = (int) ($queueResult["errors"] ?? 0);
$runId = isset($queueResult["runId"]) ? (int) $queueResult["runId"] : null;
$message = $queueResult["message"] ?? ($success ? "Monitoring queue completed." : "Monitoring queue failed.");
$message = monitoringNowSafeMessage((string) $message, $success);
$latestRun = monitoringLastRun($pdo);

monitoringNowResponse($success || $skipped ? 200 : 500, [
    "success" => $success,
    "message" => $message,
    "checked_count" => $checked,
    "error_count" => $errors,
    "duration_ms" => (int) ($queueResult["durationMs"] ?? $durationMs),
    "latest_run_id" => $runId,
    "skipped" => $skipped,
    "reason" => $reason,
    "last_run_at" => $latestRun["started_at"] ?? null,
    "display_last_run_at" => !empty($latestRun["started_at"]) ? formatNucleusDateTime($latestRun["started_at"]) : "Never",
]);
