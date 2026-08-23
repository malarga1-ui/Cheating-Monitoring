-- Migration: Teacher real-time action system
-- Creates teacher_actions table for send_message, lock_exam, reduce_time actions

CREATE TABLE IF NOT EXISTS teacher_actions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    exam_id INT NOT NULL,
    session_summary_id INT NOT NULL,
    student_id INT NOT NULL,
    teacher_id INT NOT NULL,
    action_type ENUM('send_message', 'lock_exam', 'reduce_time') NOT NULL,
    message TEXT DEFAULT NULL,
    minutes_to_reduce INT DEFAULT NULL,
    status ENUM('pending', 'delivered', 'acknowledged', 'expired') DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delivered_at DATETIME DEFAULT NULL,
    acknowledged_at DATETIME DEFAULT NULL,
    INDEX idx_teacher_actions_exam (exam_id, status),
    INDEX idx_teacher_actions_session (session_summary_id, status),
    INDEX idx_teacher_actions_account (account_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Store action log for audit
CREATE TABLE IF NOT EXISTS teacher_action_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    action_id INT NOT NULL,
    event_type ENUM('created', 'delivered', 'acknowledged', 'expired') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_action_log_action (action_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
