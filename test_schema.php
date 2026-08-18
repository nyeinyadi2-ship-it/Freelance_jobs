<?php
require 'config/db.php';
$r = $conn->query("SHOW COLUMNS FROM payments");
while($row = $r->fetch_assoc()) echo $row['Field'].' ';
