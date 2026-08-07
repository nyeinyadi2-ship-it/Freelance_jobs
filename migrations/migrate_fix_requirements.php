<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../config/db.php';

$output = "Fixing jobs table...\n";

$sql = "ALTER TABLE jobs ADD COLUMN requirements TEXT AFTER description";
if ($conn->query($sql)) {
    $output .= "Successfully added requirements column to jobs.\n";
} else {
    $output .= "Error adding requirements column: " . $conn->error . "\n";
}

echo nl2br(htmlspecialchars($output));
