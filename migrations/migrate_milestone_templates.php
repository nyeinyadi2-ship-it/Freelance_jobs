<?php
/**
 * Migration: Add template_id to milestones table for explicit template linking.
 */
require_once __DIR__ . '/../config/db.php';

try {
    // Check if column already exist
    $check = $conn->query("SHOW COLUMNS FROM milestones LIKE 'template_id'");
    if ($check && $check->num_rows > 0) {
        echo "[SKIP] Column 'template_id' already exists.\n";
    } else {
        $conn->query("ALTER TABLE milestones ADD COLUMN template_id INT DEFAULT NULL AFTER freelancer_id");
        $conn->query("ALTER TABLE milestones ADD CONSTRAINT fk_ms_template FOREIGN KEY (template_id) REFERENCES milestones(id) ON DELETE SET NULL");
        echo "[OK] Added 'template_id' column to milestones table and created foreign key.\n";
    }
} catch (Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
}
