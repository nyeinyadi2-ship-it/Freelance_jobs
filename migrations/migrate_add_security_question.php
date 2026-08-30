<?php
/**
 * Migration: Add security_question and security_answer_hash columns to users table.
 * These are used for the verification question feature in password recovery.
 */
require_once __DIR__ . '/../config/db.php';

$columns = [];

// Check existing columns
$result = $conn->query("SHOW COLUMNS FROM users LIKE 'security_question'");
if ($result && $result->num_rows === 0) {
    $columns[] = "ADD COLUMN `security_question` VARCHAR(255) DEFAULT NULL AFTER `blocked_by`";
}

$result = $conn->query("SHOW COLUMNS FROM users LIKE 'security_answer_hash'");
if ($result && $result->num_rows === 0) {
    $columns[] = "ADD COLUMN `security_answer_hash` VARCHAR(255) DEFAULT NULL AFTER `security_question`";
}

if (!empty($columns)) {
    $sql = "ALTER TABLE users " . implode(', ', $columns);
    $conn->query($sql);
    echo "Migration complete: Added columns to users table.\n";
} else {
    echo "Migration skipped: Columns already exist.\n";
}
