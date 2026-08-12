<?php
require 'config/db.php';
$res = $conn->query("SELECT COUNT(*) FROM payments");
$row = $res->fetch_row();
echo 'Payments count: ' . $row[0] . "\n";
$res2 = $conn->query("SELECT COUNT(*) FROM wallet_transactions");
$row2 = $res2->fetch_row();
echo 'Wallet transactions count: ' . $row2[0] . "\n";
