# Demo Script

1. Open Nucleus and log in as `admin` / `admin123`.
2. Show Dashboard: project counts, monitoring summary, recent activity, and project status cards.
3. Open Projects: server-side table, project details, and read-only deployment health.
4. Open Subjects: subject grouping and project organization.
5. Open Files as admin or handler: upload a safe file, download it, rename it, then delete it.
6. Open Alerts: filter unresolved alerts and resolve selected alerts as admin.
7. Open Settings: run connection diagnostics, confirm storage/lock/log status, and trigger Manual fallback run.
8. Disable a non-critical feature flag, open the tab, and show the maintenance card. Re-enable it afterward.
9. Explain that monitoring is read-only and does not deploy code.

Demo reset helpers:

```text
php handlers/seed_demo_monitoring.php
php handlers/cleanup_monitoring_data.php retention_days=30
```

Keep `.env` values private during the demo.
