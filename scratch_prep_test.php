<?php
require 'config/db.php';

// Find a milestone that is in_progress
$sql = "SELECT id, title, deadline, status FROM milestones WHERE status = 'in_progress' LIMIT 1";
$res = $conn->query($sql);
$ms = $res->fetch_assoc();

if ($ms) {
    echo "Found milestone ID: " . $ms['id'] . " Title: " . $ms['title'] . "\n";
    // Set deadline to yesterday
    $yesterday = date('Y-m-d H:i:s', strtotime('-1 day'));
    $conn->query("UPDATE milestones SET deadline = '$yesterday' WHERE id = " . $ms['id']);
    echo "Deadline set to past ($yesterday).\n";
} else {
    echo "No in_progress milestone found.\n";
}
