<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'count';

    if ($action === 'count') {
        echo json_encode([
            'count' => get_unread_notification_count($conn, $user_id)
        ]);
    } elseif ($action === 'list') {
        $limit = min((int) ($_GET['limit'] ?? 10), 50);
        $notifications = get_notifications($conn, $user_id, $limit);
        echo json_encode([
            'notifications' => $notifications,
            'count' => get_unread_notification_count($conn, $user_id)
        ]);
    } elseif ($action === 'types') {
        echo json_encode([
            'types' => get_notification_count_by_type($conn, $user_id)
        ]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
    }
} elseif ($method === 'POST') {
    if (!verify_csrf()) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? $_POST['action'] ?? '';

    if ($action === 'mark_read') {
        $nid = (int) ($input['notification_id'] ?? $_POST['notification_id'] ?? 0);
        if ($nid > 0) {
            mark_notification_read($conn, $nid, $user_id);
        }
        echo json_encode([
            'success' => true,
            'count' => get_unread_notification_count($conn, $user_id)
        ]);
    } elseif ($action === 'mark_all_read') {
        mark_all_notifications_read($conn, $user_id);
        echo json_encode([
            'success' => true,
            'count' => 0
        ]);
    } elseif ($action === 'delete') {
        $nid = (int) ($input['notification_id'] ?? $_POST['notification_id'] ?? 0);
        if ($nid > 0) {
            delete_notification($conn, $nid, $user_id);
        }
        echo json_encode([
            'success' => true,
            'count' => get_unread_notification_count($conn, $user_id)
        ]);
    } elseif ($action === 'delete_all') {
        delete_all_notifications($conn, $user_id);
        echo json_encode([
            'success' => true,
            'count' => 0
        ]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
