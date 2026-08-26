<?php
require_once __DIR__ . '/config/db.php';
$r = $conn->query("SELECT DISTINCT type FROM wallet_transactions");
while ($row = $r->fetch_assoc()) {
    echo $row['type'] . "\n";
}
