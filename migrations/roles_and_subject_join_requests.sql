ALTER TABLE roles
    MODIFY role_name ENUM('superadmin', 'admin', 'handler', 'member', 'visitor') NOT NULL UNIQUE;

INSERT IGNORE INTO roles (role_name, description) VALUES
('superadmin', 'Owns all system settings, users, and emergency controls'),
('member', 'Can join subjects and submit website requests');

UPDATE roles
SET description = 'Public visitor without an account; can look up non-sensitive website status'
WHERE role_name = 'visitor';

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
