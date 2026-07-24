<?php
/**
 * Shared PHP initialization for all freelancer pages.
 * Include this BEFORE any redirect/POST logic.
 * Then include freelancer_layout.php for the HTML output.
 * Set $page_title before including freelancer_layout.php.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';
require_once __DIR__ . '/../config/chat.php';

require_role('freelancer');

$fl_user = current_user();
$fl_uid = (int) $fl_user['user_id'];
$fl_freelancer_id = get_freelancer_id($conn, $fl_uid);
if (!$fl_freelancer_id) { set_flash('error', 'Freelancer profile not found.'); redirect('login.php'); }

// Profile
$fl_stmt = $conn->prepare("SELECT f.*, u.email, u.profile_image, u.username, u.created_at FROM freelancers f JOIN users u ON u.id = f.user_id WHERE f.id = ?");
$fl_stmt->bind_param('i', $fl_freelancer_id); $fl_stmt->execute();
$fl_profile = $fl_stmt->get_result()->fetch_assoc(); $fl_stmt->close();

// Skills
$fl_skill_names = [];
$r = $conn->query("SELECT id, skill_name FROM skills ORDER BY skill_name");
if ($r) while ($row = $r->fetch_assoc()) $fl_skill_names[$row['id']] = $row['skill_name'];
$fl_profile_skills = [];
$r = $conn->query("SELECT skill_id FROM freelancer_skills WHERE freelancer_id = $fl_freelancer_id");
if ($r) while ($row = $r->fetch_assoc()) $fl_profile_skills[] = (int) $row['skill_id'];

// Completion %
$fl_fields = [$fl_profile['full_name'], $fl_profile['title'], $fl_profile['phone'], $fl_profile['location'], $fl_profile['bio'], $fl_profile['hourly_rate'], $fl_profile['experience_years'], $fl_profile['profile_image']];
$fl_filled = 0;
foreach ($fl_fields as $f) if (!empty($f)) $fl_filled++;
$fl_completion = min(100, round(($fl_filled / count($fl_fields)) * 80 + (count($fl_profile_skills) > 0 ? 20 : 0)));

// Stats
$fl_stats = ['pending' => 0, 'active' => 0, 'completed' => 0, 'earnings' => 0];
try { $s = $conn->prepare("SELECT COUNT(*) AS c FROM job_applications WHERE freelancer_id=? AND status='pending'"); $s->bind_param('i', $fl_freelancer_id); $s->execute(); $fl_stats['pending'] = (int)$s->get_result()->fetch_assoc()['c']; $s->close(); } catch(Exception $e) {}
try { $s = $conn->prepare("SELECT COUNT(*) AS c FROM assignments WHERE freelancer_id=? AND status IN ('assigned','working','submitted')"); $s->bind_param('i', $fl_freelancer_id); $s->execute(); $fl_stats['active'] = (int)$s->get_result()->fetch_assoc()['c']; $s->close(); } catch(Exception $e) {}
try { $s = $conn->prepare("SELECT COUNT(*) AS c FROM assignments WHERE freelancer_id=? AND status='completed'"); $s->bind_param('i', $fl_freelancer_id); $s->execute(); $fl_stats['completed'] = (int)$s->get_result()->fetch_assoc()['c']; $s->close(); } catch(Exception $e) {}
try { $s = $conn->prepare("SELECT COALESCE(SUM(p.amount),0) AS t FROM payments p JOIN assignments a ON p.assignment_id=a.id WHERE a.freelancer_id=? AND p.status='paid'"); $s->bind_param('i', $fl_freelancer_id); $s->execute(); $fl_stats['earnings'] = (float)$s->get_result()->fetch_assoc()['t']; $s->close(); } catch(Exception $e) {}

$fl_chat_unread = get_unread_count($conn, $fl_uid);
$fl_notif_count = get_unread_notification_count($conn, $fl_uid);
$fl_recent_notifs = get_notifications($conn, $fl_uid, 5);
$page_title = $page_title ?? 'Dashboard';

// Active page detection
$fl_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
$fl_active = match($fl_script) {
    'dashboard.php' => 'dashboard',
    'profile.php' => 'profile',
    'browse_jobs.php' => 'browse',
    'my_tasks.php' => 'tasks',
    'portfolio.php' => 'portfolio',
    'view_portfolio.php' => 'portfolio',
    default => 'dashboard'
};
