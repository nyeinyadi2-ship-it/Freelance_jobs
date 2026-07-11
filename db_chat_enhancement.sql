-- Chat & Notification Enhancement Migration
-- Run this if db.sql and db_chat.sql were already imported

-- 1. Message attachments table
CREATE TABLE IF NOT EXISTS message_attachments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    message_id INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size INT NOT NULL DEFAULT 0,
    file_type VARCHAR(100) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    INDEX idx_attach_message (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Add message_type column to messages (text, image, file, system)
ALTER TABLE messages ADD COLUMN IF NOT EXISTS message_type ENUM('text','image','file','system') DEFAULT 'text' AFTER message;

-- 3. Add message_meta column for additional data (JSON)
ALTER TABLE messages ADD COLUMN IF NOT EXISTS message_meta JSON DEFAULT NULL AFTER message_type;

-- 4. Conversations table for optimized listing
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

-- 5. Typing status table
CREATE TABLE IF NOT EXISTS typing_status (
    user_id INT NOT NULL,
    conversation_partner_id INT NOT NULL,
    is_typing TINYINT(1) DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, conversation_partner_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (conversation_partner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Add is_read column to notifications (if missing)
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS is_read TINYINT(1) DEFAULT 0 AFTER link;

-- 7. Create notification_reads table for per-user read tracking
CREATE TABLE IF NOT EXISTS notification_reads (
    user_id INT NOT NULL,
    notification_id INT NOT NULL,
    read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, notification_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. Update users table for online status tracking
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_online TINYINT(1) DEFAULT 0 AFTER last_activity;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_seen TIMESTAMP NULL AFTER is_online;
