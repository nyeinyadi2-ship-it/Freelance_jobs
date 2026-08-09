<?php
require_once __DIR__ . '/../config/db.php';

echo "Starting migrate_deposit_verification...\n";

// Add proof_image
$res = $conn->query("SHOW COLUMNS FROM wallet_transactions LIKE 'proof_image'");
if ($res && $res->num_rows == 0) {
    $conn->query("ALTER TABLE wallet_transactions ADD COLUMN proof_image VARCHAR(255) DEFAULT NULL AFTER transaction_id");
    echo "Added proof_image column.\n";
} else {
    echo "proof_image column already exists.\n";
}

// Add rejection_reason
$res = $conn->query("SHOW COLUMNS FROM wallet_transactions LIKE 'rejection_reason'");
if ($res && $res->num_rows == 0) {
    $conn->query("ALTER TABLE wallet_transactions ADD COLUMN rejection_reason TEXT DEFAULT NULL AFTER status");
    echo "Added rejection_reason column.\n";
} else {
    echo "rejection_reason column already exists.\n";
}

// Update status ENUM
$conn->query("ALTER TABLE wallet_transactions MODIFY COLUMN status ENUM('pending','completed','rejected','failed') DEFAULT 'pending'");
echo "Updated status ENUM to include 'rejected' and set default to 'pending'.\n";

echo "Migration complete.\n";
