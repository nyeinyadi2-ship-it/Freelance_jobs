-- ============================================================
-- FreelanceHub - Complete Database Setup
-- Combined from: db.sql, db_profile.sql, db_chat_enhancement.sql,
--                 db_user_management.sql, db_escrow_fix.sql,
--                 db_payment_migration.sql, migrate_escrow_fix.sql
-- Safe to run multiple times (uses IF NOT EXISTS guards)
-- ============================================================

CREATE DATABASE IF NOT EXISTS freelancejob;
USE freelancejob;

-- ============================================================
-- PART 1: Core Tables (from db.sql)
-- ============================================================

-- 1. Users Table
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    profile_image VARCHAR(255) DEFAULT NULL,
    role ENUM('admin', 'company', 'freelancer') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Companies Table
CREATE TABLE IF NOT EXISTS companies (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT UNIQUE,
    phone VARCHAR(11) DEFAULT NULL,
    company_name VARCHAR(100),
    website VARCHAR(255),
    location VARCHAR(255) DEFAULT NULL,
    established_year INT DEFAULT NULL,
    industry VARCHAR(100) DEFAULT NULL,
    description TEXT,
    logo_image VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 3. Freelancers Table
CREATE TABLE IF NOT EXISTS freelancers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT UNIQUE,
    phone VARCHAR(11) DEFAULT NULL,
    full_name VARCHAR(100),
    title VARCHAR(200) DEFAULT NULL,
    location VARCHAR(255) DEFAULT NULL,
    bio TEXT DEFAULT NULL,
    experience_years INT DEFAULT NULL,
    hourly_rate DECIMAL(10,2) DEFAULT NULL,
    portfolio_url VARCHAR(255),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 4. Skills Table
CREATE TABLE IF NOT EXISTS skills (
    id INT PRIMARY KEY AUTO_INCREMENT,
    skill_name VARCHAR(50) UNIQUE NOT NULL
);

-- 5. Freelancer_Skills (Many-to-Many)
CREATE TABLE IF NOT EXISTS freelancer_skills (
    freelancer_id INT,
    skill_id INT,
    PRIMARY KEY (freelancer_id, skill_id),
    FOREIGN KEY (freelancer_id) REFERENCES freelancers(id) ON DELETE CASCADE,
    FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
);

-- 6. Jobs Table
CREATE TABLE IF NOT EXISTS jobs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT,
    title VARCHAR(200) NOT NULL,
    category VARCHAR(100) NOT NULL DEFAULT '',
    experience_level ENUM('beginner', 'intermediate', 'expert') DEFAULT 'intermediate',
    description TEXT,
    requirements TEXT,
    budget DECIMAL(10, 2),
    deadline DATETIME DEFAULT NULL,
    duration VARCHAR(100) DEFAULT NULL,
    attachment VARCHAR(255) DEFAULT NULL,
    status ENUM('pending', 'approved', 'rejected', 'completed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);

-- 6b. Job_Skills Table
CREATE TABLE IF NOT EXISTS job_skills (
    job_id INT,
    skill_id INT,
    PRIMARY KEY (job_id, skill_id),
    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
    FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Job_Applications Table
CREATE TABLE IF NOT EXISTS job_applications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    job_id INT,
    freelancer_id INT,
    status ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
    FOREIGN KEY (freelancer_id) REFERENCES freelancers(id) ON DELETE CASCADE
);

-- 8. Assignments Table
CREATE TABLE IF NOT EXISTS assignments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    job_id INT NOT NULL,
    freelancer_id INT NOT NULL,
    status ENUM('assigned', 'working', 'submitted', 'completed') DEFAULT 'assigned',
    submission_link VARCHAR(255),
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
    FOREIGN KEY (freelancer_id) REFERENCES freelancers(id) ON DELETE CASCADE,
    UNIQUE KEY uq_assignment_job_freelancer (job_id, freelancer_id),
    INDEX idx_assignments_job_id (job_id),
    INDEX idx_assignments_freelancer_id (freelancer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. Notifications Table
CREATE TABLE IF NOT EXISTS notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    from_user_id INT DEFAULT NULL,
    type VARCHAR(50) NOT NULL,
    message VARCHAR(500) NOT NULL,
    link VARCHAR(255) DEFAULT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_notifications_user_read (user_id, is_read),
    INDEX idx_notifications_type (type),
    INDEX idx_notifications_created (created_at)
);

-- 10. Payments Table
CREATE TABLE IF NOT EXISTS payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    assignment_id INT,
    amount DECIMAL(10, 2),
    status ENUM('pending', 'paid') DEFAULT 'pending',
    paid_at TIMESTAMP NULL,
    FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE
);

-- 11. Reviews Table
CREATE TABLE IF NOT EXISTS reviews (
    id INT PRIMARY KEY AUTO_INCREMENT,
    assignment_id INT,
    freelancer_id INT NOT NULL,
    company_user_id INT NOT NULL,
    rating TINYINT NOT NULL,
    comment TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reviews_freelancer (freelancer_id),
    INDEX idx_reviews_assignment (assignment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 12. Milestones Table
CREATE TABLE IF NOT EXISTS milestones (
    id INT PRIMARY KEY AUTO_INCREMENT,
    job_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT DEFAULT NULL,
    amount DECIMAL(10,2) NOT NULL,
    deadline DATE DEFAULT NULL,
    status ENUM('draft','funded','in_progress','submitted','approved','revision_requested') DEFAULT 'draft',
    submission_link VARCHAR(255) DEFAULT NULL,
    submission_file VARCHAR(255) DEFAULT NULL,
    submission_note TEXT DEFAULT NULL,
    submitted_at TIMESTAMP NULL,
    approved_at TIMESTAMP NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_milestones_job (job_id),
    INDEX idx_milestones_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 13. Escrow Table
CREATE TABLE IF NOT EXISTS escrow (
    id INT PRIMARY KEY AUTO_INCREMENT,
    milestone_id INT UNIQUE NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    status ENUM('held','released','refunded') DEFAULT 'held',
    funded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    released_at TIMESTAMP NULL,
    INDEX idx_escrow_milestone (milestone_id),
    INDEX idx_escrow_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 14. Messages Table
CREATE TABLE IF NOT EXISTS messages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    message TEXT NOT NULL,
    status ENUM('unread','read') DEFAULT 'unread',
    attachment_name VARCHAR(255) NULL DEFAULT NULL,
    attachment_path VARCHAR(255) NULL DEFAULT NULL,
    attachment_size INT NULL DEFAULT NULL,
    attachment_type VARCHAR(100) NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_messages_sender (sender_id),
    INDEX idx_messages_receiver (receiver_id),
    INDEX idx_messages_status (receiver_id, status),
    INDEX idx_messages_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PART 2: User Management Fields (from db_user_management.sql)
-- ============================================================

-- Add account_status column to users
SET @dbname = DATABASE();
SET @tablename = 'users';
SET @columnname = 'account_status';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = @tablename
    AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' ENUM(''active'', ''suspended'', ''blocked'') DEFAULT ''active'' AFTER last_activity')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add index for filtering by status
SET @indexname = 'idx_users_account_status';
SET @preparedStatement2 = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = @tablename
    AND INDEX_NAME = @indexname
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD INDEX ', @indexname, ' (', @columnname, ')')
));
PREPARE addIndexIfNotExists FROM @preparedStatement2;
EXECUTE addIndexIfNotExists;
DEALLOCATE PREPARE addIndexIfNotExists;

-- ============================================================
-- PART 3: User Activity Tracking (from db.sql + db_chat_enhancement.sql)
-- ============================================================

-- Add last_activity column
SET @columnname = 'last_activity';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE users ADD COLUMN last_activity TIMESTAMP NULL AFTER created_at'
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add is_online column
SET @columnname = 'is_online';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE users ADD COLUMN is_online TINYINT(1) DEFAULT 0 AFTER last_activity'
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add last_seen column
SET @columnname = 'last_seen';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE users ADD COLUMN last_seen TIMESTAMP NULL AFTER is_online'
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- PART 4: Chat Enhancement Tables (from db_chat_enhancement.sql)
-- ============================================================

-- Add message_type column to messages
SET @columnname = 'message_type';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'messages'
    AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  "ALTER TABLE messages ADD COLUMN message_type ENUM('text','image','file','system') DEFAULT 'text' AFTER message"
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add message_meta column to messages
SET @columnname = 'message_meta';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'messages'
    AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE messages ADD COLUMN message_meta JSON DEFAULT NULL AFTER message_type'
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Conversations table
CREATE TABLE IF NOT EXISTS conversations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_one_id INT NOT NULL,
    user_two_id INT NOT NULL,
    last_message_id INT DEFAULT NULL,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_one_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (user_two_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (last_message_id) REFERENCES messages(id) ON DELETE SET NULL,
    INDEX idx_conv_users (user_one_id, user_two_id),
    INDEX idx_conv_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Typing status table
CREATE TABLE IF NOT EXISTS typing_status (
    user_id INT NOT NULL,
    conversation_partner_id INT NOT NULL,
    is_typing TINYINT(1) DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, conversation_partner_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (conversation_partner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notification reads table
CREATE TABLE IF NOT EXISTS notification_reads (
    user_id INT NOT NULL,
    notification_id INT NOT NULL,
    read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, notification_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PART 5: Escrow Payment System (from db_escrow_fix.sql + migrate_escrow_fix.sql)
-- ============================================================

-- Add escrow columns to payments table
SET @columnname = 'company_id';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'payments'
    AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE payments ADD COLUMN company_id INT AFTER status'
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @columnname = 'freelancer_id';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'payments'
    AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE payments ADD COLUMN freelancer_id INT AFTER company_id'
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @columnname = 'escrow_status';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'payments'
    AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  "ALTER TABLE payments ADD COLUMN escrow_status ENUM('pending', 'funded', 'released', 'refunded') DEFAULT 'pending' AFTER freelancer_id"
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Modify escrow_status ENUM to include all required statuses
ALTER TABLE payments
MODIFY COLUMN escrow_status ENUM(
    'pending',
    'funded',
    'in_progress',
    'submitted',
    'revision_requested',
    'approved',
    'released',
    'refunded',
    'cancelled'
) DEFAULT 'pending';

SET @columnname = 'funded_at';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'payments'
    AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE payments ADD COLUMN funded_at TIMESTAMP NULL AFTER escrow_status'
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @columnname = 'released_at';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'payments'
    AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE payments ADD COLUMN released_at TIMESTAMP NULL AFTER funded_at'
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @columnname = 'refunded_at';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'payments'
    AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE payments ADD COLUMN refunded_at TIMESTAMP NULL AFTER released_at'
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @columnname = 'created_at';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'payments'
    AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE payments ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER paid_at'
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @columnname = 'payment_method';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'payments'
    AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE payments ADD COLUMN payment_method VARCHAR(50) DEFAULT NULL AFTER escrow_status'
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @columnname = 'payment_details';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'payments'
    AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE payments ADD COLUMN payment_details TEXT DEFAULT NULL AFTER payment_method'
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Create escrow_transactions table
DROP TABLE IF EXISTS escrow_transactions;
CREATE TABLE escrow_transactions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    payment_id INT NOT NULL,
    assignment_id INT NOT NULL,
    company_id INT NOT NULL,
    freelancer_id INT NOT NULL,
    transaction_type ENUM('fund', 'release', 'refund', 'cancel') NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    status ENUM('pending', 'completed', 'failed') DEFAULT 'pending',
    notes TEXT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_escrow_trans_payment (payment_id),
    INDEX idx_escrow_trans_assignment (assignment_id),
    INDEX idx_escrow_trans_company (company_id),
    INDEX idx_escrow_trans_freelancer (freelancer_id),
    INDEX idx_escrow_trans_type (transaction_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add balance columns to freelancers table
SET @columnname = 'total_earnings';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'freelancers'
    AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE freelancers ADD COLUMN total_earnings DECIMAL(10,2) DEFAULT 0.00'
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @columnname = 'total_withdrawn';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'freelancers'
    AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE freelancers ADD COLUMN total_withdrawn DECIMAL(10,2) DEFAULT 0.00'
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @columnname = 'available_balance';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'freelancers'
    AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE freelancers ADD COLUMN available_balance DECIMAL(10,2) DEFAULT 0.00'
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- PART 6: Milestone Assignment (from migrate_milestone_assignment.sql)
-- ============================================================

SET @columnname = 'freelancer_id';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'milestones'
    AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE milestones ADD COLUMN freelancer_id INT DEFAULT NULL AFTER job_id'
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index for freelancer_id on milestones
SET @indexname = 'idx_milestones_freelancer';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'milestones'
    AND INDEX_NAME = @indexname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE milestones ADD INDEX idx_milestones_freelancer (freelancer_id)'
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- PART 7: Direct Hire Fields (from migrate_direct_hire.sql)
-- ============================================================

SET @columnname = 'assignment_type';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'assignments'
    AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  "ALTER TABLE assignments ADD COLUMN assignment_type ENUM('job_application', 'direct_hire') DEFAULT 'job_application' AFTER status"
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @columnname = 'freelancer_response';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'assignments'
    AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  "ALTER TABLE assignments ADD COLUMN freelancer_response ENUM('pending', 'accepted', 'declined') DEFAULT 'pending' AFTER assignment_type"
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @columnname = 'project_title';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'assignments'
    AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE assignments ADD COLUMN project_title VARCHAR(200) DEFAULT NULL AFTER freelancer_response'
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @columnname = 'project_description';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'assignments'
    AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE assignments ADD COLUMN project_description TEXT DEFAULT NULL AFTER project_title'
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @columnname = 'budget';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'assignments'
    AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE assignments ADD COLUMN budget DECIMAL(10,2) DEFAULT NULL AFTER project_description'
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @columnname = 'deadline';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'assignments'
    AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE assignments ADD COLUMN deadline DATE DEFAULT NULL AFTER budget'
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @columnname = 'payment_type';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'assignments'
    AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  "ALTER TABLE assignments ADD COLUMN payment_type ENUM('milestone', 'full_payment') DEFAULT 'milestone' AFTER deadline"
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- PART 8: Seed Data
-- ============================================================

-- Admin user (email: admin@platform.com, password: admin123)
INSERT IGNORE INTO users (username, email, password, role) VALUES
('admin', 'admin@platform.com', '$2y$10$fLhKLQuCby5WGCF3wq4z3e7Lox/Y6xggMUdAWPPmaEp6Ui4QT1Xcm', 'admin');

-- Sample skills
INSERT IGNORE INTO skills (skill_name) VALUES
('PHP'), ('MySQL'), ('JavaScript'), ('HTML'), ('CSS'), ('Tailwind CSS'),
('Bootstrap'), ('React.js'), ('Vue.js'), ('Node.js'), ('Express.js'),
('Laravel'), ('CodeIgniter'), ('Python'), ('Java'), ('C#'), ('C++'),
('UI/UX Design'), ('Graphic Design'), ('Logo Design'), ('Brand Identity Design'),
('Adobe Photoshop'), ('Adobe Illustrator'), ('Adobe InDesign'), ('Figma'),
('Adobe XD'), ('Canva'), ('Video Editing'), ('Motion Graphics'), ('Animation'),
('Content Writing'), ('Copywriting'), ('Blog Writing'), ('Article Writing'),
('SEO Writing'), ('Translation'), ('Digital Marketing'), ('Social Media Marketing'),
('Email Marketing'), ('E-commerce');

-- ============================================================
-- DONE! All tables and columns created successfully.
-- ============================================================
