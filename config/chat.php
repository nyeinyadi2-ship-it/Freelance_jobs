<?php

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
        WHERE a.status IN ('assigned', 'submitted', 'completed')
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
    $params = [$user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id];
    $types = 'iiiiiiii';

    if ($search && trim($search) !== '') {
        $search_term = '%' . trim($search) . '%';
        $search_filter = "AND (COALESCE(f.full_name, comp.company_name, u.username) LIKE ? OR (SELECT message FROM messages WHERE (sender_id = ? AND receiver_id = u.id) OR (sender_id = u.id AND receiver_id = ?) ORDER BY created_at DESC LIMIT 1) LIKE ?)";
        $params[] = $search_term;
        $params[] = $user_id;
        $params[] = $user_id;
        $params[] = $search_term;
        $types .= 'siss';
    }

    $stmt = $conn->prepare("
        SELECT u.id AS other_user_id, u.username AS other_username,
               u.profile_image AS other_profile_image, u.last_activity AS other_last_activity, u.role AS other_role,
               COALESCE(f.full_name, comp.company_name) AS other_display_name,
               (SELECT message FROM messages WHERE (sender_id = ? AND receiver_id = u.id) OR (sender_id = u.id AND receiver_id = ?) ORDER BY created_at DESC LIMIT 1) AS last_message,
               (SELECT created_at FROM messages WHERE (sender_id = ? AND receiver_id = u.id) OR (sender_id = u.id AND receiver_id = ?) ORDER BY created_at DESC LIMIT 1) AS last_message_time,
               (SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND sender_id = u.id AND status = 'unread') AS unread_count
        FROM users u
        LEFT JOIN freelancers f ON f.user_id = u.id
        LEFT JOIN companies comp ON comp.user_id = u.id
        WHERE u.id != ?
        AND EXISTS (
            SELECT 1 FROM assignments a
            JOIN jobs j ON a.job_id = j.id
            WHERE a.status IN ('assigned', 'submitted', 'completed')
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
        $row['last_message'] = $row['last_message'] ? htmlspecialchars_decode($row['last_message']) : null;
        $row['is_online'] = is_online($row['other_last_activity']);
        $conversations[] = $row;
    }
    $stmt->close();
    return $conversations;
}

function get_admin_conversations(mysqli $conn, ?string $search = null): array
{
    if (!messages_table_exists($conn)) return [];

    $search_filter = '';
    $params = [];
    $types = '';

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
               (SELECT created_at FROM messages WHERE (sender_id = u.id OR receiver_id = u.id) ORDER BY created_at DESC LIMIT 1) AS last_message_time,
               (SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND sender_id = u.id AND status = 'unread') AS unread_count
        FROM users u
        LEFT JOIN freelancers f ON f.user_id = u.id
        LEFT JOIN companies comp ON comp.user_id = u.id
        JOIN messages m ON m.sender_id = u.id OR m.receiver_id = u.id
        {$search_filter}
        ORDER BY last_message_time DESC
    ");
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $conversations = [];
    while ($row = $result->fetch_assoc()) {
        $row['last_message'] = $row['last_message'] ? htmlspecialchars_decode($row['last_message']) : null;
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

    // Admin can read all messages between any two users
    if ($role === 'admin') {
        $stmt = $conn->prepare("
            SELECT m.id, m.sender_id, m.receiver_id, m.message, m.status, m.created_at,
                   u_sender.username AS sender_username, u_sender.profile_image AS sender_profile_image,
                   u_receiver.username AS receiver_username
            FROM messages m
            JOIN users u_sender ON m.sender_id = u_sender.id
            JOIN users u_receiver ON m.receiver_id = u_receiver.id
            WHERE (m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)
            ORDER BY m.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bind_param('iiiiii', $user_id, $other_user_id, $other_user_id, $user_id, $limit, $offset);
    } else {
        $stmt = $conn->prepare("
            SELECT m.id, m.sender_id, m.receiver_id, m.message, m.status, m.created_at,
                   u_sender.username AS sender_username, u_sender.profile_image AS sender_profile_image,
                   u_receiver.username AS receiver_username
            FROM messages m
            JOIN users u_sender ON m.sender_id = u_sender.id
            JOIN users u_receiver ON m.receiver_id = u_receiver.id
            WHERE (m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)
            ORDER BY m.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bind_param('iiiiii', $user_id, $other_user_id, $other_user_id, $user_id, $limit, $offset);
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
