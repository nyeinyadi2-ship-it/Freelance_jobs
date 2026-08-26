<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/chat.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$admin_id = (int) $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

if (!empty($input['csrf_token'])) {
    $_POST['csrf_token'] = $input['csrf_token'];
}

if (!verify_csrf()) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

$action = $input['action'] ?? '';

if ($action === 'generate_temp_password') {
    $user_id = (int) ($input['user_id'] ?? 0);
    
    if ($user_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid user ID']);
        exit;
    }

    function generateSecureTempPassword() {
        $uppers = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lowers = 'abcdefghijklmnopqrstuvwxyz';
        $numbers = '0123456789';
        $specials = '!@#$%^&*()_+-=';
        
        $password = '';
        $password .= $uppers[random_int(0, strlen($uppers) - 1)];
        $password .= $lowers[random_int(0, strlen($lowers) - 1)];
        $password .= $numbers[random_int(0, strlen($numbers) - 1)];
        $password .= $specials[random_int(0, strlen($specials) - 1)];
        
        $all = $uppers . $lowers . $numbers . $specials;
        for ($i = 0; $i < 6; $i++) {
            $password .= $all[random_int(0, strlen($all) - 1)];
        }
        
        return str_shuffle($password);
    }
    
    $temp_password = generateSecureTempPassword();
    $temp_hash = password_hash($temp_password, PASSWORD_DEFAULT);
    
    // Update users.password
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $stmt->bind_param('si', $temp_hash, $user_id);
    $stmt->execute();
    $stmt->close();
    
    // Mark latest recovery request as Resolved and inject temp password flags
    $stmt = $conn->prepare("SELECT id, message_meta FROM messages WHERE sender_id = ? AND JSON_EXTRACT(message_meta, '$.is_recovery_request') = true ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $meta = json_decode($row['message_meta'], true) ?? [];
        $meta['status'] = 'Resolved';
        $meta['must_change_password'] = true;
        $meta['expires_at'] = time() + (24 * 3600); // 24 hours
        $new_meta = json_encode($meta);
        
        $update_stmt = $conn->prepare("UPDATE messages SET message_meta = ? WHERE id = ?");
        $update_stmt->bind_param('si', $new_meta, $row['id']);
        $update_stmt->execute();
        $update_stmt->close();
    }
    $stmt->close();
    
    // Send system message
    $msg = "A temporary password was generated and the recovery request was marked as Resolved.";
    $meta = json_encode(["is_system" => true]);
    $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message, message_type, message_meta) VALUES (?, ?, ?, 'system', ?)");
    $stmt->bind_param('iiss', $admin_id, $user_id, $msg, $meta);
    $stmt->execute();
    $msg_id = $stmt->insert_id;
    $stmt->close();
    
    $u1 = min($user_id, $admin_id);
    $u2 = max($user_id, $admin_id);
    $stmt = $conn->prepare('INSERT INTO conversations (user_one_id, user_two_id, last_message_id, last_activity) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE last_message_id = VALUES(last_message_id), last_activity = NOW()');
    $stmt->bind_param('iii', $u1, $u2, $msg_id);
    $stmt->execute();
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'temp_password' => $temp_password
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid action']);
