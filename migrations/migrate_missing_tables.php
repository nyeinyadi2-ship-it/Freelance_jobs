<?php
/**
 * Migration: Create all missing tables
 * Run once: php migrate_missing_tables.php
 * Or visit in browser: http://localhost/freelancer_job/migrate_missing_tables.php
 */

require_once __DIR__ . '/../config/db.php';

$tables = [
    'payment_history' => "CREATE TABLE IF NOT EXISTS `payment_history` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `user_id` INT NOT NULL,
        `related_payment_id` INT DEFAULT NULL,
        `type` ENUM('escrow_fund','release','refund','withdrawal','withdrawal_rejected') NOT NULL,
        `amount` DECIMAL(10,2) NOT NULL,
        `description` TEXT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_payment_history_user` (`user_id`),
        INDEX `idx_payment_history_type` (`type`),
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`related_payment_id`) REFERENCES `payments`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",


    'submissions' => "CREATE TABLE IF NOT EXISTS `submissions` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `assignment_id` INT NOT NULL,
        `freelancer_id` INT NOT NULL,
        `file_path` VARCHAR(255) DEFAULT NULL,
        `project_url` VARCHAR(500) DEFAULT NULL,
        `notes` TEXT,
        `version` INT DEFAULT 1,
        `status` ENUM('pending','revision_requested','approved') DEFAULT 'pending',
        `revision_notes` TEXT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_submissions_assignment` (`assignment_id`),
        FOREIGN KEY (`assignment_id`) REFERENCES `assignments`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`freelancer_id`) REFERENCES `freelancers`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'escrow_transactions' => "CREATE TABLE IF NOT EXISTS `escrow_transactions` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `payment_id` INT NOT NULL,
        `assignment_id` INT NOT NULL,
        `company_id` INT NOT NULL,
        `freelancer_id` INT NOT NULL,
        `transaction_type` ENUM('fund','release','refund') NOT NULL,
        `amount` DECIMAL(10,2) NOT NULL,
        `status` VARCHAR(50) DEFAULT 'completed',
        `notes` TEXT DEFAULT NULL,
        `created_by` INT NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_escrow_tx_payment` (`payment_id`),
        INDEX `idx_escrow_tx_assignment` (`assignment_id`),
        FOREIGN KEY (`payment_id`) REFERENCES `payments`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`assignment_id`) REFERENCES `assignments`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`freelancer_id`) REFERENCES `freelancers`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

echo "<h2>Missing Tables Migration</h2><pre>";

foreach ($tables as $name => $sql) {
    $check = $conn->query("SHOW TABLES LIKE '$name'");
    if ($check && $check->num_rows > 0) {
        echo "OK: $name already exists\n";
    } else {
        if ($conn->query($sql)) {
            echo "CREATED: $name\n";
        } else {
            echo "ERROR: $name — " . $conn->error . "\n";
        }
    }
}

echo "</pre><p>Done.</p>";
