<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';
require_once __DIR__ . '/../config/chat.php';

require_role('company');

$user = current_user();
$company_id = get_company_id($conn, (int) $user['user_id']);

if (!$company_id) {
    set_flash('error', 'Company profile not found.');
    redirect('auth/login.php');
}

// Fetch company profile
$stmt = $conn->prepare("
    SELECT c.*, u.email, u.profile_image, u.created_at
    FROM companies c
    JOIN users u ON u.id = c.user_id
    WHERE c.id = ?
");
$stmt->bind_param('i', $company_id);
$stmt->execute();
$company = $stmt->get_result()->fetch_assoc();
$stmt->close();

// === Platform Stats ===
$total_freelancers = 0;
$total_jobs = 0;
$total_companies = 0;
try {
    $r = $conn->query('SELECT COUNT(*) AS cnt FROM freelancers');
    $total_freelancers = (int) $r->fetch_assoc()['cnt'];
    $r = $conn->query("SELECT COUNT(*) AS cnt FROM jobs WHERE status = 'approved'");
    $total_jobs = (int) $r->fetch_assoc()['cnt'];
    $r = $conn->query('SELECT COUNT(*) AS cnt FROM companies');
    $total_companies = (int) $r->fetch_assoc()['cnt'];
} catch (Exception $e) {}

// === Hero Stats ===
$freelancers_hired = 0;
$total_applications = 0;
$success_rate = 0;
try {
    $r = $conn->query("SELECT COUNT(*) AS cnt FROM assignments WHERE status = 'completed'");
    $freelancers_hired = (int) $r->fetch_assoc()['cnt'];
    $r = $conn->query('SELECT COUNT(*) AS cnt FROM job_applications');
    $total_applications = (int) $r->fetch_assoc()['cnt'];
    $r = $conn->query("SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed FROM assignments");
    $asg = $r->fetch_assoc();
    $success_rate = $asg['total'] > 0 ? round(($asg['completed'] / $asg['total']) * 100) : 0;
} catch (Exception $e) {}

// === Featured Freelancers ===
$featured_freelancers = [];
try {
    $stmt = $conn->prepare("
        SELECT f.id, f.full_name, f.title, f.hourly_rate, f.experience_years, f.location,
               u.profile_image,
               GROUP_CONCAT(DISTINCT s.skill_name ORDER BY s.skill_name SEPARATOR ', ') AS skills,
               COALESCE(AVG(r.rating), 0) AS avg_rating,
               COUNT(DISTINCT r.id) AS review_count,
               (SELECT COUNT(*) FROM assignments a JOIN jobs j ON a.job_id = j.id WHERE a.freelancer_id = f.id AND a.status = 'completed') AS completed_projects,
               (SELECT COUNT(DISTINCT j.company_id) FROM assignments a JOIN jobs j ON a.job_id = j.id WHERE a.freelancer_id = f.id AND a.status = 'completed') AS companies_worked
        FROM freelancers f
        JOIN users u ON f.user_id = u.id
        LEFT JOIN freelancer_skills fs ON fs.freelancer_id = f.id
        LEFT JOIN skills s ON fs.skill_id = s.id
        LEFT JOIN reviews r ON r.freelancer_id = f.id
        GROUP BY f.id
        ORDER BY avg_rating DESC, review_count DESC
        LIMIT 12
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $featured_freelancers[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    $featured_freelancers = [];
}

// === Latest Job Posts ===
$latest_jobs = [];
try {
    $stmt = $conn->prepare("
        SELECT j.id, j.title, j.budget, j.status, j.created_at, j.description,
               j.category, j.experience_level,
               c.company_name, c.logo_image,
               (SELECT COUNT(*) FROM job_applications ja WHERE ja.job_id = j.id) AS app_count
        FROM jobs j
        JOIN companies c ON j.company_id = c.id
        WHERE j.company_id = ?
        ORDER BY j.created_at DESC
        LIMIT 6
    ");
    $stmt->bind_param('i', $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $latest_jobs[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    $latest_jobs = [];
}

// === Client Reviews ===
$client_reviews = [];
try {
    $r = $conn->query("
        SELECT r.rating, r.comment, r.created_at,
               COALESCE(c.company_name, u.username, 'Anonymous') AS reviewer_name,
               u.profile_image AS reviewer_image,
               f.full_name AS freelancer_name
        FROM reviews r
        LEFT JOIN users u ON r.company_user_id = u.id
        LEFT JOIN companies c ON c.user_id = r.company_user_id
        LEFT JOIN freelancers f ON f.id = r.freelancer_id
        WHERE r.comment IS NOT NULL AND r.comment != ''
        ORDER BY r.created_at DESC
        LIMIT 6
    ");
    while ($row = $r->fetch_assoc()) {
        $client_reviews[] = $row;
    }
} catch (Exception $e) {
    $client_reviews = [];
}

$page_title = 'Company Home';
require __DIR__ . '/../includes/header.php';
?>

<style>
/* ── Global Variables ── */
:root {
    --c-blue: #2563eb;
    --c-blue-dark: #1d4ed8;
    --c-blue-light: #dbeafe;
    --c-blue-50: #eff6ff;
    --c-accent: #3b82f6;
}

/* ── Fade-in Animations ── */
.fade-in { opacity: 0; transform: translateY(24px); transition: opacity 0.6s ease, transform 0.6s ease; }
.fade-in.visible { opacity: 1; transform: translateY(0); }
.fade-in-d1 { transition-delay: 0.1s; }
.fade-in-d2 { transition-delay: 0.2s; }
.fade-in-d3 { transition-delay: 0.3s; }
.fade-in-d4 { transition-delay: 0.4s; }

/* ── Hero Premium ── */
.mh-hero-premium {
    position: relative; overflow: hidden;
    background: linear-gradient(135deg, #0a0e27 0%, #121a45 20%, #1e2a6e 40%, #2d3cb8 60%, #4a5de8 80%, #6366f1 100%);
    border-radius: 24px;
}
.mh-hero-premium .mh-hero-grid {
    position: absolute; inset: 0; opacity: 0.03;
    background-image:
        linear-gradient(rgba(255,255,255,0.5) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,0.5) 1px, transparent 1px);
    background-size: 48px 48px;
    pointer-events: none;
}
.mh-hero-glow-1 {
    position: absolute; top: -120px; right: -80px; width: 480px; height: 480px;
    background: radial-gradient(circle, rgba(99,102,241,0.35) 0%, rgba(139,92,246,0.15) 40%, transparent 70%);
    border-radius: 50%; filter: blur(60px); pointer-events: none;
    animation: heroFloat1 8s ease-in-out infinite alternate;
}
.mh-hero-glow-2 {
    position: absolute; bottom: -100px; left: -60px; width: 400px; height: 400px;
    background: radial-gradient(circle, rgba(59,130,246,0.3) 0%, rgba(6,182,212,0.12) 40%, transparent 70%);
    border-radius: 50%; filter: blur(50px); pointer-events: none;
    animation: heroFloat2 10s ease-in-out infinite alternate;
}
.mh-hero-glow-3 {
    position: absolute; top: 40%; left: 50%; width: 300px; height: 300px;
    background: radial-gradient(circle, rgba(168,85,247,0.2) 0%, transparent 70%);
    border-radius: 50%; filter: blur(40px); pointer-events: none;
    animation: heroFloat3 12s ease-in-out infinite alternate;
}
@keyframes heroFloat1 { 0% { transform: translate(0, 0) scale(1); } 100% { transform: translate(-30px, 20px) scale(1.1); } }
@keyframes heroFloat2 { 0% { transform: translate(0, 0) scale(1); } 100% { transform: translate(20px, -15px) scale(1.05); } }
@keyframes heroFloat3 { 0% { transform: translate(-50%, 0) scale(1); } 100% { transform: translate(-50%, -20px) scale(1.08); } }

/* Hero Badge */
.mh-hero-badge {
    display: inline-flex; align-items: center; gap: 8px;
    background: rgba(255,255,255,0.08); backdrop-filter: blur(12px);
    border: 1px solid rgba(255,255,255,0.12); border-radius: 999px;
    padding: 6px 18px 6px 12px; font-size: 0.8rem; color: #c7d2fe;
    font-weight: 500; margin-bottom: 1.5rem;
}
.mh-hero-badge-dot {
    width: 8px; height: 8px; border-radius: 50%; background: #34d399;
    box-shadow: 0 0 8px rgba(52,211,153,0.6);
    animation: pulse-dot 2s ease-in-out infinite;
}
@keyframes pulse-dot { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }

/* Hero Headline */
.mh-hero-headline {
    font-size: clamp(2rem, 4vw, 3.25rem); font-weight: 800; color: #fff;
    line-height: 1.15; letter-spacing: -0.02em; margin-bottom: 1.25rem;
}
.mh-hero-headline span {
    background: linear-gradient(135deg, #93c5fd 0%, #a78bfa 50%, #c084fc 100%);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
    background-clip: text;
}

/* Hero Subtitle */
.mh-hero-subtitle {
    font-size: 1.05rem; color: rgba(199,210,254,0.7); line-height: 1.7;
    margin-bottom: 2rem; max-width: 480px;
}

/* Hero CTA Buttons */
.mh-hero-cta-group { display: flex; gap: 12px; margin-bottom: 1.75rem; flex-wrap: wrap; }
.mh-hero-btn-primary {
    display: inline-flex; align-items: center; gap: 8px;
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
    color: #fff; font-weight: 700; font-size: 0.9rem;
    padding: 12px 28px; border-radius: 14px; text-decoration: none;
    transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
    box-shadow: 0 4px 20px rgba(99,102,241,0.35), inset 0 1px 0 rgba(255,255,255,0.15);
    border: 1px solid rgba(255,255,255,0.1);
}
.mh-hero-btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 32px rgba(99,102,241,0.45), inset 0 1px 0 rgba(255,255,255,0.2);
}
.mh-hero-btn-secondary {
    display: inline-flex; align-items: center; gap: 8px;
    background: rgba(255,255,255,0.06); backdrop-filter: blur(8px);
    color: #e0e7ff; font-weight: 700; font-size: 0.9rem;
    padding: 12px 28px; border-radius: 14px; text-decoration: none;
    border: 1px solid rgba(255,255,255,0.12);
    transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
}
.mh-hero-btn-secondary:hover {
    background: rgba(255,255,255,0.12); border-color: rgba(255,255,255,0.25);
    transform: translateY(-2px);
}

/* Hero Search Bar */
.mh-hero-search {
    display: flex; align-items: center; gap: 0;
    background: rgba(255,255,255,0.07); backdrop-filter: blur(12px);
    border: 1px solid rgba(255,255,255,0.1); border-radius: 14px;
    padding: 4px; max-width: 440px; transition: all 0.3s ease;
}
.mh-hero-search:focus-within {
    border-color: rgba(99,102,241,0.5); box-shadow: 0 0 0 3px rgba(99,102,241,0.15);
}
.mh-hero-search svg { flex-shrink: 0; color: rgba(199,210,254,0.5); margin-left: 14px; }
.mh-hero-search input {
    flex: 1; background: none; border: none; outline: none;
    color: #fff; font-size: 0.9rem; padding: 10px 12px;
    font-family: inherit;
}
.mh-hero-search input::placeholder { color: rgba(199,210,254,0.4); }
.mh-hero-search button {
    background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff;
    border: none; border-radius: 11px; padding: 10px 20px;
    font-weight: 700; font-size: 0.8rem; cursor: pointer;
    transition: all 0.2s ease; white-space: nowrap;
}
.mh-hero-search button:hover { opacity: 0.9; }

/* Hero Right — Illustration Area */
.mh-hero-visual {
    position: relative; width: 100%; min-height: 420px;
    display: flex; align-items: center; justify-content: center;
}
.mh-hero-illustration {
    position: relative; width: 100%; max-width: 460px; aspect-ratio: 1 / 1;
}
/* Central manager figure */
.mh-hero-manager {
    position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
    width: 140px; height: 160px; z-index: 2;
}
.mh-hero-manager-head {
    width: 56px; height: 56px; border-radius: 50%; margin: 0 auto 8px;
    background: linear-gradient(135deg, #c7d2fe 0%, #a5b4fc 100%);
    box-shadow: 0 4px 20px rgba(99,102,241,0.3);
    position: relative;
}
.mh-hero-manager-head::after {
    content: ''; position: absolute; bottom: 4px; left: 50%; transform: translateX(-50%);
    width: 36px; height: 18px; border-radius: 0 0 18px 18px;
    background: linear-gradient(135deg, #818cf8 0%, #6366f1 100%);
}
.mh-hero-manager-body {
    width: 80px; height: 72px; margin: 0 auto; border-radius: 16px 16px 20px 20px;
    background: linear-gradient(135deg, #4f46e5 0%, #6366f1 50%, #7c3aed 100%);
    box-shadow: 0 8px 30px rgba(99,102,241,0.3);
    position: relative;
}
.mh-hero-manager-body::before {
    content: ''; position: absolute; top: 12px; left: 50%; transform: translateX(-50%);
    width: 28px; height: 4px; border-radius: 2px; background: rgba(255,255,255,0.3);
}
.mh-hero-manager-body::after {
    content: ''; position: absolute; top: 22px; left: 50%; transform: translateX(-50%);
    width: 20px; height: 3px; border-radius: 2px; background: rgba(255,255,255,0.15);
}
.mh-hero-manager-screen {
    width: 100px; height: 68px; margin: -4px auto 0; border-radius: 6px;
    background: rgba(15,23,42,0.8); border: 2px solid rgba(99,102,241,0.3);
    box-shadow: 0 8px 30px rgba(0,0,0,0.3);
    position: relative; overflow: hidden;
}
.mh-hero-manager-screen::before {
    content: ''; position: absolute; top: 8px; left: 8px; right: 8px;
    height: 4px; border-radius: 2px; background: rgba(99,102,241,0.4);
}
.mh-hero-manager-screen::after {
    content: ''; position: absolute; top: 18px; left: 8px; right: 20px;
    height: 3px; border-radius: 2px; background: rgba(147,197,253,0.2);
}

/* Freelancer nodes around the manager */
.mh-hero-freelancer-node {
    position: absolute; z-index: 1;
}
.mh-hero-fn-circle {
    width: 44px; height: 44px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.7rem; font-weight: 700; color: #fff;
    box-shadow: 0 4px 16px rgba(0,0,0,0.2);
}
.mh-hero-fn-1 { top: 8%; left: 10%; }
.mh-hero-fn-1 .mh-hero-fn-circle { background: linear-gradient(135deg, #ec4899, #f472b6); }
.mh-hero-fn-2 { top: 5%; right: 15%; }
.mh-hero-fn-2 .mh-hero-fn-circle { background: linear-gradient(135deg, #14b8a6, #2dd4bf); }
.mh-hero-fn-3 { bottom: 30%; left: 2%; }
.mh-hero-fn-3 .mh-hero-fn-circle { background: linear-gradient(135deg, #f59e0b, #fbbf24); }
.mh-hero-fn-4 { bottom: 25%; right: 5%; }
.mh-hero-fn-4 .mh-hero-fn-circle { background: linear-gradient(135deg, #8b5cf6, #a78bfa); }
.mh-hero-fn-5 { top: 38%; left: -2%; }
.mh-hero-fn-5 .mh-hero-fn-circle { background: linear-gradient(135deg, #06b6d4, #22d3ee); }
.mh-hero-fn-6 { top: 35%; right: -2%; }
.mh-hero-fn-6 .mh-hero-fn-circle { background: linear-gradient(135deg, #f97316, #fb923c); }

/* Connection lines */
.mh-hero-connections {
    position: absolute; inset: 0; z-index: 0; pointer-events: none;
}
.mh-hero-connections line {
    stroke: rgba(99,102,241,0.2); stroke-width: 1.5; stroke-dasharray: 4 4;
}

/* Floating glassmorphism cards */
.mh-hero-float-card {
    position: absolute; z-index: 3;
    background: rgba(255,255,255,0.08); backdrop-filter: blur(16px);
    border: 1px solid rgba(255,255,255,0.12); border-radius: 16px;
    padding: 12px 16px; color: #fff; min-width: 140px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.15);
    transition: transform 0.3s ease;
}
.mh-hero-float-card:hover { transform: scale(1.04); }
.mh-hero-float-label { font-size: 0.65rem; color: rgba(199,210,254,0.6); text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; margin-bottom: 4px; }
.mh-hero-float-value { font-size: 1.1rem; font-weight: 800; }
.mh-hero-float-sub { font-size: 0.7rem; color: rgba(199,210,254,0.5); margin-top: 2px; }

/* Float card positions */
.mh-hero-fc-ratings { top: 6%; right: -5%; animation: float-card 6s ease-in-out infinite alternate; }
.mh-hero-fc-verified { bottom: 12%; left: -8%; animation: float-card 7s ease-in-out 0.5s infinite alternate; }
.mh-hero-fc-projects { top: 45%; right: -12%; animation: float-card 8s ease-in-out 1s infinite alternate; }
.mh-hero-fc-online { bottom: 35%; left: -5%; animation: float-card 5s ease-in-out 1.5s infinite alternate; }
.mh-hero-fc-hiring { top: -5%; left: 20%; animation: float-card 9s ease-in-out 0.8s infinite alternate; }

@keyframes float-card {
    0% { transform: translateY(0); }
    100% { transform: translateY(-8px); }
}

/* Star rating inside float card */
.mh-hero-stars { display: flex; gap: 2px; margin: 4px 0; }
.mh-hero-stars svg { width: 12px; height: 12px; color: #fbbf24; }

/* ── Stats Cards Below Hero ── */
.mh-stats-grid {
    display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px;
    margin-top: -48px; position: relative; z-index: 10; padding: 0 16px;
}
.mh-stat-card {
    background: var(--color-surface, #fff); border: 1px solid var(--color-border, #e5e7eb);
    border-radius: 20px; padding: 24px; text-align: center;
    box-shadow: 0 4px 24px rgba(0,0,0,0.06);
    transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
}
.mh-stat-card:hover {
    transform: translateY(-4px); box-shadow: 0 12px 40px rgba(99,102,241,0.12);
}
.mh-stat-icon {
    width: 48px; height: 48px; border-radius: 14px; margin: 0 auto 12px;
    display: flex; align-items: center; justify-content: center;
}
.mh-stat-icon svg { width: 24px; height: 24px; }
.mh-stat-number {
    font-size: 1.75rem; font-weight: 800; color: var(--color-text-primary);
    line-height: 1.2; margin-bottom: 4px;
}
.mh-stat-label {
    font-size: 0.8rem; color: var(--color-text-muted); font-weight: 500;
}

@media (max-width: 1024px) {
    .mh-hero-premium .mh-hero-grid { display: none; }
}
@media (max-width: 768px) {
    .mh-hero-visual { min-height: 300px; }
    .mh-hero-float-card { display: none; }
    .mh-stats-grid { grid-template-columns: repeat(2, 1fr); margin-top: -32px; }
    .mh-hero-premium > .relative.z-10 > div { grid-template-columns: 1fr !important; gap: 32px !important; }
    .mh-hero-badge { margin-bottom: 1rem; }
    .mh-hero-headline { font-size: 1.75rem; }
    .mh-hero-subtitle { font-size: 0.95rem; margin-bottom: 1.5rem; }
    .mh-hero-cta-group { margin-bottom: 1.25rem; }
    .mh-hero-search { max-width: 100%; }
}
@media (max-width: 480px) {
    .mh-stats-grid { grid-template-columns: 1fr; }
    .mh-hero-premium { padding: 2.5rem 0 3.5rem !important; }
}

/* ── Section Headers ── */
.mh-section-title {
    font-size: 1.75rem; font-weight: 800; color: var(--color-text-primary); margin-bottom: 0.5rem;
}
.mh-section-sub {
    font-size: 1rem; color: var(--color-text-muted); max-width: 560px;
}

/* ── Cards ── */
.mh-card {
    background: var(--color-surface, #fff); border: 1px solid var(--color-border, #e5e7eb);
    border-radius: 1rem; padding: 1.5rem; transition: all 0.3s ease;
}
.mh-card:hover {
    transform: translateY(-4px); box-shadow: 0 12px 32px rgba(37,99,235,0.1);
    border-color: var(--c-blue);
}

/* ── Freelancer Cards ── */
.mh-fl-card {
    background: var(--color-surface, #fff); border: 1px solid var(--color-border, #e5e7eb);
    border-radius: 1.25rem; padding: 2rem 1.25rem; min-width: 250px; max-width: 250px;
    flex-shrink: 0; min-height: 340px; display: flex; flex-direction: column;
    align-items: center; text-align: center; transition: all 0.3s ease;
}
.mh-fl-card:hover { transform: translateY(-6px); box-shadow: 0 16px 40px rgba(37,99,235,0.12); border-color: var(--c-blue); }
.mh-fl-avatar {
    width: 80px; height: 80px; border-radius: 50%; padding: 3px; margin-bottom: 1rem;
    background: linear-gradient(135deg, #a855f7, #ec4899, #f59e0b);
}
.mh-fl-avatar img, .mh-fl-avatar > div {
    width: 100%; height: 100%; border-radius: 50%; object-fit: cover;
    border: 3px solid var(--color-surface, #fff);
}
.mh-fl-stats {
    display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; width: 100%; margin: 1rem 0;
}
.mh-fl-stat-box {
    background: var(--color-bg, #f8fafc); border-radius: 0.75rem; padding: 0.75rem 0.5rem; text-align: center;
}
.mh-fl-stat-box p:first-child { font-size: 1.25rem; font-weight: 800; color: var(--color-text-primary); line-height: 1.2; }
.mh-fl-stat-box p:last-child { font-size: 0.7rem; color: var(--color-text-placeholder); margin-top: 2px; }
.mh-fl-bottom {
    width: 100%; display: flex; align-items: flex-end; justify-content: space-between;
    margin-top: auto; padding-top: 1rem; border-top: 1px solid var(--color-border, #e5e7eb);
}
.mh-fl-rate-label { font-size: 0.7rem; color: var(--color-text-placeholder); margin-bottom: 2px; }
.mh-fl-rate-value { font-size: 1.5rem; font-weight: 800; color: var(--color-text-primary); line-height: 1; }
.mh-fl-view-btn {
    display: inline-flex; align-items: center; justify-content: center;
    background: linear-gradient(135deg, #7c3aed, #6366f1); color: #fff;
    font-size: 0.8rem; font-weight: 700; padding: 0.6rem 1.25rem;
    border-radius: 999px; text-decoration: none; transition: all 0.3s ease;
    box-shadow: 0 4px 14px rgba(99,102,241,0.3);
}
.mh-fl-view-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(99,102,241,0.4); }

/* ── Carousel ── */
.mh-carousel { position: relative; }
.mh-carousel-wrap { overflow: hidden; margin: 0 -4px; padding: 4px; }
.mh-carousel-track { display: flex; gap: 16px; transition: transform 0.5s cubic-bezier(0.25,0.1,0.25,1); will-change: transform; }
.mh-carr-arrow {
    position: absolute; top: 40%; transform: translateY(-50%); z-index: 10;
    width: 42px; height: 42px; border-radius: 50%; border: 1px solid var(--color-border);
    background: var(--color-surface, #fff); box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    display: flex; align-items: center; justify-content: center; cursor: pointer;
    transition: all 0.2s ease; color: var(--color-text-primary);
}
.mh-carr-arrow:hover { background: var(--c-blue); color: #fff; border-color: var(--c-blue); }
.mh-carr-arrow-l { left: -14px; }
.mh-carr-arrow-r { right: -14px; }
.mh-dots { display: flex; justify-content: center; gap: 6px; margin-top: 18px; }
.mh-dot {
    width: 8px; height: 8px; border-radius: 50%; background: var(--color-border, #ddd);
    border: none; cursor: pointer; transition: all 0.3s ease; padding: 0;
}
.mh-dot.active { background: var(--c-blue); width: 24px; border-radius: 4px; }

/* ── Job Cards ── */
.mh-job-card {
    background: var(--color-surface, #fff); border: 1px solid var(--color-border, #e5e7eb);
    border-radius: 1.25rem; overflow: hidden; transition: all 0.3s ease;
    display: flex; flex-direction: column;
}
.mh-job-card:hover { transform: translateY(-6px); box-shadow: 0 16px 40px rgba(0,0,0,0.08); border-color: var(--c-blue); }
.mh-job-hero {
    position: relative; height: 160px; overflow: hidden;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex; align-items: center; justify-content: center;
}
.mh-job-hero img { width: 100%; height: 100%; object-fit: cover; position: absolute; inset: 0; }
.mh-job-badge-cat {
    position: absolute; top: 12px; left: 12px; z-index: 2;
    background: rgba(255,255,255,0.95); backdrop-filter: blur(4px);
    padding: 4px 12px; border-radius: 999px; font-size: 0.7rem; font-weight: 700;
    color: var(--c-blue);
}
.mh-job-badge-status {
    position: absolute; top: 12px; right: 12px; z-index: 2;
    background: #059669; color: #fff;
    padding: 4px 12px; border-radius: 999px; font-size: 0.7rem; font-weight: 700;
    display: flex; align-items: center; gap: 4px;
}
.mh-job-badge-status::before {
    content: ''; width: 6px; height: 6px; border-radius: 50%; background: #fff;
}
.mh-job-body { padding: 1.25rem; flex: 1; display: flex; flex-direction: column; }
.mh-job-company { display: flex; align-items: center; gap: 8px; margin-bottom: 0.75rem; }
.mh-job-company img { width: 32px; height: 32px; border-radius: 8px; object-fit: cover; border: 1px solid var(--color-border); }
.mh-job-company-name { font-size: 0.8rem; font-weight: 700; color: var(--color-text-primary); }
.mh-job-company-time { font-size: 0.7rem; color: var(--color-text-placeholder); }
.mh-job-title { font-size: 1.05rem; font-weight: 800; color: var(--color-text-primary); margin-bottom: 0.35rem; line-height: 1.3; }
.mh-job-desc { font-size: 0.85rem; color: var(--color-text-muted); margin-bottom: 0.75rem; line-height: 1.5; flex: 1; }
.mh-job-tag {
    display: inline-block; background: rgba(37,99,235,0.08); color: var(--c-blue);
    font-size: 0.7rem; font-weight: 600; padding: 4px 10px; border-radius: 999px; margin-bottom: 1rem;
}
.mh-job-meta {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0.75rem 0; border-top: 1px solid var(--color-border, #e5e7eb); margin-bottom: 0.75rem;
}
.mh-job-budget { display: flex; align-items: center; gap: 6px; }
.mh-job-budget-icon {
    width: 24px; height: 24px; border-radius: 50%; background: rgba(5,150,105,0.1);
    display: flex; align-items: center; justify-content: center;
    color: #059669; font-size: 0.75rem; font-weight: 800;
}
.mh-job-budget-val { font-size: 1.1rem; font-weight: 800; color: var(--color-text-primary); }
.mh-job-date { display: flex; align-items: center; gap: 4px; font-size: 0.75rem; color: var(--color-text-placeholder); }
.mh-job-actions { display: flex; gap: 10px; margin-top: auto; }
.mh-job-btn-detail {
    flex: 1; display: flex; align-items: center; justify-content: center; gap: 6px;
    padding: 0.6rem; border-radius: 0.75rem; font-size: 0.8rem; font-weight: 700;
    border: 1.5px solid var(--color-border, #e5e7eb); color: var(--color-text-primary);
    text-decoration: none; transition: all 0.2s ease; background: transparent;
}
.mh-job-btn-detail:hover { border-color: var(--c-blue); color: var(--c-blue); }
.mh-job-btn-apply {
    flex: 1; display: flex; align-items: center; justify-content: center; gap: 6px;
    padding: 0.6rem; border-radius: 0.75rem; font-size: 0.8rem; font-weight: 700;
    background: linear-gradient(135deg, #7c3aed, #6366f1); color: #fff;
    text-decoration: none; transition: all 0.3s ease;
    box-shadow: 0 4px 14px rgba(99,102,241,0.25);
}
.mh-job-btn-apply:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(99,102,241,0.35); }

/* ── How It Works Steps ── */
.mh-step {
    text-align: center; padding: 2rem 1.5rem;
}
.mh-step-num {
    width: 56px; height: 56px; border-radius: 50%; background: linear-gradient(135deg, var(--c-blue), var(--c-accent));
    color: #fff; font-size: 1.25rem; font-weight: 800; display: inline-flex;
    align-items: center; justify-content: center; margin-bottom: 1rem;
}

/* ── Why Choose Us ── */
.mh-why-card {
    text-align: center; padding: 2rem 1.5rem;
}
.mh-why-icon {
    width: 60px; height: 60px; border-radius: 1rem; display: inline-flex;
    align-items: center; justify-content: center; margin-bottom: 1rem;
}

/* ── Review Cards ── */
.mh-review-card {
    background: var(--color-surface, #fff); border: 1px solid var(--color-border, #e5e7eb);
    border-radius: 1rem; padding: 1.5rem; transition: all 0.3s ease;
}
.mh-review-card:hover { box-shadow: 0 8px 24px rgba(0,0,0,0.06); }

/* ── CTA Section ── */
.mh-cta {
    background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 50%, #3b82f6 100%);
    border-radius: 1.5rem; position: relative; overflow: hidden;
}
.mh-cta::before {
    content: ''; position: absolute; top: -50%; right: -20%; width: 400px; height: 400px;
    background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%); border-radius: 50%;
}

/* ── Responsive ── */
@media (max-width: 640px) {
    .mh-fl-card { min-width: 220px; max-width: 220px; }
    .mh-carr-arrow { display: none; }
}
@media (min-width: 641px) and (max-width: 768px) {
    .mh-fl-card { min-width: 230px; max-width: 230px; }
}
</style>

<!-- ════════════════════════════════════ HERO ════════════════════════════════════ -->
<section class="mh-hero-premium mx-4 md:mx-0 mb-16 fade-in" style="padding: 3.5rem 0 5.5rem;">
    <!-- Background Effects -->
    <div class="mh-hero-grid"></div>
    <div class="mh-hero-glow-1"></div>
    <div class="mh-hero-glow-2"></div>
    <div class="mh-hero-glow-3"></div>

    <div class="relative z-10 max-w-6xl mx-auto px-6 lg:px-8">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:48px;align-items:center;min-height:420px;">

            <!-- LEFT COLUMN — Content -->
            <div>
                <div class="mh-hero-badge">
                    <span class="mh-hero-badge-dot"></span>
                    Welcome back, <?= e($company['company_name'] ?? $user['username']) ?>
                </div>

                <h1 class="mh-hero-headline">
                    Hire the World's<br>
                    Best <span>Freelancers</span>
                </h1>

                <p class="mh-hero-subtitle">
                    Post projects, discover top talent, and collaborate seamlessly — all in one platform built for modern businesses.
                </p>

                <div class="mh-hero-cta-group">
                    <a href="<?= e(base_url('company/post_job.php')) ?>" class="mh-hero-btn-primary">
                        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                        Post a Job
                    </a>
                    <a href="<?= e(base_url('company/find_freelancers.php')) ?>" class="mh-hero-btn-secondary">
                        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        Find Freelancers
                    </a>
                </div>

                <form class="mh-hero-search" action="<?= e(base_url('company/find_freelancers.php')) ?>" method="get">
                    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input type="text" name="q" placeholder="Search skills, roles, or keywords...">
                    <button type="submit">Search</button>
                </form>
            </div>

            <!-- RIGHT COLUMN — Illustration + Floating Cards -->
            <div class="mh-hero-visual">
                <!-- SVG Connection Lines -->
                <svg class="mh-hero-connections" viewBox="0 0 460 460" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <line x1="100" y1="60" x2="230" y2="200"/>
                    <line x1="350" y1="50" x2="230" y2="200"/>
                    <line x1="30" y1="260" x2="230" y2="200"/>
                    <line x1="430" y1="270" x2="230" y2="200"/>
                    <line x1="50" y1="190" x2="230" y2="200"/>
                    <line x1="410" y1="180" x2="230" y2="200"/>
                </svg>

                <!-- Freelancer Nodes -->
                <div class="mh-hero-freelancer-node mh-hero-fn-1"><div class="mh-hero-fn-circle">JK</div></div>
                <div class="mh-hero-freelancer-node mh-hero-fn-2"><div class="mh-hero-fn-circle">AL</div></div>
                <div class="mh-hero-freelancer-node mh-hero-fn-3"><div class="mh-hero-fn-circle">SM</div></div>
                <div class="mh-hero-freelancer-node mh-hero-fn-4"><div class="mh-hero-fn-circle">RD</div></div>
                <div class="mh-hero-freelancer-node mh-hero-fn-5"><div class="mh-hero-fn-circle">TP</div></div>
                <div class="mh-hero-freelancer-node mh-hero-fn-6"><div class="mh-hero-fn-circle">MJ</div></div>

                <!-- Central Manager Illustration -->
                <div class="mh-hero-illustration">
                    <div class="mh-hero-manager">
                        <div class="mh-hero-manager-head"></div>
                        <div class="mh-hero-manager-body"></div>
                        <div class="mh-hero-manager-screen"></div>
                    </div>
                </div>

                <!-- Floating Glassmorphism Cards -->
                <div class="mh-hero-float-card mh-hero-fc-ratings">
                    <div class="mh-hero-float-label">Freelancer Rating</div>
                    <div class="mh-hero-float-value" style="color:#fbbf24;">4.9 / 5.0</div>
                    <div class="mh-hero-stars">
                        <svg fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        <svg fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        <svg fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        <svg fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        <svg fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                    </div>
                    <div class="mh-hero-float-sub">Based on <?= number_format($total_freelancers) ?> freelancers</div>
                </div>

                <div class="mh-hero-float-card mh-hero-fc-verified">
                    <div class="mh-hero-float-label">Verified Talent</div>
                    <div class="mh-hero-float-value" style="color:#34d399;">100% Vetted</div>
                    <div class="mh-hero-float-sub">Skills & identity confirmed</div>
                </div>

                <div class="mh-hero-float-card mh-hero-fc-projects">
                    <div class="mh-hero-float-label">Completed Projects</div>
                    <div class="mh-hero-float-value"><?= number_format($freelancers_hired) ?>+</div>
                    <div class="mh-hero-float-sub">Successfully delivered</div>
                </div>

                <div class="mh-hero-float-card mh-hero-fc-online">
                    <div class="mh-hero-float-label">Online Now</div>
                    <div class="mh-hero-float-value" style="color:#22d3ee;">
                        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#34d399;margin-right:6px;box-shadow:0 0 8px rgba(52,211,153,0.6);animation:pulse-dot 2s ease-in-out infinite;"></span>
                        <?= number_format($total_freelancers) ?>+
                    </div>
                    <div class="mh-hero-float-sub">Ready to work</div>
                </div>

                <div class="mh-hero-float-card mh-hero-fc-hiring">
                    <div class="mh-hero-float-label">Hiring Stats</div>
                    <div class="mh-hero-float-value" style="color:#a78bfa;"><?= $success_rate ?>%</div>
                    <div class="mh-hero-float-sub">Success rate</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ════════════════════════════════════ STATS CARDS ════════════════════════════════════ -->
<div class="max-w-6xl mx-auto fade-in" style="margin-top:-48px;position:relative;z-index:10;">
    <div class="mh-stats-grid">
        <div class="mh-stat-card">
            <div class="mh-stat-icon" style="background:rgba(99,102,241,0.1);">
                <svg fill="none" viewBox="0 0 24 24" stroke="#6366f1" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            </div>
            <div class="mh-stat-number"><?= number_format($total_jobs) ?>+</div>
            <div class="mh-stat-label">Active Jobs</div>
        </div>
        <div class="mh-stat-card">
            <div class="mh-stat-icon" style="background:rgba(52,211,153,0.1);">
                <svg fill="none" viewBox="0 0 24 24" stroke="#34d399" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            </div>
            <div class="mh-stat-number"><?= number_format($freelancers_hired) ?>+</div>
            <div class="mh-stat-label">Freelancers Hired</div>
        </div>
        <div class="mh-stat-card">
            <div class="mh-stat-icon" style="background:rgba(245,158,11,0.1);">
                <svg fill="none" viewBox="0 0 24 24" stroke="#f59e0b" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
            </div>
            <div class="mh-stat-number"><?= number_format($total_applications) ?></div>
            <div class="mh-stat-label">Total Applications</div>
        </div>
        <div class="mh-stat-card">
            <div class="mh-stat-icon" style="background:rgba(168,85,247,0.1);">
                <svg fill="none" viewBox="0 0 24 24" stroke="#a855f7" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div class="mh-stat-number"><?= $success_rate ?>%</div>
            <div class="mh-stat-label">Success Rate</div>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════ FEATURED FREELANCERS ════════════════════════════════════ -->
<section class="mb-16 fade-in">
    <div class="flex items-center justify-between mb-2">
        <div>
            <h2 class="mh-section-title">Featured Freelancers</h2>
            <p class="mh-section-sub">Handpicked professionals ready to bring your projects to life.</p>
        </div>
        <a href="<?= e(base_url('company/find_freelancers.php')) ?>" class="hidden sm:inline-flex items-center gap-1 text-sm font-semibold" style="color:var(--c-blue)">Browse All <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg></a>
    </div>
    <?php if (empty($featured_freelancers)): ?>
        <div class="mh-card text-center py-12" style="color:var(--color-text-placeholder)">
            <svg class="w-16 h-16 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            <p>No freelancers available yet.</p>
        </div>
    <?php else: ?>
        <div class="mh-carousel" style="position:relative;">
            <button type="button" class="mh-carr-arrow mh-carr-arrow-l" onclick="mhCarousel.prev()" aria-label="Previous">
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            </button>
            <div class="mh-carousel-wrap">
                <div class="mh-carousel-track">
                    <?php foreach ($featured_freelancers as $fl): ?>
                        <div class="mh-fl-card">
                            <?php $flImg = profile_image_url($fl['profile_image']); ?>
                            <div class="mh-fl-avatar">
                                <?php if ($flImg): ?>
                                    <img src="<?= e($flImg) ?>" alt="<?= e($fl['full_name']) ?>">
                                <?php else: ?>
                                    <div style="width:100%;height:100%;border-radius:50%;background:linear-gradient(135deg,#7c3aed,#6366f1);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:1.5rem;"><?= e(strtoupper(substr($fl['full_name'], 0, 1))) ?></div>
                                <?php endif; ?>
                            </div>
                            <h3 class="font-bold text-base mb-0.5" style="color:var(--color-text-primary)"><?= e($fl['full_name']) ?></h3>
                            <p class="text-sm mb-2" style="color:var(--color-text-muted)"><?= e($fl['title'] ?? 'Freelancer') ?></p>
                            <div class="flex items-center justify-center gap-1 mb-1">
                                <?php for ($i = 0; $i < 5; $i++): ?>
                                    <svg class="w-4 h-4" style="color:<?= $i < round($fl['avg_rating']) ? '#f59e0b' : '#e5e7eb' ?>" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                                <?php endfor; ?>
                                <span class="text-sm font-semibold ml-1" style="color:var(--color-text-primary)"><?= number_format((float) $fl['avg_rating'], 1) ?></span>
                            </div>
                            <div class="mh-fl-stats">
                                <div class="mh-fl-stat-box">
                                    <p><?= (int) $fl['completed_projects'] ?></p>
                                    <p>Projects</p>
                                </div>
                                <div class="mh-fl-stat-box">
                                    <p><?= (int) $fl['companies_worked'] ?></p>
                                    <p>Companies</p>
                                </div>
                            </div>
                            <div class="mh-fl-bottom">
                                <div>
                                    <p class="mh-fl-rate-label">Hourly Rate</p>
                                    <p class="mh-fl-rate-value">$<?= e(number_format((float) $fl['hourly_rate'], 0)) ?></p>
                                </div>
                                <a href="<?= e(base_url('company/view_freelancer.php?id=' . $fl['id'])) ?>" class="mh-fl-view-btn">View Profile</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <button type="button" class="mh-carr-arrow mh-carr-arrow-r" onclick="mhCarousel.next()" aria-label="Next">
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            </button>
            <div class="mh-dots"></div>
        </div>
    <?php endif; ?>
</section>

<!-- ════════════════════════════════════ LATEST JOB POSTS ════════════════════════════════════ -->
<section class="mb-16 fade-in">
    <div class="flex items-center justify-between mb-2">
        <div>
            <h2 class="mh-section-title">Latest Job Posts</h2>
            <p class="mh-section-sub">Fresh opportunities from top companies looking for talent.</p>
        </div>
        <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="hidden sm:inline-flex items-center gap-1 text-sm font-semibold" style="color:var(--c-blue)">View All <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg></a>
    </div>
    <?php if (empty($latest_jobs)): ?>
        <div class="mh-card text-center py-12" style="color:var(--color-text-placeholder)">
            <svg class="w-16 h-16 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            <p>No jobs posted yet. Be the first to post!</p>
        </div>
    <?php else: ?>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
            <?php
            $hero_gradients = [
                'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                'linear-gradient(135deg, #f093fb 0%, #f5576c 100%)',
                'linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)',
                'linear-gradient(135deg, #43e97b 0%, #38f9d7 100%)',
                'linear-gradient(135deg, #fa709a 0%, #fee140 100%)',
                'linear-gradient(135deg, #a18cd1 0%, #fbc2eb 100%)',
            ];
            ?>
            <?php foreach ($latest_jobs as $idx => $job): ?>
                <div class="mh-job-card">
                    <div class="mh-job-hero" style="background:<?= $hero_gradients[$idx % count($hero_gradients)] ?>">
                        <?php if (!empty($job['logo_image'])): ?>
                            <img src="<?= e(base_url('uploads/images/' . $job['logo_image'])) ?>" alt="<?= e($job['title']) ?>" onerror="this.style.display='none';this.parentElement.style.background='<?= $hero_gradients[$idx % count($hero_gradients)] ?>'">
                        <?php endif; ?>
                        <span class="mh-job-badge-cat"><?= e($job['category'] ?? 'General') ?></span>
                        <span class="mh-job-badge-status">Open</span>
                    </div>
                    <div class="mh-job-body">
                        <div class="mh-job-company">
                            <?php if (!empty($job['logo_image'])): ?>
                                <img src="<?= e(base_url('uploads/images/' . $job['logo_image'])) ?>" alt="">
                            <?php else: ?>
                                <div style="width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,#2563eb,#3b82f6);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:0.7rem;flex-shrink:0;"><?= e(strtoupper(substr($job['company_name'] ?? 'C', 0, 1))) ?></div>
                            <?php endif; ?>
                            <div>
                                <p class="mh-job-company-name"><?= e($job['company_name'] ?? 'Company') ?></p>
                                <p class="mh-job-company-time">Posted recently</p>
                            </div>
                        </div>
                        <h3 class="mh-job-title"><?= e($job['title']) ?></h3>
                        <p class="mh-job-desc"><?= e(substr($job['description'] ?? '', 0, 100)) ?><?= strlen($job['description'] ?? '') > 100 ? '...' : '' ?></p>
                        <span class="mh-job-tag"><?= e($job['category'] ?? 'General') ?></span>
                        <div class="mh-job-meta">
                            <div class="mh-job-budget">
                                <div class="mh-job-budget-icon">$</div>
                                <span class="mh-job-budget-val">$<?= e(number_format((float) $job['budget'], 0)) ?></span>
                            </div>
                            <div class="mh-job-date">
                                <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                <?= date('M j', strtotime($job['created_at'])) ?>
                            </div>
                        </div>
                        <div class="mh-job-actions">
                            <a href="<?= e(base_url('company/view_job.php?id=' . $job['id'])) ?>" class="mh-job-btn-detail">
                                <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                View Details
                            </a>
                            <a href="<?= e(base_url('company/edit_job.php?id=' . $job['id'])) ?>" class="mh-job-btn-apply">
                                <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                Edit
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- ════════════════════════════════════ HOW IT WORKS ════════════════════════════════════ -->
<section class="mb-16 fade-in">
    <div class="text-center mb-10">
        <h2 class="mh-section-title">How It Works</h2>
        <p class="mh-section-sub mx-auto">Get started in three simple steps.</p>
    </div>
    <div class="grid sm:grid-cols-3 gap-6">
        <div class="mh-card mh-step">
            <div class="mh-step-num">1</div>
            <h3 class="font-bold text-lg mb-2" style="color:var(--color-text-primary)">Post a Project</h3>
            <p class="text-sm leading-relaxed" style="color:var(--color-text-muted)">Describe your project, set a budget, and post it in minutes. Our platform makes it easy to define your requirements.</p>
        </div>
        <div class="mh-card mh-step">
            <div class="mh-step-num">2</div>
            <h3 class="font-bold text-lg mb-2" style="color:var(--color-text-primary)">Review Proposals</h3>
            <p class="text-sm leading-relaxed" style="color:var(--color-text-muted)">Receive proposals from qualified freelancers. Compare portfolios, ratings, and rates to find your perfect match.</p>
        </div>
        <div class="mh-card mh-step">
            <div class="mh-step-num">3</div>
            <h3 class="font-bold text-lg mb-2" style="color:var(--color-text-primary)">Hire & Collaborate</h3>
            <p class="text-sm leading-relaxed" style="color:var(--color-text-muted)">Hire your chosen freelancer, track progress, and collaborate through our built-in messaging system.</p>
        </div>
    </div>
</section>

<!-- ════════════════════════════════════ WHY CHOOSE US ════════════════════════════════════ -->
<section class="mb-16 fade-in">
    <div class="text-center mb-10">
        <h2 class="mh-section-title">Why Choose Us</h2>
        <p class="mh-section-sub mx-auto">Everything you need to build a world-class team.</p>
    </div>
    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-5">
        <div class="mh-card mh-why-card">
            <div class="mh-why-icon" style="background:rgba(37,99,235,0.1)">
                <svg class="w-7 h-7" style="color:var(--c-blue)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            </div>
            <h3 class="font-bold mb-1" style="color:var(--color-text-primary)">Verified Talent</h3>
            <p class="text-sm" style="color:var(--color-text-muted)">Every freelancer is vetted and reviewed by real clients.</p>
        </div>
        <div class="mh-card mh-why-card">
            <div class="mh-why-icon" style="background:rgba(5,150,105,0.1)">
                <svg class="w-7 h-7" style="color:#059669" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <h3 class="font-bold mb-1" style="color:var(--color-text-primary)">Secure Payments</h3>
            <p class="text-sm" style="color:var(--color-text-muted)">Escrow-based payments protect both parties.</p>
        </div>
        <div class="mh-card mh-why-card">
            <div class="mh-why-icon" style="background:rgba(124,58,237,0.1)">
                <svg class="w-7 h-7" style="color:#7c3aed" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
            </div>
            <h3 class="font-bold mb-1" style="color:var(--color-text-primary)">Built-in Chat</h3>
            <p class="text-sm" style="color:var(--color-text-muted)">Communicate with your team in real-time, right from the platform.</p>
        </div>
        <div class="mh-card mh-why-card">
            <div class="mh-why-icon" style="background:rgba(217,119,6,0.1)">
                <svg class="w-7 h-7" style="color:#d97706" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            </div>
            <h3 class="font-bold mb-1" style="color:var(--color-text-primary)">Fast Results</h3>
            <p class="text-sm" style="color:var(--color-text-muted)">Get proposals within hours and hire the right person today.</p>
        </div>
    </div>
</section>

<!-- ════════════════════════════════════ CLIENT REVIEWS ════════════════════════════════════ -->
<section class="mb-16 fade-in">
    <div class="text-center mb-10">
        <h2 class="mh-section-title">What Our Clients Say</h2>
        <p class="mh-section-sub mx-auto">Real feedback from companies hiring on our platform.</p>
    </div>
    <?php if (!empty($client_reviews)): ?>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
            <?php foreach ($client_reviews as $review): ?>
                <div class="mh-review-card">
                    <div class="flex items-center gap-1 mb-3">
                        <?php for ($i = 0; $i < 5; $i++): ?>
                            <svg class="w-4 h-4" style="color:<?= $i < $review['rating'] ? '#f59e0b' : '#e5e7eb' ?>" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        <?php endfor; ?>
                    </div>
                    <p class="text-sm leading-relaxed mb-4" style="color:var(--color-text-secondary)">"<?= e(substr($review['comment'], 0, 200)) ?><?= strlen($review['comment']) > 200 ? '...' : '' ?>"</p>
                    <div class="flex items-center gap-3">
                        <?php $rImg = profile_image_url($review['reviewer_image']); ?>
                        <?php if ($rImg): ?>
                            <img src="<?= e($rImg) ?>" alt="" class="w-9 h-9 rounded-full object-cover">
                        <?php else: ?>
                            <div class="w-9 h-9 rounded-full flex items-center justify-center text-white text-sm font-bold" style="background:linear-gradient(135deg,#2563eb,#3b82f6)"><?= e(strtoupper(substr($review['reviewer_name'], 0, 1))) ?></div>
                        <?php endif; ?>
                        <div>
                            <p class="text-sm font-semibold" style="color:var(--color-text-primary)"><?= e($review['reviewer_name']) ?></p>
                            <p class="text-xs" style="color:var(--color-text-placeholder)">about <?= e($review['freelancer_name'] ?? 'a freelancer') ?></p>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="mh-card text-center py-12" style="color:var(--color-text-placeholder)">
            <svg class="w-16 h-16 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
            <p>No reviews yet. Start a project to leave the first review!</p>
        </div>
    <?php endif; ?>
</section>

<!-- ════════════════════════════════════ CTA ════════════════════════════════════ -->
<section class="mb-12 fade-in">
    <div class="mh-cta text-white py-14 px-6 md:px-12 text-center" style="position:relative;">
        <div class="relative z-10">
            <h2 class="text-3xl md:text-4xl font-extrabold mb-4">Ready to Find Your Next Great Hire?</h2>
            <p class="text-blue-200 text-lg mb-8 max-w-xl mx-auto">Join thousands of companies building their dream teams with our platform.</p>
            <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                <a href="<?= e(base_url('company/post_job.php')) ?>" class="inline-flex items-center gap-2 bg-white text-blue-700 font-bold px-8 py-3.5 rounded-xl hover:bg-blue-50 transition-all shadow-lg text-base">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    Post a Job Now
                </a>
                <a href="<?= e(base_url('company/find_freelancers.php')) ?>" class="inline-flex items-center gap-2 bg-white/10 backdrop-blur-sm text-white font-bold px-8 py-3.5 rounded-xl hover:bg-white/20 transition-all border border-white/20 text-base">
                    Browse Talent
                </a>
            </div>
        </div>
    </div>
</section>

<!-- ════════════════════════════════════ FOOTER ════════════════════════════════════ -->
<footer class="mt-12 py-10 border-t" style="border-color:var(--color-border)">
    <div class="max-w-6xl mx-auto px-4">
        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-8 mb-10">
            <div>
                <div class="flex items-center gap-2 mb-3">
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center" style="background:linear-gradient(135deg,#2563eb,#3b82f6)">
                        <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    </div>
                    <span class="text-base font-bold" style="color:var(--color-text-primary)"><?= e('FreelanceHub') ?></span>
                </div>
                <p class="text-sm leading-relaxed" style="color:var(--color-text-muted)">Connect with top freelancers and grow your business with quality talent.</p>
            </div>
            <div>
                <h4 class="font-semibold text-sm mb-3 uppercase tracking-wider" style="color:var(--color-text-primary)">Company</h4>
                <div class="space-y-2">
                    <a href="<?= e(base_url('company/post_job.php')) ?>" class="block text-sm hover:underline" style="color:var(--color-text-muted);text-decoration:none">Post a Job</a>
                    <a href="<?= e(base_url('company/manage_jobs.php')) ?>" class="block text-sm hover:underline" style="color:var(--color-text-muted);text-decoration:none">Manage Jobs</a>
                    <a href="<?= e(base_url('company/profile.php')) ?>" class="block text-sm hover:underline" style="color:var(--color-text-muted);text-decoration:none">Company Profile</a>
                </div>
            </div>
            <div>
                <h4 class="font-semibold text-sm mb-3 uppercase tracking-wider" style="color:var(--color-text-primary)">Resources</h4>
                <div class="space-y-2">
                    <a href="<?= e(base_url('index.php')) ?>" class="block text-sm hover:underline" style="color:var(--color-text-muted);text-decoration:none">Home</a>
                    <a href="<?= e(base_url('company/find_freelancers.php')) ?>" class="block text-sm hover:underline" style="color:var(--color-text-muted);text-decoration:none">Find Freelancers</a>
                    <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="block text-sm hover:underline" style="color:var(--color-text-muted);text-decoration:none">Browse Jobs</a>
                </div>
            </div>
            <div>
                <h4 class="font-semibold text-sm mb-3 uppercase tracking-wider" style="color:var(--color-text-primary)">Support</h4>
                <div class="space-y-2">
                    <a href="#" class="block text-sm hover:underline" style="color:var(--color-text-muted);text-decoration:none">Help Center</a>
                    <a href="#" class="block text-sm hover:underline" style="color:var(--color-text-muted);text-decoration:none">Terms of Service</a>
                    <a href="#" class="block text-sm hover:underline" style="color:var(--color-text-muted);text-decoration:none">Privacy Policy</a>
                </div>
            </div>
        </div>
        <div class="pt-6 border-t flex flex-col sm:flex-row items-center justify-between gap-3" style="border-color:var(--color-border)">
            <p class="text-sm" style="color:var(--color-text-muted)">&copy; <?= date('Y') ?> <?= e('FreelanceHub') ?>. All rights reserved.</p>
            <div class="flex items-center gap-4">
                <a href="#" class="text-sm hover:underline" style="color:var(--color-text-muted);text-decoration:none">Privacy</a>
                <a href="#" class="text-sm hover:underline" style="color:var(--color-text-muted);text-decoration:none">Terms</a>
                <a href="#" class="text-sm hover:underline" style="color:var(--color-text-muted);text-decoration:none">Cookies</a>
            </div>
        </div>
    </div>
</footer>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ── Scroll fade-in ──
    var fadeEls = document.querySelectorAll('.fade-in');
    var obs = new IntersectionObserver(function(entries) {
        entries.forEach(function(e) { if (e.isIntersecting) { e.target.classList.add('visible'); obs.unobserve(e.target); } });
    }, { threshold: 0.1 });
    fadeEls.forEach(function(el) { obs.observe(el); });

    // ── Freelancer Carousel (infinite loop) ──
    (function() {
        var wrapper = document.querySelector('.mh-carousel-wrap');
        var track = document.querySelector('.mh-carousel-track');
        var dotsContainer = document.querySelector('.mh-dots');
        var leftBtn = document.querySelector('.mh-carr-arrow-l');
        var rightBtn = document.querySelector('.mh-carr-arrow-r');
        var carousel = document.querySelector('.mh-carousel');
        if (!track || !wrapper || !carousel) return;

        var originals = Array.prototype.slice.call(track.querySelectorAll('.mh-fl-card'));
        var totalCards = originals.length;
        if (totalCards === 0) return;

        var cloneCount = Math.min(totalCards, 8);
        originals.forEach(function(card, i) { if (i < cloneCount) track.appendChild(card.cloneNode(true)); });
        for (var i = totalCards - 1; i >= totalCards - cloneCount && i >= 0; i--) { track.insertBefore(originals[i].cloneNode(true), track.firstChild); }

        var allCards = track.querySelectorAll('.mh-fl-card');
        var originalCount = totalCards;
        var frontPadding = cloneCount;
        var currentIndex = frontPadding;
        var autoSlideInterval = null;
        var AUTO_SLIDE_MS = 3000;
        var isTransitioning = false;

        function getVisibleCount() {
            var w = wrapper.offsetWidth;
            if (w < 500) return 1;
            if (w < 780) return 2;
            if (w < 1060) return 3;
            return 4;
        }
        function getCardWidth() { return allCards[0].offsetWidth + 16; }
        function setTrackPosition(idx, animate) {
            track.style.transition = animate ? 'transform 0.5s cubic-bezier(0.25,0.1,0.25,1)' : 'none';
            track.style.transform = 'translateX(-' + (idx * getCardWidth()) + 'px)';
        }
        function getLogicalIndex(idx) { return (((idx - frontPadding) % originalCount) + originalCount) % originalCount; }
        function updateDots() {
            var logical = getLogicalIndex(currentIndex);
            dotsContainer.querySelectorAll('.mh-dot').forEach(function(d, i) { d.classList.toggle('active', i === logical); });
        }
        function buildDots() {
            dotsContainer.innerHTML = '';
            for (var i = 0; i < originalCount; i++) {
                var dot = document.createElement('button');
                dot.className = 'mh-dot' + (i === 0 ? ' active' : '');
                dot.setAttribute('aria-label', 'Slide ' + (i + 1));
                (function(idx) { dot.addEventListener('click', function() { currentIndex = frontPadding + idx; setTrackPosition(currentIndex, true); updateDots(); resetAutoSlide(); }); })(i);
                dotsContainer.appendChild(dot);
            }
        }
        function snapIfNeeded() {
            var logical = currentIndex - frontPadding;
            if (logical >= originalCount) { currentIndex = frontPadding + (logical % originalCount); setTrackPosition(currentIndex, false); }
            else if (logical < 0) { currentIndex = frontPadding + ((logical % originalCount) + originalCount) % originalCount; setTrackPosition(currentIndex, false); }
        }
        function slideNext() { if (isTransitioning) return; isTransitioning = true; currentIndex++; setTrackPosition(currentIndex, true); updateDots(); }
        function slidePrev() { if (isTransitioning) return; isTransitioning = true; currentIndex--; setTrackPosition(currentIndex, true); updateDots(); }
        track.addEventListener('transitionend', function() { isTransitioning = false; snapIfNeeded(); });
        function startAutoSlide() { stopAutoSlide(); if (originalCount <= getVisibleCount()) return; autoSlideInterval = setInterval(slideNext, AUTO_SLIDE_MS); }
        function stopAutoSlide() { if (autoSlideInterval) { clearInterval(autoSlideInterval); autoSlideInterval = null; } }
        function resetAutoSlide() { stopAutoSlide(); startAutoSlide(); }

        window.mhCarousel = {
            next: function() { slideNext(); resetAutoSlide(); },
            prev: function() { slidePrev(); resetAutoSlide(); }
        };

        buildDots(); setTrackPosition(currentIndex, false); updateDots(); startAutoSlide();
        carousel.addEventListener('mouseenter', stopAutoSlide);
        carousel.addEventListener('mouseleave', startAutoSlide);
        carousel.addEventListener('focusin', stopAutoSlide);
        carousel.addEventListener('focusout', startAutoSlide);

        var resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() { setTrackPosition(currentIndex, false); buildDots(); updateDots(); resetAutoSlide(); }, 150);
        });

        var touchStartX = 0, touchDelta = 0;
        wrapper.addEventListener('touchstart', function(e) { touchStartX = e.touches[0].clientX; touchDelta = 0; stopAutoSlide(); }, { passive: true });
        wrapper.addEventListener('touchmove', function(e) { touchDelta = e.touches[0].clientX - touchStartX; }, { passive: true });
        wrapper.addEventListener('touchend', function() {
            if (touchDelta < -40) slideNext(); else if (touchDelta > 40) slidePrev(); startAutoSlide();
        });
    })();
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
