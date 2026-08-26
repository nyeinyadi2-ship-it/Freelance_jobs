<?php
require_once __DIR__ . '/config/db.php';
$res = $conn->query("SHOW COLUMNS FROM payments");
echo "PAYMENTS:\n";
while ($row = $res->fetch_assoc()) {
    echo $row['Field'] . " - " . $row['Type'] . "\n";
}
echo "\nWALLET_TRANSACTIONS:\n";
$res = $conn->query("SHOW COLUMNS FROM wallet_transactions");
while ($row = $res->fetch_assoc()) {
    echo $row['Field'] . " - " . $row['Type'] . "\n";
}
