-- 1. Ensure username column exists
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accounts' AND COLUMN_NAME = 'username');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE accounts ADD COLUMN username VARCHAR(190) NOT NULL DEFAULT '' AFTER org_name', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Insert admin account (username: admin, password: admin@915)
-- password_hash generated via PHP: password_hash('admin@915', PASSWORD_DEFAULT)
INSERT INTO accounts (email, password_hash, role, status, org_name, username, trial_started_at, trial_ends_at)
VALUES ('admin@exammonitor.com', '$2y$10$8K1p/a0dL1LXMIgoEDFrOOemG3W4zFQ5BdmFY1YVAC9Kx2CjFs2G2', 'owner', 'trial', 'منصة مراقب الامتحانات', 'admin', NOW(), DATE_ADD(NOW(), INTERVAL 365 DAY))
ON DUPLICATE KEY UPDATE id=id;

-- 3. Set default password hashes for teachers who don't have one yet
-- Default password pattern: {username}@915
-- We generate the hash inline via a procedure since MySQL doesn't have password_hash()

-- For now, ensure teachers table has password_hash column
SET @pw_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teachers' AND COLUMN_NAME = 'password_hash');
SET @sql2 = IF(@pw_exists = 0, 'ALTER TABLE teachers ADD COLUMN password_hash VARCHAR(255) NOT NULL DEFAULT '' AFTER username', 'SELECT 1');
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;

SET @fl_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teachers' AND COLUMN_NAME = 'is_first_login');
SET @sql3 = IF(@fl_exists = 0, 'ALTER TABLE teachers ADD COLUMN is_first_login TINYINT(1) NOT NULL DEFAULT 1 AFTER password_hash', 'SELECT 1');
PREPARE stmt3 FROM @sql3; EXECUTE stmt3; DEALLOCATE PREPARE stmt3;
