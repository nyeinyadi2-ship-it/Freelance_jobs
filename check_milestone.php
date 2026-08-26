<?php
require 'config/db.php';
$res = $conn->query("SELECT id, status, deadline FROM milestones WHERE id=80");
print_r($res->fetch_assoc());
