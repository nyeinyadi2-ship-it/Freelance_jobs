<?php
require 'c:/wamp64/www/freelancer_job/config/db.php';
$r = $conn->query("SELECT id FROM freelancers WHERE user_id = 6");
print_r($r->fetch_assoc());
$r = $conn->query("SELECT id FROM companies WHERE user_id = 9");
print_r($r->fetch_assoc());
