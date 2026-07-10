<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/chat.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

if (!in_array($role, ['company', 'freelancer', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

update_last_activity($conn, $user_id);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'list_conversations') {
        $search = $_GET['search'] ?? null;
        echo json_encode(['conversations' => get_conversations($conn, $user_id, $search)]);
        exit;
    }

    if ($action === 'get_messages') {
        $other_id = (int) ($_GET['user_id'] ?? 0);
        $offset = (int) ($_GET['offset'] ?? 0);

        if ($other_id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid user ID']);
            exit;
        }

        if ($role !== 'admin' && !can_chat($conn, $user_id, $other_id)) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            exit;
        }

        if ($role !== 'admin') {
            mark_as_read($conn, $user_id, $other_id);
        }
        echo json_encode(['messages' => get_messages($conn, $user_id, $other_id, $offset)]);
        exit;
    }

    if ($action === 'get_unread_count') {
        echo json_encode(['count' => get_unread_count($conn, $user_id)]);
        exit;
    }

    if ($action === 'get_partner_info') {
        $other_id = (int) ($_GET['user_id'] ?? 0);
        if ($other_id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid user ID']);
            exit;
        }
        if ($role !== 'admin' && !can_chat($conn, $user_id, $other_id)) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            exit;
        }
        echo json_encode(['user' => get_partner_info($conn, $user_id, $other_id)]);
        exit;
    }

    if ($action === 'search_conversations') {
        $q = trim($_GET['q'] ?? '');
        echo json_encode(['conversations' => get_conversations($conn, $user_id, $q ?: null)]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = $_POST;
    $action = $input['action'] ?? '';

    if (!empty($input['csrf_token'])) {
        $_POST['csrf_token'] = $input['csrf_token'];
    }

    if (!verify_csrf()) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }

    if ($action === 'send_message') {
        $receiver_id = (int) ($input['receiver_id'] ?? 0);
        $message = trim($input['message'] ?? '');

        if ($receiver_id <= 0 || $message === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Receiver and message required']);
            exit;
        }

        if ($role !== 'admin' && !can_chat($conn, $user_id, $receiver_id)) {
            http_response_code(403);
            echo json_encode(['error' => 'You can only message users you have an active assignment with']);
            exit;
        }

        $message_id = send_message($conn, $user_id, $receiver_id, $message);

        if ($message_id) {
            $partner = get_partner_info($conn, $user_id, $receiver_id);
            if ($partner) {
                $stmt = $conn->prepare("SELECT j.title FROM assignments a JOIN jobs j ON a.job_id = j.id JOIN companies c ON j.company_id = c.id WHERE (c.user_id = ? AND a.freelancer_id = (SELECT id FROM freelancers WHERE user_id = ?)) OR (c.user_id = ? AND a.freelancer_id = (SELECT id FROM freelancers WHERE user_id = ?)) LIMIT 1");
                $stmt->bind_param('iiii', $user_id, $receiver_id, $receiver_id, $user_id);
                $stmt->execute();
                $jr = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $job_title = $jr['title'] ?? 'your assignment';
                create_notification($conn, $receiver_id, 'new_message', 'New message from ' . $_SESSION['username'] . ' about "' . $job_title . '"', 'chat/index.php?user_id=' . $user_id);
            }

            $msg = get_messages($conn, $user_id, $receiver_id, 0, 1);
            echo json_encode(['success' => true, 'message_id' => $message_id, 'message' => $msg[0] ?? null]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to send message']);
        }
        exit;
    }

    if ($action === 'mark_read') {
        $other_id = (int) ($input['user_id'] ?? 0);
        if ($other_id > 0) {
            mark_as_read($conn, $user_id, $other_id);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'mark_all_read') {
        $stmt = $conn->prepare("UPDATE messages SET status = 'read' WHERE receiver_id = ? AND status = 'unread'");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
