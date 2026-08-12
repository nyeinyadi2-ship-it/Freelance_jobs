<?php
require_once __DIR__ . '/../config/db.php';

echo "Starting wallet_transactions migration for freelancer payments...\n";

// Add new columns if they don't exist
$columns = [
    'sender_id' => 'INT NULL AFTER user_id',
    'receiver_id' => 'INT NULL AFTER sender_id',
    'job_id' => 'INT NULL AFTER receiver_id',
    'milestone_id' => 'INT NULL AFTER job_id',
    'description' => 'TEXT NULL AFTER milestone_id'
];

foreach ($columns as $column => $definition) {
    $res = $conn->query("SHOW COLUMNS FROM wallet_transactions LIKE '$column'");
    if ($res && $res->num_rows === 0) {
        $sql = "ALTER TABLE wallet_transactions ADD COLUMN $column $definition";
        if ($conn->query($sql)) {
            echo "Successfully added column: $column\n";
        } else {
            echo "Failed to add column $column: " . $conn->error . "\n";
        }
    } else {
        echo "Column $column already exists.\n";
    }
}

// Add foreign key constraints
$fks = [
    'fk_wallet_sender' => "ALTER TABLE wallet_transactions ADD CONSTRAINT fk_wallet_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE SET NULL",
    'fk_wallet_receiver' => "ALTER TABLE wallet_transactions ADD CONSTRAINT fk_wallet_receiver FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE SET NULL"
];

foreach ($fks as $fk_name => $sql) {
    // Check if constraint exists
    $check_fk = $conn->query("
        SELECT CONSTRAINT_NAME 
        FROM information_schema.KEY_COLUMN_USAGE 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'wallet_transactions' 
        AND CONSTRAINT_NAME = '$fk_name'
    ");
    if ($check_fk && $check_fk->num_rows === 0) {
        if ($conn->query($sql)) {
            echo "Successfully added foreign key constraint: $fk_name\n";
        } else {
            echo "Failed to add foreign key constraint $fk_name: " . $conn->error . "\n";
        }
    } else {
        echo "Foreign key constraint $fk_name already exists.\n";
    }
}

echo "Migration completed.\n";
