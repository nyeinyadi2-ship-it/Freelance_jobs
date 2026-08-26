<?php
require 'config/db.php';
$res = $conn->query("SHOW COLUMNS FROM jobs LIKE 'payment_type'");
if ($res->num_rows > 0) {
    echo "Column exists.\n";
    $res = $conn->query("SELECT id, payment_type, category FROM jobs LIMIT 10");
    while($row = $res->fetch_assoc()) {
        print_r($row);
    }
} else {
    echo "Column does NOT exist.\n";
}
