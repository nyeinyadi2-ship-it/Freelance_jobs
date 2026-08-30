<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/chat.php';
require_once __DIR__ . '/../config/notifications.php';

csrf_cookie();

if (!empty($_SESSION['user_id'])) {
    $role = $_SESSION['role'];
    if ($role === 'admin') redirect('admin/admin_dashboard.php');
    if ($role === 'company') redirect('index.php');
    if ($role === 'freelancer') redirect('index.php');
}

$error = '';
$success = false;
$step = (int) ($_POST['step'] ?? 1);
$recovery_email = $_SESSION['recovery_email'] ?? '';
$verification_question = $_SESSION['recovery_question'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Invalid request. Please try again.';
        $step = 1;
    } else {
        if ($step === 1) {
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            if ($username === '' || $email === '') {
                $error = 'Please enter both your username and registered email address.';
            } else {
                $stmt = $conn->prepare("SELECT id, username, email, security_question FROM users WHERE username = ? AND email = ?");
                $stmt->bind_param('ss', $username, $email);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($user && !empty($user['security_question'])) {
                    $_SESSION['recovery_user_id'] = (int) $user['id'];
                    $_SESSION['recovery_email'] = $user['email'];
                    $_SESSION['recovery_question'] = $user['security_question'];
                    $_SESSION['recovery_token'] = bin2hex(random_bytes(16));
                    $recovery_email = $user['email'];
                    $verification_question = $user['security_question'];
                    $step = 2;
                } else {
                    $error = 'No account found with that username and email combination. Please check and try again.';
                }
            }
        } elseif ($step === 2) {
            $answer = trim($_POST['verification_answer'] ?? '');
            if ($answer === '') {
                $error = 'Please enter your verification answer.';
                $step = 2;
            } elseif (empty($_SESSION['recovery_user_id'])) {
                $error = 'Session expired. Please start over.';
                $step = 1;
            } else {
                $user_id = (int) $_SESSION['recovery_user_id'];

                $stmt = $conn->prepare("SELECT security_answer_hash FROM users WHERE id = ?");
                $stmt->bind_param('i', $user_id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $stored_hash = $row['security_answer_hash'] ?? '';
                if ($stored_hash && password_verify(strtolower(trim($answer)), $stored_hash)) {
                    $message_text = trim($_POST['message'] ?? '');

                    $admin_id = get_admin_user_id($conn);
                    if ($admin_id) {
                        $meta = json_encode([
                            "is_recovery_request" => true,
                            "status" => "Verified",
                            "verification_completed" => true
                        ]);

                        $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message, message_type, message_meta) VALUES (?, ?, ?, 'text', ?)");
                        $msg_display = $message_text !== '' ? $message_text : 'Password recovery request - identity verified via security question.';
                        $stmt->bind_param('iiss', $user_id, $admin_id, $msg_display, $meta);
                        $stmt->execute();
                        $msg_id = $stmt->insert_id;
                        $stmt->close();

                        $u1 = min($user_id, $admin_id);
                        $u2 = max($user_id, $admin_id);
                        $stmt = $conn->prepare('INSERT INTO conversations (user_one_id, user_two_id, last_message_id, last_activity) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE last_message_id = VALUES(last_message_id), last_activity = NOW()');
                        $stmt->bind_param('iii', $u1, $u2, $msg_id);
                        $stmt->execute();
                        $stmt->close();
                    }

                    unset($_SESSION['recovery_email'], $_SESSION['recovery_question']);
                    redirect('auth/recovery_chat.php');
                } else {
                    $error = 'Incorrect verification answer. Please try again.';
                    $step = 2;
                }
            }
        }
    }
}

$page_title = 'Forgot Password';
require __DIR__ . '/../includes/header.php';
?>
<style>
  .auth-wrapper {
    min-height: calc(100vh - 4rem);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem 1rem;
    position: relative;
    background: linear-gradient(135deg, #eef2ff 0%, #f5f3ff 50%, #ede9fe 100%);
  }
  .dark .auth-wrapper {
    background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #0f172a 100%);
  }
  .auth-wrapper::before {
    content: '';
    position: absolute;
    top: -30%;
    left: -10%;
    width: 400px;
    height: 400px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(99,102,241,0.12) 0%, transparent 70%);
    pointer-events: none;
  }
  .auth-wrapper::after {
    content: '';
    position: absolute;
    bottom: -20%;
    right: -5%;
    width: 300px;
    height: 300px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(168,85,247,0.1) 0%, transparent 70%);
    pointer-events: none;
  }
  .auth-card {
    width: 100%;
    max-width: 440px;
    position: relative;
    z-index: 1;
    animation: authEntry 0.5s cubic-bezier(0.16, 1, 0.3, 1);
  }
  @keyframes authEntry {
    from { opacity: 0; transform: translateY(24px) scale(0.96); }
    to { opacity: 1; transform: translateY(0) scale(1); }
  }
  .auth-input {
    width: 100%;
    padding: 0.75rem 1rem 0.75rem 2.5rem;
    border: 2px solid var(--color-input-border);
    border-radius: 12px;
    background: var(--color-input-bg);
    color: var(--color-text-primary);
    font-size: 0.9375rem;
    transition: all 0.2s ease;
    outline: none;
  }
  .auth-input:focus {
    border-color: #6366f1;
    box-shadow: 0 0 0 4px rgba(99,102,241,0.12);
  }
  .auth-input-icon {
    position: absolute;
    left: 0.875rem;
    top: 50%;
    transform: translateY(-50%);
    width: 1.125rem;
    height: 1.125rem;
    color: var(--color-text-placeholder);
    pointer-events: none;
    transition: color 0.2s ease;
  }
  .auth-input-group:focus-within .auth-input-icon {
    color: #6366f1;
  }
  .auth-submit {
    width: 100%;
    padding: 0.8125rem 1.5rem;
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
    color: #fff;
    border: none;
    border-radius: 12px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.25s ease;
    position: relative;
    overflow: hidden;
  }
  .auth-submit:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 24px rgba(79,70,229,0.35);
  }
  .auth-submit:active {
    transform: translateY(0);
  }
  .shake {
    animation: shake 0.4s ease;
  }
  @keyframes shake {
    0%, 100% { transform: translateX(0); }
    20% { transform: translateX(-6px); }
    40% { transform: translateX(6px); }
    60% { transform: translateX(-4px); }
    80% { transform: translateX(4px); }
  }
</style>

<div class="auth-wrapper">
  <div class="auth-card">
    <div class="text-center mb-8">
      <a href="<?= e(base_url('index.php')) ?>" class="inline-flex items-center gap-2 text-2xl font-bold">
        <span class="w-10 h-10 rounded-xl flex items-center justify-center text-white text-lg" style="background:linear-gradient(135deg, #4f46e5, #7c3aed);">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
        </span>
        <span class="gradient-text"><?= e('Forgot Your Password?') ?></span>
      </a>
      <p class="mt-3 text-sm" style="color:var(--color-text-muted)">
        <?php if ($step === 1): ?>
          <?= e('Enter your username and registered email address to begin password recovery.') ?>
        <?php else: ?>
          <?= e('Answer your verification question to continue.') ?>
        <?php endif; ?>
      </p>
    </div>

    <div class="rounded-2xl p-6 sm:p-8" style="background:var(--color-card);border:1px solid var(--color-border);box-shadow:0 4px 24px rgba(0,0,0,0.06);">

      <?php if ($error): ?>
        <div class="shake mb-5 p-3.5 rounded-xl flex items-start gap-2.5 text-sm font-medium" style="background:rgba(239,68,68,0.08);color:#dc2626;border:1px solid rgba(239,68,68,0.15);">
          <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          <div class="flex-1"><?= $error ?></div>
        </div>
      <?php endif; ?>

      <form method="POST" class="space-y-5" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="step" value="<?= $step ?>">

        <?php if ($step === 1): ?>
          <!-- Step 1: Enter Username and Email -->
          <div class="auth-input-group">
            <label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)"><?= e('Username') ?></label>
            <div class="relative">
              <svg class="auth-input-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
              <input type="text" name="username" required class="auth-input" placeholder="Your username" value="<?= e($_POST['username'] ?? '') ?>" autocomplete="username">
            </div>
          </div>

          <div class="auth-input-group">
            <label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)"><?= e('Registered Email') ?></label>
            <div class="relative">
              <svg class="auth-input-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
              <input type="email" name="email" required class="auth-input" placeholder="you@example.com" value="<?= e($_POST['email'] ?? '') ?>">
            </div>
          </div>

          <button type="submit" class="auth-submit">
            <span class="relative z-10 flex items-center justify-center gap-2">
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
              <?= e('Continue') ?>
            </span>
          </button>

        <?php elseif ($step === 2): ?>
          <!-- Step 2: Answer Verification Question -->
          <div class="p-4 rounded-xl text-sm" style="background:rgba(99,102,241,0.06);border:1px solid rgba(99,102,241,0.15);color:var(--color-text-secondary)">
            <div class="flex items-start gap-2.5">
              <svg class="w-5 h-5 flex-shrink-0 mt-0.5 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
              <div>
                <strong>Account Verification</strong><br>
                Please answer your verification question to continue.
              </div>
            </div>
          </div>

          <div class="auth-input-group">
            <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-primary)">
              <?= e($verification_question) ?>
            </label>
            <div class="relative">
              <svg class="auth-input-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z"/></svg>
              <input type="text" name="verification_answer" required class="auth-input" placeholder="Your answer" autocomplete="off">
            </div>
          </div>

          <div class="auth-input-group">
            <label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)"><?= e('Additional Message (Optional)') ?></label>
            <div class="relative">
              <svg class="auth-input-icon" style="top:1.5rem" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
              <textarea name="message" class="auth-input" rows="2" placeholder="Any additional info for Admin..." style="resize:none; padding-top: 0.75rem; min-height: 60px;"></textarea>
            </div>
          </div>

          <button type="submit" class="auth-submit">
            <span class="relative z-10 flex items-center justify-center gap-2">
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
              <?= e('Verify & Contact Admin') ?>
            </span>
          </button>
        <?php endif; ?>
      </form>
    </div>

    <p class="mt-6 text-center text-sm" style="color:var(--color-text-muted)">
      <a href="<?= e(base_url('auth/login.php')) ?>" class="font-semibold text-indigo-600 hover:text-indigo-500 transition-colors">&larr; <?= e('Back to Login') ?></a>
    </p>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
