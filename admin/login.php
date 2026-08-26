<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';

// Set CSRF cookie early (before any HTML output)
csrf_cookie();

if (!empty($_SESSION['user_id'])) {
    $role = $_SESSION['role'];
    if ($role === 'admin') redirect('admin/admin_dashboard.php');
    if ($role === 'company') redirect('company/dashboard.php');
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
                } elseif ($user['role'] !== 'admin') {
                    $error = 'You do not have permission to access that page.';
                } else {
                    $_SESSION['user_id'] = (int) $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['profile_image'] = $user['profile_image'];
                    $_SESSION['profile_id'] = null;
                    $_SESSION['logo_image'] = null;

                    // Regenerate session ID to prevent session fixation
                    regenerate_session();

                    $admin_id = get_admin_user_id($conn);
                    if ($admin_id && $admin_id !== (int) $user['id']) {
                        create_notification($conn, $admin_id, 'login_event', "Logged in.", null, (int)$user['id']);
                    }

                    redirect('admin/admin_dashboard.php');
                }
            } else {
                $error = 'Invalid email or password.';
            }
        }
    }
}

$page_title = 'Admin Login';
require __DIR__ . '/../includes/header.php';
?>
<style>
    .auth-wrapper {
        min-height: calc(100vh - 4rem);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 2rem 1rem;
        background: linear-gradient(135deg, #1e1b4b 0%, #312e81 50%, #4338ca 100%);
        border-radius: 16px; /* slightly round the edges inside the main container */
        margin: -2rem -1rem; /* counteract main padding to fill */
    }
    .auth-card {
        width: 100%;
        max-width: 420px;
        animation: authEntry 0.5s cubic-bezier(0.16, 1, 0.3, 1);
    }
    @keyframes authEntry {
        from { opacity: 0; transform: translateY(24px) scale(0.96); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }
    .auth-input {
        width: 100%;
        padding: 0.75rem 1rem 0.75rem 2.5rem;
        border: 2px solid rgba(255,255,255,0.2);
        border-radius: 12px;
        background: rgba(255,255,255,0.1);
        color: #ffffff;
        font-size: 0.9375rem;
        transition: all 0.2s ease;
        outline: none;
    }
    .auth-input::placeholder { color: rgba(255,255,255,0.5); }
    .auth-input:focus {
        border-color: rgba(255,255,255,0.5);
        box-shadow: 0 0 0 4px rgba(255,255,255,0.1);
        background: rgba(255,255,255,0.15);
    }
    .auth-input-icon {
        position: absolute;
        left: 0.875rem;
        top: 50%;
        transform: translateY(-50%);
        width: 1.125rem;
        height: 1.125rem;
        color: rgba(255,255,255,0.5);
        pointer-events: none;
    }
    .auth-input-group:focus-within .auth-input-icon {
        color: rgba(255,255,255,0.9);
    }
    .auth-submit {
        width: 100%;
        padding: 0.8125rem 1.5rem;
        background: #ffffff;
        color: #4338ca;
        border: none;
        border-radius: 12px;
        font-size: 1rem;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.25s ease;
    }
    .auth-submit:hover {
        transform: translateY(-1px);
        box-shadow: 0 8px 24px rgba(0,0,0,0.25);
    }
    .password-toggle {
        position: absolute;
        right: 0.875rem;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        cursor: pointer;
        color: rgba(255,255,255,0.5);
        padding: 0.25rem;
        border-radius: 4px;
        transition: color 0.2s ease;
    }
    .password-toggle:hover { color: rgba(255,255,255,0.9); }
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
                <a href="<?= e(base_url('index.php')) ?>" class="inline-flex items-center gap-2.5 text-2xl font-bold text-white">
                    <div class="w-11 h-11 rounded-xl bg-white/15 flex items-center justify-center">
                        <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                        </svg>
                    </div>
                    <span><?= e('FreelanceHub') ?></span>
                </a>
                <div class="mt-3 inline-flex items-center px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider bg-white/15 text-white/90">
                    <svg class="w-3.5 h-3.5 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                    Admin Access Only
                </div>
            </div>

            <div class="rounded-2xl p-6 sm:p-8 bg-white/10 backdrop-blur-sm border border-white/20">
                <?php if ($error): ?>
                    <div class="shake mb-5 p-3.5 rounded-xl flex items-center gap-2.5 text-sm font-medium bg-red-500/20 text-red-100 border border-red-500/30">
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span><?= e($error) ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-5" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                    <div class="auth-input-group">
                        <label class="block text-sm font-medium mb-1.5 text-white/80"><?= e('Email') ?></label>
                        <div class="relative">
                            <svg class="auth-input-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            <input type="email" name="email" required class="auth-input" placeholder="admin@example.com" value="<?= e($_POST['email'] ?? '') ?>" autocomplete="email">
                        </div>
                    </div>

                    <div class="auth-input-group">
                        <label class="block text-sm font-medium mb-1.5 text-white/80"><?= e('Password') ?></label>
                        <div class="relative">
                            <svg class="auth-input-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                            <input type="password" name="password" id="loginPassword" required class="auth-input" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;" autocomplete="current-password">
                            <button type="button" class="password-toggle" onclick="togglePassword()" tabindex="-1" aria-label="Toggle password visibility">
                                <svg id="eyeOpen" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                <svg id="eyeClosed" class="w-5 h-5 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="auth-submit">
                        <span class="flex items-center justify-center gap-2">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
                            <?= e('Login') ?>
                        </span>
                    </button>
                </form>
            </div>

            <p class="mt-6 text-center text-sm text-white/60">

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
