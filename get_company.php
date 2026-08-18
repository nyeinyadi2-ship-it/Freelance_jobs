<?php
require 'c:/wamp64/www/freelancer_job/config/db.php';
$r = $conn->query("SELECT user_id FROM companies LIMIT 1");
print_r($r->fetch_assoc());
