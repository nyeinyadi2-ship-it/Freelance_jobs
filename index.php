<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/notifications.php';
require_once __DIR__ . '/config/chat.php';

// Set CSRF cookie early (before any HTML output)
csrf_cookie();

// Handle login POST from home page
$login_error = '';
$login_success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['home_login'])) {
    if (!verify_csrf()) {
        $login_error = 'Invalid request. Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $login_error = 'Email and password are required.';
        } else {
            $has_status_col = has_account_status_column();
            $sql = $has_status_col
                ? 'SELECT id, username, email, password, role, profile_image, account_status, suspension_reason, suspension_end_date, block_reason FROM users WHERE email = ?'
                : 'SELECT id, username, email, password, role, profile_image FROM users WHERE email = ?';
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($user && password_verify($password, $user['password'])) {
                $account_status = $has_status_col ? ($user['account_status'] ?? 'active') : 'active';
                $end_date = $user['suspension_end_date'] ?? null;
                
                if ($has_status_col) {
                    // check_and_update_suspension_status is in config/auth.php
                    $account_status = check_and_update_suspension_status($conn, (int)$user['id'], $account_status, $end_date);
                }

                if ($account_status === 'suspended') {
                    $reason = e($user['suspension_reason'] ?: 'No reason provided.');
                    $end_date_str = e(date('d M Y', strtotime($end_date)));
                    $login_error = "⚠️ Your account has been suspended until {$end_date_str}.<br>Reason: {$reason}";
                    $login_error_type = 'warning';
                } elseif ($account_status === 'blocked') {
                    $reason = e($user['block_reason'] ?: 'No reason provided.');
                    $login_error = "⚠️ Your account has been blocked.<br>Reason: {$reason}";
                    $login_error_type = 'error';
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
                        redirect('index.php');
                    } elseif ($user['role'] === 'freelancer') {
                        $_SESSION['profile_id'] = get_freelancer_id($conn, (int) $user['id']);
                        $_SESSION['logo_image'] = null;
                        redirect('index.php');
                    } else {
                        $_SESSION['profile_id'] = null;
                        $_SESSION['logo_image'] = null;
                        redirect('admin/admin_dashboard.php');
                    }
                }
            } else {
                $login_error = 'Invalid email or password.';
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
$r = $conn->query("SELECT COUNT(*) AS cnt FROM jobs WHERE status = 'open' AND category != 'Direct Hire'");
$stats['jobs'] = (int) $r->fetch_assoc()['cnt'];
$r = $conn->query("SELECT COUNT(*) AS cnt FROM jobs WHERE status = 'completed'");
$stats['completed'] = (int) $r->fetch_assoc()['cnt'];


// Fetch all skills for the Skills section
$skills_list = isset($_SESSION['nav_skills']) ? $_SESSION['nav_skills'] : [];
if (empty($skills_list)) {
    $r = $conn->query("SELECT id, skill_name FROM skills ORDER BY skill_name");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $skills_list[] = $row;
        }
        $_SESSION['nav_skills'] = $skills_list;
    }
}

$page_title = 'FreelanceHub - Find Work or Hire Talent';
?>
<!DOCTYPE html>
<html lang="<?= e('en') ?>" data-theme>

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
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
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

        /* Skill selector — hide native arrow across all browsers */
        .skill-select-no-arrow {
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background-image: none !important;
        }
        .skill-select-no-arrow::-ms-expand {
            display: none;
        }
        @supports (-webkit-touch-callout: none) {
            .skill-select-no-arrow {
                padding-right: 1rem;
            }
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
                    <a href="<?= e(base_url('auth/register.php')) ?>" class="px-8 py-4 rounded-xl font-semibold text-white text-base bg-white/10 backdrop-blur-md border border-white/20 hover:bg-white/20 hover:border-white/30 transition-all inline-flex items-center gap-2">
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

    <!-- ===== BROWSE BY SKILL ===== -->
    <section id="skills" class="py-28 bg-white/50 dark:bg-slate-800/30">
        <div class="max-w-7xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-10 reveal">
                <span class="section-eyebrow justify-center">Skills</span>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-gray-900 dark:text-white mb-4">Browse Jobs by Skill</h2>
                <p class="text-gray-500 dark:text-gray-400 max-w-xl mx-auto text-lg">Select a skill to find matching job opportunities</p>
            </div>

            <!-- Skill Selector -->
            <div class="max-w-md mx-auto mb-10 reveal">
                <div class="relative">
                    <svg class="w-5 h-5 absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                    <select id="skill-selector" class="skill-select-no-arrow w-full pl-12 pr-4 py-4 rounded-2xl text-sm font-medium focus:outline-none focus:ring-2 focus:ring-indigo-500 cursor-pointer transition-all" style="background-color:var(--color-card,#fff);border:1px solid var(--color-border,#e5e7eb);color:var(--color-text-primary,#111827)">
                        <option value="">Select a skill...</option>
                        <?php foreach ($skills_list as $sk): ?>
                            <option value="<?= e($sk['skill_name']) ?>"><?= e($sk['skill_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Filtered Jobs Container -->
            <div id="skill-jobs-container" class="hidden">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white" id="skill-jobs-title">Jobs</h3>
                    <span class="text-sm text-gray-500 dark:text-gray-400" id="skill-jobs-count"></span>
                </div>
                <div id="skill-jobs-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                </div>
                <div id="skill-jobs-loading" class="hidden text-center py-12">
                    <div class="inline-flex items-center gap-3 text-gray-500 dark:text-gray-400">
                        <svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                        Loading jobs...
                    </div>
                </div>
                <div id="skill-jobs-empty" class="hidden text-center py-12 bg-white dark:bg-slate-800/50 rounded-2xl border border-gray-100 dark:border-gray-700/50">
                    <svg class="w-12 h-12 mx-auto mb-3 text-gray-300 dark:text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    <p class="text-gray-500 dark:text-gray-400">No jobs found for this skill.</p>
                </div>
                <div class="text-center mt-8" id="skill-jows-view-all" style="display:none">
                    <a id="skill-view-all-link" href="#" class="btn-gradient inline-flex items-center gap-2 px-6 py-3 rounded-xl font-semibold text-white text-sm shadow-lg shadow-primary-500/25">
                        View All Matching Jobs
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                    </a>
                </div>
            </div>

            <!-- Default: show top skills as pills -->
            <div id="skills-pills" class="flex flex-wrap justify-center gap-3 reveal">
                <?php
                $top_skills = ['PHP', 'JavaScript', 'React.js', 'UI/UX Design', 'Python', 'Content Writing', 'Laravel', 'MySQL', 'Figma', 'Node.js'];
                $pill_colors = [
                    'PHP' => 'from-blue-500 to-indigo-600',
                    'JavaScript' => 'from-yellow-400 to-orange-500',
                    'React.js' => 'from-cyan-400 to-blue-500',
                    'UI/UX Design' => 'from-pink-500 to-rose-600',
                    'Python' => 'from-green-500 to-emerald-600',
                    'Content Writing' => 'from-violet-500 to-purple-600',
                    'Laravel' => 'from-red-600 to-rose-700',
                    'MySQL' => 'from-blue-600 to-indigo-700',
                    'Figma' => 'from-purple-500 to-fuchsia-600',
                    'Node.js' => 'from-green-600 to-teal-700',
                ];
                foreach ($skills_list as $sk):
                    if (!in_array($sk['skill_name'], $top_skills)) continue;
                    $color = $pill_colors[$sk['skill_name']] ?? 'from-gray-500 to-gray-600';
                ?>
                    <button type="button" onclick="selectSkill('<?= e($sk['skill_name']) ?>')" class="skill-pill inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold text-white bg-gradient-to-br <?= $color ?> shadow-md hover:shadow-lg hover:-translate-y-0.5 transition-all cursor-pointer">
                        <?= e($sk['skill_name']) ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

<script>
(function() {
    var selector = document.getElementById('skill-selector');
    var container = document.getElementById('skill-jobs-container');
    var grid = document.getElementById('skill-jobs-grid');
    var loading = document.getElementById('skill-jobs-loading');
    var empty = document.getElementById('skill-jobs-empty');
    var title = document.getElementById('skill-jobs-title');
    var count = document.getElementById('skill-jobs-count');
    var pills = document.getElementById('skills-pills');
    var viewAllWrap = document.getElementById('skill-jows-view-all');
    var viewAllLink = document.getElementById('skill-view-all-link');

    if (!selector) return;

    var debounceTimer = null;

    selector.addEventListener('change', function() {
        selectSkill(this.value);
    });

    window.selectSkill = function(skill) {
        if (!skill) {
            container.classList.add('hidden');
            pills.classList.remove('hidden');
            return;
        }
        // Update dropdown to match
        selector.value = skill;

        // Show container, hide pills
        container.classList.remove('hidden');
        pills.classList.add('hidden');
        grid.innerHTML = '';
        loading.classList.remove('hidden');
        empty.classList.add('hidden');
        viewAllWrap.style.display = 'none';
        title.textContent = skill + ' Jobs';

        fetch('<?= e(base_url("api/skill_jobs.php")) ?>?skill=' + encodeURIComponent(skill) + '&limit=6')
            .then(function(r) { return r.json(); })
            .then(function(d) {
                loading.classList.add('hidden');
                if (!d.success || !d.jobs || d.jobs.length === 0) {
                    empty.classList.remove('hidden');
                    count.textContent = '';
                    return;
                }
                count.textContent = d.count + ' job' + (d.count !== 1 ? 's' : '') + ' found';
                viewAllWrap.style.display = '';
                viewAllLink.href = '<?= e(base_url("freelancer/skill_jobs.php?skill=")) ?>' + encodeURIComponent(skill);

                d.jobs.forEach(function(job) {
                    var logoHtml = job.logo_url
                        ? '<img src="' + escHtml(job.logo_url) + '" alt="" class="w-10 h-10 rounded-lg object-cover">'
                        : '<div class="w-10 h-10 rounded-lg flex items-center justify-center text-white font-bold text-sm" style="background:linear-gradient(135deg,#6366f1,#8b5cf6)">' + escHtml(job.company_name.charAt(0).toUpperCase()) + '</div>';

                    var expLabel = job.experience_level === 'beginner' ? 'Beginner' : job.experience_level === 'expert' ? 'Expert' : 'Intermediate';
                    var timeAgo = timeSince(job.created_at);

                    grid.innerHTML +=
                        '<div class="job-card bg-white dark:bg-slate-800 rounded-2xl border border-gray-100 dark:border-gray-700/50 p-6 hover:border-indigo-300 dark:hover:border-indigo-600 transition-all">' +
                            '<div class="flex items-start gap-3 mb-4">' +
                                logoHtml +
                                '<div class="flex-1 min-w-0">' +
                                    '<p class="text-xs text-gray-400 mb-0.5">' + escHtml(job.company_name) + '</p>' +
                                    '<h3 class="font-bold text-gray-900 dark:text-white text-sm leading-snug line-clamp-2">' + escHtml(job.title) + '</h3>' +
                                '</div>' +
                            '</div>' +
                            '<p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed mb-4 line-clamp-3">' + escHtml(job.description) + '</p>' +
                            '<div class="flex flex-wrap gap-1.5 mb-4">' +
                                '<span class="px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400">' + escHtml(expLabel) + '</span>' +
                                (job.duration ? '<span class="px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">' + escHtml(job.duration) + '</span>' : '') +
                            '</div>' +
                            '<div class="flex items-center justify-between pt-3 border-t border-gray-100 dark:border-gray-700/50">' +
                                '<span class="text-sm font-bold text-emerald-600 dark:text-emerald-400">$' + Number(job.budget).toLocaleString() + '</span>' +
                                '<a href="<?= e(base_url("freelancer/view_job.php?id=")) ?>' + job.id + '" class="text-xs font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 transition-colors">View Details →</a>' +
                            '</div>' +
                        '</div>';
                });
            })
            .catch(function() {
                loading.classList.add('hidden');
                empty.classList.remove('hidden');
            });
    };

    function escHtml(s) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s || ''));
        return d.innerHTML;
    }

    function timeSince(dateStr) {
        var now = new Date();
        var d = new Date(dateStr);
        var diff = Math.floor((now - d) / 1000);
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        if (diff < 2592000) return Math.floor(diff / 86400) + 'd ago';
        return d.toLocaleDateString();
    }
})();
</script>


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
                        <span class="text-xl font-bold text-white">FreelanceHub</span>
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
                        <li><a href="<?= e(base_url('auth/register.php')) ?>" class="text-gray-400 hover:text-primary-400 text-sm transition-colors duration-200">Post a Job</a></li>
                        <li><a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="text-gray-400 hover:text-primary-400 text-sm transition-colors duration-200">Browse Jobs</a></li>
                        <li><a href="<?= $is_logged_in ? e(base_url('company/dashboard.php')) : 'javascript:void(0)' ?>" <?= $is_logged_in ? '' : 'onclick="document.getElementById(\'loginModal\').classList.remove(\'hidden\')"' ?> class="text-gray-400 hover:text-primary-400 text-sm transition-colors duration-200">Login</a></li>
                    </ul>
                </div>

                <!-- For Freelancers -->
                <div>
                    <h4 class="text-white font-bold mb-5 text-sm">For Freelancers</h4>
                    <ul class="space-y-2.5">
                        <li><a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="text-gray-400 hover:text-primary-400 text-sm transition-colors duration-200">Find Work</a></li>
                        <li><a href="<?= e(base_url('auth/register.php')) ?>" class="text-gray-400 hover:text-primary-400 text-sm transition-colors duration-200">Create Profile</a></li>
                        <li><a href="<?= $is_logged_in ? e(base_url('freelancer/dashboard.php')) : 'javascript:void(0)' ?>" <?= $is_logged_in ? '' : 'onclick="document.getElementById(\'loginModal\').classList.remove(\'hidden\')"' ?> class="text-gray-400 hover:text-primary-400 text-sm transition-colors duration-200">Login</a></li>
                    </ul>
                </div>

                <!-- Contact -->
                <div>
                    <h4 class="text-white font-bold mb-5 text-sm">Contact</h4>
                    <ul class="space-y-3">
                        <?php $admin_chat_id = get_admin_user_id($conn); ?>
                        <?php if ($admin_chat_id): ?>
                        <li>
                            <a href="<?= e(base_url('chat/index.php?user_id=' . $admin_chat_id)) ?>" class="flex items-center gap-2.5 text-gray-400 text-sm hover:text-primary-400 transition-colors duration-200">
                                <svg class="w-4 h-4 text-primary-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                                </svg>
                                Contact Us
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <div class="pt-8 border-t border-gray-800 flex flex-col sm:flex-row items-center justify-between gap-4">
                <p class="text-gray-500 text-sm">&copy; <?= date('Y') ?> FreelanceHub. All rights reserved.</p>
                <div class="flex items-center gap-6">
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
    <div id="loginModal" class="<?= $login_error ? '' : 'hidden ' ?>fixed inset-0 z-[100] flex items-center justify-center bg-black/60 backdrop-blur-sm">
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
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Sign in to continue to FreelanceHub</p>
            </div>

            <!-- Error -->
            <?php if ($login_error): ?>
                <?php 
                  $err_bg = 'rgba(239,68,68,0.08)';
                  $err_text = '#dc2626';
                  $err_border = 'rgba(239,68,68,0.15)';
                  $err_type = $login_error_type ?? 'error';
                  if ($err_type === 'warning') {
                      $err_bg = 'rgba(245,158,11,0.08)'; // Amber
                      $err_text = '#d97706';
                      $err_border = 'rgba(245,158,11,0.2)';
                  }
                ?>
                <div class="mx-8 mb-4 p-3 rounded-xl flex items-start gap-2.5 text-sm font-medium" style="background:<?= $err_bg ?>;color:<?= $err_text ?>;border:1px solid <?= $err_border ?>;">
                    <?php if ($err_type === 'warning'): ?>
                        <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    <?php else: ?>
                        <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <?php endif; ?>
                    <div class="flex-1"><?= $login_error ?></div>
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
                        <input type="email" name="email" required class="w-full pl-10 pr-4 py-2.5 rounded-xl border-2 border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white text-sm focus:border-indigo-500 focus:ring-0 focus:outline-none transition-colors" placeholder="you@example.com" value="<?= e($_POST['email'] ?? '') ?>">
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
                    <a href="<?= e(base_url('auth/register.php')) ?>" class="font-semibold text-indigo-600 hover:text-indigo-500 transition-colors">Register</a>
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
