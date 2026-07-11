<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

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
    SELECT j.id, j.company_id, j.title, j.budget, j.created_at, j.description, c.company_name, c.logo_image,
           (SELECT GROUP_CONCAT(s.skill_name SEPARATOR ', ') FROM job_applications ja JOIN freelancers f ON ja.freelancer_id = f.id JOIN freelancer_skills fs ON fs.freelancer_id = f.id JOIN skills s ON fs.skill_id = s.id WHERE ja.job_id = j.id GROUP BY ja.job_id LIMIT 3) AS applied_skills
    FROM jobs j
    JOIN companies c ON j.company_id = c.id
    WHERE j.status = 'approved'
    ORDER BY j.created_at DESC
    LIMIT 6
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

// Fetch top freelancers
$top_freelancers = [];
$r = $conn->query("
    SELECT f.id, f.full_name, f.title, f.hourly_rate, f.experience_years, u.profile_image,
           (SELECT COUNT(*) FROM job_applications WHERE freelancer_id = f.id AND status = 'accepted') AS completed_projects,
           (SELECT COUNT(*) FROM freelancer_skills WHERE freelancer_id = f.id) AS skill_count
    FROM freelancers f
    JOIN users u ON f.user_id = u.id
    ORDER BY completed_projects DESC, f.experience_years DESC
    LIMIT 4
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

        /* Premium hero */
        .hero-premium {
            background: linear-gradient(135deg, #1e1b4b 0%, #312e81 25%, #4338ca 50%, #6366f1 75%, #7c3aed 100%);
            position: relative;
            overflow: hidden;
        }

        .hero-premium::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(ellipse at 30% 50%, rgba(139, 92, 246, 0.3) 0%, transparent 50%),
                radial-gradient(ellipse at 70% 20%, rgba(59, 130, 246, 0.2) 0%, transparent 50%),
                radial-gradient(ellipse at 50% 80%, rgba(236, 72, 153, 0.15) 0%, transparent 50%);
            animation: heroGlow 10s ease-in-out infinite alternate;
        }

        @keyframes heroGlow {
            0% {
                transform: translate(0, 0) scale(1);
            }

            100% {
                transform: translate(30px, -20px) scale(1.05);
            }
        }

        /* Floating cards */
        .floating-card {
            animation: floatCard 6s ease-in-out infinite;
        }

        .floating-card:nth-child(2) {
            animation-delay: -1.5s;
        }

        .floating-card:nth-child(3) {
            animation-delay: -3s;
        }

        .floating-card:nth-child(4) {
            animation-delay: -4.5s;
        }

        @keyframes floatCard {

            0%,
            100% {
                transform: translateY(0px);
            }

            50% {
                transform: translateY(-12px);
            }
        }

        /* Gradient text */
        .gradient-text {
            background: linear-gradient(135deg, #6366f1, #a855f7, #ec4899);
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

        /* Premium nav link */
        .nav-link {
            position: relative;
            transition: color 0.2s ease;
        }

        .nav-link::after {
            content: '';
            position: absolute;
            bottom: -4px;
            left: 50%;
            width: 0;
            height: 2px;
            background: linear-gradient(90deg, #6366f1, #a855f7);
            border-radius: 1px;
            transition: all 0.3s ease;
            transform: translateX(-50%);
        }

        .nav-link:hover::after {
            width: 100%;
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

    <!-- ===== STICKY NAVIGATION ===== -->
    <nav class="fixed top-0 left-0 right-0 z-50 navbar-blur border-b border-white/20 dark:border-white/5" id="main-nav">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-20">
                <!-- Logo -->
                <a href="<?= e(base_url('index.php')) ?>" class="flex items-center gap-2 group">
                    <div class="w-10 h-10 bg-gradient-to-br from-primary-500 to-accent-500 rounded-xl flex items-center justify-center shadow-lg shadow-primary-500/30 group-hover:shadow-primary-500/50 transition-shadow">
                        <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <span class="text-xl font-bold bg-gradient-to-r from-primary-600 to-accent-600 bg-clip-text text-transparent">HireWork</span>
                </a>

                <!-- Center Nav -->
                <div class="hidden lg:flex items-center gap-1">
                    <a href="<?= e(base_url('index.php')) ?>" class="nav-link px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:text-primary-600 dark:hover:text-primary-400 rounded-lg">Home</a>
                    <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="nav-link px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:text-primary-600 dark:hover:text-primary-400 rounded-lg">Find Jobs</a>
                    <a href="<?= e(base_url('register.php')) ?>" class="nav-link px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:text-primary-600 dark:hover:text-primary-400 rounded-lg">Freelancers</a>
                    <a href="#categories" class="nav-link px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:text-primary-600 dark:hover:text-primary-400 rounded-lg">Categories</a>
                    <a href="#why-us" class="nav-link px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:text-primary-600 dark:hover:text-primary-400 rounded-lg">About</a>
                    <a href="#testimonials" class="nav-link px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:text-primary-600 dark:hover:text-primary-400 rounded-lg">Contact</a>
                </div>

                <!-- Right: Auth -->
                <div class="flex items-center gap-3">
                    <a href="<?= e(base_url('login.php')) ?>" class="hidden sm:inline-flex items-center px-5 py-2.5 text-sm font-semibold text-primary-600 dark:text-primary-400 border-2 border-primary-200 dark:border-primary-800 rounded-xl hover:bg-primary-50 dark:hover:bg-primary-900/30 transition-all">Login</a>
                    <a href="<?= e(base_url('register.php')) ?>" class="btn-gradient inline-flex items-center px-5 py-2.5 text-sm font-semibold text-white rounded-xl shadow-lg shadow-primary-500/25">Register</a>

                    <!-- Mobile menu toggle -->
                    <button id="mobile-toggle" class="lg:hidden p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
                        <svg class="w-6 h-6 text-gray-700 dark:text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- Mobile Menu -->
        <div id="mobile-menu" class="hidden lg:hidden border-t border-gray-100 dark:border-gray-800">
            <div class="px-4 py-4 space-y-2">
                <a href="<?= e(base_url('index.php')) ?>" class="block px-4 py-3 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-primary-50 dark:hover:bg-primary-900/20 rounded-xl transition-colors">Home</a>
                <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="block px-4 py-3 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-primary-50 dark:hover:bg-primary-900/20 rounded-xl transition-colors">Find Jobs</a>
                <a href="<?= e(base_url('register.php')) ?>" class="block px-4 py-3 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-primary-50 dark:hover:bg-primary-900/20 rounded-xl transition-colors">Freelancers</a>
                <a href="#categories" class="block px-4 py-3 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-primary-50 dark:hover:bg-primary-900/20 rounded-xl transition-colors">Categories</a>
                <a href="#why-us" class="block px-4 py-3 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-primary-50 dark:hover:bg-primary-900/20 rounded-xl transition-colors">About</a>
                <a href="#testimonials" class="block px-4 py-3 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-primary-50 dark:hover:bg-primary-900/20 rounded-xl transition-colors">Contact</a>
            </div>
        </div>
    </nav>

    <!-- ===== HERO SECTION ===== -->
    <section class="hero-premium min-h-screen flex items-center pt-24 pb-16 relative">
        <!-- Animated particles -->
        <div class="absolute inset-0 overflow-hidden pointer-events-none">
            <div class="absolute top-1/4 left-1/4 w-72 h-72 bg-purple-400/10 rounded-full blur-3xl" style="animation: float 8s ease-in-out infinite;"></div>
            <div class="absolute bottom-1/4 right-1/4 w-96 h-96 bg-blue-400/10 rounded-full blur-3xl" style="animation: float 10s ease-in-out infinite reverse;"></div>
            <div class="absolute top-1/2 left-1/2 w-64 h-64 bg-pink-400/10 rounded-full blur-3xl" style="animation: float 12s ease-in-out infinite 2s;"></div>
        </div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10 w-full">
            <div class="grid lg:grid-cols-2 gap-12 lg:gap-16 items-center">
                <!-- Left: Content -->
                <div class="space-y-8">
                    <div class="inline-flex items-center gap-2 glass rounded-full px-5 py-2 text-sm text-white/90 reveal">
                        <span class="w-2.5 h-2.5 bg-emerald-400 rounded-full animate-pulse"></span>
                        Trusted by <?= number_format($stats['freelancers'] + $stats['companies']) ?>+ professionals
                    </div>

                    <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold text-white leading-tight reveal reveal-d1">
                        Find Top Talent or
                        <span class="block mt-2 bg-gradient-to-r from-yellow-300 via-amber-300 to-orange-300 bg-clip-text text-transparent">Land Your Dream Job</span>
                    </h1>

                    <p class="text-lg text-indigo-100/80 max-w-lg leading-relaxed reveal reveal-d2">
                        The modern platform connecting companies with skilled freelancers. Post jobs, hire talent, and manage projects seamlessly.
                    </p>

                    <!-- Search Box -->
                    <div class="glass rounded-2xl p-2 max-w-2xl reveal reveal-d3">
                        <div class="flex flex-col sm:flex-row items-stretch gap-2">
                            <div class="flex-1 flex items-center gap-3 px-4">
                                <svg class="w-5 h-5 text-white/50 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                                <input type="text" id="hero-search" placeholder="Search for jobs, skills, or companies..." class="w-full py-3.5 bg-transparent text-white placeholder-white/50 focus:outline-none text-sm">
                            </div>
                            <button onclick="heroSearch()" class="btn-gradient px-8 py-3.5 rounded-xl font-semibold text-white text-sm whitespace-nowrap shadow-lg shadow-primary-500/30">
                                Search Jobs
                            </button>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex flex-wrap gap-4 reveal reveal-d4">
                        <a href="<?= e(base_url('register.php')) ?>" class="btn-gradient px-8 py-3.5 rounded-xl font-semibold text-white text-sm shadow-lg shadow-primary-500/25">
                            <svg class="w-5 h-5 inline mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                            Hire Freelancer
                        </a>
                        <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="glass px-8 py-3.5 rounded-xl font-semibold text-white text-sm hover:bg-white/20 transition-all">
                            <svg class="w-5 h-5 inline mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                            Find Work
                        </a>
                    </div>

                    <!-- Quick Tags -->
                    <div class="flex flex-wrap gap-2 reveal reveal-d5">
                        <span class="glass text-white/70 text-xs px-4 py-1.5 rounded-full hover:bg-white/20 transition-colors cursor-pointer">PHP</span>
                        <span class="glass text-white/70 text-xs px-4 py-1.5 rounded-full hover:bg-white/20 transition-colors cursor-pointer">JavaScript</span>
                        <span class="glass text-white/70 text-xs px-4 py-1.5 rounded-full hover:bg-white/20 transition-colors cursor-pointer">React.js</span>
                        <span class="glass text-white/70 text-xs px-4 py-1.5 rounded-full hover:bg-white/20 transition-colors cursor-pointer">UI/UX Design</span>
                        <span class="glass text-white/70 text-xs px-4 py-1.5 rounded-full hover:bg-white/20 transition-colors cursor-pointer">Python</span>
                        <span class="glass text-white/70 text-xs px-4 py-1.5 rounded-full hover:bg-white/20 transition-colors cursor-pointer">Content Writing</span>
                    </div>
                </div>

                <!-- Right: Floating Cards -->
                <div class="relative hidden lg:block h-[500px]">
                    <!-- Main illustration placeholder -->
                    <div class="absolute inset-0 flex items-center justify-center">
                        <div class="w-80 h-80 bg-gradient-to-br from-white/10 to-white/5 rounded-full flex items-center justify-center">
                            <svg class="w-40 h-40 text-white/20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="0.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                        </div>
                    </div>

                    <!-- Floating Card: Active Jobs -->
                    <div class="floating-card glass-card absolute top-8 right-0 px-5 py-4 rounded-2xl z-10">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-gradient-to-br from-emerald-400 to-emerald-600 rounded-xl flex items-center justify-center shadow-lg shadow-emerald-500/30">
                                <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-2xl font-bold text-gray-900 dark:text-white counter-val" data-target="<?= $stats['jobs'] ?>">0</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Active Jobs</p>
                            </div>
                        </div>
                    </div>

                    <!-- Floating Card: Top Freelancer -->
                    <div class="floating-card glass-card absolute top-32 left-4 px-5 py-4 rounded-2xl z-20">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-gradient-to-br from-amber-400 to-orange-500 rounded-xl flex items-center justify-center shadow-lg shadow-amber-500/30">
                                <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.538 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-bold text-gray-900 dark:text-white">Top Rated</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Freelancer</p>
                            </div>
                        </div>
                    </div>

                    <!-- Floating Card: Secure Payment -->
                    <div class="floating-card glass-card absolute bottom-32 right-4 px-5 py-4 rounded-2xl z-10">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-gradient-to-br from-blue-400 to-blue-600 rounded-xl flex items-center justify-center shadow-lg shadow-blue-500/30">
                                <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-bold text-gray-900 dark:text-white">100% Secure</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Payment</p>
                            </div>
                        </div>
                    </div>

                    <!-- Floating Card: 5-Star Reviews -->
                    <div class="floating-card glass-card absolute bottom-16 left-8 px-5 py-4 rounded-2xl z-20">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-gradient-to-br from-pink-400 to-rose-500 rounded-xl flex items-center justify-center shadow-lg shadow-pink-500/30">
                                <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.538 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-bold text-gray-900 dark:text-white">5.0 Rating</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">2.4k Reviews</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Scroll indicator -->
        <div class="absolute bottom-8 left-1/2 -translate-x-1/2 flex flex-col items-center gap-2 text-white/40">
            <span class="text-xs tracking-widest uppercase">Scroll</span>
            <div class="w-5 h-8 border-2 border-white/30 rounded-full flex justify-center pt-1.5">
                <div class="w-1 h-2 bg-white/60 rounded-full animate-bounce"></div>
            </div>
        </div>
    </section>

    <!-- ===== STATISTICS SECTION ===== -->
    <section class="py-20 -mt-20 relative z-10">
        <div class="max-w-6xl mx-auto px-4">
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-6">
                <?php
                $stat_items = [
                    ['label' => 'Freelancers', 'value' => $stats['freelancers'], 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>', 'color' => 'from-blue-500 to-indigo-600', 'shadow' => 'shadow-blue-500/20'],
                    ['label' => 'Companies', 'value' => $stats['companies'], 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>', 'color' => 'from-purple-500 to-accent-600', 'shadow' => 'shadow-purple-500/20'],
                    ['label' => 'Open Jobs', 'value' => $stats['jobs'], 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>', 'color' => 'from-emerald-500 to-teal-600', 'shadow' => 'shadow-emerald-500/20'],
                    ['label' => 'Completed', 'value' => $stats['completed'], 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>', 'color' => 'from-amber-500 to-orange-600', 'shadow' => 'shadow-amber-500/20'],
                ];
                foreach ($stat_items as $i => $s):
                ?>
                    <div class="glass-card rounded-2xl p-6 text-center lift-hover reveal reveal-d<?= $i + 1 ?>">
                        <div class="stat-icon-wrap inline-flex mb-4">
                            <div class="w-14 h-14 bg-gradient-to-br <?= $s['color'] ?> rounded-2xl flex items-center justify-center shadow-lg <?= $s['shadow'] ?>">
                                <svg class="w-7 h-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><?= $s['icon'] ?></svg>
                            </div>
                            <div class="absolute inset-0 bg-gradient-to-br <?= $s['color'] ?> rounded-2xl" style="filter:blur(12px);opacity:0.3;"></div>
                        </div>
                        <p class="text-3xl font-extrabold text-gray-900 dark:text-white counter-val" data-target="<?= $s['value'] ?>">0</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 font-medium"><?= $s['label'] ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ===== POPULAR CATEGORIES ===== -->
    <section id="categories" class="py-24 bg-white/50 dark:bg-slate-800/30">
        <div class="max-w-7xl mx-auto px-4">
            <div class="text-center mb-16 reveal">
                <span class="inline-flex items-center gap-2 text-primary-600 font-semibold text-sm uppercase tracking-widest mb-4">
                    <span class="w-8 h-0.5 bg-gradient-to-r from-primary-500 to-accent-500 rounded-full"></span>
                    Categories
                </span>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-gray-900 dark:text-white">Explore Top Skills</h2>
                <p class="mt-4 text-gray-500 dark:text-gray-400 max-w-xl mx-auto">Find talent across the most in-demand technical and creative skills</p>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-5">
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
                    $cnt_r = $conn->query("SELECT COUNT(*) AS cnt FROM jobs j JOIN companies c ON j.company_id = c.id JOIN freelancer_skills fs ON fs.freelancer_id IN (SELECT id FROM freelancers WHERE user_id = c.user_id) JOIN skills s ON fs.skill_id = s.id WHERE s.skill_name = '" . $conn->real_escape_string($skill['skill_name']) . "' AND j.status = 'approved'");
                    $job_cnt = $cnt_r ? (int) $cnt_r->fetch_assoc()['cnt'] : 0;
                ?>
                    <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="group relative overflow-hidden rounded-2xl p-6 bg-gradient-to-br <?= $cd['color'] ?> text-white text-center lift-hover reveal reveal-d<?= ($shown % 5) + 1 ?>">
                        <div class="w-14 h-14 bg-white/20 backdrop-blur-sm rounded-2xl flex items-center justify-center mx-auto mb-4 group-hover:scale-110 group-hover:rotate-3 transition-all duration-300">
                            <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><?= $cd['icon'] ?></svg>
                        </div>
                        <h3 class="font-bold text-lg mb-1"><?= e($skill['skill_name']) ?></h3>
                        <p class="text-white/70 text-sm"><?= $job_cnt ?>+ open jobs</p>
                        <div class="absolute inset-0 bg-gradient-to-t from-black/20 to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ===== FEATURED JOBS ===== -->
    <section class="py-24">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex flex-wrap items-end justify-between mb-14 reveal">
                <div>
                    <span class="inline-flex items-center gap-2 text-primary-600 font-semibold text-sm uppercase tracking-widest mb-4">
                        <span class="w-8 h-0.5 bg-gradient-to-r from-primary-500 to-accent-500 rounded-full"></span>
                        Latest Opportunities
                    </span>
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
                <div class="text-center py-16 glass-card rounded-3xl">
                    <svg class="w-20 h-20 mx-auto mb-6 text-gray-300 dark:text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                    <p class="text-gray-500 dark:text-gray-400 text-lg">No jobs available yet. Check back soon!</p>
                </div>
            <?php else: ?>
                <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-7">
                    <?php foreach ($latest_jobs as $i => $job): ?>
                        <div class="glass-card rounded-2xl p-7 lift-hover reveal reveal-d<?= ($i % 3) + 1 ?> cursor-pointer group" onclick="window.location='<?= e(base_url('freelancer/browse_jobs.php')) ?>'">
                            <!-- Header -->
                            <div class="flex items-start justify-between mb-5">
                                <div class="flex items-center gap-3">
                                    <?php if (!empty($job['logo_image'])): ?>
                                        <img src="<?= e(base_url('uploads/' . $job['logo_image'])) ?>" alt="" class="w-12 h-12 rounded-xl object-cover border-2 border-white dark:border-gray-700 shadow-md">
                                    <?php else: ?>
                                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-primary-100 to-accent-100 dark:from-primary-900/40 dark:to-accent-900/40 flex items-center justify-center text-primary-600 dark:text-primary-400 font-bold text-lg shadow-md">
                                            <?= e(_first_char($job['company_name'] ?? 'C')) ?>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <p class="font-semibold text-sm text-gray-900 dark:text-white"><?= e($job['company_name'] ?? 'Company') ?></p>
                                        <p class="text-xs text-gray-400 dark:text-gray-500">Posted recently</p>
                                    </div>
                                </div>
                                <div class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-700 flex items-center justify-center text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors cursor-pointer" onclick="event.stopPropagation()">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
                                    </svg>
                                </div>
                            </div>

                            <!-- Title & Description -->
                            <h3 class="font-bold text-lg text-gray-900 dark:text-white mb-2 group-hover:text-primary-600 dark:group-hover:text-primary-400 transition-colors leading-snug"><?= e($job['title']) ?></h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-5 line-clamp-2 leading-relaxed"><?= e(mb_strimwidth($job['description'] ?? '', 0, 120, '...')) ?></p>

                            <!-- Skills -->
                            <?php if (!empty($job['applied_skills'])): ?>
                                <div class="flex flex-wrap gap-2 mb-5">
                                    <?php $skills_arr = array_slice(explode(', ', $job['applied_skills']), 0, 3); ?>
                                    <?php foreach ($skills_arr as $skill_tag): ?>
                                        <span class="skill-tag inline-flex items-center px-3 py-1 rounded-lg text-xs font-medium bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300 border border-primary-100 dark:border-primary-800/50"><?= e($skill_tag) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Footer -->
                            <div class="flex items-center justify-between pt-5 border-t border-gray-100 dark:border-gray-700/50">
                                <div class="flex items-center gap-2">
                                    <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-emerald-400 to-emerald-600 flex items-center justify-center shadow-sm">
                                        <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1" />
                                        </svg>
                                    </div>
                                    <span class="text-lg font-bold text-gray-900 dark:text-white">$<?= e(number_format((float) $job['budget'], 0)) ?></span>
                                </div>
                                <span class="inline-flex items-center px-3 py-1.5 rounded-lg text-xs font-semibold bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800/50">
                                    <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full mr-1.5 animate-pulse"></span>
                                    Open
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- ===== TOP FREELANCERS ===== -->
    <section class="py-24 bg-gradient-to-b from-white/50 to-indigo-50/30 dark:from-slate-800/30 dark:to-slate-900/50">
        <div class="max-w-7xl mx-auto px-4">
            <div class="text-center mb-16 reveal">
                <span class="inline-flex items-center gap-2 text-primary-600 font-semibold text-sm uppercase tracking-widest mb-4">
                    <span class="w-8 h-0.5 bg-gradient-to-r from-primary-500 to-accent-500 rounded-full"></span>
                    Top Talent
                </span>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-gray-900 dark:text-white">Meet Our Top Freelancers</h2>
                <p class="mt-4 text-gray-500 dark:text-gray-400 max-w-xl mx-auto">Work with the best professionals in the industry</p>
            </div>

            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-7">
                <?php foreach ($top_freelancers as $i => $fl): ?>
                    <div class="glass-card rounded-2xl p-7 text-center lift-hover reveal reveal-d<?= ($i % 4) + 1 ?>">
                        <!-- Avatar -->
                        <div class="avatar-ring inline-block mb-5">
                            <div class="w-24 h-24 rounded-full overflow-hidden">
                                <?php if (!empty($fl['profile_image'])): ?>
                                    <img src="<?= e(base_url('uploads/' . $fl['profile_image'])) ?>" alt="" class="w-full h-full object-cover">
                                <?php else: ?>
                                    <div class="w-full h-full bg-gradient-to-br from-primary-100 to-accent-100 dark:from-primary-800/40 dark:to-accent-800/40 flex items-center justify-center text-primary-600 dark:text-primary-300 font-bold text-2xl">
                                        <?= e(_first_char($fl['full_name'] ?? 'F')) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Info -->
                        <h3 class="font-bold text-lg text-gray-900 dark:text-white mb-1"><?= e($fl['full_name']) ?></h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-3"><?= e($fl['title'] ?? 'Freelancer') ?></p>

                        <!-- Rating -->
                        <div class="flex items-center justify-center gap-1 mb-4">
                            <?php for ($s = 0; $s < 5; $s++): ?>
                                <svg class="w-4 h-4 text-amber-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.538 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                                </svg>
                            <?php endfor; ?>
                            <span class="text-sm font-semibold text-gray-700 dark:text-gray-300 ml-1">5.0</span>
                        </div>

                        <!-- Stats -->
                        <div class="flex items-center justify-center gap-4 mb-5">
                            <div class="text-center">
                                <p class="text-lg font-bold text-gray-900 dark:text-white"><?= (int) $fl['completed_projects'] ?></p>
                                <p class="text-xs text-gray-400 dark:text-gray-500">Projects</p>
                            </div>
                            <div class="w-px h-8 bg-gray-200 dark:bg-gray-700"></div>
                            <div class="text-center">
                                <p class="text-lg font-bold text-gray-900 dark:text-white"><?= (int) $fl['experience_years'] ?>y</p>
                                <p class="text-xs text-gray-400 dark:text-gray-500">Experience</p>
                            </div>
                        </div>

                        <!-- Rate & CTA -->
                        <div class="flex items-center justify-between pt-5 border-t border-gray-100 dark:border-gray-700/50">
                            <div>
                                <p class="text-xs text-gray-400 dark:text-gray-500">Hourly Rate</p>
                                <p class="text-lg font-bold text-primary-600 dark:text-primary-400">$<?= e(number_format((float) ($fl['hourly_rate'] ?? 0), 0)) ?></p>
                            </div>
                            <a href="<?= e(base_url('register.php')) ?>" class="btn-gradient px-5 py-2 rounded-xl text-sm font-semibold text-white shadow-md shadow-primary-500/20">
                                Hire Now
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ===== WHY CHOOSE US ===== -->
    <section id="why-us" class="py-24">
        <div class="max-w-7xl mx-auto px-4">
            <div class="text-center mb-16 reveal">
                <span class="inline-flex items-center gap-2 text-primary-600 font-semibold text-sm uppercase tracking-widest mb-4">
                    <span class="w-8 h-0.5 bg-gradient-to-r from-primary-500 to-accent-500 rounded-full"></span>
                    Why Choose Us
                </span>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-gray-900 dark:text-white">Built for Modern Teams</h2>
                <p class="mt-4 text-gray-500 dark:text-gray-400 max-w-xl mx-auto">Everything you need to hire, work, and get paid — in one powerful platform</p>
            </div>

            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-7">
                <?php
                $why_features = [
                    ['icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>', 'title' => 'Secure & Trusted', 'desc' => 'All accounts verified with industry-standard encryption and secure authentication.', 'color' => 'from-blue-500 to-indigo-600', 'shadow' => 'shadow-blue-500/20'],
                    ['icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>', 'title' => 'Fast & Efficient', 'desc' => 'Post jobs and find talent in minutes. Our streamlined process gets you working faster.', 'color' => 'from-amber-500 to-orange-600', 'shadow' => 'shadow-amber-500/20'],
                    ['icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>', 'title' => 'Secure Payments', 'desc' => 'Escrow-based system ensures freelancers get paid and companies get quality work.', 'color' => 'from-emerald-500 to-teal-600', 'shadow' => 'shadow-emerald-500/20'],
                    ['icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>', 'title' => 'Built-in Messaging', 'desc' => 'Communicate directly with your team. No need for external tools.', 'color' => 'from-pink-500 to-rose-600', 'shadow' => 'shadow-pink-500/20'],
                ];
                foreach ($why_features as $i => $f):
                ?>
                    <div class="glass-card rounded-2xl p-7 lift-hover reveal reveal-d<?= ($i % 4) + 1 ?>">
                        <div class="w-14 h-14 bg-gradient-to-br <?= $f['color'] ?> rounded-2xl flex items-center justify-center mb-5 shadow-lg <?= $f['shadow'] ?>">
                            <svg class="w-7 h-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><?= $f['icon'] ?></svg>
                        </div>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-3"><?= e($f['title']) ?></h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed"><?= e($f['desc']) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ===== HOW IT WORKS ===== -->
    <section class="py-24 bg-gradient-to-b from-indigo-50/30 to-white/50 dark:from-slate-900/50 dark:to-slate-800/30">
        <div class="max-w-7xl mx-auto px-4">
            <div class="text-center mb-16 reveal">
                <span class="inline-flex items-center gap-2 text-primary-600 font-semibold text-sm uppercase tracking-widest mb-4">
                    <span class="w-8 h-0.5 bg-gradient-to-r from-primary-500 to-accent-500 rounded-full"></span>
                    How It Works
                </span>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-gray-900 dark:text-white">Get Started in 5 Simple Steps</h2>
                <p class="mt-4 text-gray-500 dark:text-gray-400 max-w-xl mx-auto">Whether you're hiring or looking for work, our platform makes it effortless</p>
            </div>

            <div class="grid sm:grid-cols-2 lg:grid-cols-5 gap-7">
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
                    <div class="text-center reveal reveal-d<?= ($i % 5) + 1 ?>">
                        <div class="relative inline-block mb-6">
                            <div class="w-16 h-16 bg-gradient-to-br <?= $step['color'] ?> rounded-2xl flex items-center justify-center mx-auto shadow-lg shadow-<?= explode(' ', $step['color'])[0] ?>-500/20 relative z-10">
                                <svg class="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><?= $step['icon'] ?></svg>
                            </div>
                            <span class="absolute -top-2 -right-2 w-7 h-7 bg-white dark:bg-gray-800 rounded-full flex items-center justify-center text-xs font-bold text-primary-600 dark:text-primary-400 shadow-md border border-primary-100 dark:border-primary-800 z-20"><?= $step['num'] ?></span>
                        </div>
                        <h3 class="font-bold text-gray-900 dark:text-white mb-2"><?= e($step['title']) ?></h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed"><?= e($step['desc']) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ===== TESTIMONIALS ===== -->
    <section id="testimonials" class="py-24">
        <div class="max-w-7xl mx-auto px-4">
            <div class="text-center mb-16 reveal">
                <span class="inline-flex items-center gap-2 text-primary-600 font-semibold text-sm uppercase tracking-widest mb-4">
                    <span class="w-8 h-0.5 bg-gradient-to-r from-primary-500 to-accent-500 rounded-full"></span>
                    Testimonials
                </span>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-gray-900 dark:text-white">Loved by Thousands</h2>
                <p class="mt-4 text-gray-500 dark:text-gray-400 max-w-xl mx-auto">See what our community has to say about working with HireWork</p>
            </div>

            <div class="grid md:grid-cols-3 gap-7">
                <?php
                $testimonials = [
                    ['name' => 'Sarah Chen', 'role' => 'Startup Founder', 'text' => 'HireWork helped us find an amazing developer in just 2 days. The platform is intuitive and the payment system gives us total peace of mind.', 'color' => 'from-blue-400 to-indigo-500'],
                    ['name' => 'David Park', 'role' => 'Full-Stack Developer', 'text' => "I've earned over $15,000 through this platform. The job matching is great and I love the built-in messaging feature for client communication.", 'color' => 'from-purple-400 to-accent-500'],
                    ['name' => 'Emily Rodriguez', 'role' => 'Marketing Agency', 'text' => "We've hired 12 freelancers through HireWork. The quality of talent is outstanding and the admin team keeps everything running smoothly.", 'color' => 'from-emerald-400 to-teal-500'],
                ];
                foreach ($testimonials as $i => $t):
                ?>
                    <div class="glass-card rounded-2xl p-8 lift-hover reveal reveal-d<?= ($i % 3) + 1 ?>">
                        <!-- Stars -->
                        <div class="flex items-center gap-1 mb-5">
                            <?php for ($s = 0; $s < 5; $s++): ?>
                                <svg class="w-5 h-5 text-amber-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.538 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                                </svg>
                            <?php endfor; ?>
                        </div>

                        <!-- Quote -->
                        <p class="text-gray-600 dark:text-gray-300 mb-7 leading-relaxed text-sm italic">"<?= e($t['text']) ?>"</p>

                        <!-- User -->
                        <div class="flex items-center gap-3 pt-5 border-t border-gray-100 dark:border-gray-700/50">
                            <div class="w-12 h-12 rounded-full bg-gradient-to-br <?= $t['color'] ?> flex items-center justify-center text-white font-bold text-lg shadow-lg">
                                <?= e(_first_char($t['name'])) ?>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-900 dark:text-white text-sm"><?= e($t['name']) ?></p>
                                <p class="text-xs text-gray-400 dark:text-gray-500"><?= e($t['role']) ?></p>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ===== CTA BANNER ===== -->
    <section class="py-24">
        <div class="max-w-7xl mx-auto px-4">
            <div class="cta-glow relative overflow-hidden rounded-3xl bg-gradient-to-r from-primary-600 via-accent-600 to-primary-700 p-12 md:p-16 lg:p-20 text-center reveal">
                <!-- Decorative elements -->
                <div class="absolute top-0 left-0 w-full h-full overflow-hidden pointer-events-none">
                    <div class="absolute -top-24 -right-24 w-64 h-64 bg-white/5 rounded-full blur-2xl"></div>
                    <div class="absolute -bottom-24 -left-24 w-80 h-80 bg-white/5 rounded-full blur-2xl"></div>
                    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-96 h-96 bg-white/5 rounded-full blur-3xl"></div>
                </div>

                <div class="relative z-10">
                    <h2 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-white mb-6">Ready to Start Your<br class="hidden sm:block"> Freelance Journey?</h2>
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
        <div class="max-w-7xl mx-auto px-4">
            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-12 mb-16">
                <!-- Brand -->
                <div class="sm:col-span-2 lg:col-span-1">
                    <a href="<?= e(base_url('index.php')) ?>" class="inline-flex items-center gap-2 mb-5">
                        <div class="w-10 h-10 bg-gradient-to-br from-primary-500 to-accent-500 rounded-xl flex items-center justify-center">
                            <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <span class="text-xl font-bold text-white">HireWork</span>
                    </a>
                    <p class="text-gray-400 text-sm leading-relaxed mb-6">The modern platform connecting companies with skilled freelancers worldwide.</p>
                    <div class="flex gap-3">
                        <a href="#" class="w-10 h-10 rounded-xl bg-gray-800 hover:bg-primary-600 flex items-center justify-center text-gray-400 hover:text-white transition-all">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M24 4.557c-.883.392-1.832.656-2.828.775 1.017-.609 1.798-1.574 2.165-2.724-.951.564-2.005.974-3.127 1.195-.897-.957-2.178-1.555-3.594-1.555-3.179 0-5.515 2.966-4.797 6.045-4.091-.205-7.719-2.165-10.148-5.144-1.29 2.213-.669 5.108 1.523 6.574-.806-.026-1.566-.247-2.229-.616-.054 2.281 1.581 4.415 3.949 4.89-.693.188-1.452.232-2.224.084.626 1.956 2.444 3.379 4.6 3.419-2.07 1.623-4.678 2.348-7.29 2.04 2.179 1.397 4.768 2.212 7.548 2.212 9.142 0 14.307-7.721 13.995-14.646.962-.695 1.797-1.562 2.457-2.549z" />
                            </svg>
                        </a>
                        <a href="#" class="w-10 h-10 rounded-xl bg-gray-800 hover:bg-primary-600 flex items-center justify-center text-gray-400 hover:text-white transition-all">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z" />
                            </svg>
                        </a>
                        <a href="#" class="w-10 h-10 rounded-xl bg-gray-800 hover:bg-primary-600 flex items-center justify-center text-gray-400 hover:text-white transition-all">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z" />
                            </svg>
                        </a>
                    </div>
                </div>

                <!-- For Companies -->
                <div>
                    <h4 class="text-white font-bold mb-5">For Companies</h4>
                    <ul class="space-y-3">
                        <li><a href="<?= e(base_url('register.php')) ?>" class="text-gray-400 hover:text-primary-400 text-sm transition-colors">Post a Job</a></li>
                        <li><a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="text-gray-400 hover:text-primary-400 text-sm transition-colors">Browse Freelancers</a></li>
                        <li><a href="<?= e(base_url('login.php')) ?>" class="text-gray-400 hover:text-primary-400 text-sm transition-colors">Login</a></li>
                    </ul>
                </div>

                <!-- For Freelancers -->
                <div>
                    <h4 class="text-white font-bold mb-5">For Freelancers</h4>
                    <ul class="space-y-3">
                        <li><a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="text-gray-400 hover:text-primary-400 text-sm transition-colors">Find Work</a></li>
                        <li><a href="<?= e(base_url('register.php')) ?>" class="text-gray-400 hover:text-primary-400 text-sm transition-colors">Create Profile</a></li>
                        <li><a href="<?= e(base_url('login.php')) ?>" class="text-gray-400 hover:text-primary-400 text-sm transition-colors">Login</a></li>
                    </ul>
                </div>

                <!-- Contact -->
                <div>
                    <h4 class="text-white font-bold mb-5">Contact</h4>
                    <ul class="space-y-3">
                        <li class="flex items-center gap-3 text-gray-400 text-sm">
                            <svg class="w-5 h-5 text-primary-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                            support@hirework.com
                        </li>
                        <li class="flex items-center gap-3 text-gray-400 text-sm">
                            <svg class="w-5 h-5 text-primary-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                            </svg>
                            +1 (555) 123-4567
                        </li>
                        <li class="flex items-center gap-3 text-gray-400 text-sm">
                            <svg class="w-5 h-5 text-primary-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                            San Francisco, CA
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Bottom -->
            <div class="pt-8 border-t border-gray-800 flex flex-col sm:flex-row items-center justify-between gap-4">
                <p class="text-gray-500 text-sm">&copy; <?= date('Y') ?> HireWork. All rights reserved.</p>
                <div class="flex items-center gap-6">
                    <a href="#" class="text-gray-500 hover:text-primary-400 text-sm transition-colors">Privacy Policy</a>
                    <a href="#" class="text-gray-500 hover:text-primary-400 text-sm transition-colors">Terms of Service</a>
                    <a href="#" class="text-gray-500 hover:text-primary-400 text-sm transition-colors">Help Center</a>
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

    <!-- ===== DARK MODE TOGGLE (Fixed) ===== -->
    <button id="theme-toggle" class="fixed bottom-8 left-8 w-12 h-12 bg-white dark:bg-gray-800 rounded-2xl shadow-lg shadow-gray-200/50 dark:shadow-black/30 flex items-center justify-center text-gray-600 dark:text-gray-300 hover:shadow-xl hover:-translate-y-1 transition-all z-50 border border-gray-100 dark:border-gray-700" aria-label="Toggle theme">
        <svg class="w-5 h-5 dark:hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
        </svg>
        <svg class="w-5 h-5 hidden dark:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
        </svg>
    </button>

    <script>
        (function() {
            // Theme toggle
            var themeToggle = document.getElementById('theme-toggle');
            var html = document.documentElement;
            if (themeToggle) {
                themeToggle.addEventListener('click', function() {
                    var isDark = html.classList.toggle('dark');
                    localStorage.setItem('theme', isDark ? 'dark' : 'light');
                });
            }

            // Mobile menu
            var mobileToggle = document.getElementById('mobile-toggle');
            var mobileMenu = document.getElementById('mobile-menu');
            if (mobileToggle && mobileMenu) {
                mobileToggle.addEventListener('click', function() {
                    mobileMenu.classList.toggle('hidden');
                });
            }

            // Sticky navbar scroll effect
            var nav = document.getElementById('main-nav');
            window.addEventListener('scroll', function() {
                if (window.scrollY > 50) {
                    nav.classList.add('scrolled');
                } else {
                    nav.classList.remove('scrolled');
                }
            });

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
                if (window.scrollY > 500) {
                    backToTop.classList.add('show');
                } else {
                    backToTop.classList.remove('show');
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

            // Enter key on hero search
            var heroSearchInput = document.getElementById('hero-search');
            if (heroSearchInput) {
                heroSearchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') window.heroSearch();
                });
            }
        })();
    </script>
</body>

</html>