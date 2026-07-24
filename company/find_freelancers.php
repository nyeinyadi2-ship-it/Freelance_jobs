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

// Fetch popular skills (top 12 by freelancer count)
$popular_skills = [];
$psr = $conn->query('SELECT s.skill_name, COUNT(fs.freelancer_id) AS cnt FROM freelancer_skills fs JOIN skills s ON fs.skill_id = s.id GROUP BY s.skill_name ORDER BY cnt DESC LIMIT 12');
if ($psr) {
    while ($r = $psr->fetch_assoc()) $popular_skills[] = $r;
}

// Fetch top rated freelancers for sidebar (top 5)
$top_rated = [];
$trr = $conn->query("SELECT f.id, f.full_name, f.title, f.hourly_rate, u.profile_image, COALESCE(AVG(r.rating),0) AS avg_rating FROM freelancers f JOIN users u ON f.user_id = u.id LEFT JOIN reviews r ON r.freelancer_id = f.id GROUP BY f.id ORDER BY avg_rating DESC, COUNT(r.id) DESC LIMIT 5");
if ($trr) {
    while ($r = $trr->fetch_assoc()) $top_rated[] = $r;
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
    default => 'COALESCE(AVG(r.rating), 0) DESC, review_count DESC'
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
/* ===== FIND FREELANCERS — MATCHED TO REFERENCE ===== */
:root {
    --ff-primary: #4f46e5;
    --ff-primary-dark: #4338ca;
    --ff-primary-light: #eef2ff;
    --ff-green: #10b981;
    --ff-amber: #f59e0b;
    --ff-border: #e5e7eb;
    --ff-bg: #f9fafb;
    --ff-card: #ffffff;
    --ff-text: #111827;
    --ff-text-secondary: #6b7280;
    --ff-radius: 0.75rem;
}
html.dark {
    --ff-primary: #818cf8;
    --ff-primary-dark: #6366f1;
    --ff-primary-light: rgba(99,102,241,0.1);
    --ff-border: #334155;
    --ff-bg: #0f172a;
    --ff-card: #1e293b;
    --ff-text: #f1f5f9;
    --ff-text-secondary: #94a3b8;
}

/* Page header */
.ff-page-header { margin-bottom: 1.5rem; }
.ff-page-header h1 { font-size: 1.5rem; font-weight: 700; color: var(--ff-text); margin-bottom: 0.125rem; }
.ff-page-header p { font-size: 0.875rem; color: var(--ff-text-secondary); }

/* Top search bar */
.ff-top-search {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    background: var(--ff-card);
    border: 1px solid var(--ff-border);
    border-radius: var(--ff-radius);
    padding: 0.625rem 0.75rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.ff-top-search .ff-search-field {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    min-width: 0;
}
.ff-top-search .ff-search-field svg { width: 18px; height: 18px; color: var(--ff-text-secondary); flex-shrink: 0; }
.ff-top-search .ff-search-field input {
    width: 100%;
    border: none;
    background: transparent;
    font-size: 0.875rem;
    color: var(--ff-text);
    outline: none;
}
.ff-top-search .ff-search-field input::placeholder { color: #9ca3af; }
.ff-top-divider { width: 1px; height: 28px; background: var(--ff-border); flex-shrink: 0; }
.ff-top-select {
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--ff-border);
    border-radius: 0.5rem;
    background: var(--ff-bg);
    color: var(--ff-text);
    font-size: 0.8125rem;
    outline: none;
    min-width: 140px;
    appearance: none;
    cursor: pointer;
}
.ff-top-select:focus { border-color: var(--ff-primary); }
.ff-top-search-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.625rem 1.5rem;
    background: var(--ff-primary);
    color: #fff;
    border: none;
    border-radius: 0.5rem;
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
    white-space: nowrap;
}
.ff-top-search-btn:hover { background: var(--ff-primary-dark); }
.ff-save-search {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.625rem 1rem;
    border: 1px solid var(--ff-border);
    border-radius: 0.5rem;
    background: var(--ff-card);
    color: var(--ff-text-secondary);
    font-size: 0.8125rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
    white-space: nowrap;
    text-decoration: none;
}
.ff-save-search:hover { border-color: var(--ff-primary); color: var(--ff-primary); }
.ff-save-search svg { width: 16px; height: 16px; }

/* Layout */
.ff-layout {
    display: grid;
    grid-template-columns: 280px 1fr 240px;
    gap: 1.25rem;
    align-items: start;
}

/* Left sidebar */
.ff-sidebar { position: sticky; top: 5.5rem; }
.ff-sidebar-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1rem;
}
.ff-sidebar-header h2 { font-size: 1rem; font-weight: 700; color: var(--ff-text); }
.ff-reset-btn {
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--ff-primary);
    background: none;
    border: none;
    cursor: pointer;
    text-decoration: none;
}
.ff-reset-btn:hover { text-decoration: underline; }
.ff-filter-group { margin-bottom: 1.25rem; }
.ff-filter-group:last-child { margin-bottom: 0; }
.ff-filter-label {
    display: block;
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--ff-text);
    margin-bottom: 0.5rem;
}
.ff-filter-input {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--ff-border);
    border-radius: 0.5rem;
    background: var(--ff-card);
    color: var(--ff-text);
    font-size: 0.8125rem;
    outline: none;
}
.ff-filter-input:focus { border-color: var(--ff-primary); box-shadow: 0 0 0 3px rgba(79,70,229,0.1); }
.ff-filter-input::placeholder { color: #9ca3af; }
.ff-filter-select {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--ff-border);
    border-radius: 0.5rem;
    background: var(--ff-card);
    color: var(--ff-text);
    font-size: 0.8125rem;
    outline: none;
    appearance: none;
    cursor: pointer;
}
.ff-filter-select:focus { border-color: var(--ff-primary); box-shadow: 0 0 0 3px rgba(79,70,229,0.1); }

/* Range slider (dual handle) */
.ff-range-wrap { position: relative; height: 32px; margin-top: 0.25rem; }
.ff-range-track {
    position: absolute;
    top: 50%;
    left: 0;
    right: 0;
    height: 4px;
    background: var(--ff-border);
    border-radius: 2px;
    transform: translateY(-50%);
}
.ff-range-fill {
    position: absolute;
    top: 50%;
    height: 4px;
    background: var(--ff-primary);
    border-radius: 2px;
    transform: translateY(-50%);
}
.ff-range-wrap input[type="range"] {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    -webkit-appearance: none;
    appearance: none;
    background: transparent;
    pointer-events: none;
    margin: 0;
}
.ff-range-wrap input[type="range"]::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: #fff;
    border: 2px solid var(--ff-primary);
    cursor: pointer;
    pointer-events: auto;
    box-shadow: 0 1px 4px rgba(0,0,0,0.15);
}
.ff-range-wrap input[type="range"]::-moz-range-thumb {
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: #fff;
    border: 2px solid var(--ff-primary);
    cursor: pointer;
    pointer-events: auto;
    box-shadow: 0 1px 4px rgba(0,0,0,0.15);
}
.ff-range-labels {
    display: flex;
    justify-content: space-between;
    font-size: 0.75rem;
    color: var(--ff-text-secondary);
    margin-top: 0.25rem;
}

/* Checkboxes */
.ff-checkbox-group { display: flex; flex-direction: column; gap: 0.5rem; }
.ff-checkbox-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.8125rem;
    color: var(--ff-text);
    cursor: pointer;
}
.ff-checkbox-label input[type="checkbox"] {
    width: 16px;
    height: 16px;
    accent-color: var(--ff-primary);
    cursor: pointer;
}

/* Rating pills */
.ff-rating-pills { display: flex; flex-wrap: wrap; gap: 0.375rem; }
.ff-rating-pill {
    padding: 0.375rem 0.625rem;
    border: 1px solid var(--ff-border);
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 500;
    color: var(--ff-text-secondary);
    background: var(--ff-card);
    cursor: pointer;
    transition: all 0.15s;
    text-decoration: none;
}
.ff-rating-pill:hover, .ff-rating-pill.active {
    border-color: var(--ff-primary);
    color: var(--ff-primary);
    background: var(--ff-primary-light);
}

/* Apply button */
.ff-apply-btn {
    width: 100%;
    padding: 0.625rem;
    background: var(--ff-primary);
    color: #fff;
    border: none;
    border-radius: 0.5rem;
    font-size: 0.8125rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.375rem;
    margin-top: 1rem;
}
.ff-apply-btn:hover { background: var(--ff-primary-dark); }
.ff-apply-btn svg { width: 16px; height: 16px; }

/* Sort bar */
.ff-sort-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1rem;
}
.ff-result-count { font-size: 0.875rem; color: var(--ff-text-secondary); }
.ff-result-count strong { color: var(--ff-primary); font-weight: 700; }
.ff-sort-right { display: flex; align-items: center; gap: 0.5rem; }
.ff-sort-select {
    padding: 0.375rem 0.75rem;
    border: 1px solid var(--ff-border);
    border-radius: 0.5rem;
    background: var(--ff-card);
    color: var(--ff-text);
    font-size: 0.8125rem;
    outline: none;
    appearance: none;
    cursor: pointer;
}
.ff-view-toggle { display: flex; gap: 0.25rem; }
.ff-view-btn {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--ff-border);
    border-radius: 0.375rem;
    background: var(--ff-card);
    color: var(--ff-text-secondary);
    cursor: pointer;
    transition: all 0.15s;
}
.ff-view-btn.active, .ff-view-btn:hover {
    border-color: var(--ff-primary);
    color: var(--ff-primary);
    background: var(--ff-primary-light);
}
.ff-view-btn svg { width: 16px; height: 16px; }

/* Freelancer card — horizontal layout */
.ff-fl-card {
    display: flex;
    align-items: stretch;
    gap: 1rem;
    background: var(--ff-card);
    border: 1px solid var(--ff-border);
    border-radius: var(--ff-radius);
    padding: 1rem 1.25rem;
    margin-bottom: 0.5rem;
    transition: box-shadow 0.25s ease, border-color 0.25s ease, transform 0.25s ease;
}
.ff-fl-card:hover {
    box-shadow: 0 6px 20px rgba(79,70,229,0.08), 0 2px 6px rgba(0,0,0,0.04);
    border-color: rgba(79,70,229,0.25);
    transform: translateY(-2px);
}
.ff-fl-avatar {
    width: 76px;
    height: 76px;
    border-radius: 50%;
    object-fit: cover;
    flex-shrink: 0;
    border: 2px solid var(--ff-border);
    align-self: center;
}
.ff-fl-avatar-placeholder {
    width: 76px;
    height: 76px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-weight: 700;
    font-size: 1.375rem;
    flex-shrink: 0;
    align-self: center;
    background: linear-gradient(135deg, var(--ff-primary), #7c3aed);
}
.ff-fl-body { flex: 1; min-width: 0; display: flex; flex-direction: column; justify-content: center; }
.ff-fl-name-row { display: flex; align-items: center; gap: 0.375rem; margin-bottom: 2px; }
.ff-fl-name { font-size: 1rem; font-weight: 700; color: var(--ff-text); }
.ff-verified {
    width: 17px; height: 17px; border-radius: 50%; background: var(--ff-primary);
    display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.ff-verified svg { width: 10px; height: 10px; color: #fff; }
.ff-fl-title { font-size: 0.8125rem; color: var(--ff-text-secondary); margin-bottom: 2px; }
.ff-fl-location {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    font-size: 0.75rem;
    color: var(--ff-text-secondary);
    margin-bottom: 0.375rem;
}
.ff-fl-location svg { width: 12px; height: 12px; }
.ff-fl-skills { display: flex; flex-wrap: wrap; gap: 0.3rem; }
.ff-fl-skill {
    padding: 0.2rem 0.5rem;
    border-radius: 9999px;
    font-size: 0.6875rem;
    font-weight: 500;
    background: var(--ff-bg);
    color: var(--ff-text-secondary);
    border: 1px solid var(--ff-border);
}
.ff-fl-more {
    padding: 0.2rem 0.5rem;
    border-radius: 9999px;
    font-size: 0.6875rem;
    font-weight: 500;
    background: var(--ff-primary-light);
    color: var(--ff-primary);
}

/* Stats column */
.ff-fl-stats {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    justify-content: center;
    gap: 4px;
    flex-shrink: 0;
    min-width: 140px;
}
.ff-fl-stat {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    font-size: 0.8125rem;
    color: var(--ff-text-secondary);
    white-space: nowrap;
}
.ff-fl-stat svg { width: 13px; height: 13px; flex-shrink: 0; }
.ff-fl-stat.rating { color: var(--ff-amber); font-weight: 600; }
.ff-fl-stat.rating svg { color: var(--ff-amber); }
.ff-fl-rate {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--ff-primary);
}
.ff-fl-rate span { font-size: 0.75rem; font-weight: 400; color: var(--ff-text-secondary); }
.ff-fl-avail {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    font-size: 0.75rem;
    font-weight: 500;
    color: var(--ff-green);
}
.ff-fl-avail-dot {
    width: 6px; height: 6px; border-radius: 50%; background: var(--ff-green);
}

/* Action column */
.ff-fl-actions {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
    flex-shrink: 0;
    min-width: 130px;
    justify-content: center;
}
.ff-fl-action {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.375rem;
    padding: 0.4rem 0.75rem;
    border-radius: 0.5rem;
    font-size: 0.8125rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    border: none;
    white-space: nowrap;
    width: 100%;
}
.ff-fl-action svg { width: 14px; height: 14px; }
.ff-fl-action.primary {
    background: var(--ff-primary);
    color: #fff;
}
.ff-fl-action.primary:hover { background: var(--ff-primary-dark); }
.ff-fl-action.outline {
    background: transparent;
    color: var(--ff-primary);
    border: 1px solid var(--ff-border);
}
.ff-fl-action.outline:hover { border-color: var(--ff-primary); background: var(--ff-primary-light); }
.ff-fl-action.text {
    background: transparent;
    color: var(--ff-text-secondary);
}
.ff-fl-action.text:hover { color: var(--ff-primary); }
.ff-fl-action.text svg { width: 14px; height: 14px; }

/* Right sidebar */
.ff-right-sidebar { position: sticky; top: 5.5rem; }
.ff-right-card {
    background: var(--ff-card);
    border: 1px solid var(--ff-border);
    border-radius: var(--ff-radius);
    padding: 1rem;
    margin-bottom: 0.75rem;
}
.ff-right-card h3 {
    font-size: 0.875rem;
    font-weight: 700;
    color: var(--ff-text);
    margin-bottom: 0.625rem;
}
.ff-right-card h3 .view-all {
    float: right;
    font-size: 0.75rem;
    font-weight: 500;
    color: var(--ff-primary);
    text-decoration: none;
}
.ff-right-card h3 .view-all:hover { text-decoration: underline; }

/* Post a Job CTA */
.ff-post-cta {
    background: linear-gradient(135deg, #eef2ff, #e0e7ff);
    border: 1px solid #c7d2fe;
    border-radius: var(--ff-radius);
    padding: 1.25rem;
    text-align: center;
    margin-bottom: 0.75rem;
}
html.dark .ff-post-cta {
    background: linear-gradient(135deg, rgba(99,102,241,0.1), rgba(124,58,237,0.1));
    border-color: rgba(99,102,241,0.2);
}
.ff-post-cta-icon {
    width: 52px;
    height: 52px;
    margin: 0 auto 0.625rem;
    background: var(--ff-primary);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}
.ff-post-cta-icon svg { width: 24px; height: 24px; color: #fff; }
.ff-post-cta h4 { font-size: 0.9375rem; font-weight: 700; color: var(--ff-text); margin-bottom: 0.25rem; }
.ff-post-cta p { font-size: 0.75rem; color: var(--ff-text-secondary); margin-bottom: 0.75rem; line-height: 1.5; }
.ff-post-cta-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.625rem 1.5rem;
    background: var(--ff-primary);
    color: #fff;
    border: none;
    border-radius: 0.5rem;
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: background 0.2s;
    width: 100%;
    justify-content: center;
}
.ff-post-cta-btn:hover { background: var(--ff-primary-dark); }
.ff-post-cta-btn svg { width: 16px; height: 16px; }

/* Popular skills list */
.ff-skill-list { display: flex; flex-direction: column; gap: 0; }
.ff-skill-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px solid var(--ff-border);
    text-decoration: none;
    transition: opacity 0.15s;
}
.ff-skill-row:last-child { border-bottom: none; }
.ff-skill-row:hover { opacity: 0.7; }
.ff-skill-row-name {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.8125rem;
    color: var(--ff-text);
    font-weight: 500;
}
.ff-skill-row-name svg { width: 16px; height: 16px; color: var(--ff-primary); }
.ff-skill-row-count { font-size: 0.8125rem; color: var(--ff-text-secondary); }

/* Invite card */
.ff-invite-card {
    background: var(--ff-card);
    border: 1px solid var(--ff-border);
    border-radius: var(--ff-radius);
    padding: 1rem;
    text-align: center;
}
.ff-invite-card h4 { font-size: 0.875rem; font-weight: 700; color: var(--ff-text); margin-bottom: 0.25rem; }
.ff-invite-card p { font-size: 0.75rem; color: var(--ff-text-secondary); margin-bottom: 0.625rem; line-height: 1.5; }
.ff-invite-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.5rem 1.25rem;
    border: 1px solid var(--ff-border);
    border-radius: 0.5rem;
    background: var(--ff-card);
    color: var(--ff-text);
    font-size: 0.8125rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s;
}
.ff-invite-btn:hover { border-color: var(--ff-primary); color: var(--ff-primary); }
.ff-invite-btn svg { width: 16px; height: 16px; }

/* Empty state */
.ff-empty {
    text-align: center;
    padding: 4rem 1rem;
    background: var(--ff-card);
    border: 1px solid var(--ff-border);
    border-radius: var(--ff-radius);
}
.ff-empty svg { width: 64px; height: 64px; margin: 0 auto 1rem; color: var(--ff-text-secondary); opacity: 0.3; }
.ff-empty h3 { font-size: 1.125rem; font-weight: 700; color: var(--ff-text); margin-bottom: 0.375rem; }
.ff-empty p { font-size: 0.875rem; color: var(--ff-text-secondary); margin-bottom: 1rem; }

/* Responsive */
@media (max-width: 1100px) {
    .ff-layout { grid-template-columns: 260px 1fr; }
    .ff-right-sidebar { display: none; }
}
@media (max-width: 768px) {
    .ff-layout { grid-template-columns: 1fr !important; }
    .ff-sidebar { display: none; }
    .ff-sidebar.active {
        display: block;
        position: fixed; top: 0; left: 0; right: 0; bottom: 0;
        z-index: 50; background: var(--ff-bg); overflow-y: auto; padding: 1rem;
    }
    .ff-top-search { flex-wrap: wrap; }
    .ff-top-divider { display: none; }
    .ff-fl-card { flex-direction: column; align-items: flex-start; gap: 0.75rem; padding: 1rem; }
    .ff-fl-avatar, .ff-fl-avatar-placeholder { width: 64px; height: 64px; }
    .ff-fl-body { justify-content: flex-start; }
    .ff-fl-stats { flex-direction: row; flex-wrap: wrap; align-items: center; min-width: 0; justify-content: flex-start; }
    .ff-fl-actions { flex-direction: row; min-width: 0; justify-content: flex-start; }
    .ff-fl-action { flex: 1; }
    .ff-mobile-filter-btn { display: inline-flex !important; }
}

@media (prefers-reduced-motion: reduce) {
    * { transition: none !important; }
}
</style>

<div style="max-width:1560px;margin:0 auto;padding-bottom:3rem">

    <!-- Page Header -->
    <div class="ff-page-header">
        <h1>Find Freelancers</h1>
        <p>Find the perfect talent for your project</p>
    </div>

    <!-- Top Search Bar -->
    <form method="GET" action="<?= e(base_url('company/find_freelancers.php')) ?>" id="ff-top-form">
        <div class="ff-top-search">
            <div class="ff-search-field">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search by name, skill or keyword...">
            </div>
            <div class="ff-top-divider"></div>
            <select name="skill" class="ff-top-select">
                <option value="">All Skills</option>
                <?php foreach ($all_skills as $sk): ?>
                    <option value="<?= e($sk['skill_name']) ?>" <?= $skill_filter === $sk['skill_name'] ? 'selected' : '' ?>><?= e($sk['skill_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="ff-top-divider"></div>
            <select name="min_exp" class="ff-top-select">
                <option value="">All Levels</option>
                <option value="1" <?= $min_exp === 1 ? 'selected' : '' ?>>Entry Level</option>
                <option value="3" <?= $min_exp === 3 ? 'selected' : '' ?>>Intermediate</option>
                <option value="5" <?= $min_exp === 5 ? 'selected' : '' ?>>Expert</option>
                <option value="10" <?= $min_exp === 10 ? 'selected' : '' ?>>Master</option>
            </select>
            <input type="hidden" name="sort" value="<?= e($sort) ?>">
            <button type="submit" class="ff-top-search-btn">
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                Search
            </button>
        </div>
    </form>
    <div style="display:flex;justify-content:flex-end;margin-bottom:1.25rem">
        <a href="#" class="ff-save-search">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z"/></svg>
            Save Search
        </a>
    </div>

    <!-- Main Layout -->
    <div class="ff-layout">

        <!-- LEFT SIDEBAR: Filters -->
        <aside class="ff-sidebar" id="ff-sidebar">
            <div class="ff-sidebar-header">
                <h2>Filters</h2>
                <a href="<?= e(base_url('company/find_freelancers.php')) ?>" class="ff-reset-btn">Reset</a>
            </div>

            <form method="GET" action="<?= e(base_url('company/find_freelancers.php')) ?>" id="ff-sidebar-form">
                <input type="hidden" name="q" value="<?= e($search) ?>">
                <input type="hidden" name="sort" value="<?= e($sort) ?>">

                <!-- Skills search -->
                <div class="ff-filter-group">
                    <label class="ff-filter-label">Skills</label>
                    <div style="position:relative">
                        <svg style="position:absolute;left:0.625rem;top:50%;transform:translateY(-50%);width:14px;height:14px;color:#9ca3af" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        <select name="skill" class="ff-filter-input" style="padding-left:2rem">
                            <option value="">All Skills</option>
                            <?php foreach ($all_skills as $sk): ?>
                                <option value="<?= e($sk['skill_name']) ?>" <?= $skill_filter === $sk['skill_name'] ? 'selected' : '' ?>><?= e($sk['skill_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Category (placeholder) -->
                <div class="ff-filter-group">
                    <label class="ff-filter-label">Category</label>
                    <select name="category" class="ff-filter-select">
                        <option value="">All Categories</option>
                    </select>
                </div>

                <!-- Experience Level -->
                <div class="ff-filter-group">
                    <label class="ff-filter-label">Experience Level</label>
                    <select name="min_exp" class="ff-filter-select">
                        <option value="">All Levels</option>
                        <option value="1" <?= $min_exp === 1 ? 'selected' : '' ?>>Entry Level (1+ yrs)</option>
                        <option value="3" <?= $min_exp === 3 ? 'selected' : '' ?>>Intermediate (3+ yrs)</option>
                        <option value="5" <?= $min_exp === 5 ? 'selected' : '' ?>>Expert (5+ yrs)</option>
                        <option value="10" <?= $min_exp === 10 ? 'selected' : '' ?>>Master (10+ yrs)</option>
                    </select>
                </div>

                <!-- Hourly Rate range -->
                <div class="ff-filter-group">
                    <label class="ff-filter-label">Hourly Rate</label>
                    <div class="ff-range-wrap">
                        <div class="ff-range-track"></div>
                        <div class="ff-range-fill" id="ff-rate-fill"></div>
                        <input type="range" id="ff-rate-min" min="0" max="200" value="<?= $min_rate !== null ? e((string) $min_rate) : '0' ?>" oninput="ffUpdateRange()">
                        <input type="range" id="ff-rate-max" min="0" max="200" value="<?= $max_rate !== null ? e((string) $max_rate) : '200' ?>" oninput="ffUpdateRange()">
                    </div>
                    <div class="ff-range-labels">
                        <span id="ff-rate-min-label">$<?= $min_rate !== null ? e((string) $min_rate) : '0' ?></span>
                        <span id="ff-rate-max-label">$<?= $max_rate !== null ? e((string) $max_rate) : '200' ?>+</span>
                    </div>
                    <input type="hidden" name="min_rate" id="ff-rate-min-hidden" value="<?= $min_rate !== null ? e((string) $min_rate) : '' ?>">
                    <input type="hidden" name="max_rate" id="ff-rate-max-hidden" value="<?= $max_rate !== null ? e((string) $max_rate) : '' ?>">
                </div>

                <!-- Location -->
                <div class="ff-filter-group">
                    <label class="ff-filter-label">Location</label>
                    <select name="location" class="ff-filter-select">
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

                <!-- Availability -->
                <div class="ff-filter-group">
                    <label class="ff-filter-label">Availability</label>
                    <div class="ff-checkbox-group">
                        <label class="ff-checkbox-label"><input type="checkbox" name="avail[]" value="now" <?= in_array('now', explode(',', $availability ?? '')) ? 'checked' : '' ?>> Available Now</label>
                        <label class="ff-checkbox-label"><input type="checkbox" name="avail[]" value="week" <?= in_array('week', explode(',', $availability ?? '')) ? 'checked' : '' ?>> Available This Week</label>
                        <label class="ff-checkbox-label"><input type="checkbox" name="avail[]" value="next_week" <?= in_array('next_week', explode(',', $availability ?? '')) ? 'checked' : '' ?>> Available Next Week</label>
                    </div>
                </div>

                <!-- Rating -->
                <div class="ff-filter-group">
                    <label class="ff-filter-label">Rating</label>
                    <div class="ff-rating-pills">
                        <a href="#" class="ff-rating-pill active" onclick="ffSetRating(this,'')">All</a>
                        <a href="#" class="ff-rating-pill" onclick="ffSetRating(this,'4')">4★ & Up</a>
                        <a href="#" class="ff-rating-pill" onclick="ffSetRating(this,'3')">3★ & Up</a>
                        <a href="#" class="ff-rating-pill" onclick="ffSetRating(this,'2')">2★ & Up</a>
                        <a href="#" class="ff-rating-pill" onclick="ffSetRating(this,'1')">1★ & Up</a>
                    </div>
                    <input type="hidden" name="min_rating" id="ff-min-rating" value="">
                </div>

                <!-- Job Success -->
                <div class="ff-filter-group">
                    <label class="ff-filter-label">Job Success</label>
                    <div class="ff-range-wrap">
                        <div class="ff-range-track"></div>
                        <div class="ff-range-fill" id="ff-success-fill"></div>
                        <input type="range" id="ff-success-min" min="0" max="100" value="0" oninput="ffUpdateSuccessRange()">
                    </div>
                    <div class="ff-range-labels">
                        <span>Any</span>
                        <span id="ff-success-label">100%</span>
                    </div>
                </div>

                <button type="submit" class="ff-apply-btn">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                    Apply Filters
                </button>
            </form>
        </aside>

        <!-- CENTER: Results -->
        <div>
            <div class="ff-sort-bar">
                <p class="ff-result-count"><strong><?= count($freelancers) ?></strong> Freelancers Found</p>
                <div class="ff-sort-right">
                    <span style="font-size:0.8125rem;color:var(--ff-text-secondary)">Sort by</span>
                    <select class="ff-sort-select" onchange="ffSort(this.value)">
                        <option value="rating" <?= $sort === 'rating' ? 'selected' : '' ?>>Best Match</option>
                        <option value="rate_low" <?= $sort === 'rate_low' ? 'selected' : '' ?>>Rate: Low to High</option>
                        <option value="rate_high" <?= $sort === 'rate_high' ? 'selected' : '' ?>>Rate: High to Low</option>
                        <option value="experience" <?= $sort === 'experience' ? 'selected' : '' ?>>Most Experienced</option>
                        <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Name A-Z</option>
                    </select>
                    <div class="ff-view-toggle">
                        <button type="button" class="ff-view-btn active" title="List view">
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                        </button>
                        <button type="button" class="ff-view-btn" title="Grid view">
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1V5zm10 0a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zm10 0a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z"/></svg>
                        </button>
                    </div>
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
                        <!-- Avatar -->
                        <?php if ($flImg): ?>
                            <img src="<?= e($flImg) ?>" alt="<?= e($fl['full_name']) ?>" class="ff-fl-avatar">
                        <?php else: ?>
                            <div class="ff-fl-avatar-placeholder"><?= e(strtoupper(substr($fl['full_name'], 0, 1))) ?></div>
                        <?php endif; ?>

                        <!-- Body -->
                        <div class="ff-fl-body">
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

                        <!-- Stats -->
                        <div class="ff-fl-stats">
                            <?php if ($rating > 0): ?>
                                <div class="ff-fl-stat rating">
                                    <svg fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                                    <?= number_format($rating, 1) ?> <span style="color:var(--ff-text-secondary);font-weight:400">(<?= $review_count ?> review<?= $review_count != 1 ? 's' : '' ?>)</span>
                                </div>
                            <?php endif; ?>
                            <div class="ff-fl-stat">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                100% Job Success
                            </div>
                            <?php if ($fl['hourly_rate']): ?>
                                <div class="ff-fl-rate">$<?= e(number_format((float) $fl['hourly_rate'], 0)) ?> <span>/hr</span></div>
                            <?php endif; ?>
                            <div class="ff-fl-avail">
                                <span class="ff-fl-avail-dot"></span>
                                <?= $avail_text ?>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="ff-fl-actions">
                            <a href="<?= e(base_url('company/view_freelancer.php?id=' . $fl['id'])) ?>" class="ff-fl-action primary">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                View Profile
                            </a>
                            <a href="<?= e(base_url('chat/index.php?user=' . ($fl['user_id'] ?? ''))) ?>" class="ff-fl-action outline">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                                Send Message
                            </a>
                            <button type="button" class="ff-fl-action text">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
                                Save
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- RIGHT SIDEBAR -->
        <aside class="ff-right-sidebar">
            <!-- Post a Job CTA -->
            <div class="ff-post-cta">
                <div class="ff-post-cta-icon">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                </div>
                <h4>Post a Job &<br>Hire Top Talent</h4>
                <p>Post your job and get proposals from qualified freelancers.</p>
                <a href="<?= e(base_url('company/post_job.php')) ?>" class="ff-post-cta-btn">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    Post a Job
                </a>
            </div>

            <!-- Popular Skills -->
            <?php if (!empty($popular_skills)): ?>
                <div class="ff-right-card">
                    <h3>Popular Skills <a href="#" class="view-all">View All</a></h3>
                    <div class="ff-skill-list">
                        <?php
                        $skill_icons = ['M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z', 'M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4', 'M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9', 'M13 10V3L4 14h7v7l9-11h-7z', 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z', 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z', 'M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4'];
                        foreach ($popular_skills as $idx => $ps):
                            $icon = $skill_icons[$idx % count($skill_icons)];
                        ?>
                            <a href="<?= e(base_url('company/find_freelancers.php?skill=' . urlencode($ps['skill_name']))) ?>" class="ff-skill-row">
                                <span class="ff-skill-row-name">
                                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="<?= $icon ?>"/></svg>
                                    <?= e($ps['skill_name']) ?>
                                </span>
                                <span class="ff-skill-row-count"><?= number_format($ps['cnt']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Invite & Earn -->
            <div class="ff-invite-card">
                <h4>Invite & Earn</h4>
                <p>Invite other companies and earn rewards.</p>
                <a href="#" class="ff-invite-btn">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                    Invite Now
                </a>
            </div>
        </aside>
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
    document.getElementById('ff-rate-min-label').textContent = '$' + min;
    document.getElementById('ff-rate-max-label').textContent = '$' + max + '+';
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
    document.querySelectorAll('.ff-rating-pill').forEach(function(p) { p.classList.remove('active'); });
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
