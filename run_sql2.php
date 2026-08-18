<?php
require_once __DIR__ . '/config/db.php';

try {
    $res = $conn->query("DESCRIBE assignments");
    echo "<pre>Assignments:\n";
    while ($row = $res->fetch_assoc()) {
        print_r($row);
    }
    
    $res = $conn->query("DESCRIBE milestones");
    echo "\nMilestones:\n";
    while ($row = $res->fetch_assoc()) {
        print_r($row);
    }
    echo "</pre>";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
