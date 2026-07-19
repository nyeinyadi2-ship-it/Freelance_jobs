<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/notifications.php';
require_once __DIR__ . '/config/chat.php';

// Handle login POST from home page
$login_error = '';
$login_success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['home_login'])) {
    if (!verify_csrf()) {
        $login_error = __('error.invalid_request');
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $login_error = __('error.email_password_required');
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
                    $login_error = __('error.account_suspended');
                } elseif ($account_status === 'blocked') {
                    $login_error = __('error.account_blocked');
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
                    } elseif ($user['role'] === 'freelancer') {
                        $_SESSION['profile_id'] = get_freelancer_id($conn, (int) $user['id']);
                        $_SESSION['logo_image'] = null;
                    } else {
                        $_SESSION['profile_id'] = null;
                        $_SESSION['logo_image'] = null;
                        redirect('admin/admin_dashboard.php');
                    }
                    // Stay on home page — page will re-render with logged-in navbar
                }
            } else {
                $login_error = __('error.invalid_credentials');
            }
        }
    }
}

// Check login state for footer links
$is_logged_in = !empty($_SESSION['user_id']) && in_array($_SESSION['role'] ?? '', ['company', 'freelancer'], true);

// Fetch stats
$stats = [
    'freelancers' => 0,
    'companies' => 0,
    'jobs' => 0,
    'completed' => 0,
];
$r = $conn->query("SELECT COUNT(*) AS cnt FROM users WHERE role = 'freelancer'");
$stats['freelancers'] = (int) $r->fetch_assoc()['cnt'];
$r = $conn->query("SELECT COUNT(*) AS cnt FROM users WHERE role = 'company'");
$stats['companies'] = (int) $r->fetch_assoc()['cnt'];
$r = $conn->query("SELECT COUNT(*) AS cnt FROM jobs WHERE status = 'approved'");
$stats['jobs'] = (int) $r->fetch_assoc()['cnt'];
$r = $conn->query("SELECT COUNT(*) AS cnt FROM jobs WHERE status = 'completed'");
$stats['completed'] = (int) $r->fetch_assoc()['cnt'];

// Fetch latest jobs
$latest_jobs = [];
$r = $conn->query("
    SELECT j.id, j.company_id, j.title, j.budget, j.created_at, j.description, j.attachment, j.deadline, j.category,
           c.company_name, c.logo_image,
           (SELECT GROUP_CONCAT(s.skill_name SEPARATOR ', ') FROM job_applications ja JOIN freelancers f ON ja.freelancer_id = f.id JOIN freelancer_skills fs ON fs.freelancer_id = f.id JOIN skills s ON fs.skill_id = s.id WHERE ja.job_id = j.id GROUP BY ja.job_id LIMIT 3) AS applied_skills
    FROM jobs j
    JOIN companies c ON j.company_id = c.id
    WHERE j.status = 'approved'
    ORDER BY j.created_at DESC
    LIMIT 3
");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $latest_jobs[] = $row;
    }
}

// Fallback: if no applied skills, get company skills
if (!empty($latest_jobs)) {
    foreach ($latest_jobs as &$job) {
        if (empty($job['applied_skills'])) {
            $cid = (int) ($job['company_id'] ?? 0);
            $sr = $conn->query("SELECT GROUP_CONCAT(s.skill_name SEPARATOR ', ') AS skills FROM freelancer_skills fs JOIN skills s ON fs.skill_id = s.id JOIN freelancers f ON fs.freelancer_id = f.id WHERE f.user_id = (SELECT user_id FROM companies WHERE id = {$cid}) LIMIT 3");
            $job['applied_skills'] = $sr ? ($sr->fetch_assoc()['skills'] ?? '') : '';
        }
    }
    unset($job);
}

// Check if current user is a freelancer and get applied job IDs
$current_user_role = $_SESSION['role'] ?? null;
$current_user_id = (int) ($_SESSION['user_id'] ?? 0);
$is_freelancer = ($current_user_role === 'freelancer');
$freelancer_id = 0;
$applied_job_ids = [];

if ($is_freelancer && $current_user_id) {
    $fr = $conn->prepare('SELECT id FROM freelancers WHERE user_id = ?');
    $fr->bind_param('i', $current_user_id);
    $fr->execute();
    $fr_row = $fr->get_result()->fetch_assoc();
    $fr->close();
    if ($fr_row) {
        $freelancer_id = (int) $fr_row['id'];
        $ar = $conn->prepare('SELECT job_id FROM job_applications WHERE freelancer_id = ?');
        $ar->bind_param('i', $freelancer_id);
        $ar->execute();
        $ar_result = $ar->get_result();
        while ($ar_row = $ar_result->fetch_assoc()) {
            $applied_job_ids[] = (int) $ar_row['job_id'];
        }
        $ar->close();
    }
}

// Fetch top freelancers with pagination
$fl_per_page = 4;
$fl_page = max(1, (int) ($_GET['fl_page'] ?? 1));
$fl_offset = ($fl_page - 1) * $fl_per_page;

// Count total freelancers
$fl_total = 0;
$cnt_r = $conn->query("SELECT COUNT(*) AS cnt FROM freelancers f JOIN users u ON f.user_id = u.id");
if ($cnt_r) {
    $fl_total = (int) $cnt_r->fetch_assoc()['cnt'];
    $cnt_r->close();
}
$fl_total_pages = max(1, (int) ceil($fl_total / $fl_per_page));
$fl_page = min($fl_page, $fl_total_pages);

$top_freelancers = [];
$r = $conn->query("
    SELECT f.id, f.full_name, f.title, f.hourly_rate, f.experience_years, u.profile_image,
           (SELECT COUNT(*) FROM assignments a JOIN jobs j ON a.job_id = j.id WHERE a.freelancer_id = f.id AND a.status = 'completed') AS completed_projects,
           (SELECT COUNT(DISTINCT j.company_id) FROM assignments a JOIN jobs j ON a.job_id = j.id WHERE a.freelancer_id = f.id AND a.status = 'completed') AS companies_worked,
           (SELECT COUNT(*) FROM freelancer_skills WHERE freelancer_id = f.id) AS skill_count
    FROM freelancers f
    JOIN users u ON f.user_id = u.id
    ORDER BY completed_projects DESC, f.experience_years DESC
    LIMIT $fl_per_page OFFSET $fl_offset
");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $top_freelancers[] = $row;
    }
}

// Fetch featured skills for categories
$skills_list = [];
$r = $conn->query("SELECT id, skill_name FROM skills ORDER BY skill_name");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $skills_list[] = $row;
    }
}

$page_title = __('app.tagline');
?>
<!DOCTYPE html>
<html lang="<?= e(current_lang()) ?>" data-theme>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title) ?></title>
    <script>
        (function() {
            var t = localStorage.getItem('theme');
            if (t === 'dark' || (!t && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        poppins: ['Poppins', 'sans-serif']
                    },
                    colors: {
                        primary: {
                            50: '#eef2ff',
                            100: '#e0e7ff',
                            200: '#c7d2fe',
                            300: '#a5b4fc',
                            400: '#818cf8',
                            500: '#6366f1',
                            600: '#4f46e5',
                            700: '#4338ca',
                            800: '#3730a3',
                            900: '#312e81'
                        },
                        accent: {
                            50: '#faf5ff',
                            100: '#f3e8ff',
                            200: '#e9d5ff',
                            300: '#d8b4fe',
                            400: '#c084fc',
                            500: '#a855f7',
                            600: '#9333ea',
                            700: '#7e22ce',
                            800: '#6b21a8',
                            900: '#581c87'
                        },
                    }
                }
            }
        };
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(base_url('assets/css/custom.css')) ?>">
    <style>
        * {
            font-family: 'Poppins', sans-serif;
        }

        ::selection {
            background: rgba(99, 102, 241, 0.2);
        }

        html {
            scroll-behavior: smooth;
        }

        /* Glassmorphism */
        .glass {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(255, 255, 255, 0.6);
            box-shadow: 0 8px 32px rgba(99, 102, 241, 0.08);
        }

        html.dark .glass-card {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.08);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }

        /* ===== HERO ===== */
        .hero-section {
            position: relative;
            min-height: 92vh;
            display: flex;
            align-items: center;
            overflow: hidden;
        }

        /* Slideshow */
        .hero-slides {
            position: absolute;
            inset: 0;
            z-index: 0;
        }

        .hero-slide {
            position: absolute;
            inset: 0;
            opacity: 0;
            transition: opacity 1.6s ease-in-out;
        }

        .hero-slide img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .hero-slide.active {
            opacity: 1;
        }

        /* Hero navigation buttons */
        .hero-nav-btn {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            z-index: 10;
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .hero-nav-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-50%) scale(1.1);
        }

        .hero-nav-btn.prev {
            left: 16px;
        }

        .hero-nav-btn.next {
            right: 16px;
        }

        @media (min-width: 768px) {
            .hero-nav-btn {
                width: 56px;
                height: 56px;
            }

            .hero-nav-btn.prev {
                left: 24px;
            }

            .hero-nav-btn.next {
                right: 24px;
            }
        }

        /* Overlay */
        .hero-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(15, 23, 42, 0.65) 0%, rgba(30, 41, 59, 0.45) 50%, rgba(51, 65, 85, 0.35) 100%);
            z-index: 1;
        }

        /* Animated shapes */
        .hero-shape {
            position: absolute;
            border-radius: 50%;
            filter: blur(60px);
            opacity: 0.15;
            animation: heroFloat 6s ease-in-out infinite;
        }

        .hero-shape-1 {
            width: 400px;
            height: 400px;
            background: #818cf8;
            top: -100px;
            right: -50px;
            animation-delay: 0s;
        }

        .hero-shape-2 {
            width: 300px;
            height: 300px;
            background: #c084fc;
            bottom: -80px;
            left: -60px;
            animation-delay: -2s;
        }

        .hero-shape-3 {
            width: 200px;
            height: 200px;
            background: #f472b6;
            top: 40%;
            left: 40%;
            animation-delay: -4s;
        }

        @keyframes heroFloat {

            0%,
            100% {
                transform: translate(0, 0) scale(1);
            }

            50% {
                transform: translate(15px, -20px) scale(1.05);
            }
        }

        /* Content area */
        .hero-content {
            position: relative;
            z-index: 2;
        }

        /* Gradient text */
        .gradient-text {
            background: linear-gradient(135deg, #c4b5fd, #f9a8d4, #fda4af);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        /* Gradient button */
        .btn-gradient {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .btn-gradient::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s ease;
        }

        .btn-gradient:hover::before {
            left: 100%;
        }

        .btn-gradient:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 40px rgba(99, 102, 241, 0.4);
        }

        /* Card hover lift */
        .lift-hover {
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .lift-hover:hover {
            transform: translateY(-8px);
            box-shadow: 0 24px 60px rgba(99, 102, 241, 0.15);
        }

        /* Scroll reveal */
        .reveal {
            opacity: 0;
            transform: translateY(40px);
            transition: opacity 0.7s cubic-bezier(0.4, 0, 0.2, 1), transform 0.7s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .reveal.visible {
            opacity: 1;
            transform: translateY(0);
        }

        .reveal-d1 {
            transition-delay: 0.1s;
        }

        .reveal-d2 {
            transition-delay: 0.2s;
        }

        .reveal-d3 {
            transition-delay: 0.3s;
        }

        .reveal-d4 {
            transition-delay: 0.4s;
        }

        .reveal-d5 {
            transition-delay: 0.5s;
        }

        /* Animated counter */
        .counter-val {
            display: inline-block;
        }

        /* Timeline connector */
        .timeline-line {
            position: relative;
        }

        .timeline-line::before {
            content: '';
            position: absolute;
            top: 2rem;
            left: calc(1rem + 50%);
            width: calc(100% - 2rem);
            height: 3px;
            background: linear-gradient(90deg, #c7d2fe, #a78bfa, #c7d2fe);
            border-radius: 2px;
        }

        @media (max-width: 768px) {
            .timeline-line::before {
                display: none;
            }
        }

        /* Navbar blur */
        .navbar-blur {
            background: rgba(255, 255, 255, 0.75);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            transition: all 0.3s ease;
        }

        html.dark .navbar-blur {
            background: rgba(15, 23, 42, 0.85);
        }

        .navbar-blur.scrolled {
            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.08);
        }

        /* Back to top */
        .back-to-top {
            opacity: 0;
            visibility: hidden;
            transform: translateY(20px);
            transition: all 0.3s ease;
        }

        .back-to-top.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        /* Stat icon pulse */
        .stat-icon-wrap {
            position: relative;
        }

        .stat-icon-wrap::after {
            content: '';
            position: absolute;
            inset: -4px;
            border-radius: 16px;
            opacity: 0;
            transition: opacity 0.3s ease;
            filter: blur(8px);
        }

        .stat-icon-wrap:hover::after {
            opacity: 0.4;
        }

        /* Skill tag */
        .skill-tag {
            transition: all 0.2s ease;
        }

        .skill-tag:hover {
            transform: scale(1.05);
        }

        /* Avatar ring */
        .avatar-ring {
            background: linear-gradient(135deg, #6366f1, #a855f7, #ec4899);
            padding: 3px;
            border-radius: 50%;
        }

        /* Nav link items */
        .nav-link-item {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.5rem 0.875rem;
            font-size: 0.875rem;
            font-weight: 500;
            border-radius: 0.5rem;
            transition: all 0.2s ease;
            color: var(--color-text-secondary, #6b7280);
            position: relative;
        }

        .nav-link-item:hover {
            color: var(--color-primary, #4f46e5);
            background: rgba(99, 102, 241, 0.06);
        }

        .nav-link-item.active {
            color: var(--color-primary, #4f46e5);
            background: rgba(99, 102, 241, 0.08);
        }

        .nav-link-item.active::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 50%;
            width: 60%;
            height: 2px;
            background: linear-gradient(90deg, #6366f1, #a855f7);
            border-radius: 1px;
            transform: translateX(-50%);
        }

        /* Nav icon buttons */
        .nav-icon-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 0.5rem;
            transition: all 0.2s ease;
            color: var(--color-text-secondary, #6b7280);
        }

        .nav-icon-btn:hover {
            background: rgba(99, 102, 241, 0.06);
            color: var(--color-primary, #4f46e5);
        }

        /* Mobile nav links */
        .mobile-nav-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            font-size: 0.875rem;
            font-weight: 500;
            border-radius: 0.75rem;
            transition: all 0.2s ease;
            color: var(--color-text-secondary, #6b7280);
        }

        .mobile-nav-link:hover {
            background: rgba(99, 102, 241, 0.06);
            color: var(--color-primary, #4f46e5);
        }

        .mobile-nav-link.active {
            color: var(--color-primary, #4f46e5);
            background: rgba(99, 102, 241, 0.08);
        }

        /* Profile menu items */
        .profile-menu-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.625rem 1rem;
            font-size: 0.875rem;
            transition: all 0.15s ease;
            color: var(--color-text-secondary, #6b7280);
        }

        .profile-menu-item:hover {
            background: rgba(99, 102, 241, 0.06);
            color: var(--color-primary, #4f46e5);
        }

        /* Section eyebrow */
        .section-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: #4f46e5;
            font-weight: 600;
            font-size: 0.8125rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-bottom: 1rem;
        }

        .section-eyebrow::before {
            content: '';
            width: 2rem;
            height: 2px;
            background: linear-gradient(90deg, #6366f1, #a855f7);
            border-radius: 1px;
        }

        /* Stats section */
        .stat-card {
            position: relative;
            background: white;
            border: 1px solid rgba(0, 0, 0, 0.04);
            border-radius: 1.25rem;
            padding: 2rem 1.5rem;
            text-align: center;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04), 0 8px 32px rgba(99, 102, 241, 0.04);
        }

        html.dark .stat-card {
            background: rgba(30, 41, 59, 0.5);
            border-color: rgba(255, 255, 255, 0.05);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2), 0 8px 32px rgba(0, 0, 0, 0.15);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(99, 102, 241, 0.12);
        }

        html.dark .stat-card:hover {
            box-shadow: 0 12px 40px rgba(99, 102, 241, 0.08);
        }

        /* Category card */
        .cat-card {
            position: relative;
            border-radius: 1rem;
            padding: 1.5rem;
            text-align: center;
            color: white;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .cat-card:hover {
            transform: translateY(-6px) scale(1.02);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }

        .cat-card::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(to top, rgba(0, 0, 0, 0.15), transparent);
            pointer-events: none;
        }

        /* Job card */
        .job-card {
            background: white;
            border: 1px solid rgba(0, 0, 0, 0.04);
            border-radius: 1rem;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04), 0 8px 32px rgba(99, 102, 241, 0.04);
        }

        html.dark .job-card {
            background: rgba(30, 41, 59, 0.5);
            border-color: rgba(255, 255, 255, 0.05);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2), 0 8px 32px rgba(0, 0, 0, 0.15);
        }

        .job-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 20px 60px rgba(99, 102, 241, 0.12);
        }

        /* Freelancer card */
        .fl-card {
            background: white;
            border: 1px solid rgba(0, 0, 0, 0.04);
            border-radius: 1.25rem;
            padding: 2rem 1.5rem;
            text-align: center;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04), 0 8px 32px rgba(99, 102, 241, 0.04);
        }

        html.dark .fl-card {
            background: rgba(30, 41, 59, 0.5);
            border-color: rgba(255, 255, 255, 0.05);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2), 0 8px 32px rgba(0, 0, 0, 0.15);
        }

        .fl-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 24px 60px rgba(99, 102, 241, 0.12);
        }

        /* Feature card */
        .feat-card {
            background: white;
            border: 1px solid rgba(0, 0, 0, 0.04);
            border-radius: 1.25rem;
            padding: 2rem;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04), 0 8px 32px rgba(99, 102, 241, 0.04);
        }

        html.dark .feat-card {
            background: rgba(30, 41, 59, 0.5);
            border-color: rgba(255, 255, 255, 0.05);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2), 0 8px 32px rgba(0, 0, 0, 0.15);
        }

        .feat-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 20px 60px rgba(99, 102, 241, 0.12);
        }

        /* Steps */
        .step-card {
            position: relative;
            text-align: center;
            padding: 0 1rem;
        }

        .step-card::after {
            content: '';
            position: absolute;
            top: 2rem;
            right: -50%;
            width: 100%;
            height: 2px;
            background: linear-gradient(90deg, rgba(99, 102, 241, 0.2), rgba(168, 85, 247, 0.2));
            z-index: 0;
        }

        .step-card:last-child::after {
            display: none;
        }

        @media (max-width: 1023px) {
            .step-card::after {
                display: none;
            }
        }

        /* Testimonial card */
        .test-card {
            background: white;
            border: 1px solid rgba(0, 0, 0, 0.04);
            border-radius: 1.25rem;
            padding: 2rem;
            height: 100%;
            display: flex;
            flex-direction: column;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04), 0 8px 32px rgba(99, 102, 241, 0.04);
        }

        html.dark .test-card {
            background: rgba(30, 41, 59, 0.5);
            border-color: rgba(255, 255, 255, 0.05);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2), 0 8px 32px rgba(0, 0, 0, 0.15);
        }

        /* CTA gradient border */
        .cta-glow {
            position: relative;
        }

        .cta-glow::before {
            content: '';
            position: absolute;
            inset: -2px;
            border-radius: 28px;
            background: linear-gradient(135deg, #6366f1, #a855f7, #ec4899, #6366f1);
            z-index: -1;
            opacity: 0;
            transition: opacity 0.4s ease;
        }

        .cta-glow:hover::before {
            opacity: 1;
        }
    </style>
</head>

<body class="bg-gradient-to-br from-slate-50 via-white to-indigo-50/30 dark:from-slate-900 dark:via-slate-900 dark:to-indigo-950/30 min-h-screen">

    <?php require __DIR__ . '/includes/navbar.php'; ?>

    <!-- ===== HERO SECTION ===== -->
    <section class="hero-section">
        <!-- Slideshow Background -->
        <div class="hero-slides">
            <div class="hero-slide active">
                <img src="<?= e(base_url('uploads/office1.jpg')) ?>" alt="Office meeting">
            </div>
            <div class="hero-slide">
                <img src="<?= e(base_url('uploads/office4.avif')) ?>" alt="Virtual collaboration">
            </div>
            <div class="hero-slide">
                <img src="<?= e(base_url('uploads/office8.webp')) ?>" alt="Team presentation">
            </div>

        </div>

        <!-- Overlay -->
        <div class="hero-overlay"></div>

        <!-- Animated Shapes -->
        <div class="hero-shape hero-shape-1"></div>
        <div class="hero-shape hero-shape-2"></div>
        <div class="hero-shape hero-shape-3"></div>


        <!-- Previous Button -->
        <button class="hero-nav-btn prev" id="heroPrev" aria-label="Previous slide">
            <svg class="w-5 h-5 md:w-6 md:h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
            </svg>
        </button>

        <!-- Next Button -->
        <button class="hero-nav-btn next" id="heroNext" aria-label="Next slide">
            <svg class="w-5 h-5 md:w-6 md:h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
            </svg>
        </button>

        <!-- Content -->
        <div class="hero-content max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 w-full py-20">
            <div class="max-w-2xl">
                <div class="inline-flex items-center gap-2 bg-white/10 backdrop-blur-md rounded-full px-5 py-2 text-sm font-medium text-white/90 border border-white/15 mb-8 reveal">
                    <span class="w-2 h-2 bg-emerald-400 rounded-full animate-pulse"></span>
                    Trusted by <?= number_format($stats['freelancers'] + $stats['companies']) ?>+ professionals
                </div>

                <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold text-white leading-tight mb-6 reveal reveal-d1">
                    Welcome to
                    <span class="block mt-2 gradient-text">FreelanceHub</span>
                </h1>

                <p class="text-lg text-white/70 max-w-xl leading-relaxed mb-10 reveal reveal-d2">
                    Discover top talent or find your next opportunity. The modern platform connecting companies with skilled freelancers worldwide.
                </p>

                <div class="flex flex-wrap gap-4 mb-10 reveal reveal-d3">
                    <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="btn-gradient px-8 py-4 rounded-xl font-semibold text-white text-base shadow-lg shadow-primary-500/30 inline-flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        Find Jobs
                    </a>
                    <a href="<?= e(base_url('register.php')) ?>" class="px-8 py-4 rounded-xl font-semibold text-white text-base bg-white/10 backdrop-blur-md border border-white/20 hover:bg-white/20 hover:border-white/30 transition-all inline-flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                        Join Now
                    </a>
                </div>

                <!-- Quick Tags -->
                <div class="flex flex-wrap gap-2 reveal reveal-d4">
                    <span class="text-white/70 text-xs px-4 py-1.5 rounded-full bg-white/10 backdrop-blur-sm border border-white/10 hover:text-white hover:bg-white/20 transition-all cursor-pointer">PHP</span>
                    <span class="text-white/70 text-xs px-4 py-1.5 rounded-full bg-white/10 backdrop-blur-sm border border-white/10 hover:text-white hover:bg-white/20 transition-all cursor-pointer">JavaScript</span>
                    <span class="text-white/70 text-xs px-4 py-1.5 rounded-full bg-white/10 backdrop-blur-sm border border-white/10 hover:text-white hover:bg-white/20 transition-all cursor-pointer">React.js</span>
                    <span class="text-white/70 text-xs px-4 py-1.5 rounded-full bg-white/10 backdrop-blur-sm border border-white/10 hover:text-white hover:bg-white/20 transition-all cursor-pointer">UI/UX Design</span>
                    <span class="text-white/70 text-xs px-4 py-1.5 rounded-full bg-white/10 backdrop-blur-sm border border-white/10 hover:text-white hover:bg-white/20 transition-all cursor-pointer">Python</span>
                    <span class="text-white/70 text-xs px-4 py-1.5 rounded-full bg-white/10 backdrop-blur-sm border border-white/10 hover:text-white hover:bg-white/20 transition-all cursor-pointer">Content Writing</span>
                </div>
            </div>
        </div>

        <!-- Slideshow Dots -->
        <div class="absolute bottom-10 left-1/2 -translate-x-1/2 z-10 flex gap-3" id="heroDots">
            <button class="hero-dot w-3 h-3 rounded-full bg-white transition-all duration-300" data-slide="0" onclick="heroSlide(0)"></button>
            <button class="hero-dot w-3 h-3 rounded-full bg-white/40 transition-all duration-300" data-slide="1" onclick="heroSlide(1)"></button>
            <button class="hero-dot w-3 h-3 rounded-full bg-white/40 transition-all duration-300" data-slide="2" onclick="heroSlide(2)"></button>
        </div>
    </section>

    <!-- ===== STATISTICS SECTION ===== -->
    <section class="pt-28 pb-20 -mt-12 relative z-10">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-5 sm:gap-6">
                <?php
                $stat_items = [
                    ['label' => 'Freelancers', 'value' => $stats['freelancers'], 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>', 'color' => 'from-blue-500 to-indigo-600'],
                    ['label' => 'Companies', 'value' => $stats['companies'], 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>', 'color' => 'from-purple-500 to-accent-600'],
                    ['label' => 'Open Jobs', 'value' => $stats['jobs'], 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>', 'color' => 'from-emerald-500 to-teal-600'],
                    ['label' => 'Completed', 'value' => $stats['completed'], 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>', 'color' => 'from-amber-500 to-orange-600'],
                ];
                foreach ($stat_items as $i => $s):
                ?>
                    <div class="stat-card reveal reveal-d<?= $i + 1 ?>">
                        <div class="inline-flex mb-4">
                            <div class="w-12 h-12 bg-gradient-to-br <?= $s['color'] ?> rounded-xl flex items-center justify-center shadow-md">
                                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><?= $s['icon'] ?></svg>
                            </div>
                        </div>
                        <p class="text-3xl sm:text-4xl font-extrabold text-gray-900 dark:text-white counter-val" data-target="<?= $s['value'] ?>">0</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1.5 font-medium"><?= $s['label'] ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ===== POPULAR CATEGORIES ===== -->
    <section id="categories" class="py-28 bg-white/50 dark:bg-slate-800/30">
        <div class="max-w-7xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-16 reveal">
                <span class="section-eyebrow justify-center">Categories</span>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-gray-900 dark:text-white mb-4">Explore Top Skills</h2>
                <p class="text-gray-500 dark:text-gray-400 max-w-xl mx-auto text-lg">Find talent across the most in-demand technical and creative skills</p>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4 sm:gap-5">
                <?php
                $category_data = [
                    'PHP' => ['color' => 'from-blue-500 to-indigo-600', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>'],
                    'JavaScript' => ['color' => 'from-yellow-400 to-orange-500', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/>'],
                    'React.js' => ['color' => 'from-cyan-400 to-blue-500', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>'],
                    'UI/UX Design' => ['color' => 'from-pink-500 to-rose-600', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/>'],
                    'Python' => ['color' => 'from-green-500 to-emerald-600', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>'],
                    'Content Writing' => ['color' => 'from-violet-500 to-purple-600', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>'],
                    'Video Editing' => ['color' => 'from-red-500 to-pink-600', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>'],
                    'Digital Marketing' => ['color' => 'from-teal-500 to-cyan-600', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z"/><path stroke-linecap="round" stroke-linejoin="round" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z"/>'],
                    'Laravel' => ['color' => 'from-red-600 to-rose-700', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>'],
                    'MySQL' => ['color' => 'from-blue-600 to-indigo-700', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/>'],
                ];

                $shown = 0;
                foreach ($skills_list as $skill):
                    if ($shown >= 10) break;
                    if (!isset($category_data[$skill['skill_name']])) continue;
                    $cd = $category_data[$skill['skill_name']];
                    $shown++;
                    $cnt_r = $conn->query("SELECT COUNT(*) AS cnt FROM jobs j JOIN job_skills js ON js.job_id = j.id JOIN skills s ON js.skill_id = s.id WHERE s.skill_name = '" . $conn->real_escape_string($skill['skill_name']) . "' AND j.status = 'approved'");
                    $job_cnt = $cnt_r ? (int) $cnt_r->fetch_assoc()['cnt'] : 0;
                ?>
                    <a href="<?= e(base_url('freelancer/skill_jobs.php?skill=' . urlencode($skill['skill_name']))) ?>" class="cat-card bg-gradient-to-br <?= $cd['color'] ?> reveal reveal-d<?= ($shown % 5) + 1 ?>">
                        <div class="relative z-10">
                            <div class="w-14 h-14 bg-white/20 backdrop-blur-sm rounded-2xl flex items-center justify-center mx-auto mb-4 group-hover:scale-110 transition-transform duration-300">
                                <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><?= $cd['icon'] ?></svg>
                            </div>
                            <h3 class="font-bold text-base mb-1 relative z-10"><?= e($skill['skill_name']) ?></h3>
                            <p class="text-white/70 text-sm relative z-10"><?= $job_cnt ?>+ open jobs</p>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ===== FEATURED JOBS ===== -->
    <section id="find-jobs" class="py-28">
        <div class="max-w-7xl mx-auto px-4 sm:px-6">
            <div class="flex flex-wrap items-end justify-between mb-14 reveal">
                <div>
                    <span class="section-eyebrow">Latest Opportunities</span>
                    <h2 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-gray-900 dark:text-white">Recently Posted Jobs</h2>
                </div>
                <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="btn-gradient mt-6 sm:mt-0 inline-flex items-center gap-2 px-6 py-3 rounded-xl font-semibold text-white text-sm shadow-lg shadow-primary-500/25">
                    View All Jobs
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3" />
                    </svg>
                </a>
            </div>

            <?php if (empty($latest_jobs)): ?>
                <div class="text-center py-20 bg-white dark:bg-slate-800/50 rounded-3xl border border-gray-100 dark:border-gray-700/50">
                    <svg class="w-20 h-20 mx-auto mb-6 text-gray-300 dark:text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                    <p class="text-gray-500 dark:text-gray-400 text-lg">No jobs available yet. Check back soon!</p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($latest_jobs as $i => $job):
                        $job_id = (int) $job['id'];
                        $already_applied = in_array($job_id, $applied_job_ids);
                        $view_url = e(base_url('freelancer/view_job.php?id=' . $job_id));

                        $is_image = false;
                        $thumb_url = null;
                        if (!empty($job['attachment'])) {
                            $ext = strtolower(pathinfo($job['attachment'], PATHINFO_EXTENSION));
                            $is_image = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                            $thumb_url = e(base_url('uploads/attachments/' . $job['attachment']));
                        }
                    ?>
                        <div class="job-card reveal reveal-d<?= ($i % 2) + 1 ?> group">
                            <!-- Thumbnail -->
                            <div class="relative w-full h-48 overflow-hidden bg-gradient-to-br from-primary-50 to-accent-50 dark:from-primary-900/20 dark:to-accent-900/20">
                                <?php if ($is_image && $thumb_url): ?>
                                    <img src="<?= $thumb_url ?>" alt="<?= e($job['title']) ?>" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105">
                                <?php else: ?>
                                    <div class="w-full h-full flex items-center justify-center">
                                        <svg width="64" height="64" viewBox="0 0 72 72" fill="none" xmlns="http://www.w3.org/2000/svg" class="opacity-30">
                                            <rect width="72" height="72" rx="18" fill="url(#grad_home)" fill-opacity="0.15" />
                                            <path d="M25 32L32 25L39 32" stroke="url(#grad_home)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                                            <path d="M32 25V41" stroke="url(#grad_home)" stroke-width="2.5" stroke-linecap="round" />
                                            <path d="M43 41V32H48V41C48 43.76 45.76 46 43 46H29C26.24 46 24 43.76 24 41" stroke="url(#grad_home)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                                            <defs>
                                                <linearGradient id="grad_home" x1="0" y1="0" x2="72" y2="72" gradientUnits="userSpaceOnUse">
                                                    <stop stop-color="#6366f1" />
                                                    <stop offset="1" stop-color="#8b5cf6" />
                                                </linearGradient>
                                            </defs>
                                        </svg>
                                    </div>
                                <?php endif; ?>
                                <div class="absolute top-3 left-3 flex gap-2 flex-wrap">
                                    <?php if (!empty($job['category'])): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-semibold bg-white/90 dark:bg-gray-900/80 text-primary-700 dark:text-primary-300 backdrop-blur-sm shadow-sm">
                                            <?= e($job['category']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="absolute top-3 right-3">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-semibold bg-emerald-500/90 text-white backdrop-blur-sm shadow-sm">
                                        <span class="w-1.5 h-1.5 bg-white rounded-full animate-pulse"></span>
                                        Open
                                    </span>
                                </div>
                            </div>

                            <!-- Body -->
                            <div class="p-5">
                                <div class="flex items-center gap-3 mb-3">
                                    <?php if (!empty($job['logo_image'])): ?>
                                        <img src="<?= e(base_url('uploads/' . $job['logo_image'])) ?>" alt="" class="w-9 h-9 rounded-lg object-cover border-2 border-white dark:border-gray-700 shadow-sm">
                                    <?php else: ?>
                                        <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-primary-100 to-accent-100 dark:from-primary-900/40 dark:to-accent-900/40 flex items-center justify-center text-primary-600 dark:text-primary-400 font-bold text-xs shadow-sm">
                                            <?= e(_first_char($job['company_name'] ?? 'C')) ?>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <p class="font-semibold text-sm text-gray-900 dark:text-white"><?= e($job['company_name'] ?? 'Company') ?></p>
                                        <p class="text-xs text-gray-400 dark:text-gray-500">Posted recently</p>
                                    </div>
                                </div>

                                <h3 class="font-bold text-base text-gray-900 dark:text-white mb-2 leading-snug line-clamp-2 group-hover:text-primary-600 dark:group-hover:text-primary-400 transition-colors"><?= e($job['title']) ?></h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400 mb-3 line-clamp-2 leading-relaxed"><?= e(mb_strimwidth($job['description'] ?? '', 0, 100, '...')) ?></p>

                                <?php if (!empty($job['applied_skills'])): ?>
                                    <div class="flex flex-wrap gap-1.5 mb-4">
                                        <?php $skills_arr = array_slice(explode(', ', $job['applied_skills']), 0, 3); ?>
                                        <?php foreach ($skills_arr as $skill_tag): ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-md text-xs font-medium bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300"><?= e($skill_tag) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center gap-1.5">
                                        <div class="w-7 h-7 rounded-md bg-emerald-50 dark:bg-emerald-900/30 flex items-center justify-center">
                                            <svg class="w-3.5 h-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1" />
                                            </svg>
                                        </div>
                                        <span class="text-base font-bold text-gray-900 dark:text-white">$<?= e(number_format((float) $job['budget'], 0)) ?></span>
                                    </div>
                                    <?php if (!empty($job['deadline'])): ?>
                                        <span class="inline-flex items-center gap-1 text-xs text-gray-400 dark:text-gray-500">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                            </svg>
                                            <?= e(date('M j', strtotime($job['deadline']))) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="flex items-center gap-2 pt-4 border-t border-gray-100 dark:border-gray-700/50">
                                    <a href="<?= $view_url ?>" class="flex-1 inline-flex items-center justify-center gap-1.5 px-4 py-2.5 rounded-xl text-sm font-semibold text-primary-600 dark:text-primary-400 border-2 border-primary-100 dark:border-primary-800 hover:bg-primary-50 dark:hover:bg-primary-900/30 transition-all">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                        View Details
                                    </a>
                                    <?php if ($already_applied): ?>
                                        <span class="flex-1 inline-flex items-center justify-center gap-1.5 px-4 py-2.5 rounded-xl text-sm font-semibold text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/30 border-2 border-emerald-100 dark:border-emerald-800/50 cursor-default">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                            </svg>
                                            Applied
                                        </span>
                                    <?php elseif ($is_freelancer): ?>
                                        <form method="POST" action="<?= e(base_url('freelancer/view_job.php?id=' . $job_id)) ?>" class="flex-1" onclick="event.stopPropagation()">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="apply">
                                            <button type="submit" class="w-full inline-flex items-center justify-center gap-1.5 px-4 py-2.5 rounded-xl text-sm font-semibold text-white bg-gradient-to-r from-primary-500 to-accent-500 hover:from-primary-600 hover:to-accent-600 shadow-md shadow-primary-500/20 hover:shadow-lg hover:-translate-y-0.5 transition-all">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                                                </svg>
                                                Apply Now
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <a href="<?= e(base_url('login.php')) ?>" class="flex-1 inline-flex items-center justify-center gap-1.5 px-4 py-2.5 rounded-xl text-sm font-semibold text-white bg-gradient-to-r from-primary-500 to-accent-500 hover:from-primary-600 hover:to-accent-600 shadow-md shadow-primary-500/20 hover:shadow-lg hover:-translate-y-0.5 transition-all" onclick="event.stopPropagation()">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
                                            </svg>
                                            Apply Now
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="text-center mt-10 reveal">
                <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="inline-flex items-center gap-2 px-8 py-3.5 rounded-xl font-semibold text-sm bg-gradient-to-r from-primary-500 to-accent-500 text-white shadow-lg shadow-primary-500/25 hover:shadow-xl hover:-translate-y-0.5 transition-all">
                    View All Jobs
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3" />
                    </svg>
                </a>
            </div>
        </div>
    </section>

    <!-- ===== TOP FREELANCERS ===== -->
    <section id="freelancers" class="py-28 bg-gradient-to-b from-white/50 to-indigo-50/30 dark:from-slate-800/30 dark:to-slate-900/50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-16 reveal">
                <span class="section-eyebrow justify-center">Top Talent</span>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-gray-900 dark:text-white mb-4">Meet Our Top Freelancers</h2>
                <p class="text-gray-500 dark:text-gray-400 max-w-xl mx-auto text-lg">Work with the best professionals in the industry</p>
            </div>

            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <?php foreach ($top_freelancers as $i => $fl): ?>
                    <div class="fl-card reveal reveal-d<?= ($i % 4) + 1 ?> group">
                        <div class="inline-block mb-5">
                            <div class="avatar-ring">
                                <div class="w-20 h-20 rounded-full overflow-hidden">
                                    <?php if (!empty($fl['profile_image'])): ?>
                                        <img src="<?= e(base_url('uploads/' . $fl['profile_image'])) ?>" alt="" class="w-full h-full object-cover">
                                    <?php else: ?>
                                        <div class="w-full h-full bg-gradient-to-br from-primary-100 to-accent-100 dark:from-primary-800/40 dark:to-accent-800/40 flex items-center justify-center text-primary-600 dark:text-primary-300 font-bold text-xl">
                                            <?= e(_first_char($fl['full_name'] ?? 'F')) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <h3 class="font-bold text-base text-gray-900 dark:text-white mb-1 group-hover:text-primary-600 dark:group-hover:text-primary-400 transition-colors"><?= e($fl['full_name']) ?></h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-3"><?= e($fl['title'] ?? 'Freelancer') ?></p>

                        <div class="flex items-center justify-center gap-0.5 mb-5">
                            <?php for ($s = 0; $s < 5; $s++): ?>
                                <svg class="w-3.5 h-3.5 text-amber-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.538 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                                </svg>
                            <?php endfor; ?>
                            <span class="text-xs font-semibold text-gray-600 dark:text-gray-300 ml-1">5.0</span>
                        </div>

                        <div class="grid grid-cols-2 gap-2.5 mb-5">
                            <div class="rounded-xl p-2.5 text-center bg-primary-50/80 dark:bg-primary-900/20">
                                <p class="text-lg font-extrabold text-gray-900 dark:text-white leading-tight"><?= (int) $fl['completed_projects'] ?></p>
                                <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-0.5">Projects</p>
                            </div>
                            <div class="rounded-xl p-2.5 text-center bg-emerald-50/80 dark:bg-emerald-900/20">
                                <p class="text-lg font-extrabold text-gray-900 dark:text-white leading-tight"><?= (int) $fl['companies_worked'] ?></p>
                                <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-0.5">Companies</p>
                            </div>
                        </div>

                        <div class="flex items-center justify-between pt-4 border-t border-gray-100 dark:border-gray-700/50">
                            <div>
                                <p class="text-[11px] text-gray-400 dark:text-gray-500">Hourly Rate</p>
                                <p class="text-lg font-bold text-primary-600 dark:text-primary-400">$<?= e(number_format((float) ($fl['hourly_rate'] ?? 0), 0)) ?></p>
                            </div>
                            <a href="<?= e(base_url('company/view_freelancer.php?id=' . $fl['id'])) ?>" class="btn-gradient px-4 py-2 rounded-xl text-xs font-semibold text-white shadow-md shadow-primary-500/20">
                                View Profile
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination Controls -->
            <?php if ($fl_total_pages > 1): ?>
                <div class="flex items-center justify-center gap-2 mt-12 reveal">
                    <!-- Previous Button -->
                    <?php if ($fl_page > 1): ?>
                        <a href="?fl_page=<?= $fl_page - 1 ?>#freelancers" class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-xl text-sm font-semibold border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 hover:border-gray-300 dark:hover:border-gray-600 transition-all">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                            </svg>
                            Previous
                        </a>
                    <?php else: ?>
                        <span class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-xl text-sm font-semibold border border-gray-100 dark:border-gray-800 text-gray-300 dark:text-gray-600 cursor-not-allowed">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                            </svg>
                            Previous
                        </span>
                    <?php endif; ?>

                    <!-- Page Numbers -->
                    <div class="hidden sm:flex items-center gap-1.5">
                        <?php for ($p = 1; $p <= $fl_total_pages; $p++): ?>
                            <?php if ($p === $fl_page): ?>
                                <span class="w-10 h-10 inline-flex items-center justify-center rounded-xl text-sm font-bold bg-gradient-to-r from-primary-500 to-accent-500 text-white shadow-md shadow-primary-500/25"><?= $p ?></span>
                            <?php elseif ($p === 1 || $p === $fl_total_pages || abs($p - $fl_page) <= 1): ?>
                                <a href="?fl_page=<?= $p ?>#freelancers" class="w-10 h-10 inline-flex items-center justify-center rounded-xl text-sm font-semibold text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition-all"><?= $p ?></a>
                            <?php elseif (abs($p - $fl_page) === 2): ?>
                                <span class="w-10 h-10 inline-flex items-center justify-center text-gray-400 dark:text-gray-500">...</span>
                            <?php endif; ?>
                        <?php endfor; ?>
                    </div>

                    <!-- Mobile Page Indicator -->
                    <span class="sm:hidden px-4 py-2.5 rounded-xl text-sm font-semibold text-gray-600 dark:text-gray-300 bg-gray-50 dark:bg-gray-800">
                        Page <?= $fl_page ?> of <?= $fl_total_pages ?>
                    </span>

                    <!-- Next Button -->
                    <?php if ($fl_page < $fl_total_pages): ?>
                        <a href="?fl_page=<?= $fl_page + 1 ?>#freelancers" class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-xl text-sm font-semibold border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 hover:border-gray-300 dark:hover:border-gray-600 transition-all">
                            Next
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                        </a>
                    <?php else: ?>
                        <span class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-xl text-sm font-semibold border border-gray-100 dark:border-gray-800 text-gray-300 dark:text-gray-600 cursor-not-allowed">
                            Next
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- ===== WHY CHOOSE US ===== -->
    <section id="why-us" class="py-28">
        <div class="max-w-7xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-16 reveal">
                <span class="section-eyebrow justify-center">Why Choose Us</span>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-gray-900 dark:text-white mb-4">Built for Modern Teams</h2>
                <p class="text-gray-500 dark:text-gray-400 max-w-xl mx-auto text-lg">Everything you need to hire, work, and get paid — in one powerful platform</p>
            </div>

            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <?php
                $why_features = [
                    ['icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>', 'title' => 'Secure & Trusted', 'desc' => 'All accounts verified with industry-standard encryption and secure authentication.', 'color' => 'from-blue-500 to-indigo-600'],
                    ['icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>', 'title' => 'Fast & Efficient', 'desc' => 'Post jobs and find talent in minutes. Our streamlined process gets you working faster.', 'color' => 'from-amber-500 to-orange-600'],
                    ['icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>', 'title' => 'Secure Payments', 'desc' => 'Escrow-based system ensures freelancers get paid and companies get quality work.', 'color' => 'from-emerald-500 to-teal-600'],
                    ['icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>', 'title' => 'Built-in Messaging', 'desc' => 'Communicate directly with your team. No need for external tools.', 'color' => 'from-pink-500 to-rose-600'],
                ];
                foreach ($why_features as $i => $f):
                ?>
                    <div class="feat-card reveal reveal-d<?= ($i % 4) + 1 ?> group">
                        <div class="w-12 h-12 bg-gradient-to-br <?= $f['color'] ?> rounded-xl flex items-center justify-center mb-5 shadow-md group-hover:scale-110 transition-transform duration-300">
                            <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><?= $f['icon'] ?></svg>
                        </div>
                        <h3 class="text-base font-bold text-gray-900 dark:text-white mb-2"><?= e($f['title']) ?></h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed"><?= e($f['desc']) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ===== HOW IT WORKS ===== -->
    <section class="py-28 bg-gradient-to-b from-indigo-50/30 to-white/50 dark:from-slate-900/50 dark:to-slate-800/30">
        <div class="max-w-7xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-16 reveal">
                <span class="section-eyebrow justify-center">How It Works</span>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-gray-900 dark:text-white mb-4">Get Started in 5 Simple Steps</h2>
                <p class="text-gray-500 dark:text-gray-400 max-w-xl mx-auto text-lg">Whether you're hiring or looking for work, our platform makes it effortless</p>
            </div>

            <div class="grid sm:grid-cols-2 lg:grid-cols-5 gap-6 lg:gap-4">
                <?php
                $steps = [
                    ['num' => '01', 'title' => 'Create Account', 'desc' => 'Sign up as a Company or Freelancer in seconds', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>', 'color' => 'from-blue-500 to-indigo-600'],
                    ['num' => '02', 'title' => 'Build Profile', 'desc' => 'Showcase your skills, experience, and portfolio', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>', 'color' => 'from-purple-500 to-accent-600'],
                    ['num' => '03', 'title' => 'Post or Find Jobs', 'desc' => 'Companies post projects, freelancers browse & apply', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>', 'color' => 'from-emerald-500 to-teal-600'],
                    ['num' => '04', 'title' => 'Collaborate', 'desc' => 'Work together with built-in messaging and task tracking', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>', 'color' => 'from-amber-500 to-orange-600'],
                    ['num' => '05', 'title' => 'Get Paid', 'desc' => 'Secure payment processed when work is approved', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>', 'color' => 'from-pink-500 to-rose-600'],
                ];
                foreach ($steps as $i => $step):
                ?>
                    <div class="step-card reveal reveal-d<?= ($i % 5) + 1 ?>">
                        <div class="relative inline-block mb-5">
                            <div class="w-14 h-14 bg-gradient-to-br <?= $step['color'] ?> rounded-2xl flex items-center justify-center mx-auto shadow-md relative z-10">
                                <svg class="w-7 h-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><?= $step['icon'] ?></svg>
                            </div>
                            <span class="absolute -top-1.5 -right-1.5 w-6 h-6 bg-white dark:bg-gray-800 rounded-full flex items-center justify-center text-[10px] font-bold text-primary-600 dark:text-primary-400 shadow-sm border border-primary-100 dark:border-primary-800 z-20"><?= $step['num'] ?></span>
                        </div>
                        <h3 class="font-bold text-sm text-gray-900 dark:text-white mb-1.5"><?= e($step['title']) ?></h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed"><?= e($step['desc']) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ===== TESTIMONIALS ===== -->
    <section id="testimonials" class="py-28">
        <div class="max-w-7xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-16 reveal">
                <span class="section-eyebrow justify-center">Testimonials</span>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-gray-900 dark:text-white mb-4">Loved by Thousands</h2>
                <p class="text-gray-500 dark:text-gray-400 max-w-xl mx-auto text-lg">See what our community has to say about working with HireWork</p>
            </div>

            <!-- Carousel -->
            <div class="relative" id="testimonial-carousel">
                <div class="overflow-hidden">
                    <div id="testimonial-track" class="flex transition-transform duration-500 ease-out">
                        <?php
                        $testimonials = [
                            ['name' => 'Sarah Chen', 'role' => 'Startup Founder', 'text' => 'HireWork helped us find an amazing developer in just 2 days. The platform is intuitive and the payment system gives us total peace of mind.', 'color' => 'from-blue-400 to-indigo-500'],
                            ['name' => 'David Park', 'role' => 'Full-Stack Developer', 'text' => "I've earned over $15,000 through this platform. The job matching is great and I love the built-in messaging feature for client communication.", 'color' => 'from-purple-400 to-accent-500'],
                            ['name' => 'Emily Rodriguez', 'role' => 'Marketing Agency', 'text' => "We've hired 12 freelancers through HireWork. The quality of talent is outstanding and the admin team keeps everything running smoothly.", 'color' => 'from-emerald-400 to-teal-500'],
                        ];
                        foreach ($testimonials as $i => $t):
                        ?>
                            <div class="w-full md:w-1/3 flex-shrink-0 px-3">
                                <div class="test-card">
                                    <div class="flex items-center gap-0.5 mb-5">
                                        <?php for ($s = 0; $s < 5; $s++): ?>
                                            <svg class="w-4 h-4 text-amber-400" fill="currentColor" viewBox="0 0 20 20">
                                                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.538 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                                            </svg>
                                        <?php endfor; ?>
                                    </div>
                                    <p class="text-gray-600 dark:text-gray-300 mb-6 leading-relaxed text-sm italic flex-1">"<?= e($t['text']) ?>"</p>
                                    <div class="flex items-center gap-3 pt-5 border-t border-gray-100 dark:border-gray-700/50">
                                        <div class="w-10 h-10 rounded-full bg-gradient-to-br <?= $t['color'] ?> flex items-center justify-center text-white font-bold text-sm shadow-md">
                                            <?= e(_first_char($t['name'])) ?>
                                        </div>
                                        <div>
                                            <p class="font-semibold text-gray-900 dark:text-white text-sm"><?= e($t['name']) ?></p>
                                            <p class="text-xs text-gray-400 dark:text-gray-500"><?= e($t['role']) ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <button id="carousel-prev" class="absolute top-1/2 -translate-y-1/2 -left-4 w-11 h-11 bg-white dark:bg-gray-800 rounded-full shadow-lg border border-gray-200 dark:border-gray-700 flex items-center justify-center text-gray-600 dark:text-gray-300 hover:bg-primary-50 dark:hover:bg-primary-900/30 hover:text-primary-600 transition-all z-10 hidden md:flex" aria-label="Previous">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>

                <button id="carousel-next" class="absolute top-1/2 -translate-y-1/2 -right-4 w-11 h-11 bg-white dark:bg-gray-800 rounded-full shadow-lg border border-gray-200 dark:border-gray-700 flex items-center justify-center text-gray-600 dark:text-gray-300 hover:bg-primary-50 dark:hover:bg-primary-900/30 hover:text-primary-600 transition-all z-10 hidden md:flex" aria-label="Next">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                    </svg>
                </button>

                <div class="flex justify-center gap-2 mt-8" id="carousel-dots"></div>
            </div>
        </div>

        <script>
            (function() {
                const track = document.getElementById('testimonial-track');
                const prevBtn = document.getElementById('carousel-prev');
                const nextBtn = document.getElementById('carousel-next');
                const dotsContainer = document.getElementById('carousel-dots');
                const cards = track.children;
                const total = cards.length;
                let current = 0;
                let perPage = 3;
                let autoInterval;

                function getPerPage() {
                    if (window.innerWidth < 768) return 1;
                    if (window.innerWidth < 1024) return 2;
                    return 3;
                }

                function totalPages() {
                    return Math.max(1, Math.ceil(total / perPage));
                }

                function buildDots() {
                    dotsContainer.innerHTML = '';
                    const pages = totalPages();
                    for (let i = 0; i < pages; i++) {
                        const dot = document.createElement('button');
                        dot.className = 'w-3 h-3 rounded-full transition-all duration-300 ' + (i === 0 ? 'bg-primary-500 scale-110' : 'bg-gray-300 dark:bg-gray-600 hover:bg-primary-300');
                        dot.setAttribute('aria-label', 'Go to slide ' + (i + 1));
                        dot.addEventListener('click', function() {
                            goTo(i);
                        });
                        dotsContainer.appendChild(dot);
                    }
                }

                function updateDots() {
                    const dots = dotsContainer.children;
                    for (let i = 0; i < dots.length; i++) {
                        dots[i].className = 'w-3 h-3 rounded-full transition-all duration-300 ' + (i === current ? 'bg-primary-500 scale-110' : 'bg-gray-300 dark:bg-gray-600 hover:bg-primary-300');
                    }
                }

                function goTo(index) {
                    const pages = totalPages();
                    current = ((index % pages) + pages) % pages;
                    const cardWidth = 100 / perPage;
                    const offset = current * perPage * cardWidth;
                    track.style.transform = 'translateX(-' + offset + '%)';
                    updateDots();
                    resetAuto();
                }

                function next() {
                    goTo(current + 1);
                }

                function prev() {
                    goTo(current - 1);
                }

                function startAuto() {
                    stopAuto();
                    autoInterval = setInterval(next, 4000);
                }

                function stopAuto() {
                    if (autoInterval) clearInterval(autoInterval);
                }

                function resetAuto() {
                    stopAuto();
                    startAuto();
                }

                prevBtn.addEventListener('click', function() {
                    prev();
                });
                nextBtn.addEventListener('click', function() {
                    next();
                });

                // Touch/swipe support
                let touchStartX = 0;
                let touchEndX = 0;
                track.addEventListener('touchstart', function(e) {
                    touchStartX = e.changedTouches[0].screenX;
                    stopAuto();
                }, {
                    passive: true
                });
                track.addEventListener('touchend', function(e) {
                    touchEndX = e.changedTouches[0].screenX;
                    const diff = touchStartX - touchEndX;
                    if (Math.abs(diff) > 50) {
                        diff > 0 ? next() : prev();
                    }
                    startAuto();
                }, {
                    passive: true
                });

                // Pause on hover
                document.getElementById('testimonial-carousel').addEventListener('mouseenter', stopAuto);
                document.getElementById('testimonial-carousel').addEventListener('mouseleave', startAuto);

                // Responsive
                function onResize() {
                    perPage = getPerPage();
                    buildDots();
                    goTo(current);
                }

                window.addEventListener('resize', onResize);
                perPage = getPerPage();
                buildDots();
                goTo(0);
                startAuto();
            })();
        </script>
    </section>

    <!-- ===== CTA BANNER ===== -->
    <section class="py-28">
        <div class="max-w-7xl mx-auto px-4 sm:px-6">
            <div class="cta-glow relative overflow-hidden rounded-3xl bg-gradient-to-r from-primary-600 via-accent-600 to-primary-700 p-12 md:p-16 lg:p-20 text-center reveal">
                <div class="absolute top-0 left-0 w-full h-full overflow-hidden pointer-events-none">
                    <div class="absolute -top-24 -right-24 w-64 h-64 bg-white/5 rounded-full blur-2xl"></div>
                    <div class="absolute -bottom-24 -left-24 w-80 h-80 bg-white/5 rounded-full blur-2xl"></div>
                    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-96 h-96 bg-white/5 rounded-full blur-3xl"></div>
                </div>

                <div class="relative z-10">
                    <h2 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-white mb-5">Ready to Start Your<br class="hidden sm:block"> Freelance Journey?</h2>
                    <p class="text-lg text-white/80 mb-10 max-w-2xl mx-auto">Join thousands of companies and freelancers already using HireWork to build amazing things together.</p>
                    <div class="flex flex-wrap justify-center gap-4">
                        <a href="<?= e(base_url('register.php')) ?>" class="inline-flex items-center px-8 py-4 rounded-xl font-bold text-primary-600 bg-white hover:bg-gray-50 transition-all shadow-xl shadow-black/10 hover:shadow-2xl hover:-translate-y-1">
                            <svg class="w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                            Get Started Free
                        </a>
                        <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="inline-flex items-center px-8 py-4 rounded-xl font-bold text-white border-2 border-white/30 hover:bg-white/10 transition-all hover:-translate-y-1">
                            <svg class="w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                            Explore Jobs
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ===== FOOTER ===== -->
    <footer class="bg-gray-900 dark:bg-black pt-20 pb-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6">
            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-10 lg:gap-12 mb-16">
                <!-- Brand -->
                <div class="sm:col-span-2 lg:col-span-1">
                    <a href="<?= e(base_url('index.php')) ?>" class="inline-flex items-center gap-2.5 mb-5">
                        <div class="w-10 h-10 bg-gradient-to-br from-primary-500 to-accent-500 rounded-xl flex items-center justify-center shadow-lg shadow-primary-500/20">
                            <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <span class="text-xl font-bold text-white">HireWork</span>
                    </a>
                    <p class="text-gray-400 text-sm leading-relaxed mb-6">The modern platform connecting companies with skilled freelancers worldwide.</p>
                    <div class="flex gap-2.5">
                        <a href="#" class="w-9 h-9 rounded-lg bg-gray-800 hover:bg-primary-600 flex items-center justify-center text-gray-400 hover:text-white transition-all duration-300">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M24 4.557c-.883.392-1.832.656-2.828.775 1.017-.609 1.798-1.574 2.165-2.724-.951.564-2.005.974-3.127 1.195-.897-.957-2.178-1.555-3.594-1.555-3.179 0-5.515 2.966-4.797 6.045-4.091-.205-7.719-2.165-10.148-5.144-1.29 2.213-.669 5.108 1.523 6.574-.806-.026-1.566-.247-2.229-.616-.054 2.281 1.581 4.415 3.949 4.89-.693.188-1.452.232-2.224.084.626 1.956 2.444 3.379 4.6 3.419-2.07 1.623-4.678 2.348-7.29 2.04 2.179 1.397 4.768 2.212 7.548 2.212 9.142 0 14.307-7.721 13.995-14.646.962-.695 1.797-1.562 2.457-2.549z" />
                            </svg>
                        </a>
                        <a href="#" class="w-9 h-9 rounded-lg bg-gray-800 hover:bg-primary-600 flex items-center justify-center text-gray-400 hover:text-white transition-all duration-300">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z" />
                            </svg>
                        </a>
                        <a href="#" class="w-9 h-9 rounded-lg bg-gray-800 hover:bg-primary-600 flex items-center justify-center text-gray-400 hover:text-white transition-all duration-300">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z" />
                            </svg>
                        </a>
                    </div>
                </div>

                <!-- For Companies -->
                <div>
                    <h4 class="text-white font-bold mb-5 text-sm">For Companies</h4>
                    <ul class="space-y-2.5">
                        <li><a href="<?= e(base_url('register.php')) ?>" class="text-gray-400 hover:text-primary-400 text-sm transition-colors duration-200">Post a Job</a></li>
                        <li><a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="text-gray-400 hover:text-primary-400 text-sm transition-colors duration-200">Browse Jobs</a></li>
                        <li><a href="<?= $is_logged_in ? e(base_url('company/dashboard.php')) : 'javascript:void(0)' ?>" <?= $is_logged_in ? '' : 'onclick="document.getElementById(\'loginModal\').classList.remove(\'hidden\')"' ?> class="text-gray-400 hover:text-primary-400 text-sm transition-colors duration-200">Login</a></li>
                    </ul>
                </div>

                <!-- For Freelancers -->
                <div>
                    <h4 class="text-white font-bold mb-5 text-sm">For Freelancers</h4>
                    <ul class="space-y-2.5">
                        <li><a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="text-gray-400 hover:text-primary-400 text-sm transition-colors duration-200">Find Work</a></li>
                        <li><a href="<?= e(base_url('register.php')) ?>" class="text-gray-400 hover:text-primary-400 text-sm transition-colors duration-200">Create Profile</a></li>
                        <li><a href="<?= $is_logged_in ? e(base_url('freelancer/dashboard.php')) : 'javascript:void(0)' ?>" <?= $is_logged_in ? '' : 'onclick="document.getElementById(\'loginModal\').classList.remove(\'hidden\')"' ?> class="text-gray-400 hover:text-primary-400 text-sm transition-colors duration-200">Login</a></li>
                    </ul>
                </div>

                <!-- Contact -->
                <div>
                    <h4 class="text-white font-bold mb-5 text-sm">Contact</h4>
                    <ul class="space-y-3">
                        <li class="flex items-center gap-2.5 text-gray-400 text-sm">
                            <svg class="w-4 h-4 text-primary-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                            support@hirework.com
                        </li>
                        <li class="flex items-center gap-2.5 text-gray-400 text-sm">
                            <svg class="w-4 h-4 text-primary-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                            </svg>
                            +1 (555) 123-4567
                        </li>
                        <li class="flex items-center gap-2.5 text-gray-400 text-sm">
                            <svg class="w-4 h-4 text-primary-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                            San Francisco, CA
                        </li>
                    </ul>
                </div>
            </div>

            <div class="pt-8 border-t border-gray-800 flex flex-col sm:flex-row items-center justify-between gap-4">
                <p class="text-gray-500 text-sm">&copy; <?= date('Y') ?> HireWork. All rights reserved.</p>
                <div class="flex items-center gap-6">
                    <a href="#" class="text-gray-500 hover:text-primary-400 text-sm transition-colors duration-200">Privacy Policy</a>
                    <a href="#" class="text-gray-500 hover:text-primary-400 text-sm transition-colors duration-200">Terms of Service</a>
                    <a href="#" class="text-gray-500 hover:text-primary-400 text-sm transition-colors duration-200">Help Center</a>
                </div>
            </div>
        </div>
    </footer>

    <!-- ===== BACK TO TOP ===== -->
    <button id="back-to-top" class="back-to-top fixed bottom-8 right-8 w-12 h-12 bg-gradient-to-br from-primary-500 to-accent-500 text-white rounded-2xl shadow-lg shadow-primary-500/30 flex items-center justify-center hover:shadow-xl hover:-translate-y-1 transition-all z-50">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7" />
        </svg>
    </button>

    <!-- ===== LOGIN MODAL ===== -->
    <div id="loginModal" class="hidden fixed inset-0 z-[100] flex items-center justify-center bg-black/60 backdrop-blur-sm">
        <div class="relative w-full max-w-md mx-4 bg-white dark:bg-gray-900 rounded-2xl shadow-2xl overflow-hidden" style="animation: modalEntry 0.3s cubic-bezier(0.16, 1, 0.3, 1);">
            <!-- Close button -->
            <button type="button" onclick="document.getElementById('loginModal').classList.add('hidden')" class="absolute top-4 right-4 w-8 h-8 flex items-center justify-center rounded-lg text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors z-10">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>

            <!-- Header -->
            <div class="px-8 pt-8 pb-4 text-center">
                <div class="w-14 h-14 mx-auto mb-4 rounded-2xl flex items-center justify-center text-white text-xl" style="background:linear-gradient(135deg, #4f46e5, #7c3aed);">
                    <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                </div>
                <h2 class="text-xl font-bold text-gray-900 dark:text-white">Welcome Back</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Sign in to continue to HireWork</p>
            </div>

            <!-- Error -->
            <?php if ($login_error): ?>
                <div class="mx-8 mb-4 p-3 rounded-xl flex items-center gap-2.5 text-sm font-medium" style="background:rgba(239,68,68,0.08);color:#dc2626;border:1px solid rgba(239,68,68,0.15);">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span><?= e($login_error) ?></span>
                </div>
            <?php endif; ?>

            <!-- Form -->
            <form method="POST" class="px-8 pb-8 space-y-4" onsubmit="return handleHomeLogin(event)">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="home_login" value="1">

                <div>
                    <label class="block text-sm font-medium mb-1.5 text-gray-700 dark:text-gray-300">Email</label>
                    <div class="relative">
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                        <input type="email" name="email" required class="w-full pl-10 pr-4 py-2.5 rounded-xl border-2 border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white text-sm focus:border-indigo-500 focus:ring-0 focus:outline-none transition-colors" placeholder="you@example.com">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1.5 text-gray-700 dark:text-gray-300">Password</label>
                    <div class="relative">
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                        <input type="password" name="password" id="homeLoginPassword" required class="w-full pl-10 pr-10 py-2.5 rounded-xl border-2 border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white text-sm focus:border-indigo-500 focus:ring-0 focus:outline-none transition-colors" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;">
                        <button type="button" onclick="toggleHomePassword()" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                            <svg id="homeEyeOpen" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                            </svg>
                            <svg id="homeEyeClosed" class="w-5 h-5 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                            </svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="w-full py-3 rounded-xl text-white text-sm font-semibold transition-all hover:-translate-y-0.5 hover:shadow-lg" style="background:linear-gradient(135deg, #4f46e5, #7c3aed);">
                    Login
                </button>
            </form>

            <div class="px-8 pb-8 text-center">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Don't have an account?
                    <a href="<?= e(base_url('register.php')) ?>" class="font-semibold text-indigo-600 hover:text-indigo-500 transition-colors">Register</a>
                </p>
            </div>
        </div>
    </div>

    <style>
        @keyframes modalEntry {
            from {
                opacity: 0;
                transform: scale(0.95) translateY(10px);
            }

            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }
    </style>

    <script>
        (function() {
            // Scroll reveal
            var revealEls = document.querySelectorAll('.reveal');
            var revealObserver = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('visible');
                    }
                });
            }, {
                threshold: 0.1,
                rootMargin: '0px 0px -60px 0px'
            });
            revealEls.forEach(function(el) {
                revealObserver.observe(el);
            });

            // Counter animation
            var counters = document.querySelectorAll('.counter-val');
            var counterObserver = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        var el = entry.target;
                        var target = parseInt(el.getAttribute('data-target')) || 0;
                        var duration = 2000;
                        var startTime = null;

                        function step(timestamp) {
                            if (!startTime) startTime = timestamp;
                            var progress = Math.min((timestamp - startTime) / duration, 1);
                            var eased = 1 - Math.pow(1 - progress, 3);
                            el.textContent = Math.floor(eased * target).toLocaleString();
                            if (progress < 1) requestAnimationFrame(step);
                            else el.textContent = target.toLocaleString() + '+';
                        }
                        requestAnimationFrame(step);
                        counterObserver.unobserve(el);
                    }
                });
            }, {
                threshold: 0.5
            });
            counters.forEach(function(el) {
                counterObserver.observe(el);
            });

            // Back to top
            var backToTop = document.getElementById('back-to-top');
            window.addEventListener('scroll', function() {
                if (backToTop) {
                    if (window.scrollY > 500) backToTop.classList.add('show');
                    else backToTop.classList.remove('show');
                }
            });
            if (backToTop) {
                backToTop.addEventListener('click', function() {
                    window.scrollTo({
                        top: 0,
                        behavior: 'smooth'
                    });
                });
            }

            // Hero search
            window.heroSearch = function() {
                var input = document.getElementById('hero-search');
                if (input && input.value.trim()) {
                    window.location = '<?= e(base_url('freelancer/browse_jobs.php')) ?>?q=' + encodeURIComponent(input.value.trim());
                } else if (input) {
                    input.focus();
                }
            };
            var heroSearchInput = document.getElementById('hero-search');
            if (heroSearchInput) {
                heroSearchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') window.heroSearch();
                });
            }

            // Login modal
            var loginModal = document.getElementById('loginModal');
            if (loginModal) {
                loginModal.addEventListener('click', function(e) {
                    if (e.target === loginModal) loginModal.classList.add('hidden');
                });
            }
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && loginModal && !loginModal.classList.contains('hidden')) {
                    loginModal.classList.add('hidden');
                }
            });
        })();

        function toggleHomePassword() {
            var pwd = document.getElementById('homeLoginPassword');
            var open = document.getElementById('homeEyeOpen');
            var closed = document.getElementById('homeEyeClosed');
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

        function handleHomeLogin(e) {
            var btn = e.target.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="inline-flex items-center gap-2"><svg class="animate-spin w-5 h-5" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> Signing in...</span>';
            }
            return true;
        }

        // Hero Slideshow
        (function() {
            var slides = document.querySelectorAll('.hero-slide');
            var dots = document.querySelectorAll('.hero-dot');
            var prevBtn = document.getElementById('heroPrev');
            var nextBtn = document.getElementById('heroNext');
            var current = 0;
            var total = slides.length;
            var interval;

            function goTo(index) {
                slides[current].classList.remove('active');
                dots[current].classList.remove('bg-white');
                dots[current].classList.add('bg-white/40');
                current = index;
                slides[current].classList.add('active');
                dots[current].classList.remove('bg-white/40');
                dots[current].classList.add('bg-white');
            }

            function next() {
                goTo((current + 1) % total);
            }

            function prev() {
                goTo((current - 1 + total) % total);
            }

            function startAuto() {
                stopAuto();
                interval = setInterval(next, 5000);
            }

            function stopAuto() {
                if (interval) clearInterval(interval);
            }

            window.heroSlide = function(index) {
                stopAuto();
                goTo(index);
                startAuto();
            };

            if (prevBtn) prevBtn.addEventListener('click', function() {
                stopAuto();
                prev();
                startAuto();
            });
            if (nextBtn) nextBtn.addEventListener('click', function() {
                stopAuto();
                next();
                startAuto();
            });

            // Touch swipe support
            var touchStartX = 0;
            var heroEl = document.querySelector('.hero-section');
            if (heroEl) {
                heroEl.addEventListener('touchstart', function(e) {
                    touchStartX = e.changedTouches[0].screenX;
                    stopAuto();
                }, {
                    passive: true
                });
                heroEl.addEventListener('touchend', function(e) {
                    var diff = touchStartX - e.changedTouches[0].screenX;
                    if (Math.abs(diff) > 50) {
                        diff > 0 ? next() : prev();
                    }
                    startAuto();
                }, {
                    passive: true
                });
            }

            startAuto();
        })();
    </script>
</body>

</html>