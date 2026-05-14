# Monitoring Setup

Monitoring is read-only. It checks project reachability and optional metadata endpoints, writes to `deployment_checks`, mirrors current state in `project_status`, and raises `monitoring_alerts`.

Modes:

- `manual`: administrators can run the queue from Settings.
- `external_cron`: schedule `php handlers/run_monitoring_queue.php batch=10`.
- `disabled`: automatic scheduling is off, but the admin manual fallback remains available.

Remote HTTP queue access requires `MONITORING_QUEUE_TOKEN` and should use:

```text
handlers/run_monitoring_queue.php?token=YOUR_TOKEN
```

Cleanup:

```text
php handlers/cleanup_monitoring_data.php retention_days=30
```

The queue uses `storage/locks/monitoring.lock` to prevent overlapping runs and logs operational details to `storage/logs/monitoring.log`.
