<?php
/**
 * Migration: Add requirements column to jobs table.
 * Run once: php migrate_requirements_column.php
 */

require_once __DIR__ . '/../config/db.php';

$check = $conn->query("SHOW COLUMNS FROM jobs LIKE 'requirements'");
if ($check && $check->num_rows > 0) {
    echo "[SKIP] Column 'requirements' already exists.\n";
} else {
    try {
        $conn->query("ALTER TABLE jobs ADD COLUMN requirements TEXT DEFAULT NULL AFTER description");
        echo "[OK] Added column 'requirements'.\n";
    } catch (mysqli_sql_exception $e) {
        echo "[ERROR] Failed to add 'requirements': " . $e->getMessage() . "\n";
    }
}

echo "\nMigration complete.\n";
