<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/../config/db.php';

function add_column($conn, $table, $column, $definition) {
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    if ($res->num_rows == 0) {
        if ($conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition")) {
            echo "Added $column to $table<br>";
        } else {
            echo "Error adding $column to $table: " . $conn->error . "<br>";
        }
    } else {
        echo "Column $column already exists in $table<br>";
    }
}

// Add missing columns to `payments`
add_column($conn, 'payments', 'milestone_id', 'int DEFAULT NULL AFTER assignment_id');
add_column($conn, 'payments', 'company_id', 'int DEFAULT NULL AFTER milestone_id');
add_column($conn, 'payments', 'freelancer_id', 'int DEFAULT NULL AFTER company_id');
add_column($conn, 'payments', 'payment_method', 'varchar(50) DEFAULT NULL AFTER amount');
add_column($conn, 'payments', 'transaction_reference', 'varchar(100) DEFAULT NULL AFTER payment_method');
add_column($conn, 'payments', 'transaction_slip', 'varchar(255) DEFAULT NULL AFTER transaction_reference');
add_column($conn, 'payments', 'recipient_name', 'varchar(255) DEFAULT NULL AFTER transaction_slip');
add_column($conn, 'payments', 'recipient_phone', 'varchar(20) DEFAULT NULL AFTER recipient_name');

// Add missing columns to `wallet_transactions`
add_column($conn, 'wallet_transactions', 'sender_id', 'int DEFAULT NULL AFTER user_id');
add_column($conn, 'wallet_transactions', 'receiver_id', 'int DEFAULT NULL AFTER sender_id');
add_column($conn, 'wallet_transactions', 'job_id', 'int DEFAULT NULL AFTER receiver_id');
add_column($conn, 'wallet_transactions', 'milestone_id', 'int DEFAULT NULL AFTER job_id');
add_column($conn, 'wallet_transactions', 'description', 'text DEFAULT NULL AFTER milestone_id');

echo "Schema updated successfully!";
?>
