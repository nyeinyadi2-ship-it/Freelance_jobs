<?php
require 'config/db.php';
$conn->query("INSERT INTO assignments (job_id, freelancer_id) VALUES (64, 6)");
if($conn->error) {
    echo "Error on assignments: " . $conn->error . "\n";
}
$conn->query("INSERT INTO job_applications (job_id, freelancer_id, status) VALUES (64, 6, 'accepted')");
if($conn->error) {
    echo "Error on applications: " . $conn->error . "\n";
}
echo "Done\n";
