<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json');

$skill_name = trim(urldecode($_GET['skill'] ?? ''));
$limit = min(max((int) ($_GET['limit'] ?? 6), 1), 24);

if ($skill_name === '') {
    echo json_encode(['success' => false, 'error' => 'Skill name is required']);
    exit;
}

// Fetch jobs that match the skill
$sql = "SELECT j.id, j.title, j.description, j.budget, j.created_at, j.experience_level, j.duration,
               c.company_name, c.logo_image
        FROM jobs j
        JOIN companies c ON j.company_id = c.id
        JOIN job_skills js ON js.job_id = j.id
        JOIN skills s ON js.skill_id = s.id
        WHERE s.skill_name = ? AND j.status IN ('open', 'position_filled') AND j.category != 'Direct Hire'
        ORDER BY j.created_at DESC
        LIMIT ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('si', $skill_name, $limit);
$stmt->execute();
$result = $stmt->get_result();
$jobs = [];
while ($row = $result->fetch_assoc()) {
    $row['description'] = mb_strimwidth(strip_tags($row['description'] ?? ''), 0, 120, '...');
    $row['budget'] = (float) $row['budget'];
    $row['logo_url'] = $row['logo_image'] ? base_url('uploads/images/' . $row['logo_image']) : null;
    unset($row['logo_image']);
    $jobs[] = $row;
}
$stmt->close();

echo json_encode(['success' => true, 'jobs' => $jobs, 'count' => count($jobs), 'skill' => $skill_name]);
