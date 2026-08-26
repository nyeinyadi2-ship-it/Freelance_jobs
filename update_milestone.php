<?php
require 'config/db.php';
$conn->query("UPDATE milestones SET freelancer_id = 6 WHERE id = 80");
if($conn->error) echo $conn->error; else echo "Updated milestone\n";
