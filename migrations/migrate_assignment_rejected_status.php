<?php
/**
 * Migration: Add 'rejected' status to assignments table.
 * Required for direct hire rejection workflow.
 */
require_once __DIR__ . '/../config/db.php';

$result = $conn->query("SHOW COLUMNS FROM assignments LIKE 'status'");
$row = $result->fetch_assoc();
$type = $row['Type'] ?? '';

if (strpos($type, 'rejected') === false) {
    $conn->query("ALTER TABLE assignments MODIFY COLUMN status ENUM('assigned','working','submitted','completed','rejected') DEFAULT 'assigned'");
    echo "[OK] Added 'rejected' status to assignments table.\n";
} else {
    echo "[SKIP] 'rejected' status already exists.\n";
}
