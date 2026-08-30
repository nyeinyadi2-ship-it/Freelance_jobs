<?php
$page_title = 'Skill Jobs';
$public_access = true;

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';
require_once __DIR__ . '/../includes/job_helpers.php';

$user = current_user();
$fl_user = null;
$fl_freelancer_id = 0;
if ($user && ($user['role'] ?? '') === 'freelancer') {
    $fl_user = $user;
    $fl_freelancer_id = get_freelancer_id($conn, (int)$user['user_id']);
}

$raw_input = trim($_GET['id'] ?? ($_GET['skill'] ?? ($_GET['name'] ?? '')));
$search_q = trim(urldecode($_GET['q'] ?? ''));

$skill_id = 0;
$skill_info = null;

if ($raw_input !== '') {
    if (is_numeric($raw_input) && (int)$raw_input > 0) {
        $skill_id = (int)$raw_input;
        $st = $conn->prepare('SELECT id, skill_name FROM skills WHERE id = ?');
        $st->bind_param('i', $skill_id);
        $st->execute();
        $skill_info = $st->get_result()->fetch_assoc();
        $st->close();
    } else {
        $st = $conn->prepare('SELECT id, skill_name FROM skills WHERE LOWER(skill_name) = LOWER(?) LIMIT 1');
        $st->bind_param('s', $raw_input);
        $st->execute();
        $skill_info = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$skill_info) {
            $like = '%' . $raw_input . '%';
            $st = $conn->prepare('SELECT id, skill_name FROM skills WHERE LOWER(skill_name) LIKE LOWER(?) LIMIT 1');
            $st->bind_param('s', $like);
            $st->execute();
            $skill_info = $st->get_result()->fetch_assoc();
            $st->close();
        }
    }
}

if (!$skill_info) {
    set_flash('error', 'Skill not found.');
    redirect('freelancer/browse_jobs.php');
}

$skill_id = (int) $skill_info['id'];
$skill_name = $skill_info['skill_name'];
$page_title = 'Browse Jobs in ' . $skill_name;

// Handle apply
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    if (!$fl_freelancer_id) {
        set_flash('error', 'Please login as a freelancer to apply for jobs.');
        redirect('auth/login.php');
    }

    $job_id = (int) ($_POST['job_id'] ?? 0);
    if ($job_id > 0) {
        $st = $conn->prepare("SELECT id FROM jobs WHERE id = ? AND status = 'open'");
        $st->bind_param('i', $job_id); $st->execute();
        $job = $st->get_result()->fetch_assoc(); $st->close();
        if (!$job) { set_flash('error', 'Job is not available for application.'); }
        else {
            // Check position limit
            $st = $conn->prepare('SELECT COUNT(*) AS cnt FROM assignments WHERE job_id = ? AND status NOT IN (\'rejected\', \'cancelled\')');
            $st->bind_param('i', $job_id); $st->execute();
            $filled = (int) $st->get_result()->fetch_assoc()['cnt']; $st->close();
            if ($filled >= 1) {
                set_flash('error', 'The position for this job has been filled.');
            } else {
                $st = $conn->prepare('SELECT id FROM job_applications WHERE job_id = ? AND freelancer_id = ?');
                $st->bind_param('ii', $job_id, $fl_freelancer_id); $st->execute();
                $exists = $st->get_result()->num_rows > 0; $st->close();
                if ($exists) { set_flash('error', 'You have already applied for this job.'); }
                else {
                    $st = $conn->prepare('INSERT INTO job_applications (job_id, freelancer_id) VALUES (?, ?)');
                    $st->bind_param('ii', $job_id, $fl_freelancer_id); $st->execute(); $st->close();
                    $st = $conn->prepare("SELECT j.title, c.user_id FROM jobs j JOIN companies c ON j.company_id = c.id WHERE j.id = ?");
                    $st->bind_param('i', $job_id); $st->execute();
                    $ji = $st->get_result()->fetch_assoc(); $st->close();
                    $fl_uid = (int) ($user['user_id'] ?? 0);
                    if ($ji) create_notification($conn, (int) $ji['user_id'], 'new_application', "Applied for your job \"{$ji['title']}\".", 'company/view_applications.php?id=' . $job_id, $fl_uid);
                    set_flash('success', 'Application submitted successfully.');
                }
            }
        }
    }
    redirect('freelancer/skill_jobs.php?id=' . $skill_id . (!empty($search_q) ? '&q=' . urlencode($search_q) : ''));
}

// Fetch jobs for this skill
$params = [$fl_freelancer_id, $skill_id, $skill_name];
$types = 'iis';

$search_clause = '';
if ($search_q !== '') {
    $search_clause = " AND (j.title LIKE ? OR j.description LIKE ?)";
    $like_q = '%' . $search_q . '%';
    $params[] = $like_q;
    $params[] = $like_q;
    $types .= 'ss';
}

check_and_update_expired_jobs($conn);

$sql = "SELECT j.id,j.title,j.description,j.budget,j.created_at,j.category,j.experience_level,j.deadline,j.duration,j.attachment,j.status,
        c.company_name,c.logo_image,
        (SELECT ja.status FROM job_applications ja WHERE ja.job_id=j.id AND ja.freelancer_id=?) AS my_status,
        (SELECT COUNT(*) FROM assignments a WHERE a.job_id=j.id AND a.status NOT IN ('rejected', 'cancelled')) AS assigned_count,
        (SELECT GROUP_CONCAT(DISTINCT s.skill_name SEPARATOR ',') FROM job_skills js2 JOIN skills s ON js2.skill_id = s.id WHERE js2.job_id = j.id) AS skills_concat
        FROM jobs j LEFT JOIN companies c ON j.company_id=c.id
        WHERE j.status NOT IN ('closed', 'cancelled', 'expired') 
          AND NOT EXISTS (SELECT 1 FROM assignments a_dh WHERE a_dh.job_id = j.id AND a_dh.assignment_type = 'direct_hire')
          AND (EXISTS (SELECT 1 FROM job_skills js_filter WHERE js_filter.job_id = j.id AND js_filter.skill_id = ?) OR LOWER(j.category) = LOWER(?))
          {$search_clause}
        ORDER BY j.created_at DESC";

$st = $conn->prepare($sql);
$st->bind_param($types, ...$params);
$st->execute(); 
$r = $st->get_result();
$jobs = [];
$completed_count = 0;
while ($row = $r->fetch_assoc()) {
    if ($row['status'] === 'expired' || is_deadline_passed($row['deadline'])) {
        continue;
    }
    if ($row['status'] === 'completed') {
        if ($completed_count >= 1) continue;
        $completed_count++;
    }
    $row['skills'] = !empty($row['skills_concat']) ? explode(',', $row['skills_concat']) : [];
    $jobs[] = $row;
}
$st->close();

require __DIR__ . '/../includes/freelancer_layout.php';
?>

<style>
    /* ===== Skill Jobs Page Styles ===== */
    .sj-hero {
        position: relative;
        background: linear-gradient(135deg, #1e1b4b 0%, #312e81 35%, #4338ca 65%, #6366f1 100%);
        border-radius: 24px;
        overflow: hidden;
    }
    .sj-hero::before {
        content: '';
        position: absolute;
        inset: 0;
        background: radial-gradient(ellipse at 20% 80%, rgba(139, 92, 246, 0.25) 0%, transparent 50%),
                    radial-gradient(ellipse at 80% 20%, rgba(99, 102, 241, 0.2) 0%, transparent 50%);
        pointer-events: none;
    }
    .sj-hero::after {
        content: '';
        position: absolute;
        top: -60px;
        right: -60px;
        width: 260px;
        height: 260px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(255,255,255,0.06) 0%, transparent 70%);
        pointer-events: none;
    }
    .sj-hero .sj-orb-1 {
        position: absolute;
        bottom: -80px;
        left: -40px;
        width: 200px;
        height: 200px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(168, 85, 247, 0.15) 0%, transparent 70%);
        pointer-events: none;
    }
    .sj-hero .sj-orb-2 {
        position: absolute;
        top: 20%;
        right: 15%;
        width: 120px;
        height: 120px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(255,255,255,0.04) 0%, transparent 70%);
        pointer-events: none;
    }

    .sj-search-wrap {
        background: var(--color-card, #fff);
        border: 1px solid var(--color-border, #e2e8f0);
        border-radius: 16px;
        box-shadow: 0 4px 24px rgba(0, 0, 0, 0.04), 0 1px 4px rgba(0, 0, 0, 0.02);
        transition: box-shadow 0.3s ease, border-color 0.3s ease;
    }
    .sj-search-wrap:focus-within {
        box-shadow: 0 8px 32px rgba(99, 102, 241, 0.1), 0 2px 8px rgba(99, 102, 241, 0.06);
        border-color: #818cf8;
    }
    .sj-search-input {
        background: transparent;
        color: var(--color-text-primary, #0f172a);
        font-size: 0.9rem;
        font-weight: 500;
        outline: none;
        width: 100%;
        padding: 16px 20px 16px 52px;
    }
    .sj-search-input::placeholder {
        color: var(--color-text-placeholder, #94a3b8);
        font-weight: 400;
    }

    .sj-btn-search {
        background: linear-gradient(135deg, #4f46e5, #7c3aed);
        color: #fff;
        font-size: 0.8rem;
        font-weight: 700;
        padding: 10px 22px;
        border-radius: 12px;
        border: none;
        cursor: pointer;
        transition: all 0.25s ease;
        white-space: nowrap;
    }
    .sj-btn-search:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 20px rgba(79, 70, 229, 0.35);
    }
    .sj-btn-clear {
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--color-text-muted, #64748b);
        padding: 6px 12px;
        border-radius: 8px;
        transition: all 0.2s ease;
        text-decoration: none;
        white-space: nowrap;
    }
    .sj-btn-clear:hover {
        background: rgba(99, 102, 241, 0.08);
        color: #4f46e5;
    }

    /* Job Cards */
    .sj-card {
        background: var(--color-card, #fff);
        border: 1px solid var(--color-border, #e2e8f0);
        border-radius: 20px;
        overflow: hidden;
        transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        display: flex;
        flex-direction: column;
    }
    .sj-card:hover {
        transform: translateY(-6px);
        box-shadow: 0 20px 50px rgba(99, 102, 241, 0.1), 0 8px 24px rgba(0, 0, 0, 0.04);
        border-color: #c7d2fe;
    }
    .sj-card-top-accent {
        height: 4px;
        background: linear-gradient(90deg, #4f46e5, #7c3aed, #a855f7);
        opacity: 0;
        transition: opacity 0.35s ease;
    }
    .sj-card:hover .sj-card-top-accent {
        opacity: 1;
    }
    .sj-card-body {
        padding: 24px;
        flex: 1;
        display: flex;
        flex-direction: column;
    }
    .sj-card-company {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
    }
    .sj-card-company-logo {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        object-fit: cover;
        border: 1px solid var(--color-border, #e2e8f0);
        flex-shrink: 0;
    }
    .sj-card-company-initial {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 1rem;
        background: linear-gradient(135deg, #eef2ff, #e0e7ff);
        color: #4f46e5;
        border: 1px solid #c7d2fe;
        flex-shrink: 0;
    }
    .sj-card-company-name {
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--color-text-secondary, #475569);
        line-height: 1.3;
    }
    .sj-card-company-date {
        font-size: 0.7rem;
        color: var(--color-text-placeholder, #94a3b8);
        margin-top: 2px;
    }
    .sj-card-title {
        font-size: 1.05rem;
        font-weight: 700;
        color: var(--color-text-primary, #0f172a);
        line-height: 1.4;
        margin-bottom: 8px;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    .sj-card-title a {
        color: inherit;
        text-decoration: none;
        transition: color 0.2s ease;
    }
    .sj-card-title a:hover {
        color: #4f46e5;
    }
    .sj-card-desc {
        font-size: 0.8rem;
        color: var(--color-text-muted, #64748b);
        line-height: 1.65;
        margin-bottom: 16px;
        display: -webkit-box;
        -webkit-line-clamp: 3;
        -webkit-box-orient: vertical;
        overflow: hidden;
        flex: 1;
    }
    .sj-skill-tag {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 8px;
        font-size: 0.7rem;
        font-weight: 600;
        background: #f1f5f9;
        color: #475569;
        transition: all 0.2s ease;
    }
    .sj-skill-tag:hover {
        background: #e0e7ff;
        color: #4338ca;
        transform: translateY(-1px);
    }
    .sj-skill-overflow {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 8px;
        font-size: 0.7rem;
        font-weight: 600;
        background: #f1f5f9;
        color: #94a3b8;
    }

    .sj-card-footer {
        padding: 16px 24px;
        border-top: 1px solid var(--color-border, #e2e8f0);
        background: rgba(248, 250, 252, 0.5);
    }
    .sj-card-budget {
        font-size: 1.1rem;
        font-weight: 800;
        background: linear-gradient(135deg, #059669, #10b981);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }
    .sj-card-meta-label {
        font-size: 0.65rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--color-text-placeholder, #94a3b8);
    }
    .sj-card-meta-value {
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--color-text-primary, #0f172a);
    }

    .sj-btn-details {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        flex: 1;
        padding: 10px 16px;
        border-radius: 12px;
        font-size: 0.78rem;
        font-weight: 700;
        color: var(--color-text-secondary, #475569);
        border: 1px solid var(--color-border, #e2e8f0);
        background: var(--color-card, #fff);
        text-decoration: none;
        transition: all 0.25s ease;
    }
    .sj-btn-details:hover {
        background: #f8fafc;
        border-color: #c7d2fe;
        color: #4f46e5;
        transform: translateY(-1px);
    }
    .sj-btn-apply {
        display: flex;
        align-items: center;
        justify-content: center;
        flex: 1;
        padding: 10px 16px;
        border-radius: 12px;
        font-size: 0.78rem;
        font-weight: 700;
        color: #fff;
        border: none;
        background: linear-gradient(135deg, #4f46e5, #7c3aed);
        cursor: pointer;
        text-decoration: none;
        transition: all 0.25s ease;
    }
    .sj-btn-apply:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 20px rgba(79, 70, 229, 0.35);
    }

    /* Experience badge */
    .sj-exp-badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.65rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }
    .sj-exp-beginner {
        background: #ecfdf5;
        color: #059669;
        border: 1px solid #a7f3d0;
    }
    .sj-exp-intermediate {
        background: #eef2ff;
        color: #4f46e5;
        border: 1px solid #c7d2fe;
    }
    .sj-exp-expert {
        background: #faf5ff;
        color: #7c3aed;
        border: 1px solid #ddd6fe;
    }

    /* Empty state */
    .sj-empty-icon {
        width: 80px;
        height: 80px;
        border-radius: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 20px;
        background: linear-gradient(135deg, #eef2ff, #e0e7ff);
        color: #4f46e5;
    }

    /* Entrance animations */
    @keyframes sjFadeUp {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .sj-animate { animation: sjFadeUp 0.5s ease forwards; }
    .sj-animate-d1 { animation-delay: 0.05s; opacity: 0; }
    .sj-animate-d2 { animation-delay: 0.1s; opacity: 0; }
    .sj-animate-d3 { animation-delay: 0.15s; opacity: 0; }

    /* Status badge overrides */
    .sj-status-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 6px 14px;
        border-radius: 10px;
        font-size: 0.75rem;
        font-weight: 700;
    }
    .sj-status-open { background: #ecfdf5; color: #059669; }
    .sj-status-in_review { background: #fef9c3; color: #a16207; }
    .sj-status-hired { background: #eef2ff; color: #4f46e5; }
    .sj-status-in_progress { background: #e0f2fe; color: #0284c7; }
    .sj-status-completed { background: #f0fdf4; color: #166534; }
    .sj-status-cancelled { background: #fef2f2; color: #dc2626; }
    .sj-status-closed { background: #f1f5f9; color: #64748b; }
    .sj-status-pending { background: #fef9c3; color: #a16207; }
    .sj-status-accepted { background: #ecfdf5; color: #059669; }
    .sj-status-rejected { background: #fef2f2; color: #dc2626; }
    .sj-status-withdrawn { background: #f1f5f9; color: #64748b; }

    @media (max-width: 640px) {
        .sj-card-body { padding: 20px; }
        .sj-card-footer { padding: 14px 20px; }
    }
</style>

<!-- Hero Section -->
<section class="sj-hero sj-animate">
    <div class="sj-orb-1"></div>
    <div class="sj-orb-2"></div>
    <div class="relative max-w-7xl mx-auto px-5 sm:px-8 py-10 sm:py-14">
        <!-- Breadcrumb -->
        <nav class="flex items-center gap-1.5 text-xs font-medium text-indigo-300/80 mb-6" aria-label="Breadcrumb">
            <a href="<?= e(base_url('index.php')) ?>" class="hover:text-white transition-colors duration-200">Home</a>
            <svg class="w-3.5 h-3.5 text-indigo-400/60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="hover:text-white transition-colors duration-200">Browse Jobs</a>
            <svg class="w-3.5 h-3.5 text-indigo-400/60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            <span class="text-white font-semibold"><?= e($skill_name) ?></span>
        </nav>

        <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-6">
            <div class="sj-animate sj-animate-d2">
                <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-white/10 backdrop-blur-sm text-xs font-semibold text-indigo-200 mb-4 border border-white/10">
                    <svg class="w-3.5 h-3.5 text-indigo-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                    Skill-Based Jobs
                </div>
                <h1 class="text-3xl sm:text-4xl lg:text-[2.75rem] font-extrabold tracking-tight text-white leading-tight mb-3">
                    Browse Jobs in<br class="sm:hidden"> <span class="bg-clip-text text-transparent bg-gradient-to-r from-white via-indigo-100 to-purple-200"><?= e($skill_name) ?></span>
                </h1>
                <p class="text-sm sm:text-base text-indigo-200/80 max-w-xl leading-relaxed">
                    Find the latest freelance opportunities matching your skills and start building your career.
                </p>
            </div>

            <div class="flex items-center gap-4 self-start md:self-auto sj-animate sj-animate-d3">
                <div class="px-6 py-4 rounded-2xl bg-white/10 backdrop-blur-sm border border-white/10 text-center min-w-[100px]">
                    <span class="block text-3xl font-extrabold text-white mb-0.5"><?= count($jobs) ?></span>
                    <span class="text-[0.7rem] text-indigo-200 font-medium uppercase tracking-wider">Job<?= count($jobs) !== 1 ? 's' : '' ?> Found</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Search Section -->
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 -mt-7 relative z-10 mb-10">
    <form method="GET" action="skill_jobs.php" class="max-w-2xl mx-auto">
        <input type="hidden" name="id" value="<?= e($skill_id) ?>">
        <div class="sj-search-wrap flex items-center">
            <div class="pl-4 pr-1">
                <svg class="w-5 h-5 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            </div>
            <input type="text" name="q" value="<?= e($search_q) ?>" placeholder="Search <?= e($skill_name) ?> jobs by title or keyword..." class="sj-search-input" autocomplete="off">

            <?php if ($search_q !== ''): ?>
                <a href="skill_jobs.php?id=<?= e($skill_id) ?>" class="sj-btn-clear">Clear</a>
            <?php endif; ?>

            <div class="pr-2">
                <button type="submit" class="sj-btn-search">Search</button>
            </div>
        </div>
    </form>
</div>

<!-- Jobs Grid -->
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-16">
    <?php if (empty($jobs)): ?>
        <!-- Empty State -->
        <div class="max-w-lg mx-auto text-center py-16 px-8 rounded-3xl border" style="background:var(--color-card,#fff);border-color:var(--color-border,#e2e8f0)">
            <div class="sj-empty-icon">
                <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            </div>
            <h3 class="text-xl font-extrabold mb-2" style="color:var(--color-text-primary,#0f172a)">
                No jobs found for this skill
            </h3>
            <p class="text-sm mb-8 leading-relaxed" style="color:var(--color-text-muted,#64748b)">
                <?= $search_q !== '' ? 'No opportunities match your search keywords. Try clearing your search or check back later for new postings.' : 'Try another skill or check back later for new opportunities.' ?>
            </p>
            <div class="flex flex-wrap items-center justify-center gap-3">
                <?php if ($search_q !== ''): ?>
                    <a href="skill_jobs.php?id=<?= e($skill_id) ?>" class="sj-btn-details" style="padding:12px 24px;font-size:0.8rem">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        Clear Search
                    </a>
                <?php endif; ?>
                <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="sj-btn-apply" style="padding:12px 24px;font-size:0.8rem">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                    Browse All Jobs
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($jobs as $i => $job): ?>
                <div class="sj-card">
                    <!-- Top accent line -->
                    <div class="sj-card-top-accent"></div>

                    <!-- Card body -->
                    <div class="sj-card-body">
                        <!-- Company header -->
                        <div class="sj-card-company">
                            <?php if (!empty($job['logo_image'])): ?>
                                <img src="<?= e(base_url('uploads/images/' . $job['logo_image'])) ?>" alt="" class="sj-card-company-logo">
                            <?php else: ?>
                                <div class="sj-card-company-initial">
                                    <?= strtoupper(mb_substr($job['company_name'] ?? 'C', 0, 1)) ?>
                                </div>
                            <?php endif; ?>
                            <div class="min-w-0 flex-1">
                                <div class="sj-card-company-name truncate"><?= e($job['company_name'] ?? 'Company') ?></div>
                                <div class="sj-card-company-date"><?= e(date('M j, Y', strtotime($job['created_at']))) ?></div>
                            </div>
                            <?php
                            $exp_level = strtolower($job['experience_level'] ?? 'intermediate');
                            $exp_label = $exp_level === 'beginner' ? 'Beginner' : ($exp_level === 'expert' ? 'Expert' : 'Intermediate');
                            $exp_class = 'sj-exp-' . $exp_level;
                            ?>
                            <span class="sj-exp-badge <?= e($exp_class) ?>"><?= e($exp_label) ?></span>
                        </div>

                        <!-- Title -->
                        <h3 class="sj-card-title">
                            <a href="<?= e(base_url('freelancer/view_job.php?id=' . $job['id'])) ?>"><?= e($job['title']) ?></a>
                        </h3>

                        <!-- Description -->
                        <p class="sj-card-desc"><?= e(mb_strimwidth(strip_tags($job['description'] ?? ''), 0, 160, '...')) ?></p>

                        <!-- Skills -->
                        <?php if (!empty($job['skills'])): ?>
                            <div class="flex flex-wrap gap-1.5 mb-4">
                                <?php foreach (array_slice($job['skills'], 0, 4) as $sk): ?>
                                    <span class="sj-skill-tag"><?= e($sk) ?></span>
                                <?php endforeach; ?>
                                <?php if (count($job['skills']) > 4): ?>
                                    <span class="sj-skill-overflow">+<?= count($job['skills']) - 4 ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Footer -->
                    <div class="sj-card-footer">
                        <!-- Meta row -->
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <span class="sj-card-meta-label block">Budget</span>
                                <span class="sj-card-budget"><?= number_format((float) $job['budget'], 0) ?> MMK</span>
                            </div>
                            <?php if ($job['deadline']): ?>
                                <div class="text-right">
                                    <span class="sj-card-meta-label block">Deadline</span>
                                    <span class="sj-card-meta-value"><?= e(date('M j, Y', strtotime($job['deadline']))) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Actions -->
                        <div class="flex items-center gap-2">
                            <a href="<?= e(base_url('freelancer/view_job.php?id=' . $job['id'])) ?>" class="sj-btn-details">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                View Details
                            </a>

                            <?php
                            $is_open = $job['status'] === 'open';
                            ?>
                            <?php if (!$is_open): ?>
                                <?php
                                $status_class = 'sj-status-' . ($job['status'] ?? 'closed');
                                ?>
                                <span class="sj-status-badge <?= e($status_class) ?>"><?= e(ucfirst(str_replace('_', ' ', $job['status'] ?? 'closed'))) ?></span>
                            <?php elseif ($job['my_status']): ?>
                                <?php
                                $my_status_class = 'sj-status-' . ($job['my_status'] ?? 'pending');
                                ?>
                                <span class="sj-status-badge <?= e($my_status_class) ?>"><?= e(ucfirst(str_replace('_', ' ', $job['my_status'] ?? 'pending'))) ?></span>
                            <?php else: ?>
                                <?php if (!$fl_freelancer_id): ?>
                                    <a href="<?= e(base_url('auth/login.php')) ?>" class="sj-btn-apply">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                                        Apply
                                    </a>
                                <?php else: ?>
                                    <form method="POST" class="flex-1">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                                        <button type="submit" class="sj-btn-apply w-full">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                                            Apply
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/freelancer_footer.php'; ?>
