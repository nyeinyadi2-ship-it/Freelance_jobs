<?php
/**
 * Migration: Enhance notifications table
 * Run once: php migrate_notifications.php
 */

$conn = new mysqli('localhost', 'root', '', 'freelancejob');
$conn->set_charset('utf8mb4');

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$migrations = [
    // Add from_user_id column if not exists
    "ALTER TABLE notifications ADD COLUMN from_user_id INT DEFAULT NULL AFTER user_id",

    // Add foreign key for from_user_id
    "ALTER TABLE notifications ADD FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE SET NULL",

    // Add indexes for better query performance
    "CREATE INDEX idx_notifications_user_read ON notifications (user_id, is_read)",
    "CREATE INDEX idx_notifications_type ON notifications (type)",
    "CREATE INDEX idx_notifications_created ON notifications (created_at)",
];

foreach ($migrations as $sql) {
    $result = $conn->query($sql);
    if ($result) {
        echo "OK: " . substr($sql, 0, 60) . "...\n";
    } else {
        $error = $conn->error;
        if (strpos($error, 'Duplicate') !== false || strpos($error, 'already exists') !== false) {
            echo "SKIP: " . substr($sql, 0, 60) . "... (already exists)\n";
        } else {
            echo "ERR: " . substr($sql, 0, 60) . "... - " . $error . "\n";
        }
    }
}

$conn->close();
echo "\nMigration complete.\n";
