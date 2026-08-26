<?php
$pdo = new PDO('mysql:host=localhost;dbname=freelance_db;charset=utf8', 'root', '');
$stmt = $pdo->query("DESCRIBE freelancers");
while($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo json_encode($r) . "\n";
}
