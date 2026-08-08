<?php
// Ensure any PHP error results in a JSON response
header('Content-Type: application/json');

set_error_handler(function($severity, $message, $file, $line) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'detail' => $message]);
    exit;
});

set_exception_handler(function($e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'detail' => $e->getMessage()]);
    exit;
});

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';
require_once __DIR__ . '/../config/chat.php';

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
        echo json_encode(['messages' => get_messages_enhanced($conn, $user_id, $other_id, $offset)]);
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
        $partner = get_partner_info($conn, $user_id, $other_id);
        if ($partner) {
            $partner['last_seen_text'] = get_last_seen_text($partner['last_activity'], $partner['is_online'] ?? null);
        }
        echo json_encode(['user' => $partner]);
        exit;
    }

    if ($action === 'search_conversations') {
        $q = trim($_GET['q'] ?? '');
        echo json_encode(['conversations' => get_conversations($conn, $user_id, $q ?: null)]);
        exit;
    }

    if ($action === 'typing_status') {
        $partner_id = (int) ($_GET['partner_id'] ?? 0);
        if ($partner_id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid partner ID']);
            exit;
        }
        echo json_encode(['is_typing' => get_typing_status($conn, $user_id, $partner_id)]);
        exit;
    }

    if ($action === 'get_notifications') {
        require_once __DIR__ . '/../config/notifications.php';
        $limit = min((int) ($_GET['limit'] ?? 5), 20);
        echo json_encode([
            'notifications' => get_notifications($conn, $user_id, $limit),
            'count' => get_unread_notification_count($conn, $user_id)
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
    exit;
}

if ($method === 'POST') {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = [];

    if (strpos($contentType, 'multipart/form-data') !== false) {
        $input = $_POST;
    } else {
        $json = json_decode(file_get_contents('php://input'), true);
        if ($json) $input = $json;
        else $input = $_POST;
    }

    $action = $input['action'] ?? '';

    if (!empty($input['csrf_token'])) {
        $_POST['csrf_token'] = $input['csrf_token'];
    }

    if (!verify_csrf()) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }

    if ($action === 'send_message' || $action === 'send_file') {
        $receiver_id = (int) ($input['receiver_id'] ?? 0);
        $message = trim($input['message'] ?? '');
        $message_type = $input['message_type'] ?? ($action === 'send_file' ? 'file' : 'text');

        if ($receiver_id <= 0 || ($message === '' && empty($_FILES['attachment']))) {
            http_response_code(400);
            echo json_encode(['error' => 'Receiver and message required']);
            exit;
        }

        if ($role !== 'admin' && !can_chat($conn, $user_id, $receiver_id)) {
            http_response_code(403);
            echo json_encode(['error' => 'You can only message users you have an active assignment with']);
            exit;
        }

        // If file present, auto-set message_type to 'file'
        if (!empty($_FILES['attachment'])) {
            $message_type = 'file';
        }

        $file = !empty($_FILES['attachment']) ? $_FILES['attachment'] : null;
        $message_id = send_message_with_attachment($conn, $user_id, $receiver_id, $message, $file, $message_type);

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

            $msgs = get_messages_enhanced($conn, $user_id, $receiver_id, 0, 1);
            echo json_encode(['success' => true, 'message_id' => $message_id, 'message' => $msgs[0] ?? null]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to send message']);
        }
        exit;
    }

    if ($action === 'mark_read') {
        $other_id = (int) ($input['user_id'] ?? $input['partner_id'] ?? 0);
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

    if ($action === 'typing') {
        $partner_id = (int) ($input['partner_id'] ?? 0);
        $is_typing = !empty($input['is_typing']);
        if ($partner_id > 0) {
            set_typing_status($conn, $user_id, $partner_id, $is_typing);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'upload_attachment') {
        if (empty($_FILES['file'])) {
            http_response_code(400);
            echo json_encode(['error' => 'No file uploaded']);
            exit;
        }
        $result = upload_chat_attachment($_FILES['file']);
        if ($result === null) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid file type or too large']);
            exit;
        }
        echo json_encode(['success' => true, 'file' => $result]);
        exit;
    }

    if ($action === 'mark_notif_read') {
        require_once __DIR__ . '/../config/notifications.php';
        $nid = (int) ($input['notification_id'] ?? 0);
        if ($nid > 0) {
            mark_notification_read($conn, $nid, $user_id);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'edit_message') {
        $msg_id = (int) ($input['message_id'] ?? 0);
        $new_text = trim($input['message'] ?? '');
        if ($msg_id <= 0 || $new_text === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid data']);
            exit;
        }
        $stmt = $conn->prepare("UPDATE messages SET message = ?, is_edited = 1 WHERE id = ? AND sender_id = ? AND is_deleted = 0");
        $stmt->bind_param('sii', $new_text, $msg_id, $user_id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected > 0) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(403);
            echo json_encode(['error' => 'Cannot edit this message']);
        }
        exit;
    }

    if ($action === 'delete_message') {
        $msg_id = (int) ($input['message_id'] ?? 0);
        if ($msg_id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid data']);
            exit;
        }
        $stmt = $conn->prepare("UPDATE messages SET is_deleted = 1 WHERE id = ? AND sender_id = ?");
        $stmt->bind_param('ii', $msg_id, $user_id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected > 0) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(403);
            echo json_encode(['error' => 'Cannot delete this message']);
        }
        exit;
    }

    if ($action === 'delete_conversation') {
        $partner_id = (int) ($input['partner_id'] ?? 0);
        if ($partner_id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid data']);
            exit;
        }
        $stmt = $conn->prepare("UPDATE messages SET hidden_for = CASE WHEN hidden_for IS NULL OR hidden_for = '' THEN ? ELSE CONCAT(hidden_for, ',', ?) END WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)) AND (hidden_for IS NULL OR NOT FIND_IN_SET(?, hidden_for))");
        $str_user = (string) $user_id;
        $stmt->bind_param('ssiiiis', $str_user, $str_user, $user_id, $partner_id, $partner_id, $user_id, $str_user);
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
