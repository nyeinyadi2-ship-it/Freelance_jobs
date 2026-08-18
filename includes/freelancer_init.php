<?php
/**
 * Shared PHP initialization for all freelancer pages.
 * Include this BEFORE any redirect/POST logic.
 * Then include freelancer_layout.php for the HTML output.
 * Set $page_title before including freelancer_layout.php.
 */
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', '86400');
    ini_set('session.cookie_lifetime', '0');
    session_start();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';
require_once __DIR__ . '/../config/chat.php';

// Set CSRF cookie early (before any HTML output)
csrf_cookie();

require_role('freelancer');

$fl_user = current_user();
$fl_uid = (int) $fl_user['user_id'];
$fl_freelancer_id = get_freelancer_id($conn, $fl_uid);
if (!$fl_freelancer_id) { set_flash('error', 'Freelancer profile not found.'); redirect('auth/login.php'); }

// Stats and profile removed from global init to optimize page load times
// They will be loaded on the dashboard/profile pages instead
$page_title = $page_title ?? 'Dashboard';

// Active page detection
$fl_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
$fl_active = match($fl_script) {
    'dashboard.php' => 'dashboard',
    'profile.php' => 'profile',
    'browse_jobs.php' => 'browse',
    'my_tasks.php' => 'tasks',

    default => 'dashboard'
};
