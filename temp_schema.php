<?php
require 'config/db.php';
$tables = ['companies', 'freelancers', 'escrow', 'withdraw_requests'];
foreach ($tables as $t) {
    echo strtoupper($t) . ":\n";
    try {
        $res = $conn->query("SHOW COLUMNS FROM $t");
        if ($res) {
            foreach($res->fetch_all(MYSQLI_ASSOC) as $row) {
                echo "  " . $row['Field'] . " (" . $row['Type'] . ")\n";
            }
        } else { echo "  (Table not found)\n"; }
    } catch(Exception $e) { echo "  (Table not found)\n"; }
}
