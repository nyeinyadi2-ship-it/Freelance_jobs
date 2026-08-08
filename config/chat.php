<?php

/**
 * Chat system enhanced functions
 */

function messages_table_exists(mysqli $conn): bool
{
    static $exists = null;
    if ($exists === null) {
        $result = $conn->query("SHOW TABLES LIKE 'messages'");
        $exists = $result && $result->num_rows > 0;
    }
    return $exists;
}

function can_chat(mysqli $conn, int $user_id, int $other_user_id): bool
{
    if (!messages_table_exists($conn)) return false;

    // Admin can chat with anyone who has sent/received messages
    $role = $_SESSION['role'] ?? '';
    if ($role === 'admin') return true;

    $stmt = $conn->prepare("
        SELECT 1 FROM assignments a
        JOIN jobs j ON a.job_id = j.id
        WHERE a.status IN ('assigned', 'working', 'submitted', 'completed')
        AND (
            (j.company_id = (SELECT id FROM companies WHERE user_id = ?) AND a.freelancer_id = (SELECT id FROM freelancers WHERE user_id = ?))
            OR
            (j.company_id = (SELECT id FROM companies WHERE user_id = ?) AND a.freelancer_id = (SELECT id FROM freelancers WHERE user_id = ?))
        )
        LIMIT 1
    ");
    $stmt->bind_param('iiii', $user_id, $other_user_id, $other_user_id, $user_id);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

function send_message(mysqli $conn, int $sender_id, int $receiver_id, string $message): ?int
{
    if (!messages_table_exists($conn)) return null;
    $stmt = $conn->prepare('INSERT INTO messages (sender_id, receiver_id, message) VALUES (?, ?, ?)');
    $stmt->bind_param('iis', $sender_id, $receiver_id, $message);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id > 0 ? $id : null;
}

function get_conversations(mysqli $conn, int $user_id, ?string $search = null): array
{
    if (!messages_table_exists($conn)) return [];
    $role = $_SESSION['role'] ?? '';

    if ($role === 'admin') {
        return get_admin_conversations($conn, $search);
    }

    $search_filter = '';
    $str_user_id = (string) $user_id;
    $params = [
        $user_id, $user_id, $str_user_id, // last_message (3)
        $user_id, $user_id, $str_user_id, // last_message_is_deleted (3)
        $user_id, $user_id, $str_user_id, // last_message_time (3)
        $user_id, $str_user_id,           // unread_count (2)
        $user_id,                         // u.id != ? (1)
        $user_id,                         // company user_id = ? (1)
        $user_id                          // freelancer user_id = ? (1)
    ];
    $types = 'iisiisiisisiii';

    if ($search && trim($search) !== '') {
        $search_term = '%' . trim($search) . '%';
        $search_filter = "AND (COALESCE(f.full_name, comp.company_name, u.username) LIKE ? OR (SELECT message FROM messages WHERE ((sender_id = ? AND receiver_id = u.id) OR (sender_id = u.id AND receiver_id = ?)) AND (hidden_for IS NULL OR NOT FIND_IN_SET(?, hidden_for)) ORDER BY created_at DESC LIMIT 1) LIKE ?)";
        $params[] = $search_term;
        $params[] = $user_id;
        $params[] = $user_id;
        $params[] = $str_user_id;
        $params[] = $search_term;
        $types .= 'siiss';
    }

    $stmt = $conn->prepare("
        SELECT u.id AS other_user_id, u.username AS other_username,
               u.profile_image AS other_profile_image, u.last_activity AS other_last_activity, u.role AS other_role,
               COALESCE(f.full_name, comp.company_name) AS other_display_name,
               (SELECT message FROM messages WHERE ((sender_id = ? AND receiver_id = u.id) OR (sender_id = u.id AND receiver_id = ?)) AND (hidden_for IS NULL OR NOT FIND_IN_SET(?, hidden_for)) ORDER BY created_at DESC LIMIT 1) AS last_message,
               (SELECT is_deleted FROM messages WHERE ((sender_id = ? AND receiver_id = u.id) OR (sender_id = u.id AND receiver_id = ?)) AND (hidden_for IS NULL OR NOT FIND_IN_SET(?, hidden_for)) ORDER BY created_at DESC LIMIT 1) AS last_message_is_deleted,
               (SELECT created_at FROM messages WHERE ((sender_id = ? AND receiver_id = u.id) OR (sender_id = u.id AND receiver_id = ?)) AND (hidden_for IS NULL OR NOT FIND_IN_SET(?, hidden_for)) ORDER BY created_at DESC LIMIT 1) AS last_message_time,
               (SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND sender_id = u.id AND status = 'unread' AND (hidden_for IS NULL OR NOT FIND_IN_SET(?, hidden_for))) AS unread_count
        FROM users u
        LEFT JOIN freelancers f ON f.user_id = u.id
        LEFT JOIN companies comp ON comp.user_id = u.id
        WHERE u.id != ?
        AND EXISTS (
            SELECT 1 FROM assignments a
            JOIN jobs j ON a.job_id = j.id
            WHERE a.status IN ('assigned', 'working', 'submitted', 'completed')
            AND (
                (j.company_id = (SELECT id FROM companies WHERE user_id = ?) AND a.freelancer_id = (SELECT id FROM freelancers WHERE user_id = u.id))
                OR
                (j.company_id = (SELECT id FROM companies WHERE user_id = u.id) AND a.freelancer_id = (SELECT id FROM freelancers WHERE user_id = ?))
            )
        )
        {$search_filter}
        ORDER BY last_message_time IS NULL, last_message_time DESC
    ");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $conversations = [];
    while ($row = $result->fetch_assoc()) {
        if (!empty($row['last_message_is_deleted'])) {
            $row['last_message'] = 'This message was deleted';
        } else {
            $row['last_message'] = $row['last_message'] ? htmlspecialchars_decode($row['last_message']) : null;
        }
        $row['is_online'] = is_online($row['other_last_activity']);
        $conversations[] = $row;
    }
    $stmt->close();
    return $conversations;
}

function get_admin_conversations(mysqli $conn, ?string $search = null): array
{
    if (!messages_table_exists($conn)) return [];

    $admin_id = (int) ($_SESSION['user_id'] ?? 0);
    if ($admin_id <= 0) return [];

    $params = [$admin_id];
    $types = 'i';
    $search_filter = '';

    if ($search && trim($search) !== '') {
        $search_term = '%' . trim($search) . '%';
        $search_filter = "WHERE (COALESCE(f.full_name, comp.company_name, u.username) LIKE ? OR (SELECT message FROM messages WHERE (sender_id = u.id OR receiver_id = u.id) ORDER BY created_at DESC LIMIT 1) LIKE ?)";
        $params[] = $search_term;
        $params[] = $search_term;
        $types .= 'ss';
    }

    $stmt = $conn->prepare("
        SELECT DISTINCT u.id AS other_user_id, u.username AS other_username,
               u.profile_image AS other_profile_image, u.last_activity AS other_last_activity, u.role AS other_role,
               COALESCE(f.full_name, comp.company_name) AS other_display_name,
               (SELECT message FROM messages WHERE (sender_id = u.id OR receiver_id = u.id) ORDER BY created_at DESC LIMIT 1) AS last_message,
               (SELECT is_deleted FROM messages WHERE (sender_id = u.id OR receiver_id = u.id) ORDER BY created_at DESC LIMIT 1) AS last_message_is_deleted,
               (SELECT created_at FROM messages WHERE (sender_id = u.id OR receiver_id = u.id) ORDER BY created_at DESC LIMIT 1) AS last_message_time,
               (SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND sender_id = u.id AND status = 'unread') AS unread_count
        FROM users u
        LEFT JOIN freelancers f ON f.user_id = u.id
        LEFT JOIN companies comp ON comp.user_id = u.id
        JOIN messages m ON m.sender_id = u.id OR m.receiver_id = u.id
        {$search_filter}
        ORDER BY last_message_time DESC
    ");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $conversations = [];
    while ($row = $result->fetch_assoc()) {
        if (!empty($row['last_message_is_deleted'])) {
            $row['last_message'] = 'This message was deleted';
        } else {
            $row['last_message'] = $row['last_message'] ? htmlspecialchars_decode($row['last_message']) : null;
        }
        $row['is_online'] = is_online($row['other_last_activity']);
        $conversations[] = $row;
    }
    $stmt->close();
    return $conversations;
}

function get_messages(mysqli $conn, int $user_id, int $other_user_id, int $offset = 0, int $limit = 100): array
{
    if (!messages_table_exists($conn)) return [];
    $role = $_SESSION['role'] ?? '';

    if ($role === 'admin') {
        $stmt = $conn->prepare("
            SELECT m.id, m.sender_id, m.receiver_id, m.message, m.message_type, m.message_meta, m.status, m.created_at, m.is_edited, m.is_deleted, m.hidden_for,
                   u_sender.username AS sender_username, u_sender.profile_image AS sender_profile_image,
                   u_receiver.username AS receiver_username
            FROM messages m
            JOIN users u_sender ON m.sender_id = u_sender.id
            JOIN users u_receiver ON m.receiver_id = u_receiver.id
            WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
            ORDER BY m.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bind_param('iiiiii', $user_id, $other_user_id, $other_user_id, $user_id, $limit, $offset);
    } else {
        $stmt = $conn->prepare("
            SELECT m.id, m.sender_id, m.receiver_id, m.message, m.message_type, m.message_meta, m.status, m.created_at, m.is_edited, m.is_deleted, m.hidden_for,
                   u_sender.username AS sender_username, u_sender.profile_image AS sender_profile_image,
                   u_receiver.username AS receiver_username
            FROM messages m
            JOIN users u_sender ON m.sender_id = u_sender.id
            JOIN users u_receiver ON m.receiver_id = u_receiver.id
            WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
              AND (m.hidden_for IS NULL OR NOT FIND_IN_SET(?, m.hidden_for))
            ORDER BY m.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $str_user_id = (string) $user_id;
        $stmt->bind_param('iiiisii', $user_id, $other_user_id, $other_user_id, $user_id, $str_user_id, $limit, $offset);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $messages[] = $row;
    }
    $stmt->close();
    return array_reverse($messages);
}

function mark_as_read(mysqli $conn, int $user_id, int $other_user_id): void
{
    if (!messages_table_exists($conn)) return;
    $stmt = $conn->prepare("UPDATE messages SET status = 'read' WHERE receiver_id = ? AND sender_id = ? AND status = 'unread'");
    $stmt->bind_param('ii', $user_id, $other_user_id);
    $stmt->execute();
    $stmt->close();
}

function get_unread_count(mysqli $conn, int $user_id): int
{
    if (!messages_table_exists($conn)) return 0;
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM messages WHERE receiver_id = ? AND status = 'unread'");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $count = (int) $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();
    return $count;
}

function get_partner_info(mysqli $conn, int $user_id, int $other_user_id): ?array
{
    $stmt = $conn->prepare("
        SELECT u.id, u.username, u.profile_image, u.last_activity, u.role,
               COALESCE(f.full_name, comp.company_name) AS display_name
        FROM users u
        LEFT JOIN freelancers f ON f.user_id = u.id
        LEFT JOIN companies comp ON comp.user_id = u.id
        WHERE u.id = ?
    ");
    $stmt->bind_param('i', $other_user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($result) {
        $result['is_online'] = is_online($result['last_activity']);
    }
    return $result ?: null;
}

function update_last_activity(mysqli $conn, int $user_id): void
{
    $stmt = $conn->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->close();
}

function is_online(?string $last_activity): bool
{
    if (!$last_activity) return false;
    $timestamp = strtotime($last_activity);
    return (time() - $timestamp) < 120;
}

function format_message_time(string $datetime): string
{
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;

    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j', $time);
}

function format_message_date(string $datetime): string
{
    $time = strtotime($datetime);
    $today = strtotime('today');
    $yesterday = strtotime('yesterday');

    if ($time >= $today) return 'Today';
    if ($time >= $yesterday) return 'Yesterday';
    return date('M j, Y', $time);
}

// ============ ENHANCED FUNCTIONS ============

function message_attachments_table_exists(mysqli $conn): bool
{
    static $exists = null;
    if ($exists === null) {
        $result = $conn->query("SHOW TABLES LIKE 'message_attachments'");
        $exists = $result && $result->num_rows > 0;
    }
    return $exists;
}

function send_message_with_attachment(mysqli $conn, int $sender_id, int $receiver_id, string $message, ?array $file = null, string $message_type = 'text'): ?int
{
    if (!messages_table_exists($conn)) return null;

    $stmt = $conn->prepare('INSERT INTO messages (sender_id, receiver_id, message, message_type) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('iiss', $sender_id, $receiver_id, $message, $message_type);
    $stmt->execute();
    $message_id = (int) $stmt->insert_id;
    $stmt->close();

    if ($message_id > 0 && $file !== null && !empty($file['name'])) {
        $att = upload_chat_attachment($file);
        if ($att !== null) {
            $stmt = $conn->prepare('INSERT INTO message_attachments (message_id, file_name, file_path, file_size, file_type) VALUES (?, ?, ?, ?, ?)');
            $stmt->bind_param('issis', $message_id, $att['name'], $att['path'], $att['size'], $att['type']);
            $stmt->execute();
            $stmt->close();
        }
    }

    // Update or create conversation
    $u1 = min($sender_id, $receiver_id);
    $u2 = max($sender_id, $receiver_id);
    $stmt = $conn->prepare('INSERT INTO conversations (user_one_id, user_two_id, last_message_id, last_activity) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE last_message_id = VALUES(last_message_id), last_activity = NOW()');
    $stmt->bind_param('iii', $u1, $u2, $message_id);
    $stmt->execute();
    $stmt->close();

    // Update last_activity
    update_last_activity($conn, $sender_id);

    return $message_id;
}

function upload_chat_attachment(array $file): ?array
{
    $allowed = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','zip','rar'];
    $max_size = 10 * 1024 * 1024; // 10MB

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return null;
    if ($file['size'] > $max_size) return null;

    $upload_dir = __DIR__ . '/../uploads/chat/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    $filename = 'chat_' . uniqid() . '_' . time() . '.' . $ext;
    $dest = $upload_dir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) return null;

    return [
        'name' => $file['name'],
        'path' => 'chat/' . $filename,
        'size' => $file['size'],
        'type' => $ext,
    ];
}

function get_message_attachments(mysqli $conn, int $message_id): array
{
    if (!message_attachments_table_exists($conn)) return [];
    $stmt = $conn->prepare('SELECT id, file_name, file_path, file_size, file_type FROM message_attachments WHERE message_id = ?');
    $stmt->bind_param('i', $message_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $attachments = [];
    while ($row = $result->fetch_assoc()) {
        $row['file_url'] = chat_file_url($row['file_path']);
        $size = (int) $row['file_size'];
        if ($size > 1048576) {
            $row['file_size_formatted'] = round($size / 1048576, 1) . ' MB';
        } elseif ($size > 1024) {
            $row['file_size_formatted'] = round($size / 1024, 1) . ' KB';
        } else {
            $row['file_size_formatted'] = $size . ' B';
        }
        $attachments[] = $row;
    }
    $stmt->close();
    return $attachments;
}

function set_typing_status(mysqli $conn, int $user_id, int $partner_id, bool $is_typing): void
{
    $stmt = $conn->prepare('INSERT INTO typing_status (user_id, conversation_partner_id, is_typing) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE is_typing = VALUES(is_typing), updated_at = NOW()');
    $val = $is_typing ? 1 : 0;
    $stmt->bind_param('iii', $user_id, $partner_id, $val);
    $stmt->execute();
    $stmt->close();
}

function get_typing_status(mysqli $conn, int $user_id, int $partner_id): bool
{
    $stmt = $conn->prepare('SELECT is_typing FROM typing_status WHERE user_id = ? AND conversation_partner_id = ? AND updated_at > DATE_SUB(NOW(), INTERVAL 10 SECOND)');
    $stmt->bind_param('ii', $partner_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ? (bool) $row['is_typing'] : false;
}

function get_last_seen_text(?string $last_activity, ?int $is_online = null): string
{
    if ($is_online === 1) return 'Online';
    if ($is_online === 0) return 'Offline';
    if (!$last_activity) return 'Offline';

    $time = strtotime($last_activity);
    $now = time();
    $diff = $now - $time;

    if ($diff < 120) return 'Online';
    if ($diff < 3600) return 'Last seen ' . floor($diff / 60) . 'm ago';
    if ($diff < 86400) return 'Last seen ' . floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return 'Last seen ' . floor($diff / 86400) . 'd ago';
    return 'Last seen ' . date('M j', $time);
}

function get_messages_enhanced(mysqli $conn, int $user_id, int $other_user_id, int $offset = 0, int $limit = 100): array
{
    $msgs = get_messages($conn, $user_id, $other_user_id, $offset, $limit);

    // Attach attachment data
    if (!empty($msgs) && message_attachments_table_exists($conn)) {
        foreach ($msgs as &$msg) {
            if ($msg['is_deleted']) {
                $msg['message'] = 'This message was deleted';
                $msg['attachments'] = [];
            } else {
                $msg['attachments'] = get_message_attachments($conn, (int) $msg['id']);
            }
        }
    }
    return $msgs;
}

function get_or_create_conversation_id(mysqli $conn, int $user_id, int $other_user_id): ?int
{
    $u1 = min($user_id, $other_user_id);
    $u2 = max($user_id, $other_user_id);

    $stmt = $conn->prepare('SELECT id FROM conversations WHERE user_one_id = ? AND user_two_id = ?');
    $stmt->bind_param('ii', $u1, $u2);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if ($row) return (int) $row['id'];

    $stmt = $conn->prepare('INSERT INTO conversations (user_one_id, user_two_id) VALUES (?, ?)');
    $stmt->bind_param('ii', $u1, $u2);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id > 0 ? $id : null;
}

function update_user_online_status(mysqli $conn, int $user_id, bool $is_online): void
{
    $val = $is_online ? 1 : 0;
    $stmt = $conn->prepare("UPDATE users SET is_online = ?, last_seen = NOW(), last_activity = NOW() WHERE id = ?");
    $stmt->bind_param('ii', $val, $user_id);
    $stmt->execute();
    $stmt->close();
}

function chat_file_url(string $file_path): string
{
    return base_url('uploads/' . $file_path);
}

function chat_file_icon(string $ext): string
{
    $icons = [
        'pdf' => 'text-red-500',
        'doc' => 'text-blue-500',
        'docx' => 'text-blue-600',
        'zip' => 'text-yellow-600',
        'rar' => 'text-orange-500',
        'jpg' => 'text-green-500',
        'jpeg' => 'text-green-500',
        'png' => 'text-green-500',
        'gif' => 'text-purple-500',
        'webp' => 'text-teal-500',
    ];
    $color = $icons[$ext] ?? 'text-gray-500';
    return $color;
}
