<?php
require 'config/db.php';

echo "=== submissions rows ===\n";
$r = $conn->query("SELECT id, assignment_id, freelancer_id, status, created_at FROM submissions");
while ($row = $r->fetch_assoc()) {
    echo "  id={$row['id']} assignment_id={$row['assignment_id']} freelancer_id={$row['freelancer_id']} status={$row['status']}\n";
}

echo "\n=== orphaned submissions (assignment_id not in assignments) ===\n";
$r2 = $conn->query("SELECT s.id, s.assignment_id FROM submissions s LEFT JOIN assignments a ON s.assignment_id = a.id WHERE s.assignment_id IS NOT NULL AND a.id IS NULL");
while ($row = $r2->fetch_assoc()) {
    echo "  submission id={$row['id']} has orphaned assignment_id={$row['assignment_id']}\n";
}

echo "\n=== assignments table (first 10) ===\n";
$r3 = $conn->query("SELECT id, status FROM assignments LIMIT 10");
while ($row = $r3->fetch_assoc()) {
    echo "  assignment id={$row['id']} status={$row['status']}\n";
}
