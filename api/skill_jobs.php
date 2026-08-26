<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json');

$skill_id = (int) ($_GET['id'] ?? 0);
$limit = min(max((int) ($_GET['limit'] ?? 6), 1), 24);

if ($skill_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Skill ID is required']);
    exit;
}

// Get skill name for display
$st_s = $conn->prepare("SELECT skill_name FROM skills WHERE id = ?");
$st_s->bind_param("i", $skill_id);
$st_s->execute();
$st_s->bind_result($skill_name);
$st_s->fetch();
$st_s->close();

// Fetch jobs that match the skill
$sql = "SELECT j.id, j.title, j.description, j.budget, j.created_at, j.experience_level, j.duration,
               c.company_name, c.logo_image
        FROM jobs j
        JOIN companies c ON j.company_id = c.id
        WHERE j.status IN ('open', 'in_review', 'hired', 'in_progress', 'completed', 'cancelled', 'closed') AND j.category != 'Direct Hire'
        AND EXISTS (SELECT 1 FROM job_skills js WHERE js.job_id = j.id AND js.skill_id = ?)
        ORDER BY j.created_at DESC
        LIMIT ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $skill_id, $limit);
$stmt->execute();
$result = $stmt->get_result();
$jobs = [];
$completed_count = 0;
while ($row = $result->fetch_assoc()) {
    if ($row['status'] === 'completed') {
        if ($completed_count >= 1) continue;
        $completed_count++;
    }
    $row['description'] = mb_strimwidth(strip_tags($row['description'] ?? ''), 0, 120, '...');
    $row['budget'] = (float) $row['budget'];
    $row['logo_url'] = $row['logo_image'] ? base_url('uploads/images/' . $row['logo_image']) : null;
    unset($row['logo_image']);
    $jobs[] = $row;
}
$stmt->close();

echo json_encode(['success' => true, 'jobs' => $jobs, 'count' => count($jobs), 'skill' => $skill_name]);
