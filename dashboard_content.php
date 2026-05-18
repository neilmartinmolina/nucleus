<?php
require_once __DIR__ . "/includes/core.php";

$roleManager = new RoleManager($pdo);
$canRunMonitoringNow = in_array($roleManager->getUserRole($_SESSION["userId"] ?? null), ["admin", "superadmin"], true);
[$accessWhere, $accessParams] = $roleManager->projectAccessSql("p");
$todayWhere = $accessWhere ? $accessWhere . " AND DATE(p.last_updated_at) = CURDATE()" : " WHERE DATE(p.last_updated_at) = CURDATE()";

$todayStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM projects p {$todayWhere}");
$todayStmt->execute($accessParams);
$updatedToday = (int) $todayStmt->fetch()["c"];

$countStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM projects p {$accessWhere}");
$countStmt->execute($accessParams);
$totalWebsites = (int) $countStmt->fetch()["c"];
$totalFolders = (int) $pdo->query("SELECT COUNT(*) AS c FROM subjects")->fetch()["c"];
$totalUsers = (int) $pdo->query("SELECT COUNT(*) AS c FROM users")->fetch()["c"];

$monitoringSettings = monitoringSettings($pdo);
$schedulerMode = monitoringNormalizeSchedulerMode((string) ($monitoringSettings["scheduler_mode"] ?? ""));
$schedulerModes = monitoringSchedulerModes();
$lastMonitoringRun = monitoringLastRun($pdo);
$monitoringLockState = monitoringLockState();
$monitoringIntervalMinutes = max(1, (int) ($monitoringSettings["check_interval_minutes"] ?? 5));
$lastMonitoringRunStartedMs = $lastMonitoringRun ? (int) strtotime($lastMonitoringRun["started_at"]) * 1000 : 0;
$monitoringStaleAfter = max(1, (int) ($monitoringSettings["stale_after_minutes"] ?? 10));
$monitoringIsBroken = false;

if (!$lastMonitoringRun) {
    $monitoringIsBroken = true;
} else {
    $monitoringRunAgeMinutes = max(0, (int) floor((time() - strtotime($lastMonitoringRun["started_at"])) / 60));
    $monitoringIsBroken = (($lastMonitoringRun["status"] ?? "") === "failed") || $monitoringRunAgeMinutes > $monitoringStaleAfter;
}

$openAlertCounts = $pdo->query("
    SELECT severity, COUNT(*) AS count
    FROM monitoring_alerts
    WHERE is_resolved = 0
    GROUP BY severity
")->fetchAll();
$openAlertTotal = 0;
foreach ($openAlertCounts as $alertCount) {
    $openAlertTotal += (int) $alertCount["count"];
}

function dashboardFormatBytes(int $bytes): string
{
    if ($bytes >= 1073741824) {
        return rtrim(rtrim(number_format($bytes / 1073741824, 1), "0"), ".") . " GB";
    }
    if ($bytes >= 1048576) {
        return rtrim(rtrim(number_format($bytes / 1048576, 1), "0"), ".") . " MB";
    }
    if ($bytes >= 1024) {
        return rtrim(rtrim(number_format($bytes / 1024, 1), "0"), ".") . " KB";
    }
    return $bytes . " B";
}

function dashboardScalar(PDO $pdo, string $sql, array $params = []): int
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) ($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

$resourceStorageBytes = dashboardScalar($pdo, "
    SELECT COALESCE(SUM(file_size), 0)
    FROM resource_files
    WHERE is_deleted = 0
");
$localResourceStorageBytes = dashboardScalar($pdo, "
    SELECT COALESCE(SUM(file_size), 0)
    FROM resource_files
    WHERE is_deleted = 0 AND storage_driver = 'local'
");
$driveStorageBytes = dashboardScalar($pdo, "SELECT COALESCE(SUM(file_size), 0) FROM drive_files");
$usedStorageBytes = $resourceStorageBytes + $driveStorageBytes;
$storageDriver = strtolower((string) STORAGE_DEFAULT_DRIVER);
$storageModeLabel = $storageDriver === "ftp" ? "File Server" : "Local Storage";
$storageModeDetail = $storageDriver === "ftp"
    ? ((string) FTP_STORAGE_HOST !== "" ? FTP_STORAGE_HOST : "FTP storage")
    : STORAGE_LOCAL_ROOT;
$localStorageRoot = STORAGE_LOCAL_ROOT;
$localDiskTotal = is_dir($localStorageRoot) ? (int) @disk_total_space($localStorageRoot) : 0;
$localDiskFree = is_dir($localStorageRoot) ? (int) @disk_free_space($localStorageRoot) : 0;
$storageTotalBytes = $localDiskTotal > 0 ? $localDiskTotal : max((int) RESOURCE_PROJECT_QUOTA_BYTES, $usedStorageBytes, 1);
$storageFreeBytes = $localDiskTotal > 0 ? $localDiskFree : max(0, $storageTotalBytes - $usedStorageBytes);
$storageUsedPercent = $storageTotalBytes > 0 ? min(100, round(($usedStorageBytes / $storageTotalBytes) * 100, 1)) : 0;
$storageFreePercent = $storageTotalBytes > 0 ? max(0, min(100, round(($storageFreeBytes / $storageTotalBytes) * 100, 1))) : 0;
$storageUsedLabel = dashboardFormatBytes($usedStorageBytes);
$localStorageUsedLabel = dashboardFormatBytes($localResourceStorageBytes);
$storageFreeLabel = dashboardFormatBytes($storageFreeBytes);
$storageTotalLabel = dashboardFormatBytes($storageTotalBytes);
$localStorageUsedPercent = $storageTotalBytes > 0 ? min(100, round(($localResourceStorageBytes / $storageTotalBytes) * 100, 1)) : 0;

$modeCounts = ["local" => 0, "ftp" => 0];
try {
    $storageModeRows = $pdo->query("
        SELECT storage_driver, COUNT(*) AS count
        FROM resource_files
        WHERE is_deleted = 0
        GROUP BY storage_driver
    ")->fetchAll();
    foreach ($storageModeRows as $row) {
        $mode = strtolower((string) $row["storage_driver"]);
        if (isset($modeCounts[$mode])) {
            $modeCounts[$mode] = (int) $row["count"];
        }
    }
} catch (Throwable $e) {
    // Older installs may not have resource storage metadata yet.
}

$loadTimeRows = $pdo->query("
    SELECT checked_at, response_time_ms
    FROM deployment_checks
    WHERE response_time_ms IS NOT NULL
    ORDER BY checked_at DESC, id DESC
    LIMIT 8
")->fetchAll();
$loadTimeRows = array_reverse($loadTimeRows);
$loadLabels = [];
$loadValues = [];
foreach ($loadTimeRows as $row) {
    $loadLabels[] = date("H:i", strtotime($row["checked_at"]));
    $loadValues[] = round(((int) $row["response_time_ms"]) / 1000, 2);
}
if (!$loadLabels) {
    $loadLabels = ["00:00", "03:00", "06:00", "09:00", "12:00", "15:00", "18:00", "21:00"];
    $loadValues = [0.8, 1.1, 0.9, 1.4, 1.2, 1.6, 1.3, 1.5];
}

$deployedCount = dashboardScalar($pdo, "
    SELECT COUNT(*)
    FROM projects p
    INNER JOIN (
        SELECT project_id, MAX(id) AS latest_check_id
        FROM deployment_checks
        GROUP BY project_id
    ) latest_dc ON latest_dc.project_id = p.project_id
    INNER JOIN deployment_checks dc ON dc.id = latest_dc.latest_check_id
    {$accessWhere}
    " . ($accessWhere ? " AND " : " WHERE ") . " dc.status = 'deployed'
", $accessParams);
$onlineCount = dashboardScalar($pdo, "
    SELECT COUNT(*)
    FROM projects p
    INNER JOIN (
        SELECT project_id, MAX(id) AS latest_check_id
        FROM deployment_checks
        GROUP BY project_id
    ) latest_dc ON latest_dc.project_id = p.project_id
    INNER JOIN deployment_checks dc ON dc.id = latest_dc.latest_check_id
    {$accessWhere}
    " . ($accessWhere ? " AND " : " WHERE ") . " dc.http_code BETWEEN 200 AND 399
", $accessParams);
$warningAlertCount = dashboardScalar($pdo, "SELECT COUNT(*) FROM monitoring_alerts WHERE is_resolved = 0 AND severity = 'warning'");
$criticalAlertCount = dashboardScalar($pdo, "SELECT COUNT(*) FROM monitoring_alerts WHERE is_resolved = 0 AND severity = 'critical'");
$warningStatusCount = dashboardScalar($pdo, "
    SELECT COUNT(*)
    FROM projects p
    INNER JOIN (
        SELECT project_id, MAX(id) AS latest_check_id
        FROM deployment_checks
        GROUP BY project_id
    ) latest_dc ON latest_dc.project_id = p.project_id
    INNER JOIN deployment_checks dc ON dc.id = latest_dc.latest_check_id
    {$accessWhere}
    " . ($accessWhere ? " AND " : " WHERE ") . " dc.status = 'warning'
", $accessParams);
$criticalStatusCount = dashboardScalar($pdo, "
    SELECT COUNT(*)
    FROM projects p
    INNER JOIN (
        SELECT project_id, MAX(id) AS latest_check_id
        FROM deployment_checks
        GROUP BY project_id
    ) latest_dc ON latest_dc.project_id = p.project_id
    INNER JOIN deployment_checks dc ON dc.id = latest_dc.latest_check_id
    {$accessWhere}
    " . ($accessWhere ? " AND " : " WHERE ") . " dc.status = 'error'
", $accessParams);
$severityLabels = ["Deployed", "Online", "Warnings", "Critical"];
$severityValues = [
    $deployedCount,
    $onlineCount,
    $warningStatusCount + $warningAlertCount,
    $criticalStatusCount + $criticalAlertCount,
];
if (array_sum($severityValues) === 0) {
    $severityValues = [1, 0, 0, 0];
}
$severityChartColors = ["#4F9CF9", "#22c55e", "#facc15", "#dc2626"];

$durationMs = isset($lastMonitoringRun["duration_ms"]) ? (int) $lastMonitoringRun["duration_ms"] : 0;
$durationLabel = $durationMs > 0 ? round($durationMs / 1000, 2) . "s" : "-";
$checkedLabel = (string) (int) ($lastMonitoringRun["checked_count"] ?? 0);
$errorLabel = (string) (int) ($lastMonitoringRun["error_count"] ?? 0);
$queueLabel = $monitoringLockState["label"] ?? "Idle";
$schedulerLabel = $schedulerModes[$schedulerMode]["label"] ?? "Manual";
?>

<section
  class="nucleus-bento"
  data-monitoring-scheduler
  data-scheduler-mode="<?php echo htmlspecialchars($schedulerMode); ?>"
  data-monitoring-interval-minutes="<?php echo $monitoringIntervalMinutes; ?>"
  data-monitoring-lock-state="<?php echo htmlspecialchars($monitoringLockState["state"] ?? "idle"); ?>"
  data-monitoring-last-run-ms="<?php echo $lastMonitoringRunStartedMs; ?>"
  data-can-run-monitoring="<?php echo $canRunMonitoringNow ? "1" : "0"; ?>"
>
  <article class="bento-card tile-stat">
    <div>
      <p>Total Projects</p>
      <strong><?php echo $totalWebsites; ?></strong>
    </div>
    <span class="icon-tile">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path></svg>
    </span>
  </article>

  <article class="bento-card tile-stat">
    <div>
      <p>Subjects</p>
      <strong><?php echo $totalFolders; ?></strong>
    </div>
    <span class="icon-tile">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path></svg>
    </span>
  </article>

  <article class="bento-card tile-stat">
    <div>
      <p>Users</p>
      <strong><?php echo $totalUsers; ?></strong>
    </div>
    <span class="icon-tile">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
    </span>
  </article>

  <article class="bento-card tile-stat">
    <div>
      <p>Updated Today</p>
      <strong><?php echo $updatedToday; ?></strong>
    </div>
    <span class="icon-tile">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path></svg>
    </span>
  </article>

  <article class="bento-card tile-load">
    <div class="card-heading">
      <div>
        <h2>Load Time</h2>
        <p>Latest response time checks</p>
      </div>
      <div class="health-actions">
        <span class="health-pill <?php echo $monitoringIsBroken ? "is-warning" : "is-healthy"; ?>"><?php echo $monitoringIsBroken ? "Attention" : "Healthy"; ?></span>
        <button type="button" class="help-icon-button" data-monitoring-status-help aria-label="Show monitoring status details">?</button>
      </div>
    </div>
    <div class="chart-frame">
      <canvas id="nucleusLoadChart"></canvas>
    </div>
  </article>

  <article class="bento-card tile-errors">
    <div class="card-heading">
      <div>
        <h2>Error Types</h2>
        <p><?php echo $openAlertTotal; ?> open alert<?php echo $openAlertTotal === 1 ? "" : "s"; ?></p>
      </div>
      <a href="dashboard.php?page=alerts" class="mini-link">Alerts</a>
    </div>
    <div class="donut-frame">
      <canvas id="nucleusErrorChart"></canvas>
    </div>
    <div class="error-stats">
      <?php foreach ($severityLabels as $index => $label): ?>
      <div>
        <span style="background: <?php echo htmlspecialchars($severityChartColors[$index]); ?>"></span>
        <p><?php echo htmlspecialchars($label); ?></p>
        <strong><?php echo (int) $severityValues[$index]; ?></strong>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if ($canRunMonitoringNow): ?>
    <button type="button" data-run-monitoring-now class="diagnostics-button">Run Diagnostics</button>
    <?php else: ?>
    <a href="dashboard.php?page=alerts" class="diagnostics-button">View Diagnostics</a>
    <?php endif; ?>
  </article>

  <article class="bento-card tile-metric">
    <p>Last Run</p>
    <strong><?php echo $lastMonitoringRun ? htmlspecialchars(formatNucleusDateTime($lastMonitoringRun["started_at"])) : "Never"; ?></strong>
  </article>
  <article class="bento-card tile-metric">
    <p>Duration</p>
    <strong><?php echo htmlspecialchars($durationLabel); ?></strong>
  </article>
  <article class="bento-card tile-metric">
    <p>Checked</p>
    <strong><?php echo htmlspecialchars($checkedLabel); ?></strong>
  </article>
  <article class="bento-card tile-metric">
    <p>Errors</p>
    <strong><?php echo htmlspecialchars($errorLabel); ?></strong>
  </article>
  <article class="bento-card tile-metric">
    <p>Queue Lock</p>
    <strong><?php echo htmlspecialchars($queueLabel); ?></strong>
  </article>
  <article class="bento-card tile-metric">
    <p>Scheduler</p>
    <strong><?php echo htmlspecialchars($schedulerLabel); ?></strong>
  </article>

  <article class="bento-card tile-storage">
    <div class="card-heading">
      <div>
        <h2>Cloud Storage Availability</h2>
        <p><?php echo htmlspecialchars($storageFreeLabel); ?> free of <?php echo htmlspecialchars($storageTotalLabel); ?></p>
      </div>
      <span class="health-pill is-healthy"><?php echo htmlspecialchars($storageFreePercent); ?>% Free</span>
    </div>
    <div class="storage-bars" aria-label="Cloud storage availability">
      <div>
        <div class="storage-bar-label">
          <span>Available storage</span>
          <strong><?php echo htmlspecialchars($storageFreeLabel); ?></strong>
        </div>
        <div class="storage-bar">
          <span style="width: <?php echo htmlspecialchars((string) $storageFreePercent); ?>%"></span>
        </div>
      </div>
      <div class="storage-faded">
        <div class="storage-bar-label">
          <span>Local storage used</span>
          <strong><?php echo htmlspecialchars($localStorageUsedLabel); ?></strong>
        </div>
        <div class="storage-bar">
          <span style="width: <?php echo htmlspecialchars((string) $localStorageUsedPercent); ?>%"></span>
        </div>
      </div>
    </div>
  </article>

  <article class="bento-card tile-storage-mode">
    <div class="card-heading">
      <div>
        <h2>Storage Server</h2>
        <p>Current file storage route</p>
      </div>
    </div>
    <div class="server-mode-card">
      <span class="<?php echo $storageDriver === "ftp" ? "is-server" : "is-local"; ?>">
        <?php echo htmlspecialchars($storageModeLabel); ?>
      </span>
      <strong><?php echo $storageDriver === "ftp" ? "Actual file server" : "Local disk"; ?></strong>
      <p><?php echo htmlspecialchars($storageModeDetail); ?></p>
    </div>
    <div class="mode-split">
      <div>
        <p>Local files</p>
        <strong><?php echo (int) $modeCounts["local"]; ?></strong>
      </div>
      <div>
        <p>File server files</p>
        <strong><?php echo (int) $modeCounts["ftp"]; ?></strong>
      </div>
    </div>
  </article>
</section>

<style>
  #pageContent {
    background:
      radial-gradient(circle at 0 0, rgba(79, 156, 249, 0.13), transparent 24rem),
      linear-gradient(135deg, #f8fafc 0%, #eef6ff 100%);
  }
  .nucleus-bento {
    display: grid;
    grid-template-columns: repeat(12, minmax(0, 1fr));
    gap: clamp(0.55rem, 0.85vw, 0.75rem);
    width: 100%;
    max-width: none;
    min-height: 100%;
    align-content: stretch;
    margin: 0;
  }
  .bento-card {
    min-width: 0;
    overflow: hidden;
    border: 1px solid rgba(191, 219, 254, 0.9);
    border-radius: 0.5rem;
    background: rgba(255, 255, 255, 0.95);
    box-shadow: 0 18px 42px rgba(4, 56, 115, 0.08);
  }
  .tile-stat {
    grid-column: span 3;
    min-height: 6.25rem;
    padding: 1rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.85rem;
  }
  .tile-stat p,
  .tile-metric p {
    color: #64748b;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0;
    text-transform: uppercase;
  }
  .tile-stat strong {
    display: block;
    margin-top: 0.25rem;
    color: #043873;
    font-size: 1.75rem;
    line-height: 1.1;
  }
  .icon-tile {
    width: 2.75rem;
    height: 2.75rem;
    flex: 0 0 auto;
    border-radius: 0.5rem;
    display: grid;
    place-items: center;
    color: #043873;
    background: #e0f2fe;
  }
  .icon-tile svg {
    width: 1.35rem;
    height: 1.35rem;
  }
  .tile-load {
    grid-column: span 8;
    min-height: 18rem;
    padding: 1.1rem;
  }
  .tile-errors {
    grid-column: span 4;
    min-height: 18rem;
    padding: 1.1rem;
  }
  .tile-storage {
    grid-column: span 8;
    min-height: 7.25rem;
    padding: 0.85rem;
  }
  .tile-storage-mode {
    grid-column: span 4;
    min-height: 7.25rem;
    padding: 0.85rem;
  }
  .card-heading {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 0.75rem;
  }
  .card-heading h2 {
    color: #043873;
    font-size: 1rem;
    font-weight: 800;
    line-height: 1.2;
  }
  .card-heading p {
    margin-top: 0.15rem;
    color: #64748b;
    font-size: 0.875rem;
  }
  .mini-link {
    color: #043873;
    font-size: 0.75rem;
    font-weight: 800;
  }
  .mini-link:hover {
    color: #4F9CF9;
  }
  .health-pill {
    border-radius: 999px;
    padding: 0.3rem 0.65rem;
    font-size: 0.75rem;
    font-weight: 800;
  }
  .health-pill.is-healthy {
    background: #dcfce7;
    color: #166534;
  }
  .health-pill.is-warning {
    background: #fef3c7;
    color: #92400e;
  }
  .health-actions {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
  }
  .help-icon-button {
    width: 1.65rem;
    height: 1.65rem;
    border: 1px solid #bfdbfe;
    border-radius: 999px;
    background: #ffffff;
    color: #043873;
    font-size: 0.8rem;
    font-weight: 900;
    line-height: 1;
    display: inline-grid;
    place-items: center;
    transition: background-color 150ms ease, border-color 150ms ease, color 150ms ease;
  }
  .help-icon-button:hover {
    border-color: #4F9CF9;
    background: #e0f2fe;
  }
  .chart-frame {
    position: relative;
    height: 13rem;
  }
  .donut-frame {
    position: relative;
    width: min(100%, 10.5rem);
    height: 9rem;
    margin: 0 auto;
  }
  .chart-frame canvas,
  .donut-frame canvas {
    width: 100% !important;
    height: 100% !important;
  }
  .error-stats {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.45rem;
    margin-top: 0.65rem;
  }
  .error-stats div {
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: center;
    gap: 0.4rem;
    border: 1px solid #e2e8f0;
    border-radius: 0.5rem;
    background: #f8fafc;
    padding: 0.5rem 0.6rem;
  }
  .error-stats span {
    width: 0.55rem;
    height: 0.55rem;
    border-radius: 999px;
  }
  .error-stats p,
  .error-stats strong {
    color: #334155;
    font-size: 0.75rem;
    line-height: 1;
  }
  .diagnostics-button {
    margin-top: 0.75rem;
    display: inline-flex;
    width: 100%;
    align-items: center;
    justify-content: center;
    border-radius: 0.5rem;
    background: #043873;
    padding: 0.65rem 0.85rem;
    color: #ffffff;
    font-size: 0.875rem;
    font-weight: 800;
    transition: background-color 150ms ease, opacity 150ms ease;
  }
  .diagnostics-button:hover {
    background: #4F9CF9;
  }
  .diagnostics-button:disabled {
    cursor: wait;
    opacity: 0.65;
  }
  .storage-bars {
    display: grid;
    gap: 0.45rem;
  }
  .storage-bar-label {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 0.25rem;
    color: #64748b;
    font-size: 0.8rem;
    font-weight: 700;
  }
  .storage-bar-label strong {
    color: #043873;
  }
  .storage-bar {
    height: 0.55rem;
    overflow: hidden;
    border-radius: 999px;
    background: #dbeafe;
  }
  .storage-bar span {
    display: block;
    height: 100%;
    min-width: 0.25rem;
    border-radius: inherit;
    background: linear-gradient(90deg, #043873, #4F9CF9);
  }
  .storage-faded {
    opacity: 0.55;
  }
  .storage-faded .storage-bar {
    background: #e2e8f0;
  }
  .storage-faded .storage-bar span {
    background: #64748b;
  }
  .server-mode-card {
    border: 1px solid #dbeafe;
    border-radius: 0.5rem;
    background: #f8fafc;
    padding: 0.6rem;
  }
  .server-mode-card span {
    display: inline-flex;
    border-radius: 999px;
    padding: 0.2rem 0.5rem;
    font-size: 0.72rem;
    font-weight: 800;
  }
  .server-mode-card span.is-server {
    background: #dcfce7;
    color: #166534;
  }
  .server-mode-card span.is-local {
    background: #e0f2fe;
    color: #043873;
  }
  .server-mode-card strong {
    display: block;
    margin-top: 0.35rem;
    color: #043873;
    font-size: 1rem;
  }
  .server-mode-card p {
    margin-top: 0.2rem;
    color: #64748b;
    font-size: 0.78rem;
    overflow-wrap: anywhere;
  }
  .mode-split {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.5rem;
    margin-top: 0.45rem;
  }
  .mode-split div {
    border: 1px solid #e2e8f0;
    border-radius: 0.5rem;
    padding: 0.4rem 0.5rem;
    background: #ffffff;
  }
  .mode-split p {
    color: #64748b;
    font-size: 0.72rem;
    font-weight: 700;
  }
  .mode-split strong {
    display: block;
    margin-top: 0.2rem;
    color: #043873;
    font-size: 1rem;
  }
  .tile-metric {
    grid-column: span 2;
    min-height: 5.25rem;
    padding: 0.75rem;
  }
  .tile-metric strong {
    display: block;
    margin-top: 0.35rem;
    color: #043873;
    font-size: 1rem;
    line-height: 1.25;
  }
  @media (max-width: 1180px) {
    .tile-stat,
    .tile-metric {
      grid-column: span 6;
    }
    .tile-load,
    .tile-errors,
    .tile-storage,
    .tile-storage-mode {
      grid-column: span 12;
    }
  }
  @media (max-width: 720px) {
    .nucleus-bento {
      grid-template-columns: 1fr;
    }
    .tile-stat,
    .tile-load,
    .tile-errors,
    .tile-storage,
    .tile-storage-mode,
    .tile-metric {
      grid-column: 1 / -1;
    }
    .chart-frame {
      height: 13rem;
    }
  }
</style>

<script>
(() => {
  function showMonitoringStatusHelp() {
    const html = `
      <div class="text-left text-sm leading-6 text-slate-600">
        <p><strong class="text-slate-800">Healthy</strong> means the monitoring queue has completed recently, the last run did not fail, and the latest run is within the configured stale window.</p>
        <p class="mt-3"><strong class="text-slate-800">Attention</strong> means no run has completed yet, the latest run failed, or the latest run is older than the configured stale-after threshold.</p>
        <p class="mt-3"><strong class="text-slate-800">Project health badges</strong> also consider response status, response time, successful checks, consecutive failures, and open monitoring alerts.</p>
      </div>
    `;
    if (window.Swal) {
      Swal.fire({
        title: 'Monitoring Status',
        html,
        icon: 'info',
        confirmButtonColor: '#3085d6'
      });
      return;
    }
    alert('Healthy: recent successful monitoring run. Attention: missing, failed, or stale monitoring run.');
  }

  document.querySelectorAll('[data-monitoring-status-help]').forEach(button => {
    button.addEventListener('click', showMonitoringStatusHelp);
  });

  const loadChart = () => new Promise((resolve, reject) => {
    if (window.Chart) {
      resolve();
      return;
    }
    const script = document.createElement('script');
    script.src = 'https://cdn.jsdelivr.net/npm/chart.js';
    script.onload = resolve;
    script.onerror = reject;
    document.head.appendChild(script);
  });

  loadChart().then(() => {
    const loadCanvas = document.getElementById('nucleusLoadChart');
    const errorCanvas = document.getElementById('nucleusErrorChart');
    if (!loadCanvas || !errorCanvas || !window.Chart) return;

    Chart.defaults.font.family = 'Inter, ui-sans-serif, system-ui, sans-serif';
    Chart.defaults.color = '#64748b';
    Chart.defaults.responsive = true;
    Chart.defaults.maintainAspectRatio = false;

    new Chart(loadCanvas, {
      type: 'line',
      data: {
        labels: <?php echo json_encode($loadLabels); ?>,
        datasets: [{
          label: 'Response time',
          data: <?php echo json_encode($loadValues); ?>,
          borderColor: '#043873',
          backgroundColor: 'rgba(79, 156, 249, 0.16)',
          borderWidth: 3,
          tension: 0.4,
          pointRadius: 3,
          fill: true
        }]
      },
      options: {
        plugins: { legend: { display: false } },
        scales: {
          y: { beginAtZero: true, border: { display: false }, grid: { color: 'rgba(148, 163, 184, 0.2)' }, ticks: { callback: value => `${value}s` } },
          x: { border: { display: false }, grid: { display: false } }
        }
      }
    });

    new Chart(errorCanvas, {
      type: 'doughnut',
      data: {
        labels: <?php echo json_encode($severityLabels); ?>,
        datasets: [{
          data: <?php echo json_encode($severityValues); ?>,
          backgroundColor: <?php echo json_encode($severityChartColors); ?>,
          borderColor: '#ffffff',
          borderWidth: 4,
          hoverOffset: 6,
          cutout: '68%'
        }]
      },
      options: { plugins: { legend: { display: false } } }
    });
  }).catch(error => console.error('Chart.js failed to load', error));
})();
</script>
