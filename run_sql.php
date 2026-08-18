<?php
require_once __DIR__ . '/config/db.php';

try {
    $res = $conn->query("DESCRIBE payments");
    while ($row = $res->fetch_assoc()) {
        print_r($row);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
