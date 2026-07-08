<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/lang.php';
init_lang();

function base_url(string $path = ''): string
{
    $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $root = preg_replace('#/(admin|company|freelancer)$#', '', $script);
    $root = rtrim($root, '/');

    if ($path === '') {
        return $root === '' ? '/' : $root;
    }

    return ($root === '' ? '' : $root) . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . base_url($path));
    exit;
}

function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        set_flash('error', __('error.login_required'));
        redirect('login.php');
    }
}

function require_role(string $role): void
{
    require_login();

    if (($_SESSION['role'] ?? '') !== $role) {
        set_flash('error', __('error.no_permission'));
        redirect('index.php');
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
    ];
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
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(): bool
{
    $token = $_POST['csrf_token'] ?? '';

    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function status_badge(string $status): string
{
    $classes = [
        'pending' => 'bg-yellow-100 text-yellow-800',
        'approved' => 'bg-green-100 text-green-800',
        'rejected' => 'bg-red-100 text-red-800',
        'completed' => 'bg-blue-100 text-blue-800',
        'accepted' => 'bg-green-100 text-green-800',
        'assigned' => 'bg-indigo-100 text-indigo-800',
        'submitted' => 'bg-purple-100 text-purple-800',
        'paid' => 'bg-emerald-100 text-emerald-800',
    ];

    $class = $classes[$status] ?? 'bg-gray-100 text-gray-800';

    return '<span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full ' . $class . '">' . e(translate_status($status)) . '</span>';
}
