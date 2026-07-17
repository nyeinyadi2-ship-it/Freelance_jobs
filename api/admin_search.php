<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user = current_user();
if (!$user || $user['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// Helper: check if a column exists in a table
function search_column_exists(mysqli $conn, string $table, string $column): bool
{
    $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    $exists = $result && $result->num_rows > 0;
    if ($result) $result->free();
    return $exists;
}

$q = trim($_GET['q'] ?? '');
if ($q === '' || mb_strlen($q) < 2) {
    echo json_encode(['results' => []]);
    exit;
}

$like = '%' . $q . '%';
$results = [];

// 1. Search Jobs
try {
    $has_visibility = search_column_exists($conn, 'jobs', 'visibility');
    $visibility_col = $has_visibility ? 'j.visibility,' : '';
    $stmt = $conn->prepare("
        SELECT j.id, j.title, j.budget, j.status, {$visibility_col} c.company_name
        FROM jobs j
        JOIN companies c ON j.company_id = c.id
        WHERE j.title LIKE ? OR j.description LIKE ? OR j.category LIKE ?
        ORDER BY j.created_at DESC
        LIMIT 5
    ");
    $stmt->bind_param('sss', $like, $like, $like);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $vis = $row['visibility'] ?? 'public';
        $results[] = [
            'type' => 'job',
            'icon' => 'M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
            'title' => $row['title'],
            'subtitle' => '$' . number_format((float)$row['budget'], 0) . ' · ' . $row['company_name'],
            'badge' => ucfirst($row['status']),
            'badge_color' => $row['status'] === 'approved' ? 'green' : ($row['status'] === 'completed' ? 'blue' : ($row['status'] === 'rejected' ? 'red' : 'amber')),
            'url' => 'admin/approve_jobs.php?filter=' . ($vis === 'private' ? 'hidden' : 'active'),
        ];
    }
    $stmt->close();
} catch (mysqli_sql_exception $e) {}

// 2. Search Companies (Clients)
try {
    $stmt = $conn->prepare("
        SELECT c.id, c.company_name, c.industry, u.email, u.username, u.id AS user_id
        FROM companies c
        JOIN users u ON c.user_id = u.id
        WHERE c.company_name LIKE ? OR c.industry LIKE ? OR u.username LIKE ? OR u.email LIKE ?
        ORDER BY c.company_name
        LIMIT 5
    ");
    $stmt->bind_param('ssss', $like, $like, $like, $like);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $results[] = [
            'type' => 'company',
            'icon' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
            'title' => $row['company_name'],
            'subtitle' => $row['industry'] ?? $row['email'],
            'badge' => 'Client',
            'badge_color' => 'emerald',
            'url' => 'admin/view_user.php?id=' . $row['user_id'],
        ];
    }
    $stmt->close();
} catch (mysqli_sql_exception $e) {}

// 3. Search Freelancers
try {
    $has_status = has_account_status_column();
    $status_col = $has_status ? 'u.account_status,' : "'active' AS account_status,";
    $stmt = $conn->prepare("
        SELECT f.id, f.full_name, f.title, f.experience_years, u.email, u.username, u.id AS user_id, {$status_col} u.role
        FROM freelancers f
        JOIN users u ON f.user_id = u.id
        WHERE f.full_name LIKE ? OR f.title LIKE ? OR u.username LIKE ? OR u.email LIKE ?
        ORDER BY f.full_name
        LIMIT 5
    ");
    $stmt->bind_param('ssss', $like, $like, $like, $like);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $results[] = [
            'type' => 'freelancer',
            'icon' => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
            'title' => $row['full_name'] ?: $row['username'],
            'subtitle' => $row['title'] ?: $row['email'],
            'badge' => ucfirst($row['account_status'] ?? 'active'),
            'badge_color' => ($row['account_status'] ?? 'active') === 'active' ? 'green' : (($row['account_status'] ?? '') === 'suspended' ? 'amber' : 'red'),
            'url' => 'admin/view_user.php?id=' . $row['user_id'],
        ];
    }
    $stmt->close();
} catch (mysqli_sql_exception $e) {}

// 4. Search Skills/Categories
try {
    $stmt = $conn->prepare("
        SELECT id, skill_name FROM skills WHERE skill_name LIKE ? ORDER BY skill_name LIMIT 3
    ");
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $results[] = [
            'type' => 'category',
            'icon' => 'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z',
            'title' => $row['skill_name'],
            'subtitle' => 'Skill / Category',
            'badge' => 'Skill',
            'badge_color' => 'purple',
            'url' => 'admin/approve_jobs.php?filter=all',
        ];
    }
    $stmt->close();
} catch (mysqli_sql_exception $e) {}

// 5. Search Payments
try {
    $stmt = $conn->prepare("
        SELECT p.id, p.amount, p.status, p.paid_at, j.title AS job_title, c.company_name
        FROM payments p
        JOIN assignments a ON p.assignment_id = a.id
        JOIN jobs j ON a.job_id = j.id
        JOIN companies c ON j.company_id = c.id
        WHERE j.title LIKE ? OR c.company_name LIKE ?
        ORDER BY p.paid_at DESC
        LIMIT 5
    ");
    $stmt->bind_param('ss', $like, $like);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $results[] = [
            'type' => 'payment',
            'icon' => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
            'title' => '$' . number_format((float)$row['amount'], 2) . ' — ' . $row['job_title'],
            'subtitle' => $row['company_name'] . ' · ' . ($row['paid_at'] ?? 'Pending'),
            'badge' => ucfirst($row['status']),
            'badge_color' => $row['status'] === 'paid' ? 'green' : 'amber',
            'url' => 'admin/approve_jobs.php',
        ];
    }
    $stmt->close();
} catch (mysqli_sql_exception $e) {}

// 6. Search Messages
try {
    $stmt = $conn->prepare("
        SELECT m.id, m.message, m.created_at,
               u1.username AS sender_name, u2.username AS receiver_name
        FROM messages m
        JOIN users u1 ON m.sender_id = u1.id
        JOIN users u2 ON m.receiver_id = u2.id
        WHERE m.message LIKE ?
        ORDER BY m.created_at DESC
        LIMIT 5
    ");
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $results[] = [
            'type' => 'message',
            'icon' => 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z',
            'title' => mb_strimwidth($row['message'], 0, 60, '...'),
            'subtitle' => $row['sender_name'] . ' → ' . $row['receiver_name'],
            'badge' => 'Message',
            'badge_color' => 'slate',
            'url' => 'admin/manage_users.php',
        ];
    }
    $stmt->close();
} catch (mysqli_sql_exception $e) {}

// 7. Search Job Applications (Reviews)
try {
    $stmt = $conn->prepare("
        SELECT ja.id, ja.status, ja.applied_at, j.title AS job_title, f.full_name, c.company_name
        FROM job_applications ja
        JOIN jobs j ON ja.job_id = j.id
        JOIN freelancers f ON ja.freelancer_id = f.id
        JOIN companies c ON j.company_id = c.id
        WHERE j.title LIKE ? OR f.full_name LIKE ? OR c.company_name LIKE ?
        ORDER BY ja.applied_at DESC
        LIMIT 5
    ");
    $stmt->bind_param('sss', $like, $like, $like);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $results[] = [
            'type' => 'application',
            'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2',
            'title' => $row['full_name'] . ' → ' . $row['job_title'],
            'subtitle' => $row['company_name'] . ' · ' . $row['applied_at'],
            'badge' => ucfirst($row['status']),
            'badge_color' => $row['status'] === 'accepted' ? 'green' : ($row['status'] === 'rejected' ? 'red' : 'amber'),
            'url' => 'admin/approve_jobs.php?filter=all',
        ];
    }
    $stmt->close();
} catch (mysqli_sql_exception $e) {}

// 8. Search Assignments (Reports)
try {
    $stmt = $conn->prepare("
        SELECT a.id, a.status, a.assigned_at, j.title AS job_title, f.full_name, c.company_name
        FROM assignments a
        JOIN jobs j ON a.job_id = j.id
        JOIN freelancers f ON a.freelancer_id = f.id
        JOIN companies c ON j.company_id = c.id
        WHERE j.title LIKE ? OR f.full_name LIKE ? OR c.company_name LIKE ?
        ORDER BY a.assigned_at DESC
        LIMIT 5
    ");
    $stmt->bind_param('sss', $like, $like, $like);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $results[] = [
            'type' => 'assignment',
            'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
            'title' => $row['full_name'] . ' → ' . $row['job_title'],
            'subtitle' => $row['company_name'] . ' · ' . ucfirst($row['status']),
            'badge' => ucfirst($row['status']),
            'badge_color' => $row['status'] === 'completed' ? 'green' : 'indigo',
            'url' => 'admin/approve_jobs.php?filter=all',
        ];
    }
    $stmt->close();
} catch (mysqli_sql_exception $e) {}

// 9. Search Users (by username/email - catch-all for anything not found above)
try {
    $stmt = $conn->prepare("
        SELECT u.id, u.username, u.email, u.role
        FROM users u
        WHERE (u.username LIKE ? OR u.email LIKE ?)
        AND u.id NOT IN (
            SELECT c.user_id FROM companies c WHERE c.company_name LIKE ? OR c.industry LIKE ?
            UNION
            SELECT f.user_id FROM freelancers f WHERE f.full_name LIKE ? OR f.title LIKE ?
        )
        ORDER BY u.created_at DESC
        LIMIT 3
    ");
    $stmt->bind_param('ssssss', $like, $like, $like, $like, $like, $like);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        if ($row['role'] === 'admin') continue;
        $results[] = [
            'type' => 'user',
            'icon' => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z',
            'title' => $row['username'],
            'subtitle' => $row['email'],
            'badge' => ucfirst($row['role']),
            'badge_color' => $row['role'] === 'company' ? 'emerald' : 'purple',
            'url' => 'admin/view_user.php?id=' . $row['id'],
        ];
    }
    $stmt->close();
} catch (mysqli_sql_exception $e) {}

echo json_encode(['results' => $results]);
