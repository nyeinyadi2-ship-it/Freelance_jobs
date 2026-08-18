<?php
require 'config/db.php';
$r = $conn->query("SELECT id, title FROM jobs WHERE id IN (SELECT job_id FROM job_skills WHERE skill_id IN (SELECT id FROM skills WHERE skill_name = 'UI/UX Design'))");
while ($row = $r->fetch_assoc()) {
    echo $row['id'] . ' | ' . $row['title'] . "\n";
}
