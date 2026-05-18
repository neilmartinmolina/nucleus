-- Intelligent monitoring scheduler support.
-- This keeps Nucleus read-only: queue workers read public endpoints and persist observations.

ALTER TABLE project_status
    ADD COLUMN IF NOT EXISTS last_checked_at TIMESTAMP NULL AFTER checked_at,
    ADD COLUMN IF NOT EXISTS last_successful_check_at TIMESTAMP NULL AFTER last_checked_at,
    ADD COLUMN IF NOT EXISTS consecutive_failures INT NOT NULL DEFAULT 0 AFTER last_successful_check_at,
    ADD COLUMN IF NOT EXISTS status_source VARCHAR(50) NULL AFTER consecutive_failures,
    ADD COLUMN IF NOT EXISTS response_time_ms INT NULL AFTER status_source;

UPDATE project_status ps
SET ps.last_checked_at = COALESCE(ps.last_checked_at, ps.checked_at)
WHERE ps.checked_at IS NOT NULL;

UPDATE project_status ps
SET ps.last_successful_check_at = COALESCE(
    ps.last_successful_check_at,
    (SELECT MAX(dc.checked_at) FROM deployment_checks dc WHERE dc.project_id = ps.project_id AND dc.status = 'deployed')
);

UPDATE project_status ps
SET ps.consecutive_failures = (
    SELECT COUNT(*)
    FROM deployment_checks dcf
    WHERE dcf.project_id = ps.project_id
      AND dcf.status IN ('warning', 'error')
      AND dcf.checked_at > COALESCE(
          (SELECT MAX(dcs.checked_at) FROM deployment_checks dcs WHERE dcs.project_id = ps.project_id AND dcs.status = 'deployed'),
          '1970-01-01'
      )
);

UPDATE project_status ps
LEFT JOIN deployment_checks dc ON dc.id = (
    SELECT id FROM deployment_checks WHERE project_id = ps.project_id ORDER BY checked_at DESC, id DESC LIMIT 1
)
SET ps.status_source = COALESCE(ps.status_source, dc.status_source),
    ps.response_time_ms = COALESCE(ps.response_time_ms, dc.response_time_ms);

CREATE TABLE IF NOT EXISTS monitoring_runs (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    finished_at TIMESTAMP NULL,
    duration_ms INT NULL,
    batch_size INT NOT NULL DEFAULT 10,
    checked_count INT NOT NULL DEFAULT 0,
    skipped_count INT NOT NULL DEFAULT 0,
    error_count INT NOT NULL DEFAULT 0,
    status ENUM('running', 'completed', 'failed', 'skipped') NOT NULL DEFAULT 'running',
    message TEXT NULL,
    INDEX idx_monitoring_runs_started (started_at),
    INDEX idx_monitoring_runs_status_started (status, started_at)
);

CREATE TABLE IF NOT EXISTS monitoring_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO monitoring_settings (setting_key, setting_value)
VALUES
    ('scheduler_mode', 'manual'),
    ('scheduler_enabled', '0'),
    ('scheduler_interval_minutes', '2'),
    ('scheduler_batch_size', '3'),
    ('scheduler_force', '0'),
    ('lock_timeout_seconds', '300'),
    ('check_interval_minutes', '2'),
    ('stale_after_minutes', '10'),
    ('failure_threshold', '3'),
    ('batch_size', '3'),
    ('response_slow_ms', '3000'),
    ('retention_days', '30')
ON DUPLICATE KEY UPDATE setting_value = setting_value;

CREATE INDEX IF NOT EXISTS idx_project_status_scheduler ON project_status (status, last_checked_at, consecutive_failures);
CREATE INDEX IF NOT EXISTS idx_project_status_last_checked ON project_status (last_checked_at);
CREATE INDEX IF NOT EXISTS idx_project_status_last_success ON project_status (last_successful_check_at);
