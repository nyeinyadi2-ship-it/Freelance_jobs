<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';

require_role('company');

$user = current_user();
$company_id = get_company_id($conn, (int) $user['user_id']);

if (!$company_id) {
    set_flash('error', 'Company profile not found.');
    redirect('auth/login.php');
}

// Get filter parameters
$search = trim($_GET['q'] ?? '');
$skill_filter = trim($_GET['skill'] ?? '');
$min_rate = isset($_GET['min_rate']) ? (float) $_GET['min_rate'] : null;
$max_rate = isset($_GET['max_rate']) ? (float) $_GET['max_rate'] : null;
$min_exp = isset($_GET['min_exp']) ? (int) $_GET['min_exp'] : null;
$availability = trim($_GET['availability'] ?? '');
$location_filter = trim($_GET['location'] ?? '');
$sort = $_GET['sort'] ?? 'rating';

// Fetch all skills for filter
$all_skills = [];
$sr = $conn->query('SELECT id, skill_name FROM skills ORDER BY skill_name');
if ($sr) {
    while ($r = $sr->fetch_assoc()) $all_skills[] = $r;
}

// Build main query
$where = ['u.role = "freelancer"'];
$params = [];
$types = '';

if ($search !== '') {
    $where[] = '(f.full_name LIKE ? OR f.title LIKE ? OR f.location LIKE ?)';
    $search_param = '%' . $search . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'sss';
}

if ($skill_filter !== '') {
    $where[] = 'f.id IN (SELECT fs.freelancer_id FROM freelancer_skills fs JOIN skills s ON fs.skill_id = s.id WHERE s.skill_name = ?)';
    $params[] = $skill_filter;
    $types .= 's';
}

if ($min_rate !== null) {
    $where[] = 'f.hourly_rate >= ?';
    $params[] = $min_rate;
    $types .= 'd';
}

if ($max_rate !== null) {
    $where[] = 'f.hourly_rate <= ?';
    $params[] = $max_rate;
    $types .= 'd';
}

if ($min_exp !== null) {
    $where[] = 'f.experience_years >= ?';
    $params[] = $min_exp;
    $types .= 'i';
}

if ($location_filter !== '') {
    $where[] = 'f.location LIKE ?';
    $params[] = '%' . $location_filter . '%';
    $types .= 's';
}

$where_sql = implode(' AND ', $where);

$order_by = match($sort) {
    'rate_low' => 'f.hourly_rate ASC',
    'rate_high' => 'f.hourly_rate DESC',
    'experience' => 'f.experience_years DESC',
    'name' => 'f.full_name ASC',
    default => 'avg_rating DESC, review_count DESC'
};

$sql = "SELECT f.id, f.full_name, f.title, f.hourly_rate, f.experience_years, f.location, f.bio,
               u.id AS user_id, u.profile_image, u.created_at,
               GROUP_CONCAT(DISTINCT s.skill_name ORDER BY s.skill_name SEPARATOR ', ') AS skills,
               COALESCE(AVG(r.rating), 0) AS avg_rating,
               COUNT(DISTINCT r.id) AS review_count
        FROM freelancers f
        JOIN users u ON f.user_id = u.id
        LEFT JOIN freelancer_skills fs ON fs.freelancer_id = f.id
        LEFT JOIN skills s ON fs.skill_id = s.id
        LEFT JOIN reviews r ON r.freelancer_id = f.id
        WHERE {$where_sql}
        GROUP BY f.id
        ORDER BY {$order_by}
        LIMIT 50";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$freelancers = [];
while ($row = $result->fetch_assoc()) {
    $freelancers[] = $row;
}
$stmt->close();

$page_title = 'Find Freelancers';
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= e(base_url('assets/css/company.css')) ?>">

<style>
/* ===== FIND FREELANCERS — PREMIUM DASHBOARD V2 ===== */
:root {
    --ff-blue: #1a73e8;
    --ff-blue-dark: #1557b0;
    --ff-blue-light: #e8f0fe;
    --ff-blue-50: #f0f7ff;
    --ff-green: #0d9488;
    --ff-green-light: #f0fdfa;
    --ff-amber: #f59e0b;
    --ff-border: #e5e7eb;
    --ff-bg: #f3f4f6;
    --ff-card: #ffffff;
    --ff-text: #111827;
    --ff-text-sec: #6b7280;
    --ff-text-muted: #9ca3af;
    --ff-radius: 14px;
    --ff-radius-lg: 18px;
    --ff-shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
    --ff-shadow: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.04);
    --ff-shadow-md: 0 4px 12px rgba(0,0,0,0.07);
    --ff-shadow-lg: 0 8px 30px rgba(0,0,0,0.08);
}
html.dark {
    --ff-blue: #4da6ff;
    --ff-blue-dark: #2b8ae0;
    --ff-blue-light: rgba(77,166,255,0.1);
    --ff-blue-50: rgba(77,166,255,0.08);
    --ff-green-light: rgba(13,148,136,0.1);
    --ff-border: #2d3748;
    --ff-bg: #111827;
    --ff-card: #1f2937;
    --ff-text: #f3f4f6;
    --ff-text-sec: #9ca3af;
    --ff-text-muted: #6b7280;
    --ff-shadow-sm: 0 1px 2px rgba(0,0,0,0.2);
    --ff-shadow: 0 1px 3px rgba(0,0,0,0.3);
    --ff-shadow-md: 0 4px 12px rgba(0,0,0,0.3);
    --ff-shadow-lg: 0 8px 30px rgba(0,0,0,0.3);
}

/* ===== HERO ===== */
.ff-hero {
    background: linear-gradient(135deg, #0b3d91 0%, #1a73e8 60%, #4da6ff 100%);
    border-radius: var(--ff-radius-lg);
    margin-bottom: 2rem;
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    min-height: 280px;
}
.ff-hero::before {
    content: '';
    position: absolute;
    inset: 0;
    background:
        radial-gradient(ellipse 500px 400px at 85% 50%, rgba(255,255,255,0.10) 0%, transparent 70%),
        radial-gradient(ellipse 300px 300px at 15% 80%, rgba(255,255,255,0.06) 0%, transparent 70%);
    pointer-events: none;
}
.ff-hero-inner {
    position: relative; z-index: 1;
    display: flex; align-items: center; gap: 2rem;
    padding: 2.5rem 2.5rem 2.5rem 3rem;
    width: 100%;
}
.ff-hero-text { flex: 1; min-width: 0; }
.ff-hero-text h1 {
    font-size: 2rem; font-weight: 800; color: #fff;
    margin-bottom: 0.5rem; line-height: 1.2; letter-spacing: -0.02em;
}
.ff-hero-text p {
    font-size: 1rem; color: rgba(255,255,255,0.8);
    margin-bottom: 0; line-height: 1.5;
}
.ff-hero-illustration {
    flex-shrink: 0; width: 320px; height: 220px;
    display: flex; align-items: center; justify-content: center;
    position: relative;
}
/* SVG illustration built with CSS shapes */
.ff-illust-card {
    position: absolute;
    background: rgba(255,255,255,0.15);
    backdrop-filter: blur(8px);
    border: 1px solid rgba(255,255,255,0.2);
    border-radius: 12px;
    display: flex; align-items: center; gap: 0.625rem;
    padding: 0.75rem 1rem;
    animation: ffFloat 4s ease-in-out infinite;
}
.ff-illust-card:nth-child(1) { top: 10px; left: 20px; animation-delay: 0s; }
.ff-illust-card:nth-child(2) { top: 70px; right: 0; animation-delay: 1s; }
.ff-illust-card:nth-child(3) { bottom: 20px; left: 40px; animation-delay: 2s; }
.ff-illust-avatar {
    width: 36px; height: 36px; border-radius: 50%;
    background: linear-gradient(135deg, rgba(255,255,255,0.4), rgba(255,255,255,0.15));
    display: flex; align-items: center; justify-content: center;
    font-size: 0.8rem; font-weight: 700; color: #fff;
}
.ff-illust-info { display: flex; flex-direction: column; gap: 2px; }
.ff-illust-name { font-size: 0.75rem; font-weight: 600; color: #fff; }
.ff-illust-role { font-size: 0.625rem; color: rgba(255,255,255,0.7); }
.ff-illust-dots {
    position: absolute;
    width: 120px; height: 120px;
    border-radius: 50%;
    border: 2px dashed rgba(255,255,255,0.15);
    top: 50%; left: 50%;
    transform: translate(-50%, -50%);
}
@keyframes ffFloat {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-8px); }
}

/* ===== SEARCH BAR (below hero) ===== */
.ff-search-bar {
    background: var(--ff-card);
    border: 1px solid var(--ff-border);
    border-radius: var(--ff-radius-lg);
    padding: 0.625rem;
    margin-top: -2rem;
    margin-bottom: 1.5rem;
    position: relative;
    z-index: 2;
    box-shadow: var(--ff-shadow-lg);
    display: flex;
    align-items: center;
    gap: 0;
}
.ff-search-bar .ff-sb-field {
    flex: 1; display: flex; align-items: center; gap: 0.5rem;
    padding: 0.625rem 1rem; min-width: 0;
}
.ff-search-bar .ff-sb-field svg { width: 18px; height: 18px; color: var(--ff-text-muted); flex-shrink: 0; }
.ff-search-bar .ff-sb-field input {
    width: 100%; border: none; background: transparent;
    font-size: 0.875rem; color: var(--ff-text); outline: none;
}
.ff-search-bar .ff-sb-field input::placeholder { color: var(--ff-text-muted); }
.ff-search-bar .ff-sb-sep { width: 1px; height: 28px; background: var(--ff-border); flex-shrink: 0; }
.ff-search-bar .ff-sb-select {
    padding: 0.5rem 2rem 0.5rem 0.875rem;
    border: none; background: transparent;
    color: var(--ff-text); font-size: 0.8125rem; outline: none;
    min-width: 140px; appearance: none; cursor: pointer;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' fill='%239ca3af' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10l-5 5z'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 0.75rem center;
}
.ff-search-bar .ff-sb-btn {
    display: inline-flex; align-items: center; gap: 0.5rem;
    padding: 0.75rem 2rem;
    background: var(--ff-blue); color: #fff;
    border: none; border-radius: 10px;
    font-size: 0.875rem; font-weight: 600;
    cursor: pointer; transition: all 0.2s;
    white-space: nowrap; flex-shrink: 0;
}
.ff-search-bar .ff-sb-btn:hover { background: var(--ff-blue-dark); box-shadow: 0 4px 12px rgba(26,115,232,0.35); }
.ff-search-bar .ff-sb-btn svg { width: 16px; height: 16px; }

/* ===== LAYOUT ===== */
.ff-layout {
    display: grid;
    grid-template-columns: 260px 1fr;
    gap: 1.5rem;
    align-items: start;
}

/* ===== LEFT SIDEBAR ===== */
.ff-sidebar { position: sticky; top: 5.5rem; display: flex; flex-direction: column; gap: 1rem; }
.ff-sidebar-card {
    background: var(--ff-card);
    border: 1px solid var(--ff-border);
    border-radius: var(--ff-radius);
    padding: 1.25rem;
    box-shadow: var(--ff-shadow-sm);
}
.ff-sidebar-hdr {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 1rem;
}
.ff-sidebar-hdr h3 { font-size: 0.9375rem; font-weight: 700; color: var(--ff-text); margin: 0; }
.ff-reset-link {
    font-size: 0.75rem; font-weight: 600; color: var(--ff-blue);
    text-decoration: none; transition: opacity 0.15s;
}
.ff-reset-link:hover { opacity: 0.7; }
.ff-fg { margin-bottom: 1.125rem; }
.ff-fg:last-child { margin-bottom: 0; }
.ff-fl { display: block; font-size: 0.8125rem; font-weight: 600; color: var(--ff-text); margin-bottom: 0.5rem; }
.ff-fi {
    width: 100%; padding: 0.5rem 0.75rem;
    border: 1px solid var(--ff-border); border-radius: 10px;
    background: var(--ff-bg); color: var(--ff-text);
    font-size: 0.8125rem; outline: none; transition: border-color 0.15s;
}
.ff-fi:focus { border-color: var(--ff-blue); box-shadow: 0 0 0 3px rgba(26,115,232,0.08); }
.ff-fs {
    width: 100%; padding: 0.5rem 0.75rem;
    border: 1px solid var(--ff-border); border-radius: 10px;
    background: var(--ff-bg); color: var(--ff-text);
    font-size: 0.8125rem; outline: none; appearance: none; cursor: pointer;
    transition: border-color 0.15s;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' fill='%239ca3af' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10l-5 5z'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 0.75rem center;
    padding-right: 2rem;
}
.ff-fs:focus { border-color: var(--ff-blue); box-shadow: 0 0 0 3px rgba(26,115,232,0.08); }

/* Range */
.ff-rng { position: relative; height: 28px; margin-top: 0.25rem; }
.ff-rng-track { position: absolute; top: 50%; left: 0; right: 0; height: 4px; background: var(--ff-border); border-radius: 2px; transform: translateY(-50%); }
.ff-rng-fill { position: absolute; top: 50%; height: 4px; background: var(--ff-blue); border-radius: 2px; transform: translateY(-50%); }
.ff-rng input[type="range"] {
    position: absolute; top: 0; left: 0; width: 100%; height: 100%;
    -webkit-appearance: none; appearance: none;
    background: transparent; pointer-events: none; margin: 0;
}
.ff-rng input[type="range"]::-webkit-slider-thumb {
    -webkit-appearance: none; width: 18px; height: 18px; border-radius: 50%;
    background: #fff; border: 2px solid var(--ff-blue); cursor: pointer;
    pointer-events: auto; box-shadow: 0 1px 4px rgba(0,0,0,0.15);
}
.ff-rng input[type="range"]::-moz-range-thumb {
    width: 18px; height: 18px; border-radius: 50%;
    background: #fff; border: 2px solid var(--ff-blue); cursor: pointer;
    pointer-events: auto; box-shadow: 0 1px 4px rgba(0,0,0,0.15);
}
.ff-rng-labels { display: flex; justify-content: space-between; font-size: 0.75rem; color: var(--ff-text-sec); margin-top: 0.125rem; }

/* Checkboxes */
.ff-cb-group { display: flex; flex-direction: column; gap: 0.5rem; }
.ff-cb-label { display: flex; align-items: center; gap: 0.5rem; font-size: 0.8125rem; color: var(--ff-text); cursor: pointer; }
.ff-cb-label input[type="checkbox"] { width: 16px; height: 16px; accent-color: var(--ff-blue); cursor: pointer; border-radius: 4px; }

/* Rating pills */
.ff-rp-group { display: flex; flex-wrap: wrap; gap: 0.375rem; }
.ff-rp {
    padding: 0.375rem 0.75rem;
    border: 1px solid var(--ff-border); border-radius: 9999px;
    font-size: 0.75rem; font-weight: 500;
    color: var(--ff-text-sec); background: var(--ff-bg);
    cursor: pointer; transition: all 0.15s; text-decoration: none;
}
.ff-rp:hover, .ff-rp.active { border-color: var(--ff-blue); color: var(--ff-blue); background: var(--ff-blue-light); }

/* Apply */
.ff-apply-btn {
    width: 100%; padding: 0.625rem;
    background: var(--ff-blue); color: #fff;
    border: none; border-radius: 10px;
    font-size: 0.8125rem; font-weight: 600;
    cursor: pointer; transition: all 0.2s;
    display: flex; align-items: center; justify-content: center; gap: 0.5rem;
    margin-top: 1rem;
}
.ff-apply-btn:hover { background: var(--ff-blue-dark); }
.ff-apply-btn svg { width: 15px; height: 15px; }

/* ===== RESULTS HEADER ===== */
.ff-results-hdr {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 1rem; flex-wrap: wrap; gap: 0.75rem;
}
.ff-results-hdr p { font-size: 0.875rem; color: var(--ff-text-sec); margin: 0; }
.ff-results-hdr strong { color: var(--ff-text); font-weight: 700; }
.ff-results-right { display: flex; align-items: center; gap: 0.5rem; }
.ff-sort-sel {
    padding: 0.375rem 0.75rem;
    border: 1px solid var(--ff-border); border-radius: 8px;
    background: var(--ff-card); color: var(--ff-text);
    font-size: 0.8125rem; outline: none; appearance: none; cursor: pointer;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' fill='%239ca3af' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10l-5 5z'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 0.5rem center;
    padding-right: 1.75rem;
}
.ff-view-btn {
    width: 34px; height: 34px;
    display: flex; align-items: center; justify-content: center;
    border: 1px solid var(--ff-border); border-radius: 8px;
    background: var(--ff-card); color: var(--ff-text-sec);
    cursor: pointer; transition: all 0.15s;
}
.ff-view-btn.active, .ff-view-btn:hover { border-color: var(--ff-blue); color: var(--ff-blue); background: var(--ff-blue-light); }
.ff-view-btn svg { width: 16px; height: 16px; }

/* ===== FREELANCER CARD ===== */
.ff-fl-card {
    background: var(--ff-card);
    border: 1px solid var(--ff-border);
    border-radius: var(--ff-radius);
    padding: 1.5rem;
    margin-bottom: 1rem;
    box-shadow: var(--ff-shadow-sm);
    transition: all 0.25s ease;
}
.ff-fl-card:hover {
    box-shadow: var(--ff-shadow-md);
    border-color: rgba(26,115,232,0.2);
}
.ff-fl-top { display: flex; align-items: flex-start; gap: 1.25rem; margin-bottom: 1rem; }
.ff-fl-avatar-wrap { flex-shrink: 0; position: relative; }
.ff-fl-avatar {
    width: 72px; height: 72px; border-radius: 50%;
    object-fit: cover; display: block;
    border: 3px solid var(--ff-blue-light);
}
.ff-fl-avatar-placeholder {
    width: 72px; height: 72px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-weight: 700; font-size: 1.375rem;
    background: linear-gradient(135deg, #1a73e8, #6366f1);
}
.ff-fl-avail-dot {
    position: absolute; bottom: 2px; right: 2px;
    width: 14px; height: 14px; border-radius: 50%;
    background: var(--ff-green); border: 2.5px solid var(--ff-card);
}
.ff-fl-info { flex: 1; min-width: 0; }
.ff-fl-name-row { display: flex; align-items: center; gap: 0.375rem; margin-bottom: 2px; }
.ff-fl-name { font-size: 1.0625rem; font-weight: 700; color: var(--ff-text); }
.ff-verified {
    width: 18px; height: 18px; border-radius: 50%;
    background: var(--ff-blue);
    display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.ff-verified svg { width: 10px; height: 10px; color: #fff; }
.ff-fl-title { font-size: 0.8125rem; color: var(--ff-text-sec); margin-bottom: 0.25rem; }
.ff-fl-location {
    display: inline-flex; align-items: center; gap: 0.25rem;
    font-size: 0.75rem; color: var(--ff-text-muted); margin-bottom: 0.625rem;
}
.ff-fl-location svg { width: 12px; height: 12px; }
.ff-fl-skills { display: flex; flex-wrap: wrap; gap: 0.375rem; }
.ff-fl-skill {
    padding: 0.25rem 0.625rem; border-radius: 9999px;
    font-size: 0.6875rem; font-weight: 500;
    background: var(--ff-blue-light); color: var(--ff-blue);
}
.ff-fl-more {
    padding: 0.25rem 0.5rem; border-radius: 9999px;
    font-size: 0.6875rem; font-weight: 600;
    background: var(--ff-blue-light); color: var(--ff-blue);
}

/* Card meta row */
.ff-fl-meta {
    display: flex; align-items: center; flex-wrap: wrap; gap: 1.5rem;
    padding-top: 1rem; border-top: 1px solid var(--ff-border);
}
.ff-fl-meta-item {
    display: flex; align-items: center; gap: 0.375rem;
    font-size: 0.8125rem; color: var(--ff-text-sec);
}
.ff-fl-meta-item svg { width: 16px; height: 16px; flex-shrink: 0; }
.ff-fl-meta-item.rating { color: var(--ff-amber); font-weight: 600; }
.ff-fl-meta-item.rating svg { color: var(--ff-amber); }
.ff-fl-meta-item.success { color: var(--ff-green); font-weight: 600; }
.ff-fl-meta-item.success svg { color: var(--ff-green); }
.ff-fl-meta-item.rate { font-weight: 700; color: var(--ff-text); }
.ff-fl-meta-item.rate span { font-weight: 400; color: var(--ff-text-muted); font-size: 0.75rem; }

/* Card actions */
.ff-fl-actions {
    display: flex; align-items: center; gap: 0.5rem;
    margin-left: auto; flex-shrink: 0;
}
.ff-fl-action {
    display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;
    padding: 0.5rem 1.125rem; border-radius: 10px;
    font-size: 0.8125rem; font-weight: 600;
    cursor: pointer; transition: all 0.2s;
    text-decoration: none; border: none; white-space: nowrap;
}
.ff-fl-action svg { width: 15px; height: 15px; }
.ff-fl-action.primary {
    background: var(--ff-blue); color: #fff;
}
.ff-fl-action.primary:hover { background: var(--ff-blue-dark); box-shadow: 0 4px 12px rgba(26,115,232,0.3); }
.ff-fl-action.outline {
    background: transparent; color: var(--ff-blue);
    border: 1px solid var(--ff-border);
}
.ff-fl-action.outline:hover { border-color: var(--ff-blue); background: var(--ff-blue-light); }

/* ===== RIGHT SIDEBAR ===== */
.ff-right-sidebar { position: sticky; top: 5.5rem; display: flex; flex-direction: column; gap: 1rem; }
.ff-post-cta {
    background: linear-gradient(145deg, #e8f0fe, #d2e3fc);
    border: 1px solid #b6d4fe;
    border-radius: var(--ff-radius);
    padding: 1.5rem;
    text-align: center;
}
html.dark .ff-post-cta {
    background: linear-gradient(145deg, rgba(26,115,232,0.12), rgba(99,102,241,0.08));
    border-color: rgba(77,166,255,0.2);
}
.ff-post-cta-icon {
    width: 52px; height: 52px; margin: 0 auto 0.75rem;
    background: var(--ff-blue); border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
}
.ff-post-cta-icon svg { width: 24px; height: 24px; color: #fff; }
.ff-post-cta h4 { font-size: 0.9375rem; font-weight: 700; color: var(--ff-text); margin-bottom: 0.375rem; }
.ff-post-cta p { font-size: 0.8125rem; color: var(--ff-text-sec); margin-bottom: 1rem; line-height: 1.5; }
.ff-post-cta-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;
    padding: 0.625rem 1.5rem; width: 100%;
    background: var(--ff-blue); color: #fff;
    border: none; border-radius: 10px;
    font-size: 0.875rem; font-weight: 600;
    cursor: pointer; text-decoration: none; transition: all 0.2s;
}
.ff-post-cta-btn:hover { background: var(--ff-blue-dark); }
.ff-post-cta-btn svg { width: 16px; height: 16px; }

.ff-right-card {
    background: var(--ff-card); border: 1px solid var(--ff-border);
    border-radius: var(--ff-radius); padding: 1.25rem;
    box-shadow: var(--ff-shadow-sm);
}
.ff-right-card h3 {
    font-size: 0.875rem; font-weight: 700; color: var(--ff-text);
    margin: 0 0 0.75rem; padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--ff-border);
}
.ff-right-card h3 .view-all {
    float: right; font-size: 0.75rem; font-weight: 600;
    color: var(--ff-blue); text-decoration: none;
}
.ff-right-card h3 .view-all:hover { text-decoration: underline; }

.ff-skill-list { display: flex; flex-direction: column; }
.ff-skill-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0.5rem 0.25rem; border-radius: 8px;
    text-decoration: none; transition: background 0.15s;
}
.ff-skill-row:hover { background: var(--ff-blue-light); }
.ff-skill-row + .ff-skill-row { border-top: 1px solid var(--ff-border); }
.ff-skill-row-name {
    display: flex; align-items: center; gap: 0.5rem;
    font-size: 0.8125rem; color: var(--ff-text); font-weight: 500;
}
.ff-skill-row-name svg { width: 15px; height: 15px; color: var(--ff-blue); }
.ff-skill-row-count {
    font-size: 0.75rem; font-weight: 600; color: var(--ff-blue);
}

.ff-invite-card {
    background: var(--ff-card); border: 1px solid var(--ff-border);
    border-radius: var(--ff-radius); padding: 1.25rem;
    text-align: center; box-shadow: var(--ff-shadow-sm);
}
.ff-invite-card h4 { font-size: 0.875rem; font-weight: 700; color: var(--ff-text); margin-bottom: 0.375rem; }
.ff-invite-card p { font-size: 0.8125rem; color: var(--ff-text-sec); margin-bottom: 0.75rem; line-height: 1.5; }
.ff-invite-btn {
    display: inline-flex; align-items: center; gap: 0.375rem;
    padding: 0.5rem 1.25rem;
    border: 1px solid var(--ff-border); border-radius: 10px;
    background: var(--ff-bg); color: var(--ff-text);
    font-size: 0.8125rem; font-weight: 600;
    cursor: pointer; text-decoration: none; transition: all 0.2s;
}
.ff-invite-btn:hover { border-color: var(--ff-blue); color: var(--ff-blue); background: var(--ff-blue-light); }
.ff-invite-btn svg { width: 16px; height: 16px; }

/* ===== EMPTY STATE ===== */
.ff-empty {
    text-align: center; padding: 4rem 1.5rem;
    background: var(--ff-card); border: 1px solid var(--ff-border);
    border-radius: var(--ff-radius); box-shadow: var(--ff-shadow-sm);
}
.ff-empty svg { width: 56px; height: 56px; margin: 0 auto 1rem; color: var(--ff-blue); opacity: 0.15; }
.ff-empty h3 { font-size: 1.0625rem; font-weight: 700; color: var(--ff-text); margin-bottom: 0.375rem; }
.ff-empty p { font-size: 0.875rem; color: var(--ff-text-sec); margin-bottom: 1.25rem; }

/* ===== RESPONSIVE ===== */
@media (max-width: 1200px) {
    .ff-layout { grid-template-columns: 240px 1fr; }
    .ff-hero-illustration { display: none; }
}
@media (max-width: 768px) {
    .ff-layout { grid-template-columns: 1fr !important; }
    .ff-sidebar { display: none; }
    .ff-sidebar.active {
        display: flex;
        position: fixed; top: 0; left: 0; right: 0; bottom: 0;
        z-index: 50; background: var(--ff-bg); overflow-y: auto; padding: 1rem;
    }
    .ff-hero-inner { padding: 1.5rem; }
    .ff-hero-text h1 { font-size: 1.5rem; }
    .ff-search-bar { flex-wrap: wrap; }
    .ff-search-bar .ff-sb-sep { display: none; }
    .ff-search-bar .ff-sb-select { min-width: 100%; border-top: 1px solid var(--ff-border); }
    .ff-search-bar .ff-sb-btn { width: 100%; }
    .ff-fl-top { flex-direction: column; }
    .ff-fl-meta { gap: 0.75rem; }
    .ff-fl-actions { margin-left: 0; margin-top: 0.5rem; width: 100%; }
    .ff-fl-action { flex: 1; }
    .ff-mobile-filter-btn { display: inline-flex !important; }
}
@media (prefers-reduced-motion: reduce) {
    * { animation: none !important; transition: none !important; }
}
</style>

<div style="max-width:1560px;margin:0 auto;padding-bottom:3rem">

    <!-- Hero Section with Illustration -->
    <div class="ff-hero">
        <div class="ff-hero-inner">
            <div class="ff-hero-text">
                <h1>Find Expert Freelancers</h1>
                <p>Discover top talent and build your dream team in minutes. Browse profiles, compare skills, and hire with confidence.</p>
            </div>
            <div class="ff-hero-illustration">
                <div class="ff-illust-dots"></div>
                <div class="ff-illust-card">
                    <div class="ff-illust-avatar">S</div>
                    <div class="ff-illust-info">
                        <span class="ff-illust-name">Sarah K.</span>
                        <span class="ff-illust-role">UI/UX Designer</span>
                    </div>
                </div>
                <div class="ff-illust-card">
                    <div class="ff-illust-avatar">J</div>
                    <div class="ff-illust-info">
                        <span class="ff-illust-name">James M.</span>
                        <span class="ff-illust-role">Full Stack Dev</span>
                    </div>
                </div>
                <div class="ff-illust-card">
                    <div class="ff-illust-avatar">A</div>
                    <div class="ff-illust-info">
                        <span class="ff-illust-name">Anna L.</span>
                        <span class="ff-illust-role">Data Scientist</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Search Bar -->
    <form method="GET" action="<?= e(base_url('company/find_freelancers.php')) ?>" id="ff-top-form">
        <div class="ff-search-bar">
            <div class="ff-sb-field">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search by name, skill or keyword...">
            </div>
            <div class="ff-sb-sep"></div>
            <select name="skill" class="ff-sb-select">
                <option value="">All Skills</option>
                <?php foreach ($all_skills as $sk): ?>
                    <option value="<?= e($sk['skill_name']) ?>" <?= $skill_filter === $sk['skill_name'] ? 'selected' : '' ?>><?= e($sk['skill_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="ff-sb-sep"></div>
            <select name="min_exp" class="ff-sb-select">
                <option value="">Experience Level</option>
                <option value="1" <?= $min_exp === 1 ? 'selected' : '' ?>>Entry Level</option>
                <option value="3" <?= $min_exp === 3 ? 'selected' : '' ?>>Intermediate</option>
                <option value="5" <?= $min_exp === 5 ? 'selected' : '' ?>>Expert</option>
                <option value="10" <?= $min_exp === 10 ? 'selected' : '' ?>>Master</option>
            </select>
            <input type="hidden" name="sort" value="<?= e($sort) ?>">
            <button type="submit" class="ff-sb-btn">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                Search
            </button>
        </div>
    </form>

    <!-- Main Layout -->
    <div class="ff-layout">

        <!-- LEFT SIDEBAR: Filters -->
        <aside class="ff-sidebar" id="ff-sidebar">
            <div class="ff-sidebar-card">
                <div class="ff-sidebar-hdr">
                    <h3>Filters</h3>
                    <a href="<?= e(base_url('company/find_freelancers.php')) ?>" class="ff-reset-link">Reset All</a>
                </div>
                <form method="GET" action="<?= e(base_url('company/find_freelancers.php')) ?>" id="ff-sidebar-form">
                    <input type="hidden" name="q" value="<?= e($search) ?>">
                    <input type="hidden" name="sort" value="<?= e($sort) ?>">

                    <div class="ff-fg">
                        <label class="ff-fl">Skills</label>
                        <select name="skill" class="ff-fs">
                            <option value="">All Skills</option>
                            <?php foreach ($all_skills as $sk): ?>
                                <option value="<?= e($sk['skill_name']) ?>" <?= $skill_filter === $sk['skill_name'] ? 'selected' : '' ?>><?= e($sk['skill_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="ff-fg">
                        <label class="ff-fl">Category</label>
                        <select name="category" class="ff-fs">
                            <option value="">All Categories</option>
                        </select>
                    </div>

                    <div class="ff-fg">
                        <label class="ff-fl">Experience Level</label>
                        <select name="min_exp" class="ff-fs">
                            <option value="">All Levels</option>
                            <option value="1" <?= $min_exp === 1 ? 'selected' : '' ?>>Entry Level (1+ yrs)</option>
                            <option value="3" <?= $min_exp === 3 ? 'selected' : '' ?>>Intermediate (3+ yrs)</option>
                            <option value="5" <?= $min_exp === 5 ? 'selected' : '' ?>>Expert (5+ yrs)</option>
                            <option value="10" <?= $min_exp === 10 ? 'selected' : '' ?>>Master (10+ yrs)</option>
                        </select>
                    </div>

                    <div class="ff-fg">
                        <label class="ff-fl">Hourly Rate</label>
                        <div class="ff-rng">
                            <div class="ff-rng-track"></div>
                            <div class="ff-rng-fill" id="ff-rate-fill"></div>
                            <input type="range" id="ff-rate-min" min="0" max="200" value="<?= $min_rate !== null ? e((string) $min_rate) : '0' ?>" oninput="ffUpdateRange()">
                            <input type="range" id="ff-rate-max" min="0" max="200" value="<?= $max_rate !== null ? e((string) $max_rate) : '200' ?>" oninput="ffUpdateRange()">
                        </div>
                        <div class="ff-rng-labels">
                            <span id="ff-rate-min-label"><?= $min_rate !== null ? e((string) $min_rate) : '0' ?> MMK</span>
                            <span id="ff-rate-max-label"><?= $max_rate !== null ? e((string) $max_rate) : '200' ?> MMK+</span>
                        </div>
                        <input type="hidden" name="min_rate" id="ff-rate-min-hidden" value="<?= $min_rate !== null ? e((string) $min_rate) : '' ?>">
                        <input type="hidden" name="max_rate" id="ff-rate-max-hidden" value="<?= $max_rate !== null ? e((string) $max_rate) : '' ?>">
                    </div>

                    <div class="ff-fg">
                        <label class="ff-fl">Location</label>
                        <select name="location" class="ff-fs">
                            <option value="">All Locations</option>
                            <?php
                            $locations = [];
                            $lr = $conn->query("SELECT DISTINCT location FROM freelancers WHERE location IS NOT NULL AND location != '' ORDER BY location");
                            if ($lr) { while ($r = $lr->fetch_assoc()) $locations[] = $r['location']; }
                            foreach ($locations as $loc):
                            ?>
                                <option value="<?= e($loc) ?>" <?= $location_filter === $loc ? 'selected' : '' ?>><?= e($loc) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="ff-fg">
                        <label class="ff-fl">Availability</label>
                        <div class="ff-cb-group">
                            <label class="ff-cb-label"><input type="checkbox" name="avail[]" value="now" <?= in_array('now', explode(',', $availability ?? '')) ? 'checked' : '' ?>> Available Now</label>
                            <label class="ff-cb-label"><input type="checkbox" name="avail[]" value="week" <?= in_array('week', explode(',', $availability ?? '')) ? 'checked' : '' ?>> This Week</label>
                            <label class="ff-cb-label"><input type="checkbox" name="avail[]" value="next_week" <?= in_array('next_week', explode(',', $availability ?? '')) ? 'checked' : '' ?>> Next Week</label>
                        </div>
                    </div>

                    <div class="ff-fg">
                        <label class="ff-fl">Rating</label>
                        <div class="ff-rp-group">
                            <a href="#" class="ff-rp active" onclick="ffSetRating(this,'')">All</a>
                            <a href="#" class="ff-rp" onclick="ffSetRating(this,'4')">4★+</a>
                            <a href="#" class="ff-rp" onclick="ffSetRating(this,'3')">3★+</a>
                            <a href="#" class="ff-rp" onclick="ffSetRating(this,'2')">2★+</a>
                            <a href="#" class="ff-rp" onclick="ffSetRating(this,'1')">1★+</a>
                        </div>
                        <input type="hidden" name="min_rating" id="ff-min-rating" value="">
                    </div>

                    <div class="ff-fg">
                        <label class="ff-fl">Job Success</label>
                        <div class="ff-rng">
                            <div class="ff-rng-track"></div>
                            <div class="ff-rng-fill" id="ff-success-fill"></div>
                            <input type="range" id="ff-success-min" min="0" max="100" value="0" oninput="ffUpdateSuccessRange()">
                        </div>
                        <div class="ff-rng-labels">
                            <span>Any</span>
                            <span id="ff-success-label">100%</span>
                        </div>
                    </div>

                    <button type="submit" class="ff-apply-btn">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                        Apply Filters
                    </button>
                </form>
            </div>
        </aside>

        <!-- CENTER: Results -->
        <div>
            <div class="ff-results-hdr">
                <p><strong><?= count($freelancers) ?></strong> freelancers found</p>
                <div class="ff-results-right">
                    <span style="font-size:0.8125rem;color:var(--ff-text-sec)">Sort by</span>
                    <select class="ff-sort-sel" onchange="ffSort(this.value)">
                        <option value="rating" <?= $sort === 'rating' ? 'selected' : '' ?>>Best Match</option>
                        <option value="rate_low" <?= $sort === 'rate_low' ? 'selected' : '' ?>>Rate: Low to High</option>
                        <option value="rate_high" <?= $sort === 'rate_high' ? 'selected' : '' ?>>Rate: High to Low</option>
                        <option value="experience" <?= $sort === 'experience' ? 'selected' : '' ?>>Most Experienced</option>
                        <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Name A-Z</option>
                    </select>
                    <button type="button" class="ff-view-btn active" title="List view">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>
                    <button type="button" class="ff-view-btn" title="Grid view">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1V5zm10 0a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zm10 0a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z"/></svg>
                    </button>
                    <button type="button" class="ff-view-btn ff-mobile-filter-btn" style="display:none" onclick="document.getElementById('ff-sidebar').classList.add('active')">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                    </button>
                </div>
            </div>

            <?php if (empty($freelancers)): ?>
                <div class="ff-empty">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    <h3>No freelancers found</h3>
                    <p>Try adjusting your filters or search terms to find the right talent.</p>
                    <a href="<?= e(base_url('company/find_freelancers.php')) ?>" class="ff-apply-btn" style="width:auto;display:inline-flex">Clear Filters</a>
                </div>
            <?php else: ?>
                <?php foreach ($freelancers as $fl): ?>
                    <?php
                        $flImg = $fl['profile_image'] ? base_url('uploads/images/' . $fl['profile_image']) : null;
                        $skills = array_slice(explode(', ', $fl['skills'] ?? ''), 0, 4);
                        $total_skills = count(explode(', ', $fl['skills'] ?? ''));
                        $rating = (float) $fl['avg_rating'];
                        $review_count = (int) $fl['review_count'];
                        $avail_text = $review_count > 20 ? 'Limited' : 'Available Now';
                    ?>
                    <div class="ff-fl-card">
                        <div class="ff-fl-top">
                            <!-- Avatar -->
                            <div class="ff-fl-avatar-wrap">
                                <?php if ($flImg): ?>
                                    <img src="<?= e($flImg) ?>" alt="<?= e($fl['full_name']) ?>" class="ff-fl-avatar">
                                <?php else: ?>
                                    <div class="ff-fl-avatar-placeholder"><?= e(strtoupper(substr($fl['full_name'], 0, 1))) ?></div>
                                <?php endif; ?>
                                <div class="ff-fl-avail-dot"></div>
                            </div>

                            <!-- Info -->
                            <div class="ff-fl-info">
                                <div class="ff-fl-name-row">
                                    <span class="ff-fl-name"><?= e($fl['full_name']) ?></span>
                                    <span class="ff-verified" title="Verified"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg></span>
                                </div>
                                <div class="ff-fl-title"><?= e($fl['title'] ?? 'Freelancer') ?></div>
                                <?php if ($fl['location']): ?>
                                    <div class="ff-fl-location">
                                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                        <?= e($fl['location']) ?>
                                    </div>
                                <?php endif; ?>
                                <div class="ff-fl-skills">
                                    <?php foreach ($skills as $sk): ?>
                                        <span class="ff-fl-skill"><?= e($sk) ?></span>
                                    <?php endforeach; ?>
                                    <?php if ($total_skills > 4): ?>
                                        <span class="ff-fl-more">+<?= $total_skills - 4 ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="ff-fl-actions">
                                <a href="<?= e(base_url('company/view_freelancer.php?id=' . $fl['id'])) ?>" class="ff-fl-action primary">
                                    View Profile
                                </a>
                                <a href="<?= e(base_url('company/view_freelancer.php?id=' . $fl['id'])) ?>" class="ff-fl-action outline">
                                    Hire Now
                                </a>
                            </div>
                        </div>

                        <!-- Meta Row -->
                        <div class="ff-fl-meta">
                            <?php if ($rating > 0): ?>
                                <div class="ff-fl-meta-item rating">
                                    <svg fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                                    <?= number_format($rating, 1) ?> (<?= $review_count ?> review<?= $review_count != 1 ? 's' : '' ?>)
                                </div>
                            <?php endif; ?>
                            <div class="ff-fl-meta-item success">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                100% Job Success
                            </div>
                            <?php if ($fl['hourly_rate']): ?>
                                <div class="ff-fl-meta-item rate">
                                    <?= e(number_format((float) $fl['hourly_rate'], 0)) ?> MMK <span>/hr</span>
                                </div>
                            <?php endif; ?>
                            <div class="ff-fl-meta-item" style="color:var(--ff-green);font-weight:500;font-size:0.75rem;">
                                <?= $avail_text ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>


    </div>
</div>

<script>
/* Range slider logic */
function ffUpdateRange() {
    var min = parseInt(document.getElementById('ff-rate-min').value);
    var max = parseInt(document.getElementById('ff-rate-max').value);
    if (min > max) { var tmp = min; min = max; max = tmp; }
    var pct1 = (min / 200) * 100;
    var pct2 = (max / 200) * 100;
    var fill = document.getElementById('ff-rate-fill');
    fill.style.left = pct1 + '%';
    fill.style.width = (pct2 - pct1) + '%';
    document.getElementById('ff-rate-min-label').textContent = min + ' MMK';
    document.getElementById('ff-rate-max-label').textContent = max + '+' + ' MMK';
    document.getElementById('ff-rate-min-hidden').value = min > 0 ? min : '';
    document.getElementById('ff-rate-max-hidden').value = max < 200 ? max : '';
}
function ffUpdateSuccessRange() {
    var val = document.getElementById('ff-success-min').value;
    document.getElementById('ff-success-label').textContent = val + '%';
    var fill = document.getElementById('ff-success-fill');
    fill.style.left = '0';
    fill.style.width = val + '%';
}
function ffSetRating(el, val) {
    document.querySelectorAll('.ff-rp').forEach(function(p) { p.classList.remove('active'); });
    el.classList.add('active');
    document.getElementById('ff-min-rating').value = val;
}
function ffSort(val) {
    var url = new URL(window.location.href);
    url.searchParams.set('sort', val);
    window.location.href = url.toString();
}
/* Init range sliders */
document.addEventListener('DOMContentLoaded', function() {
    ffUpdateRange();
    ffUpdateSuccessRange();
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
