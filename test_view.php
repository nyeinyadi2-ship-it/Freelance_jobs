<?php
require 'config/db.php';

// Create a mock freelancer profile request
// Get the ID of another freelancer (e.g., ID 2 or any other)
$res = $conn->query("SELECT id FROM users WHERE role = 'freelancer' LIMIT 1, 1");
$row = $res->fetch_assoc();
$other_uid = $row['id'];

echo "Testing with UID: " . $other_uid . "\n";
