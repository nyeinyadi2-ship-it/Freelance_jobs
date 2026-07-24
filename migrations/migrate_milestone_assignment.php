<?php
/**
 * Migration: Add freelancer_id to milestones table for milestone assignment.
 * Run once: php migrate_milestone_assignment.php
 */
require_once __DIR__ . '/../config/db.php';

try {
    // Check if column already exist
    $check = $conn->query("SHOW COLUMNS FROM milestones LIKE 'freelancer_id'");
    if ($check && $check->num_rows > 0) {
        echo "[SKIP] Column 'freelancer_id' already exists.\n";
    } else {
        $conn->query("ALTER TABLE milestones ADD COLUMN freelancer_id INT DEFAULT NULL AFTER job_id");
        $conn->query("ALTER TABLE milestones ADD INDEX idx_milestones_freelancer (freelancer_id)");
        echo "[OK] Added 'freelancer_id' column to milestones table.\n";
    }
} catch (Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
}
