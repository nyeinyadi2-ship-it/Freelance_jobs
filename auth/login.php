<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';

if (!empty($_SESSION['user_id'])) {
  $role = $_SESSION['role'];
  if ($role === 'admin') redirect('admin/admin_dashboard.php');
  if ($role === 'company') redirect('company/index.php');
  if ($role === 'freelancer') redirect('freelancer/dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $error = 'Email and password are required.';
        } else {
            $has_status_col = has_account_status_column();
            $sql = $has_status_col
                ? 'SELECT id, username, email, password, role, profile_image, account_status FROM users WHERE email = ?'
                : 'SELECT id, username, email, password, role, profile_image FROM users WHERE email = ?';
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($user && password_verify($password, $user['password'])) {
                $account_status = $has_status_col ? ($user['account_status'] ?? 'active') : 'active';
                if ($account_status === 'suspended') {
                    $error = 'Your account has been suspended. Please contact support.';
                } elseif ($account_status === 'blocked') {
                    $error = 'Your account has been blocked. Please contact support.';
                } else {
                    $_SESSION['user_id'] = (int) $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['profile_image'] = $user['profile_image'];

                    $admin_id = get_admin_user_id($conn);
                    if ($admin_id && $admin_id !== (int) $user['id']) {
                        $role_label = ucfirst($user['role']);
                        create_notification($conn, $admin_id, 'login_event', "{$role_label} \"{$user['username']}\" has logged in.", null);
                    }

                    if ($user['role'] === 'company') {
                        $_SESSION['profile_id'] = get_company_id($conn, (int) $user['id']);
                        $stmt = $conn->prepare('SELECT logo_image FROM companies WHERE user_id = ?');
                        $stmt->bind_param('i', $user['id']);
                        $stmt->execute();
                        $company = $stmt->get_result()->fetch_assoc();
                        $_SESSION['logo_image'] = $company['logo_image'] ?? null;
                        $stmt->close();
                        redirect('company/index.php');
                    } elseif ($user['role'] === 'freelancer') {
                        $_SESSION['profile_id'] = get_freelancer_id($conn, (int) $user['id']);
                        $_SESSION['logo_image'] = null;
                        redirect('freelancer/dashboard.php');
                    } else {
                        $_SESSION['profile_id'] = null;
                        $_SESSION['logo_image'] = null;
                        redirect('admin/admin_dashboard.php');
                    }
                }
            } else {
                $error = 'Invalid email or password.';
            }
        }
    }
}

$page_title = 'Login';
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
  .auth-input.is-invalid {
    border-color: #ef4444;
    box-shadow: 0 0 0 4px rgba(239,68,68,0.1);
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
  .auth-submit::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent);
    transform: translateX(-100%);
    transition: transform 0.6s ease;
  }
  .auth-submit:hover::after {
    transform: translateX(100%);
  }
  .social-btn {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.625rem;
    border-radius: 10px;
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    border: 2px solid var(--color-border);
    background: var(--color-card);
    color: var(--color-text-secondary);
  }
  .social-btn:hover {
    border-color: #6366f1;
    background: rgba(99,102,241,0.04);
    transform: translateY(-1px);
  }
  .auth-divider {
    display: flex;
    align-items: center;
    gap: 1rem;
    color: var(--color-text-placeholder);
    font-size: 0.8125rem;
  }
  .auth-divider::before,
  .auth-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--color-border);
  }
  .password-toggle {
    position: absolute;
    right: 0.875rem;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    cursor: pointer;
    color: var(--color-text-placeholder);
    padding: 0.25rem;
    border-radius: 4px;
    transition: color 0.2s ease;
  }
  .password-toggle:hover {
    color: var(--color-text-secondary);
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
    <!-- Logo / Brand -->
    <div class="text-center mb-8">
      <a href="<?= e(base_url('index.php')) ?>" class="inline-flex items-center gap-2 text-2xl font-bold">
        <span class="w-10 h-10 rounded-xl flex items-center justify-center text-white text-lg" style="background:linear-gradient(135deg, #4f46e5, #7c3aed);">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
        </span>
        <span class="gradient-text"><?= e('FreelanceHub') ?></span>
      </a>
      <p class="mt-3 text-sm" style="color:var(--color-text-muted)"><?= e('Welcome back! Sign in to continue') ?></p>
    </div>

    <!-- Card -->
    <div class="rounded-2xl p-6 sm:p-8" style="background:var(--color-card);border:1px solid var(--color-border);box-shadow:0 4px 24px rgba(0,0,0,0.06);">

      <!-- Error -->
      <?php if ($error): ?>
        <div class="shake mb-5 p-3.5 rounded-xl flex items-center gap-2.5 text-sm font-medium" style="background:rgba(239,68,68,0.08);color:#dc2626;border:1px solid rgba(239,68,68,0.15);">
          <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          <span><?= e($error) ?></span>
        </div>
      <?php endif; ?>

      <form method="POST" class="space-y-5" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <!-- Email -->
        <div class="auth-input-group">
          <label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)"><?= e('Email') ?></label>
          <div class="relative">
            <svg class="auth-input-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            <input type="email" name="email" required class="auth-input" placeholder="you@example.com" value="<?= e($_POST['email'] ?? '') ?>" autocomplete="email">
          </div>
        </div>

        <!-- Password -->
        <div class="auth-input-group">
          <label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)"><?= e('Password') ?></label>
          <div class="relative">
            <svg class="auth-input-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
            <input type="password" name="password" id="loginPassword" required class="auth-input" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;" autocomplete="current-password">
            <button type="button" class="password-toggle" onclick="togglePassword()" tabindex="-1" aria-label="Toggle password visibility">
              <svg id="eyeOpen" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
              <svg id="eyeClosed" class="w-5 h-5 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
            </button>
          </div>
        </div>

        <!-- Remember & Forgot -->
        <div class="flex items-center justify-between">
          <label class="flex items-center gap-2 text-sm cursor-pointer" style="color:var(--color-text-muted)">
            <input type="checkbox" name="remember" class="w-4 h-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" style="accent-color:#4f46e5">
            <?= e('Remember me') ?>
          </label>
          <a href="<?= e(base_url('auth/forgot_password.php')) ?>" class="text-sm font-medium text-indigo-600 hover:text-indigo-500 transition-colors"><?= e('Forgot password?') ?></a>
        </div>

        <button type="submit" class="auth-submit">
          <span class="relative z-10 flex items-center justify-center gap-2">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
            <?= e('Login') ?>
          </span>
        </button>
      </form>

      <!-- Social -->
      <div class="mt-6">
        <div class="auth-divider">
          <span><?= e('Or continue with') ?></span>
        </div>
        <div class="flex gap-3 mt-4">
          <button type="button" class="social-btn" onclick="alert('Google login coming soon.')">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
            Google
          </button>
          <button type="button" class="social-btn" onclick="alert('GitHub login coming soon.')">
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z"/></svg>
            GitHub
          </button>
        </div>
      </div>
    </div>

    <!-- Register link -->
    <p class="mt-6 text-center text-sm" style="color:var(--color-text-muted)">
      <?= e(__('login.no_account')) ?>
      <a href="<?= e(base_url('auth/register.php')) ?>" class="font-semibold text-indigo-600 hover:text-indigo-500 transition-colors"><?= e('Register') ?></a>
    </p>
  </div>
</div>

<script>
function togglePassword() {
  var pwd = document.getElementById('loginPassword');
  var open = document.getElementById('eyeOpen');
  var closed = document.getElementById('eyeClosed');
  if (pwd.type === 'password') {
    pwd.type = 'text';
    open.classList.add('hidden');
    closed.classList.remove('hidden');
  } else {
    pwd.type = 'password';
    open.classList.remove('hidden');
    closed.classList.add('hidden');
  }
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
