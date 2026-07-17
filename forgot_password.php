<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

if (!empty($_SESSION['user_id'])) {
  $role = $_SESSION['role'];
  if ($role === 'admin') redirect('admin/admin_dashboard.php');
  if ($role === 'company') redirect('company/dashboard.php');
  if ($role === 'freelancer') redirect('freelancer/dashboard.php');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = __('error.invalid_request');
    } else {
        $email = trim($_POST['email'] ?? '');
        if ($email === '') {
            $error = __('error.email_password_required');
        } else {
            $stmt = $conn->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($user) {
                $new_password = bin2hex(random_bytes(8));
                $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
                $stmt->bind_param('si', $hashed, $user['id']);
                $stmt->execute();
                $stmt->close();
                $success = 'A new password has been sent to your email address. Please check your inbox.';
            } else {
                $error = 'No account found with that email address.';
            }
        }
    }
}

$page_title = __('login.title');
require __DIR__ . '/includes/header.php';
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
  .auth-card {
    width: 100%;
    max-width: 26rem;
    position: relative;
    z-index: 1;
  }
  .auth-input-group { position: relative; }
  .auth-input-icon {
    position: absolute;
    left: 1rem;
    top: 50%;
    transform: translateY(-50%);
    width: 1.125rem;
    height: 1.125rem;
    color: var(--color-text-placeholder);
    pointer-events: none;
  }
  .auth-input {
    width: 100%;
    padding: 0.75rem 1rem 0.75rem 2.75rem;
    border-radius: 0.75rem;
    font-size: 0.875rem;
    background: var(--color-bg);
    border: 1px solid var(--color-border);
    color: var(--color-text-primary);
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
  }
  .auth-input:focus {
    outline: none;
    border-color: #4f46e5;
    box-shadow: 0 0 0 3px rgba(99,102,241,0.15);
  }
  .auth-submit {
    width: 100%;
    padding: 0.75rem 1.5rem;
    border-radius: 0.75rem;
    font-weight: 600;
    font-size: 0.875rem;
    color: white;
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
  }
  .auth-submit:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 24px rgba(99,102,241,0.3);
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
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
        </span>
        <span class="gradient-text"><?= e(__('app.name')) ?></span>
      </a>
      <p class="mt-3 text-sm" style="color:var(--color-text-muted)">Reset your password</p>
    </div>

    <div class="rounded-2xl p-6 sm:p-8" style="background:var(--color-card);border:1px solid var(--color-border);box-shadow:0 4px 24px rgba(0,0,0,0.06);">

      <?php if ($error): ?>
        <div class="shake mb-5 p-3.5 rounded-xl flex items-center gap-2.5 text-sm font-medium" style="background:rgba(239,68,68,0.08);color:#dc2626;border:1px solid rgba(239,68,68,0.15);">
          <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          <span><?= e($error) ?></span>
        </div>
      <?php endif; ?>

      <?php if ($success): ?>
        <div class="mb-5 p-3.5 rounded-xl flex items-center gap-2.5 text-sm font-medium" style="background:rgba(16,185,129,0.08);color:#059669;border:1px solid rgba(16,185,129,0.15);">
          <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          <span><?= e($success) ?></span>
        </div>
        <p class="mt-4 text-center text-sm">
          <a href="<?= e(base_url('login.php')) ?>" class="font-semibold text-indigo-600 hover:text-indigo-500 transition-colors">Back to Login</a>
        </p>
      <?php else: ?>
        <form method="POST" class="space-y-5" novalidate>
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

          <div class="auth-input-group">
            <label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)">Email address</label>
            <div class="relative">
              <svg class="auth-input-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
              <input type="email" name="email" required class="auth-input" placeholder="you@example.com" value="<?= e($_POST['email'] ?? '') ?>" autocomplete="email">
            </div>
          </div>

          <button type="submit" class="auth-submit">
            <span class="relative z-10 flex items-center justify-center gap-2">
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
              Send Reset Link
            </span>
          </button>
        </form>
      <?php endif; ?>
    </div>

    <p class="mt-6 text-center text-sm" style="color:var(--color-text-muted)">
      Remember your password?
      <a href="<?= e(base_url('login.php')) ?>" class="font-semibold text-indigo-600 hover:text-indigo-500 transition-colors">Back to Login</a>
    </p>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
