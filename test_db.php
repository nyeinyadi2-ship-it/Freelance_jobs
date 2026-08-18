<?php
require 'config/db.php';

$stmt = $conn->prepare("UPDATE users SET available_balance = 5000000 WHERE id = 12");
$stmt->execute();
echo "User 12 balance updated.\n";
