<?php
require 'config/db.php';
$skill_name = 'Graphic Design';
$st = $conn->prepare('SELECT id, skill_name FROM skills WHERE skill_name = ?');
$st->bind_param('s', $skill_name);
$st->execute();
$skill_info = $st->get_result()->fetch_assoc();
$st->close();

$fl_freelancer_id = 1; // dummy

$params = [$fl_freelancer_id, $skill_info['id']];
$types = 'ii';

$sql = "SELECT j.id,j.title,j.status,
        c.company_name,
        (SELECT ja.status FROM job_applications ja WHERE ja.job_id=j.id AND ja.freelancer_id=?) AS my_status,
        (SELECT COUNT(*) FROM assignments a WHERE a.job_id=j.id AND a.status != 'completed') AS assigned_count,
        (SELECT GROUP_CONCAT(s.skill_name SEPARATOR ',') FROM job_skills js2 JOIN skills s ON js2.skill_id = s.id WHERE js2.job_id = j.id) AS skills_concat
        FROM jobs j JOIN companies c ON j.company_id=c.id
        JOIN job_skills js ON js.job_id = j.id
        WHERE j.status IN ('open', 'position_filled') AND j.category != 'Direct Hire' AND js.skill_id = ?
        ORDER BY j.created_at DESC";

$st = $conn->prepare($sql);
$st->bind_param($types, ...$params);
$st->execute(); 
$r = $st->get_result();
$jobs = [];
while ($row = $r->fetch_assoc()) {
    $jobs[] = $row;
}
$st->close();
print_r($jobs);
