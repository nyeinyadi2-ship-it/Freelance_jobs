<?php
require 'config/db.php';
$res = $conn->query("SHOW CREATE TABLE proposal_projects");
$row = $res->fetch_assoc();
echo $row['Create Table'] . "\n";
