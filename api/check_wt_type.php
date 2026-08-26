<?php
header('Content-Type: text/plain');
require_once __DIR__ . '/../config/db.php';
$res = $conn->query("SHOW COLUMNS FROM wallet_transactions LIKE 'type'");
$row = $res->fetch_assoc();
echo "Type enum: " . $row['Type'] . "\n";
?>
