<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/chat.php';
require_once __DIR__ . '/../config/notifications.php';

// Set CSRF cookie early
csrf_cookie();

if (empty($_SESSION['recovery_user_id']) || empty($_SESSION['recovery_token'])) {
    redirect('auth/forgot_password.php');
}

$user_id = (int) $_SESSION['recovery_user_id'];
$admin_id = get_admin_user_id($conn);

if (!$admin_id) {
    die("Admin account not found.");
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $message_text = trim($_POST['message'] ?? '');
        if ($message_text !== '') {
            $meta = json_encode(["is_recovery_request" => true]);
            $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message, message_type, message_meta) VALUES (?, ?, ?, 'text', ?)");
            $stmt->bind_param('iiss', $user_id, $admin_id, $message_text, $meta);
            $stmt->execute();
            $msg_id = $stmt->insert_id;
            $stmt->close();
            
            $u1 = min($user_id, $admin_id);
            $u2 = max($user_id, $admin_id);
            $stmt = $conn->prepare('INSERT INTO conversations (user_one_id, user_two_id, last_message_id, last_activity) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE last_message_id = VALUES(last_message_id), last_activity = NOW()');
            $stmt->bind_param('iii', $u1, $u2, $msg_id);
            $stmt->execute();
            $stmt->close();
            
            // Redirect to prevent resubmission
            redirect('auth/recovery_chat.php');
        }
    }
}

// Fetch messages
$stmt = $conn->prepare("
    SELECT m.id, m.sender_id, m.receiver_id, m.message, m.created_at, m.message_meta,
           u_sender.username AS sender_username
    FROM messages m
    JOIN users u_sender ON m.sender_id = u_sender.id
    WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
    ORDER BY m.created_at ASC
");
$stmt->bind_param('iiii', $user_id, $admin_id, $admin_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();
$messages = [];
while ($row = $result->fetch_assoc()) {
    $messages[] = $row;
}
$stmt->close();

$page_title = 'Password Reset Support';
require __DIR__ . '/../includes/header.php';
?>
<style>
  .chat-wrapper {
    max-width: 600px;
    margin: 2rem auto;
    background: var(--color-card);
    border: 1px solid var(--color-border);
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.05);
    display: flex;
    flex-direction: column;
    height: 70vh;
  }
  .chat-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--color-border);
    background: linear-gradient(to right, rgba(99,102,241,0.05), transparent);
    border-radius: 12px 12px 0 0;
  }
  .chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 1.5rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
    background: var(--color-bg);
  }
  .msg-bubble {
    max-width: 80%;
    padding: 0.75rem 1rem;
    border-radius: 12px;
    position: relative;
    word-wrap: break-word;
    font-size: 0.9375rem;
  }
  .msg-sent {
    align-self: flex-end;
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    color: white;
    border-bottom-right-radius: 4px;
  }
  .msg-received {
    align-self: flex-start;
    background: var(--color-card);
    border: 1px solid var(--color-border);
    color: var(--color-text-primary);
    border-bottom-left-radius: 4px;
  }
  .chat-input-area {
    padding: 1.25rem 1.5rem;
    border-top: 1px solid var(--color-border);
    background: var(--color-card);
    border-radius: 0 0 12px 12px;
  }
  .chat-input {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 1px solid var(--color-border);
    border-radius: 20px;
    background: var(--color-input-bg);
    color: var(--color-text-primary);
    outline: none;
    resize: none;
  }
  .chat-input:focus {
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99,102,241,0.1);
  }
  .send-btn {
    padding: 0.75rem 1.5rem;
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    color: white;
    border: none;
    border-radius: 20px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
  }
  .send-btn:hover {
    box-shadow: 0 4px 12px rgba(79,70,229,0.3);
  }
</style>

<div class="container mx-auto px-4">
    <div class="chat-wrapper">
        <div class="chat-header flex items-center justify-between">
            <div>
                <h2 class="text-lg font-bold gradient-text">Password Reset Support</h2>
                <p class="text-sm" style="color:var(--color-text-muted)">Chat with Admin to recover your account</p>
            </div>
            <a href="<?= e(base_url('auth/login.php')) ?>" class="text-sm font-medium text-indigo-600 hover:text-indigo-500">Back to Login</a>
        </div>

        <div class="chat-messages" id="chatBox">
            <div class="msg-bubble msg-received">
                <strong>System:</strong><br>
                Please wait while Admin verifies your account. You can leave additional information below.
            </div>
            
            <?php foreach ($messages as $msg): ?>
                <?php $is_mine = ((int)$msg['sender_id'] === $user_id); ?>
                <div class="msg-bubble <?= $is_mine ? 'msg-sent' : 'msg-received' ?>">
                    <?php if (!$is_mine): ?>
                        <strong class="text-xs opacity-70 block mb-1">Admin</strong>
                    <?php endif; ?>
                    <?= nl2br(e($msg['message'])) ?>
                    <div class="text-xs opacity-70 text-right mt-1" style="font-size: 0.7rem;">
                        <?= e(date('H:i', strtotime($msg['created_at']))) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="chat-input-area">
            <?php if ($error): ?>
                <div class="text-red-500 text-sm mb-2"><?= $error ?></div>
            <?php endif; ?>
            <form method="POST" class="flex gap-2">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="text" name="message" class="chat-input flex-1" placeholder="Type a message..." required autocomplete="off">
                <button type="submit" class="send-btn">Send</button>
            </form>
        </div>
    </div>
</div>

<script>
    // Auto scroll to bottom
    const chatBox = document.getElementById('chatBox');
    chatBox.scrollTop = chatBox.scrollHeight;
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
