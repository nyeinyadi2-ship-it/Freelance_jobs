<?php
$pdo = new PDO('mysql:host=localhost;dbname=freelance_db;charset=utf8', 'root', '');
foreach(['reviews', 'assignments', 'milestones', 'jobs', 'payments'] as $t) {
    echo "TABLE $t\n";
    $stmt = $pdo->query("DESCRIBE $t");
    while($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo json_encode($r) . "\n";
    }
    echo "\n";
}
