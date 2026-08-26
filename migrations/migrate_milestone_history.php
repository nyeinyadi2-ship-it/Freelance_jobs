<?php
/**
 * Migration: Create milestone_history table for auditing milestone status changes and actions.
 */
require_once __DIR__ . '/../config/db.php';

echo "Running migration: migrate_milestone_history\n";

// Create milestone_history table
$conn->query("
    CREATE TABLE IF NOT EXISTS milestone_history (
        id INT NOT NULL AUTO_INCREMENT,
        milestone_id INT NOT NULL,
        freelancer_id INT DEFAULT NULL,
        company_id INT DEFAULT NULL,
        user_id INT DEFAULT NULL,
        previous_status VARCHAR(50) DEFAULT NULL,
        new_status VARCHAR(50) DEFAULT NULL,
        action_type VARCHAR(100) NOT NULL,
        description TEXT DEFAULT NULL,
        old_deadline DATETIME DEFAULT NULL,
        new_deadline DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_mh_milestone (milestone_id),
        KEY idx_mh_freelancer (freelancer_id),
        KEY idx_mh_company (company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
");
echo "  - milestone_history table created (or already exists)\n";

echo "Migration complete.\n";
