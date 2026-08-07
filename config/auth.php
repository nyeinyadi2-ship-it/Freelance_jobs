<?php

// Configure session cookie BEFORE session_start()
// CSRF failures on POST requests are almost always caused by the browser
// not sending the session cookie due to missing SameSite attribute.
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', '86400');
    ini_set('session.cookie_lifetime', '0'); // Browser session
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/upload.php';

function base_url(string $path = ''): string
{
    $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $root = preg_replace('#/(admin|auth|company|freelancer|chat|api|migrations)$#', '', $script);
    $root = rtrim($root, '/');

    if ($path === '') {
        return $root === '' ? '/' : $root;
    }

    return ($root === '' ? '' : $root) . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    header('Location: ' . base_url($path));
    exit;
}

function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        set_flash('error', 'Please log in to continue.');
        redirect('auth/login.php');
    }

    // Check if account is suspended or blocked (only if column exists)
    if (!has_account_status_column()) {
        return;
    }

    $status = $_SESSION['account_status'] ?? null;
    if ($status === null) {
        // Fetch from DB if not cached in session
        global $conn;
        $stmt = $conn->prepare('SELECT account_status FROM users WHERE id = ?');
        $uid = (int) $_SESSION['user_id'];
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $status = $row ? ($row['account_status'] ?? 'active') : 'active';
        $_SESSION['account_status'] = $status;
    }

    if ($status === 'suspended') {
        session_destroy();
        set_flash('error', 'Your account has been suspended. Please contact support.');
        redirect('auth/login.php');
    } elseif ($status === 'blocked') {
        session_destroy();
        set_flash('error', 'Your account has been blocked. Please contact support.');
        redirect('auth/login.php');
    }
}

function require_role(string $role): void
{
    require_login();

    if (($_SESSION['role'] ?? '') !== $role) {
        set_flash('error', 'You do not have permission to access that page.');
        redirect('auth/login.php');
    }
}

function current_user(): array
{
    return [
        'user_id' => $_SESSION['user_id'] ?? null,
        'username' => $_SESSION['username'] ?? null,
        'email' => $_SESSION['email'] ?? null,
        'role' => $_SESSION['role'] ?? null,
        'profile_id' => $_SESSION['profile_id'] ?? null,
        'profile_image' => $_SESSION['profile_image'] ?? null,
        'logo_image' => $_SESSION['logo_image'] ?? null,
    ];
}

function profile_image_url(?string $filename): ?string
{
    if ($filename) {
        return base_url('uploads/images/' . $filename);
    }
    return null;
}

function get_company_id(mysqli $conn, int $user_id): ?int
{
    $stmt = $conn->prepare('SELECT id FROM companies WHERE user_id = ?');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $result ? (int) $result['id'] : null;
}

function get_freelancer_id(mysqli $conn, int $user_id): ?int
{
    $stmt = $conn->prepare('SELECT id FROM freelancers WHERE user_id = ?');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $result ? (int) $result['id'] : null;
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

function csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token'])) {
        // Restore from cookie if session data was lost (e.g. session file deleted)
        if (!empty($_COOKIE['csrf_token']) && ctype_xdigit($_COOKIE['csrf_token']) && strlen($_COOKIE['csrf_token']) === 64) {
            $_SESSION['csrf_token'] = $_COOKIE['csrf_token'];
        } else {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    // Keep cookie in sync with session token on every page load
    if (!headers_sent() && (empty($_COOKIE['csrf_token']) || $_COOKIE['csrf_token'] !== $_SESSION['csrf_token'])) {
        _set_csrf_cookie($_SESSION['csrf_token']);
    }

    return $_SESSION['csrf_token'];
}

function _set_csrf_cookie(string $token): void
{
    setcookie('csrf_token', $token, [
        'expires'  => time() + 86400,
        'path'     => '/',
        'httponly'  => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Set the CSRF cookie. Call this BEFORE any HTML output
 * (e.g. right after including config/auth.php).
 */
function csrf_cookie(): void
{
    $token = csrf_token();
    if (!headers_sent()) {
        _set_csrf_cookie($token);
    }
}

function verify_csrf(): bool
{
    // Detect when PHP discards ALL POST data because post_max_size was exceeded.
    // In this case $_POST is completely empty but CONTENT_LENGTH is set.
    $content_length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $post_max = _ini_bytes(ini_get('post_max_size'));
    if ($content_length > 0 && empty($_POST) && $content_length > $post_max) {
        _log_csrf_failure('post_too_large', '');
        return false;
    }

    $token = $_POST['csrf_token'] ?? '';

    // Ensure session is active
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // If POST field is missing, fall back to cookie value.
    // This handles cases where browser extensions strip hidden inputs.
    if ($token === '') {
        $token = $_COOKIE['csrf_token'] ?? '';
    }

    if ($token === '') {
        _log_csrf_failure('missing_token');
        return false;
    }

    // Check session token (primary)
    if (!empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token)) {
        return true;
    }

    // Check cookie fallback (session data lost but cookie survived)
    if (!empty($_COOKIE['csrf_token']) && hash_equals($_COOKIE['csrf_token'], $token)) {
        $_SESSION['csrf_token'] = $token;
        return true;
    }

    _log_csrf_failure('token_mismatch', $token);

    return false;
}

/**
 * Parse a PHP ini size value like "8M" into bytes.
 */
function _ini_bytes(string $val): int
{
    $val = trim($val);
    if ($val === '') return 0;
    $num = (int) $val;
    $suffix = strtolower(substr($val, -1));
    return match($suffix) {
        'g' => $num * 1073741824,
        'm' => $num * 1048576,
        'k' => $num * 1024,
        default => $num,
    };
}

function _log_csrf_failure(string $reason, string $token = ''): void
{
    $log_file = __DIR__ . '/../logs/csrf_failures.log';
    $log_dir = dirname($log_file);
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }

    $session_token = $_SESSION['csrf_token'] ?? '(empty)';
    $cookie_token = $_COOKIE['csrf_token'] ?? '(empty)';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $script = $_SERVER['SCRIPT_NAME'] ?? 'unknown';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'unknown';
    $session_id = session_id() ?: '(none)';

    $entry = sprintf(
        "[%s] reason=%s script=%s method=%s ip=%s session_id=%s session_token=%s cookie_token=%s post_token=%s ua=%s\n",
        date('Y-m-d H:i:s'),
        $reason,
        $script,
        $method,
        $ip,
        $session_id,
        substr($session_token, 0, 16) . '...',
        substr($cookie_token, 0, 16) . '...',
        $token ? substr($token, 0, 16) . '...' : '(none)',
        substr($ua, 0, 80)
    );

    @file_put_contents($log_file, $entry, FILE_APPEND | LOCK_EX);
}

function delete_csrf_cookie(): void
{
    if (!headers_sent()) {
        setcookie('csrf_token', '', [
            'expires'  => time() - 86400,
            'path'     => '/',
            'httponly'  => true,
            'samesite' => 'Lax',
        ]);
    }
}

function regenerate_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function _first_char(string $s): string {
    if (function_exists('mb_substr')) {
        return mb_strtoupper(mb_substr($s, 0, 1));
    }
    return strtoupper(substr($s, 0, 1));
}

/**
 * Check if the account_status column exists in the users table.
 */
function has_account_status_column(): bool
{
    static $exists = null;
    if ($exists === null) {
        global $conn;
        $result = $conn->query("SHOW COLUMNS FROM users LIKE 'account_status'");
        $exists = $result && $result->num_rows > 0;
    }
    return $exists;
}

function status_badge(string $status): string
{
    $classes = [
        'pending' => 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-300',
        'approved' => 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300',
        'rejected' => 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300',
        'completed' => 'bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300',
        'position_filled' => 'bg-teal-100 dark:bg-teal-900/30 text-teal-800 dark:text-teal-300',
        'accepted' => 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300',
        'assigned' => 'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-800 dark:text-indigo-300',
        'working' => 'bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-300',
        'submitted' => 'bg-purple-100 dark:bg-purple-900/30 text-purple-800 dark:text-purple-300',
        'paid' => 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-800 dark:text-emerald-300',
        'active' => 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300',
        'suspended' => 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-300',
        'blocked' => 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300',
        'expired' => 'bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-gray-300',
        'closed' => 'bg-gray-300 dark:bg-gray-800 text-gray-900 dark:text-gray-400',
        'reviewed' => 'bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300',
        'hired' => 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300',
    ];

    $class = $classes[$status] ?? 'bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-300';

    $display = match($status) {
        'position_filled' => 'Position Filled',
        default => ucfirst($status),
    };

    return '<span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full ' . $class . '">' . e($display) . '</span>';
}
