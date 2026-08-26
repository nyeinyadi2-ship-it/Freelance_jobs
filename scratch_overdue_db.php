<?php
require 'config/db.php';

// 1. Create milestone_history table
$sql = "CREATE TABLE IF NOT EXISTS `milestone_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `milestone_id` int NOT NULL,
  `freelancer_id` int DEFAULT NULL,
  `company_id` int DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `previous_status` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `new_status` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `old_deadline` datetime DEFAULT NULL,
  `new_deadline` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mh_milestone` (`milestone_id`),
  KEY `idx_mh_freelancer` (`freelancer_id`),
  KEY `idx_mh_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
if (!$conn->query($sql)) {
    echo "Error creating milestone_history: " . $conn->error . "\n";
} else {
    echo "milestone_history OK\n";
}

// 2. Create milestone_extensions table
$sql = "CREATE TABLE IF NOT EXISTS `milestone_extensions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `milestone_id` int NOT NULL,
  `freelancer_id` int NOT NULL,
  `current_deadline` datetime DEFAULT NULL,
  `requested_deadline` datetime NOT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci,
  `status` enum('pending','approved','rejected') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `company_response` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_me_milestone` (`milestone_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
if (!$conn->query($sql)) {
    echo "Error creating milestone_extensions: " . $conn->error . "\n";
} else {
    echo "milestone_extensions OK\n";
}

// 3. Alter milestones table for status ENUM and additional columns
$sql = "ALTER TABLE milestones 
  MODIFY COLUMN status ENUM('draft', 'funded', 'in_progress', 'submitted', 'approved', 'revision_requested', 'payment_pending', 'paid', 'overdue', 'cancelled') DEFAULT 'draft'";
if (!$conn->query($sql)) {
    echo "Error altering milestones status: " . $conn->error . "\n";
} else {
    echo "milestones status ENUM OK\n";
}

$columns = [
    'extension_reason' => 'TEXT DEFAULT NULL',
    'cancellation_reason' => 'TEXT DEFAULT NULL'
];

foreach ($columns as $col => $def) {
    $res = $conn->query("SHOW COLUMNS FROM milestones LIKE '$col'");
    if ($res && $res->num_rows == 0) {
        $sql = "ALTER TABLE milestones ADD COLUMN $col $def";
        if (!$conn->query($sql)) {
            echo "Error adding column $col: " . $conn->error . "\n";
        } else {
            echo "Column $col added OK\n";
        }
    } else {
        echo "Column $col already exists\n";
    }
}
echo "DB Update Complete.\n";
