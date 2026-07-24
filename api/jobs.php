<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$user = current_user();
if (!$user['user_id'] || ($user['role'] ?? '') !== 'company') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$job_id = (int) ($input['job_id'] ?? 0);
$csrf = $input['csrf_token'] ?? '';

if ($job_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid job ID']);
    exit;
}

// Verify company owns this job
$company_id = get_company_id($conn, (int) $user['user_id']);
if (!$company_id) {
    echo json_encode(['success' => false, 'error' => 'Company not found']);
    exit;
}

$stmt = $conn->prepare('SELECT id, title FROM jobs WHERE id = ? AND company_id = ?');
$stmt->bind_param('ii', $job_id, $company_id);
$stmt->execute();
$job = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$job) {
    echo json_encode(['success' => false, 'error' => 'Job not found or access denied']);
    exit;
}

if ($action === 'delete') {
    // Check if job has active assignments
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM assignments WHERE job_id = ? AND status IN ('assigned', 'working', 'submitted')");
    $stmt->bind_param('i', $job_id);
    $stmt->execute();
    $active = (int) $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();

    if ($active > 0) {
        echo json_encode(['success' => false, 'error' => 'Cannot delete job with active assignments. Complete or cancel them first.']);
        exit;
    }

    // Delete related records first
    $conn->begin_transaction();
    try {
        // Delete job skills
        $stmt = $conn->prepare('DELETE FROM job_skills WHERE job_id = ?');
        $stmt->bind_param('i', $job_id);
        $stmt->execute();
        $stmt->close();

        // Delete job applications
        $stmt = $conn->prepare('DELETE FROM job_applications WHERE job_id = ?');
        $stmt->bind_param('i', $job_id);
        $stmt->execute();
        $stmt->close();

        // Delete the job
        $stmt = $conn->prepare('DELETE FROM jobs WHERE id = ? AND company_id = ?');
        $stmt->bind_param('ii', $job_id, $company_id);
        $stmt->execute();
        $deleted = $stmt->affected_rows > 0;
        $stmt->close();

        $conn->commit();

        if ($deleted) {
            echo json_encode(['success' => true, 'message' => 'Job "' . htmlspecialchars($job['title']) . '" has been deleted.']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to delete job.']);
        }
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => 'Delete failed: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
}
