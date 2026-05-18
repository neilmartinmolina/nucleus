-- Nucleus 3NF database redesign
-- Creates the new database and normalized tables for subjects, projects,
-- project status, project membership, files, comments, notifications, and logs.

CREATE DATABASE IF NOT EXISTS nucleus
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE nucleus;

CREATE TABLE IF NOT EXISTS roles (
    role_id INT PRIMARY KEY AUTO_INCREMENT,
    role_name ENUM('superadmin', 'admin', 'handler', 'member', 'visitor') NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
    userId INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(255) NOT NULL UNIQUE,
    passwordHash VARCHAR(255) NOT NULL,
    fullName VARCHAR(255) NOT NULL,
    email VARCHAR(255) NULL UNIQUE,
    isVerified TINYINT(1) NOT NULL DEFAULT 1,
    email_verified_at TIMESTAMP NULL,
    email_verification_token VARCHAR(255) NULL,
    email_verification_expires_at TIMESTAMP NULL,
    email_verification_sent_at TIMESTAMP NULL,
    phone VARCHAR(50) NULL,
    department VARCHAR(255) NULL,
    bio TEXT NULL,
    role_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(role_id)
);

CREATE TABLE IF NOT EXISTS subjects (
    subject_id INT PRIMARY KEY AUTO_INCREMENT,
    subject_code VARCHAR(50) NOT NULL UNIQUE,
    subject_name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    archived_at TIMESTAMP NULL,
    FOREIGN KEY (created_by) REFERENCES users(userId) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS subject_members (
    subject_member_id INT PRIMARY KEY AUTO_INCREMENT,
    subject_id INT NOT NULL,
    userId INT NOT NULL,
    access_level ENUM('manager', 'contributor', 'viewer') NOT NULL DEFAULT 'manager',
    added_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE CASCADE,
    FOREIGN KEY (userId) REFERENCES users(userId) ON DELETE CASCADE,
    FOREIGN KEY (added_by) REFERENCES users(userId) ON DELETE SET NULL,
    UNIQUE KEY unique_subject_member (subject_id, userId)
);

CREATE TABLE IF NOT EXISTS subject_requests (
    request_id INT PRIMARY KEY AUTO_INCREMENT,
    requested_by INT NOT NULL,
    subject_code VARCHAR(50) NOT NULL,
    subject_name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    reviewed_by INT NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (requested_by) REFERENCES users(userId) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(userId) ON DELETE SET NULL,
    INDEX idx_subject_requests_status (status),
    INDEX idx_subject_requests_requested_by (requested_by)
);

CREATE TABLE IF NOT EXISTS subject_join_requests (
    join_request_id INT PRIMARY KEY AUTO_INCREMENT,
    subject_id INT NOT NULL,
    requested_by INT NOT NULL,
    message TEXT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    reviewed_by INT NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE CASCADE,
    FOREIGN KEY (requested_by) REFERENCES users(userId) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(userId) ON DELETE SET NULL,
    UNIQUE KEY unique_pending_subject_join (subject_id, requested_by, status),
    INDEX idx_subject_join_requests_status (status),
    INDEX idx_subject_join_requests_subject_status (subject_id, status),
    INDEX idx_subject_join_requests_requested_by (requested_by)
);

CREATE TABLE IF NOT EXISTS project_requests (
    request_id INT PRIMARY KEY AUTO_INCREMENT,
    requested_by INT NOT NULL,
    subject_id INT NOT NULL,
    project_name VARCHAR(255) NOT NULL,
    public_url VARCHAR(2048) NOT NULL,
    github_repo_url VARCHAR(2048) NOT NULL,
    github_repo_name VARCHAR(255) NULL,
    requested_version VARCHAR(50) NOT NULL DEFAULT '1.0.0',
    message TEXT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    reviewed_by INT NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (requested_by) REFERENCES users(userId) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(userId) ON DELETE SET NULL,
    INDEX idx_project_requests_status (status),
    INDEX idx_project_requests_subject_status (subject_id, status),
    INDEX idx_project_requests_requested_by (requested_by)
);

CREATE TABLE IF NOT EXISTS projects (
    project_id INT PRIMARY KEY AUTO_INCREMENT,
    subject_id INT NULL,
    project_name VARCHAR(255) NOT NULL,
    public_url VARCHAR(2048) NULL,
    github_repo_url VARCHAR(2048) NULL,
    github_repo_name VARCHAR(255) NULL,
    deployment_mode ENUM('hostinger_git', 'custom_webhook') NOT NULL DEFAULT 'hostinger_git',
    deploy_path VARCHAR(2048) NULL,
    webhook_secret VARCHAR(128) NULL,
    current_version VARCHAR(50) NOT NULL DEFAULT '1.0.0',
    owner_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    saved_at TIMESTAMP NULL,
    last_updated_at TIMESTAMP NULL,
    FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE SET NULL,
    FOREIGN KEY (owner_id) REFERENCES users(userId) ON DELETE RESTRICT,
    INDEX idx_projects_subject (subject_id),
    INDEX idx_projects_owner (owner_id),
    INDEX idx_projects_repo_name (github_repo_name)
);

CREATE TABLE IF NOT EXISTS project_status (
    status_id INT PRIMARY KEY AUTO_INCREMENT,
    project_id INT NOT NULL UNIQUE,
    status ENUM('initializing', 'building', 'deployed', 'warning', 'error') NOT NULL DEFAULT 'initializing',
    last_commit VARCHAR(255) NULL,
    status_note TEXT NULL,
    checked_at TIMESTAMP NULL,
    last_checked_at TIMESTAMP NULL,
    last_successful_check_at TIMESTAMP NULL,
    consecutive_failures INT NOT NULL DEFAULT 0,
    status_source VARCHAR(50) NULL,
    response_time_ms INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(userId) ON DELETE SET NULL,
    INDEX idx_project_status_scheduler (status, last_checked_at, consecutive_failures),
    INDEX idx_project_status_last_checked (last_checked_at),
    INDEX idx_project_status_last_success (last_successful_check_at)
);

CREATE TABLE IF NOT EXISTS deployment_checks (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    project_id INT NOT NULL,
    checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('initializing', 'building', 'deployed', 'warning', 'error') NOT NULL,
    http_code INT NULL,
    response_time_ms INT NULL,
    status_source VARCHAR(50) NOT NULL,
    error_message TEXT NULL,
    version VARCHAR(100) NULL,
    commit_hash VARCHAR(255) NULL,
    branch VARCHAR(255) NULL,
    remote_updated_at TIMESTAMP NULL,
    FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE,
    INDEX idx_deployment_checks_project_checked (project_id, checked_at),
    INDEX idx_deployment_checks_project_status (project_id, status),
    INDEX idx_deployment_checks_project_status_checked (project_id, status, checked_at)
);

CREATE TABLE IF NOT EXISTS monitoring_alerts (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    project_id INT NOT NULL,
    alert_type VARCHAR(80) NOT NULL,
    message TEXT NOT NULL,
    severity ENUM('info', 'warning', 'critical') NOT NULL DEFAULT 'warning',
    is_resolved TINYINT(1) NOT NULL DEFAULT 0,
    triggered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE,
    INDEX idx_monitoring_alerts_project_resolved (project_id, is_resolved),
    INDEX idx_monitoring_alerts_type_resolved (alert_type, is_resolved),
    INDEX idx_monitoring_alerts_triggered (triggered_at)
);

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

CREATE TABLE IF NOT EXISTS feature_flags (
    id INT PRIMARY KEY AUTO_INCREMENT,
    feature_key VARCHAR(100) NOT NULL UNIQUE,
    feature_name VARCHAR(255) NOT NULL,
    feature_group VARCHAR(100) NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    maintenance_message TEXT NULL,
    updated_by INT NULL,
    updated_at DATETIME NULL,
    FOREIGN KEY (updated_by) REFERENCES users(userId) ON DELETE SET NULL,
    INDEX idx_feature_flags_group (feature_group),
    INDEX idx_feature_flags_enabled (is_enabled)
);

CREATE TABLE IF NOT EXISTS project_members (
    project_member_id INT PRIMARY KEY AUTO_INCREMENT,
    project_id INT NOT NULL,
    userId INT NOT NULL,
    member_role ENUM('owner', 'contributor', 'viewer') NOT NULL DEFAULT 'contributor',
    added_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE,
    FOREIGN KEY (userId) REFERENCES users(userId) ON DELETE CASCADE,
    FOREIGN KEY (added_by) REFERENCES users(userId) ON DELETE SET NULL,
    UNIQUE KEY unique_project_member (project_id, userId)
);

CREATE TABLE IF NOT EXISTS activity_logs (
    activity_id INT PRIMARY KEY AUTO_INCREMENT,
    project_id INT NULL,
    userId INT NULL,
    action VARCHAR(100) NOT NULL,
    version VARCHAR(50) NULL,
    note TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE SET NULL,
    FOREIGN KEY (userId) REFERENCES users(userId) ON DELETE SET NULL,
    INDEX idx_activity_project_created (project_id, created_at),
    INDEX idx_activity_user_created (userId, created_at)
);

CREATE TABLE IF NOT EXISTS files (
    file_id INT PRIMARY KEY AUTO_INCREMENT,
    project_id INT NOT NULL,
    uploaded_by INT NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(150) NULL,
    file_size BIGINT UNSIGNED NULL,
    visibility ENUM('private', 'project') NOT NULL DEFAULT 'project',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(userId) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS resource_files (
    resource_file_id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    project_id INT NOT NULL,
    uploaded_by INT NOT NULL,
    storage_driver ENUM('local','ftp') NOT NULL DEFAULT 'local',
    storage_path VARCHAR(2048) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    mime_type VARCHAR(150) NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(255) NOT NULL,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    deleted_at TIMESTAMP NULL,
    deleted_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(userId) ON DELETE RESTRICT,
    FOREIGN KEY (deleted_by) REFERENCES users(userId) ON DELETE SET NULL,
    INDEX idx_resource_files_project_deleted (project_id, is_deleted),
    INDEX idx_resource_files_uploaded_by (uploaded_by),
    INDEX idx_resource_files_storage (storage_driver, storage_path(191))
);

CREATE TABLE IF NOT EXISTS comments (
    comment_id INT PRIMARY KEY AUTO_INCREMENT,
    project_id INT NOT NULL,
    userId INT NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE,
    FOREIGN KEY (userId) REFERENCES users(userId) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS notifications (
    notification_id INT PRIMARY KEY AUTO_INCREMENT,
    userId INT NULL,
    project_id INT NULL,
    type ENUM('status', 'comment', 'file', 'system') NOT NULL DEFAULT 'system',
    title VARCHAR(255) NOT NULL,
    message TEXT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (userId) REFERENCES users(userId) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS login_attempts (
    attemptId INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    success TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS password_resets (
    resetId INT PRIMARY KEY AUTO_INCREMENT,
    userId INT NOT NULL,
    token VARCHAR(255) NOT NULL,
    expiry TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (userId) REFERENCES users(userId) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS password_reset_attempts (
    attemptId INT PRIMARY KEY AUTO_INCREMENT,
    userId INT NOT NULL,
    email VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (userId) REFERENCES users(userId) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS error_logs (
    logId INT PRIMARY KEY AUTO_INCREMENT,
    error_message TEXT NOT NULL,
    query TEXT,
    params TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO roles (role_name, description) VALUES
('superadmin', 'Owns all system settings, users, and emergency controls'),
('admin', 'Full visibility and system monitoring access'),
('handler', 'Can create and manage owned or assigned projects'),
('member', 'Can join subjects and submit website requests'),
('visitor', 'Public visitor without an account; can look up non-sensitive website status');

INSERT IGNORE INTO users (username, passwordHash, fullName, email, role_id)
SELECT 'admin', '$2y$12$Fi3OAf7w0SeOoVM9v11BgeTwuVCbzmsnTPMqaYOGd0HKvq.VY8PfW', 'System Administrator', 'admin@example.com', role_id
FROM roles WHERE role_name = 'admin';

INSERT IGNORE INTO users (username, passwordHash, fullName, email, role_id)
SELECT 'handler', '$2y$12$EMMWGgdib0yMBx4280x6ze9oCyn17xasgrP2gPjztrsCsqU7DMqXK', 'Project Handler', 'handler@example.com', role_id
FROM roles WHERE role_name = 'handler';

INSERT IGNORE INTO users (username, passwordHash, fullName, email, role_id)
SELECT 'visitor', '$2y$12$RAgQ/fV3SmsGP94K0KP/NODu9vXhVNBU9lIad0WPphPqm4JoLgyd.', 'Project Visitor', 'visitor@example.com', role_id
FROM roles WHERE role_name = 'visitor';

INSERT INTO monitoring_settings (setting_key, setting_value)
VALUES
('scheduler_mode', 'manual'),
('check_interval_minutes', '5'),
('stale_after_minutes', '10'),
('failure_threshold', '3'),
('batch_size', '10'),
('response_slow_ms', '3000'),
('retention_days', '30')
ON DUPLICATE KEY UPDATE setting_value = setting_value;

INSERT INTO feature_flags (feature_key, feature_name, feature_group, is_enabled)
VALUES
('dashboard', 'Dashboard', 'Core', 1),
('projects', 'Projects', 'Projects', 1),
('subjects', 'Subjects', 'Subjects', 1),
('subject_resources', 'Subject Resources', 'Subjects', 1),
('subject_posts', 'Subject Posts', 'Subjects', 1),
('tutorials', 'Tutorials', 'Learning', 1),
('alerts', 'Alerts', 'Monitoring', 1),
('requests', 'Requests', 'Workflow', 1),
('logs', 'Logs', 'Admin', 1),
('settings', 'Settings', 'Admin', 1)
ON DUPLICATE KEY UPDATE feature_name = VALUES(feature_name), feature_group = VALUES(feature_group);

INSERT IGNORE INTO subjects (subject_code, subject_name, description, created_by)
SELECT 'GENERAL', 'General Projects', 'Default subject for uncategorized academic projects.', userId
FROM users WHERE username = 'admin';
