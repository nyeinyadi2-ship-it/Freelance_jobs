<?php
require 'config/db.php';
$freelancer = $conn->query("SELECT email FROM users WHERE role='freelancer' LIMIT 1")->fetch_assoc()['email'];
$company = $conn->query("SELECT email FROM users WHERE role='company' LIMIT 1")->fetch_assoc()['email'];
echo "FL: $freelancer\nCO: $company\n";
