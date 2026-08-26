<?php
header('Content-Type: text/plain');
require_once __DIR__ . '/../config/db.php';

$res = $conn->query("SHOW COLUMNS FROM payments");
echo "payments:\n";
while ($row = $res->fetch_assoc()) {
    echo $row['Field'] . "\n";
}

$res = $conn->query("SHOW COLUMNS FROM wallet_transactions");
echo "\nwallet_transactions:\n";
while ($row = $res->fetch_assoc()) {
    echo $row['Field'] . "\n";
}
?>
