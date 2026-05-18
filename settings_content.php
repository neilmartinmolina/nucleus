<?php
require_once __DIR__ . "/includes/core.php";

if (!isAuthenticated()) {
    echo "<div class=\"p-8 text-center\"><p class=\"text-slate-600\">Please login to continue</p></div>";
    exit;
}

$roleManager = new RoleManager($pdo);
$isAdmin = isAdminLike();
$monitoringSettings = monitoringSettings($pdo);
$schedulerMode = monitoringNormalizeSchedulerMode((string) ($monitoringSettings["scheduler_mode"] ?? ""));
$schedulerModes = monitoringSchedulerModes();
$schedulerStatus = monitoringSchedulerStatus($pdo, $isAdmin);
$schedulerEnabled = !empty($monitoringSettings["scheduler_enabled"]);
$schedulerIntervalMinutes = max(1, (int) ($monitoringSettings["scheduler_interval_minutes"] ?? 2));
$schedulerBatchSize = max(1, (int) ($monitoringSettings["scheduler_batch_size"] ?? 3));
$schedulerForce = !empty($monitoringSettings["scheduler_force"]);
$lockTimeoutSeconds = max(60, (int) ($monitoringSettings["lock_timeout_seconds"] ?? 300));
$lastRun = monitoringLastRun($pdo);
$lockState = monitoringLockState();
$cronCommand = monitoringCronCommand($monitoringSettings);
$latestMonitoringErrors = $pdo->query("
    SELECT id, status, message, error_count, started_at
    FROM monitoring_runs
    WHERE status = 'failed' OR error_count > 0
    ORDER BY started_at DESC, id DESC
    LIMIT 3
")->fetchAll();
$features = $pdo->query("
    SELECT ff.*, u.fullName AS updated_by_name
    FROM feature_flags ff
    LEFT JOIN users u ON u.userId = ff.updated_by
    ORDER BY COALESCE(ff.feature_group, 'Other'), ff.feature_name
")->fetchAll();

function settingsBadge(bool $ok): string
{
    return $ok
        ? "bg-emerald-50 text-emerald-700 ring-emerald-600/20"
        : "bg-amber-50 text-amber-700 ring-amber-600/20";
}
?>
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
  <div>
    <h1 class="text-2xl font-bold text-slate-800">Settings</h1>
    <p class="text-sm text-slate-500">System configuration, feature controls, and safe connection diagnostics</p>
  </div>
  <?php if ($isAdmin): ?>
  <button type="button" data-test-connections class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800">Run Connection Tests</button>
  <?php endif; ?>
</div>

<div class="space-y-6">
  <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <h2 class="text-lg font-semibold text-slate-800">General Settings</h2>
    <dl class="mt-4 grid grid-cols-1 gap-4 text-sm md:grid-cols-3">
      <div class="rounded-lg border border-slate-100 bg-slate-50 p-4">
        <dt class="text-slate-500">Environment</dt>
        <dd class="mt-1 font-semibold text-slate-800"><?php echo htmlspecialchars(APP_ENV); ?></dd>
      </div>
      <div class="rounded-lg border border-slate-100 bg-slate-50 p-4">
        <dt class="text-slate-500">Database</dt>
        <dd class="mt-1 font-semibold text-slate-800"><?php echo htmlspecialchars(DB_NAME); ?></dd>
      </div>
      <div class="rounded-lg border border-slate-100 bg-slate-50 p-4">
        <dt class="text-slate-500">Timezone</dt>
        <dd class="mt-1 font-semibold text-slate-800"><?php echo htmlspecialchars(date_default_timezone_get()); ?></dd>
      </div>
    </dl>
  </section>

  <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
      <div>
        <h2 class="text-lg font-semibold text-slate-800">Monitoring Scheduler</h2>
        <p class="mt-1 text-sm text-slate-500">Choose how the global monitoring queue starts. Browser demo scheduling is for local/demo use; the manual fallback remains available in every mode.</p>
      </div>
      <?php if ($isAdmin): ?>
      <button type="button" data-run-monitoring-now class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">Run Monitoring Now</button>
      <?php endif; ?>
    </div>
    <?php if ($isAdmin): ?>
    <form method="POST" action="handlers/update_monitoring_settings.php" data-return-page="settings" class="mt-5 space-y-5">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">
      <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <label class="block rounded-lg border border-slate-100 bg-slate-50 p-4">
          <span class="flex items-start gap-3">
            <input type="radio" name="scheduler_mode" value="manual" <?php echo $schedulerMode === "manual" ? "checked" : ""; ?> class="mt-1 h-4 w-4 border-slate-300 text-cta">
            <span>
              <span class="block font-semibold text-slate-800">Manual only</span>
              <span class="mt-1 block text-xs text-slate-500">Only the manual button starts monitoring.</span>
            </span>
          </span>
        </label>
        <label class="block rounded-lg border border-slate-100 bg-slate-50 p-4">
          <span class="flex items-start gap-3">
            <input type="radio" name="scheduler_mode" value="browser_demo" <?php echo $schedulerMode === "browser_demo" ? "checked" : ""; ?> class="mt-1 h-4 w-4 border-slate-300 text-cta">
            <span>
              <span class="block font-semibold text-slate-800">Browser demo scheduler</span>
              <span class="mt-1 block text-xs text-slate-500">Admin dashboard tabs may call the protected handler on a timer.</span>
            </span>
          </span>
        </label>
        <label class="block rounded-lg border border-slate-100 bg-slate-50 p-4">
          <span class="flex items-start gap-3">
            <input type="radio" name="scheduler_mode" value="external_cron" <?php echo $schedulerMode === "external_cron" ? "checked" : ""; ?> class="mt-1 h-4 w-4 border-slate-300 text-cta">
            <span>
              <span class="block font-semibold text-slate-800">External cron/task scheduler</span>
              <span class="mt-1 block text-xs text-slate-500">Use server cron or Windows Task Scheduler; the browser will not auto-run.</span>
            </span>
          </span>
        </label>
      </div>
      <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <label class="flex items-center gap-3 rounded-lg border border-slate-100 bg-slate-50 p-4 text-sm font-medium text-slate-700">
          <input type="checkbox" name="scheduler_enabled" value="1" <?php echo $schedulerEnabled ? "checked" : ""; ?> class="h-4 w-4 rounded border-slate-300 text-cta">
          Enable selected scheduler mode
        </label>
        <label class="block rounded-lg border border-slate-100 bg-slate-50 p-4">
          <span class="text-xs font-medium uppercase text-slate-500">Interval minutes</span>
          <input type="number" min="1" max="1440" name="scheduler_interval_minutes" value="<?php echo $schedulerIntervalMinutes; ?>" class="mt-2 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none transition focus:border-cta focus:ring-2 focus:ring-cta/20">
        </label>
        <label class="block rounded-lg border border-slate-100 bg-slate-50 p-4">
          <span class="text-xs font-medium uppercase text-slate-500">Batch size</span>
          <input type="number" min="1" max="100" name="scheduler_batch_size" value="<?php echo $schedulerBatchSize; ?>" class="mt-2 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none transition focus:border-cta focus:ring-2 focus:ring-cta/20">
        </label>
        <label class="flex items-center gap-3 rounded-lg border border-slate-100 bg-slate-50 p-4 text-sm font-medium text-slate-700">
          <input type="checkbox" name="scheduler_force" value="1" <?php echo $schedulerForce ? "checked" : ""; ?> class="h-4 w-4 rounded border-slate-300 text-cta">
          Force queue selection
        </label>
        <label class="block rounded-lg border border-slate-100 bg-slate-50 p-4">
          <span class="text-xs font-medium uppercase text-slate-500">Lock timeout seconds</span>
          <input type="number" min="60" max="3600" name="lock_timeout_seconds" value="<?php echo $lockTimeoutSeconds; ?>" class="mt-2 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none transition focus:border-cta focus:ring-2 focus:ring-cta/20">
        </label>
        <label class="block rounded-lg border border-slate-100 bg-slate-50 p-4">
          <span class="text-xs font-medium uppercase text-slate-500">Stale after minutes</span>
          <input type="number" min="1" max="1440" name="stale_after_minutes" value="<?php echo (int) ($monitoringSettings["stale_after_minutes"] ?? 10); ?>" class="mt-2 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none transition focus:border-cta focus:ring-2 focus:ring-cta/20">
        </label>
        <label class="block rounded-lg border border-slate-100 bg-slate-50 p-4">
          <span class="text-xs font-medium uppercase text-slate-500">Failure threshold</span>
          <input type="number" min="1" max="25" name="failure_threshold" value="<?php echo (int) ($monitoringSettings["failure_threshold"] ?? 3); ?>" class="mt-2 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none transition focus:border-cta focus:ring-2 focus:ring-cta/20">
        </label>
        <label class="block rounded-lg border border-slate-100 bg-slate-50 p-4">
          <span class="text-xs font-medium uppercase text-slate-500">Slow response ms</span>
          <input type="number" min="100" max="60000" name="response_slow_ms" value="<?php echo (int) ($monitoringSettings["response_slow_ms"] ?? 3000); ?>" class="mt-2 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none transition focus:border-cta focus:ring-2 focus:ring-cta/20">
        </label>
      </div>
      <div class="text-right">
        <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white">Save Monitoring Settings</button>
      </div>
    </form>
    <?php endif; ?>
    <div class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-3 xl:grid-cols-6">
      <?php foreach ($monitoringSettings as $key => $value): ?>
      <div class="rounded-lg border border-slate-100 bg-slate-50 p-4">
        <p class="text-xs font-medium uppercase text-slate-500"><?php echo htmlspecialchars(str_replace("_", " ", $key)); ?></p>
        <p class="mt-1 text-sm font-semibold text-slate-800"><?php echo htmlspecialchars((string) $value); ?></p>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="mt-4 rounded-lg border border-slate-100 bg-slate-50 p-4 text-sm text-slate-600">
      Last queue run: <strong class="text-slate-800"><?php echo $lastRun ? htmlspecialchars(formatNucleusDateTime($lastRun["started_at"])) : "Never"; ?></strong>
      · Status: <strong class="text-slate-800"><?php echo htmlspecialchars($lastRun["status"] ?? "none"); ?></strong>
      · Lock: <strong class="text-slate-800"><?php echo htmlspecialchars($lockState["label"]); ?></strong>
      <span class="mt-1 block text-xs text-slate-500"><?php echo htmlspecialchars($lockState["message"] ?? ""); ?></span>
      <?php if ($isAdmin && (!empty($lockState["stale"]) || !empty($lockState["invalid"]))): ?>
      <form method="POST" action="handlers/clear_monitoring_lock.php" data-return-page="settings" class="mt-3">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">
        <button
          type="submit"
          class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800 transition hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-60"
        >
          Clear stale lock
        </button>
        <p class="mt-2 text-xs text-slate-500">
          Use this only when the lock is stale and auto-runs are blocked.
        </p>
      </form>
      <?php endif; ?>
    </div>
    <div class="mt-4 rounded-lg border border-slate-100 bg-slate-50 p-4 text-sm text-slate-600">
      <p><strong class="text-slate-800">Queue diagnostics:</strong> status <?php echo htmlspecialchars($lastRun["status"] ?? "none"); ?>, checked <?php echo (int) ($lastRun["checked_count"] ?? 0); ?>, errors <?php echo (int) ($lastRun["error_count"] ?? 0); ?>.</p>
      <?php if ($schedulerMode === "external_cron"): ?>
      <p class="mt-3 font-semibold text-slate-800">Cron / Task Scheduler</p>
      <code class="mt-2 block max-w-full overflow-x-auto rounded-lg bg-slate-900 px-3 py-2 text-xs text-white"><?php echo htmlspecialchars($cronCommand); ?></code>
      <p class="mt-2">Run this command every <?php echo $schedulerIntervalMinutes; ?> minute<?php echo $schedulerIntervalMinutes === 1 ? "" : "s"; ?>. On Windows, point Task Scheduler at PHP and pass <code>handlers/run_monitoring_queue.php batch=<?php echo $schedulerBatchSize; ?></code> as arguments.</p>
      <?php if ($schedulerStatus["queue_stale"]): ?>
      <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 font-medium text-amber-800">External scheduler appears inactive. Use manual run or switch to browser demo scheduler.</p>
      <?php endif; ?>
      <?php endif; ?>
      <?php if ($latestMonitoringErrors): ?>
      <div class="mt-3 space-y-2">
        <?php foreach ($latestMonitoringErrors as $errorRun): ?>
        <p class="rounded-lg border border-slate-200 bg-white p-3 text-xs text-slate-500">Run #<?php echo (int) $errorRun["id"]; ?>: <?php echo htmlspecialchars($errorRun["status"]); ?>, <?php echo (int) $errorRun["error_count"]; ?> errors, <?php echo htmlspecialchars($errorRun["message"] ?? "No message recorded."); ?></p>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </section>

  <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
    <div class="border-b border-slate-100 p-6">
      <h2 class="text-lg font-semibold text-slate-800">Feature Controls</h2>
      <p class="mt-1 text-sm text-slate-500">Disable tabs or content sections during construction. Direct route access is blocked server-side.</p>
    </div>
    <?php if (!$isAdmin): ?>
    <div class="p-6 text-sm text-slate-500">Only administrators can edit feature controls.</div>
    <?php else: ?>
    <form method="POST" action="handlers/update_feature_flags.php" data-return-page="settings">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">
      <div class="divide-y divide-slate-100">
        <?php foreach ($features as $feature): ?>
        <div class="grid gap-4 p-5 lg:grid-cols-[1fr_8rem_2fr_14rem] lg:items-center">
          <div>
            <input type="hidden" name="feature_key[]" value="<?php echo htmlspecialchars($feature["feature_key"]); ?>">
            <p class="font-semibold text-slate-800"><?php echo htmlspecialchars($feature["feature_name"]); ?></p>
            <p class="text-xs text-slate-500"><?php echo htmlspecialchars($feature["feature_key"]); ?> · <?php echo htmlspecialchars($feature["feature_group"] ?? "Other"); ?></p>
          </div>
          <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
            <input type="checkbox" name="is_enabled[]" value="<?php echo htmlspecialchars($feature["feature_key"]); ?>" <?php echo (int) $feature["is_enabled"] === 1 ? "checked" : ""; ?> class="h-4 w-4 rounded border-slate-300 text-cta">
            Enabled
          </label>
          <input type="text" name="maintenance_message[<?php echo htmlspecialchars($feature["feature_key"]); ?>]" value="<?php echo htmlspecialchars($feature["maintenance_message"] ?? ""); ?>" placeholder="Custom maintenance message" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none transition focus:border-cta focus:ring-2 focus:ring-cta/20">
          <p class="text-xs text-slate-500">
            <?php echo $feature["updated_at"] ? "Updated " . htmlspecialchars(formatNucleusDateTime($feature["updated_at"])) : "Never updated"; ?>
            <?php if (!empty($feature["updated_by_name"])): ?> by <?php echo htmlspecialchars($feature["updated_by_name"]); ?><?php endif; ?>
          </p>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="border-t border-slate-100 p-5 text-right">
        <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white">Save Feature Controls</button>
      </div>
    </form>
    <?php endif; ?>
  </section>

  <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h2 class="text-lg font-semibold text-slate-800">Connection Diagnostics</h2>
        <p class="mt-1 text-sm text-slate-500">Shows safe connection state only. Secrets, raw paths, and exception traces are never displayed.</p>
      </div>
    </div>
    <div id="connectionDiagnostics" class="mt-5 grid grid-cols-1 gap-4 xl:grid-cols-2">
      <div class="rounded-lg border border-slate-100 bg-slate-50 p-4 text-sm text-slate-500">Click Run Connection Tests to refresh diagnostics.</div>
    </div>
  </section>

  <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <h2 class="text-lg font-semibold text-slate-800">Storage Settings</h2>
    <dl class="mt-4 grid grid-cols-1 gap-4 text-sm md:grid-cols-3">
      <div class="rounded-lg border border-slate-100 bg-slate-50 p-4">
        <dt class="text-slate-500">Active Driver</dt>
        <dd class="mt-1 font-semibold text-slate-800"><?php echo htmlspecialchars(StorageManager::defaultDriver()); ?></dd>
      </div>
      <div class="rounded-lg border border-slate-100 bg-slate-50 p-4">
        <dt class="text-slate-500">Max File Size</dt>
        <dd class="mt-1 font-semibold text-slate-800"><?php echo number_format(RESOURCE_MAX_FILE_SIZE / 1024 / 1024, 1); ?> MB</dd>
      </div>
      <div class="rounded-lg border border-slate-100 bg-slate-50 p-4">
        <dt class="text-slate-500">Project Quota</dt>
        <dd class="mt-1 font-semibold text-slate-800"><?php echo number_format(RESOURCE_PROJECT_QUOTA_BYTES / 1024 / 1024, 1); ?> MB</dd>
      </div>
    </dl>
    <p class="mt-4 text-sm text-slate-500">Storage and FTP credentials are read from server-side config or .env. They are not editable from the web UI.</p>
  </section>
</div>

<script>
(function() {
  const button = document.querySelector('[data-test-connections]');
  const target = document.getElementById('connectionDiagnostics');
  if (!button || !target) return;

  function badge(ok) {
    return ok
      ? '<span class="inline-flex rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-600/20">Connected</span>'
      : '<span class="inline-flex rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 ring-1 ring-amber-600/20">Attention</span>';
  }

  function escapeHTML(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;'
    })[char]);
  }

  function row(label, value) {
    return `<div class="flex justify-between gap-4"><dt class="text-slate-500">${escapeHTML(label)}</dt><dd class="font-medium text-slate-800 text-right">${escapeHTML(value)}</dd></div>`;
  }

  function card(title, ok, rows) {
    return `<section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
      <div class="mb-4 flex items-center justify-between gap-3"><h3 class="font-semibold text-slate-800">${title}</h3>${badge(ok)}</div>
      <dl class="space-y-3 text-sm">${rows.join('')}</dl>
    </section>`;
  }

  button.addEventListener('click', async function() {
    button.disabled = true;
    target.innerHTML = '<div class="rounded-lg border border-slate-100 bg-slate-50 p-4 text-sm text-slate-500">Testing connections...</div>';
    try {
      const response = await fetch('handlers/test_connections.php', { headers: { 'Accept': 'application/json' } });
      const result = await response.json();
      if (!result.success) throw new Error(result.message || 'Connection diagnostics failed.');
      const db = result.database;
      const fs = result.file_storage;
      const ftp = result.ftp;
      const mon = result.monitoring;
      const git = result.git_metadata;
      target.innerHTML = [
        card('Database Server', !!db.connected, [
          row('Host', db.host),
          row('Database', db.name),
          row('User', db.user),
          row('Version', db.version || 'Unavailable')
        ]),
        card('File Storage', !!(fs.resources.writable && fs.logs.writable && fs.locks.writable), [
          row('Active Driver', fs.active_driver),
          row('Resources Writable', fs.resources.writable ? 'Yes' : 'No'),
          row('Logs Writable', fs.logs.writable ? 'Yes' : 'No'),
          row('Locks Writable', fs.locks.writable ? 'Yes' : 'No')
        ]),
        card('FTP File Server', !!ftp.connected || !ftp.configured, [
          row('Host', ftp.host),
          row('Port', ftp.port),
          row('Username', ftp.username),
          row('Root Path', ftp.root_path),
          row('Passive Mode', ftp.passive_mode ? 'Enabled' : 'Disabled'),
          row('Configured', ftp.configured ? 'Yes' : 'No')
        ]),
        card('Monitoring Queue', mon.last_status === 'completed' || mon.last_status === 'skipped', [
          row('Scheduler Mode', mon.scheduler_mode),
          row('Last Run', mon.last_run),
          row('Last Status', mon.last_status),
          row('Lock State', mon.lock_state.label),
          row('Lock Message', mon.lock_state.message),
          row('Cron', mon.cron_command)
        ]),
        card('Git / Deployment Metadata', true, [
          row('App URL', git.app_url),
          row('Webhook URL', git.webhook_url),
          row('Status', git.metadata_status)
        ])
      ].join('');
    } catch (err) {
      target.innerHTML = `<div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">${err.message}</div>`;
    } finally {
      button.disabled = false;
    }
  });
})();
</script>
