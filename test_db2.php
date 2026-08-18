<?php
require 'config/db.php';
$res = $conn->query('SHOW COLUMNS FROM freelancer_payment_settings');
while($row = $res->fetch_assoc()) echo $row['Field'].' '.$row['Type']."\n";
