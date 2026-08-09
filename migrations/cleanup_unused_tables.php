<?php

require_once __DIR__ . '/../config/db.php';

echo "Starting database cleanup...\n";

// 1. Drop unused table notification_reads
$sql1 = "DROP TABLE IF EXISTS notification_reads";
if ($conn->query($sql1)) {
    echo "Successfully dropped table: notification_reads\n";
} else {
    echo "Error dropping notification_reads: " . $conn->error . "\n";
}

// 2. Drop unused table escrow_transactions
$sql2 = "DROP TABLE IF EXISTS escrow_transactions";
if ($conn->query($sql2)) {
    echo "Successfully dropped table: escrow_transactions\n";
} else {
    echo "Error dropping escrow_transactions: " . $conn->error . "\n";
}

echo "Cleanup finished safely.\n";
