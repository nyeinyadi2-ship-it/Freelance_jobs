<?php
require 'config/db.php';
$tables = ['payments', 'wallet_transactions', 'users'];
foreach($tables as $table) {
    echo "--- $table ---\n";
    $res = $conn->query("SHOW CREATE TABLE $table");
    if($res && $row = $res->fetch_row()) {
        echo $row[1] . "\n\n";
    }
}
