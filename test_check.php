<?php
require 'config/db.php';
$res = $conn->query('SELECT user_id FROM companies WHERE id = 15')->fetch_assoc();
print_r($res);
