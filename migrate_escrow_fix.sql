-- Migration: Fix Escrow Payment System
-- Run this on your existing database

-- 1. Update payments table ENUM to include all required statuses
ALTER TABLE `payments`
MODIFY COLUMN `escrow_status` ENUM(
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

-- 2. Add payment_method and payment_details columns if not exists
SET @exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'payment_method');
SET @sql = IF(@exists = 0, 'ALTER TABLE `payments` ADD COLUMN `payment_method` VARCHAR(50) DEFAULT NULL AFTER `escrow_status`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'payment_details');
SET @sql = IF(@exists = 0, 'ALTER TABLE `payments` ADD COLUMN `payment_details` TEXT DEFAULT NULL AFTER `payment_method`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Create escrow_transactions table WITHOUT foreign keys (use indexes only)
-- This avoids the "Failed to open referenced table" error
DROP TABLE IF EXISTS `escrow_transactions`;
CREATE TABLE `escrow_transactions` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `payment_id` INT NOT NULL,
    `assignment_id` INT NOT NULL,
    `company_id` INT NOT NULL,
    `freelancer_id` INT NOT NULL,
    `transaction_type` ENUM('fund', 'release', 'refund', 'cancel') NOT NULL,
    `amount` DECIMAL(10, 2) NOT NULL,
    `status` ENUM('pending', 'completed', 'failed') DEFAULT 'pending',
    `notes` TEXT DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_escrow_trans_payment` (`payment_id`),
    INDEX `idx_escrow_trans_assignment` (`assignment_id`),
    INDEX `idx_escrow_trans_company` (`company_id`),
    INDEX `idx_escrow_trans_freelancer` (`freelancer_id`),
    INDEX `idx_escrow_trans_type` (`transaction_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Add balance columns to freelancers table if not exists
SET @exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'freelancers' AND COLUMN_NAME = 'total_earnings');
SET @sql = IF(@exists = 0, 'ALTER TABLE `freelancers` ADD COLUMN `total_earnings` DECIMAL(10,2) DEFAULT 0.00', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'freelancers' AND COLUMN_NAME = 'total_withdrawn');
SET @sql = IF(@exists = 0, 'ALTER TABLE `freelancers` ADD COLUMN `total_withdrawn` DECIMAL(10,2) DEFAULT 0.00', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'freelancers' AND COLUMN_NAME = 'available_balance');
SET @sql = IF(@exists = 0, 'ALTER TABLE `freelancers` ADD COLUMN `available_balance` DECIMAL(10,2) DEFAULT 0.00', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
