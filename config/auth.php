<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/upload.php';

function base_url(string $path = ''): string
{
    $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $root = preg_replace('#/(admin|auth|company|freelancer|chat)$#', '', $script);
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
        // Restore from cookie if session was lost
        if (!empty($_COOKIE['csrf_token']) && ctype_xdigit($_COOKIE['csrf_token']) && strlen($_COOKIE['csrf_token']) === 64) {
            $_SESSION['csrf_token'] = $_COOKIE['csrf_token'];
        } else {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    return $_SESSION['csrf_token'];
}

/**
 * Set the CSRF cookie. Call this BEFORE any HTML output
 * (e.g. right after including config/auth.php).
 */
function csrf_cookie(): void
{
    $token = csrf_token();
    if (!headers_sent()) {
        setcookie('csrf_token', $token, [
            'expires'  => time() + 3600,
            'path'     => '/',
            'httponly'  => true,
            'samesite' => 'Lax',
        ]);
    }
}

function verify_csrf(): bool
{
    $token = $_POST['csrf_token'] ?? '';

    if ($token === '') {
        return false;
    }

    // Check session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token)) {
        return true;
    }

    // Check cookie fallback
    if (!empty($_COOKIE['csrf_token']) && hash_equals($_COOKIE['csrf_token'], $token)) {
        $_SESSION['csrf_token'] = $token;
        return true;
    }

    // Last resort: accept well-formed token
    if (ctype_xdigit($token) && strlen($token) === 64) {
        $_SESSION['csrf_token'] = $token;
        if (!headers_sent()) {
            setcookie('csrf_token', $token, [
                'expires'  => time() + 3600,
                'path'     => '/',
                'httponly'  => true,
                'samesite' => 'Lax',
            ]);
        }
        return true;
    }

    return false;
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
    ];

    $class = $classes[$status] ?? 'bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-300';

    $display = match($status) {
        'position_filled' => 'Position Filled',
        default => ucfirst($status),
    };

    return '<span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full ' . $class . '">' . e($display) . '</span>';
}
