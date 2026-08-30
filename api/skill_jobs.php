<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/job_helpers.php';

header('Content-Type: application/json');

$raw_input = trim($_GET['id'] ?? ($_GET['skill'] ?? ($_GET['name'] ?? ($_GET['q'] ?? ''))));
$limit = min(max((int) ($_GET['limit'] ?? 6), 1), 24);

$skill_id = 0;
$skill_name = '';

if ($raw_input !== '') {
    if (is_numeric($raw_input) && (int)$raw_input > 0) {
        $skill_id = (int)$raw_input;
        $st_s = $conn->prepare("SELECT skill_name FROM skills WHERE id = ?");
        $st_s->bind_param("i", $skill_id);
        $st_s->execute();
        $res = $st_s->get_result()->fetch_assoc();
        if ($res) {
            $skill_name = $res['skill_name'];
        }
        $st_s->close();
    } else {
        // Look up by skill_name (exact match or partial match)
        $st_s = $conn->prepare("SELECT id, skill_name FROM skills WHERE LOWER(skill_name) = LOWER(?) LIMIT 1");
        $st_s->bind_param("s", $raw_input);
        $st_s->execute();
        $res = $st_s->get_result()->fetch_assoc();
        $st_s->close();
        if (!$res) {
            $like = '%' . $raw_input . '%';
            $st_s = $conn->prepare("SELECT id, skill_name FROM skills WHERE LOWER(skill_name) LIKE LOWER(?) LIMIT 1");
            $st_s->bind_param("s", $like);
            $st_s->execute();
            $res = $st_s->get_result()->fetch_assoc();
            $st_s->close();
        }
        if ($res) {
            $skill_id = (int)$res['id'];
            $skill_name = $res['skill_name'];
        } else {
            $skill_name = $raw_input;
        }
    }
}

if ($skill_id <= 0 && empty($skill_name)) {
    echo json_encode(['success' => false, 'error' => 'Skill is required', 'jobs' => [], 'count' => 0]);
    exit;
}

// Fetch jobs that match the skill via job_skills table or category matching
$sql = "SELECT j.id, j.title, j.description, j.budget, j.created_at, j.experience_level, j.duration, j.status,
               c.company_name, c.logo_image
        FROM jobs j
        LEFT JOIN companies c ON j.company_id = c.id
        WHERE j.status NOT IN ('closed', 'cancelled', 'expired') 
          AND NOT EXISTS (SELECT 1 FROM assignments a_dh WHERE a_dh.job_id = j.id AND a_dh.assignment_type = 'direct_hire')
          AND (
              (? > 0 AND EXISTS (SELECT 1 FROM job_skills js WHERE js.job_id = j.id AND js.skill_id = ?))
              OR (? != '' AND LOWER(j.category) = LOWER(?))
          )
        ORDER BY j.created_at DESC
        LIMIT ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param('iissi', $skill_id, $skill_id, $skill_name, $skill_name, $limit);
$stmt->execute();
$result = $stmt->get_result();
$jobs = [];
$completed_count = 0;
while ($row = $result->fetch_assoc()) {
    if (($row['status'] ?? '') === 'expired' || is_deadline_passed($row['deadline'] ?? null)) {
        continue;
    }
    if (($row['status'] ?? '') === 'completed') {
        if ($completed_count >= 1) continue;
        $completed_count++;
    }
    $row['description'] = mb_strimwidth(strip_tags($row['description'] ?? ''), 0, 120, '...');
    $row['budget'] = (float) $row['budget'];
    $row['logo_url'] = !empty($row['logo_image']) ? base_url('uploads/images/' . $row['logo_image']) : null;
    unset($row['logo_image']);
    $jobs[] = $row;
}
$stmt->close();

echo json_encode(['success' => true, 'jobs' => $jobs, 'count' => count($jobs), 'skill' => $skill_name]);
