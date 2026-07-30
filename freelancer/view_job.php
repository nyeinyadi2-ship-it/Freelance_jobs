<?php
$page_title = 'Job Details';
require __DIR__ . '/../includes/freelancer_init.php';

$job_id = (int) ($_GET['id'] ?? 0);
if ($job_id <= 0) {
    set_flash('error', 'Invalid job ID.');
    redirect('freelancer/browse_jobs.php');
}

// Handle Apply
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'apply' && verify_csrf()) {
    $st = $conn->prepare("SELECT id, company_id FROM jobs WHERE id = ? AND status IN ('approved', 'position_filled')");
    $st->bind_param('i', $job_id); $st->execute();
    $job_check = $st->get_result()->fetch_assoc(); $st->close();

    if (!$job_check) {
        set_flash('error', 'Job is not available for application.');
    } else {
        // Check position limit
        $st = $conn->prepare("SELECT freelancers_needed FROM jobs WHERE id = ?");
        $st->bind_param('i', $job_id); $st->execute();
        $job_meta = $st->get_result()->fetch_assoc(); $st->close();
        $needed = (int) ($job_meta['freelancers_needed'] ?? 1);

        $st = $conn->prepare("SELECT COUNT(*) AS cnt FROM assignments WHERE job_id = ? AND status != 'completed'");
        $st->bind_param('i', $job_id); $st->execute();
        $filled = (int) $st->get_result()->fetch_assoc()['cnt']; $st->close();

        if ($filled >= $needed) {
            set_flash('error', 'All positions for this job have been filled.');
        } else {
            $st = $conn->prepare('SELECT id FROM job_applications WHERE job_id = ? AND freelancer_id = ?');
            $st->bind_param('ii', $job_id, $fl_freelancer_id); $st->execute();
            $already_applied = $st->get_result()->num_rows > 0; $st->close();

            if ($already_applied) {
                set_flash('error', 'You have already applied for this job.');
            } else {
                $st = $conn->prepare('INSERT INTO job_applications (job_id, freelancer_id) VALUES (?, ?)');
                $st->bind_param('ii', $job_id, $fl_freelancer_id); $st->execute(); $st->close();

                $st = $conn->prepare("SELECT j.title, c.user_id FROM jobs j JOIN companies c ON j.company_id = c.id WHERE j.id = ?");
                $st->bind_param('i', $job_id); $st->execute();
                $ji = $st->get_result()->fetch_assoc(); $st->close();
                if ($ji) {
                    create_notification($conn, (int) $ji['user_id'], 'new_application', $fl_user['username'] . " applied for your job \"{$ji['title']}\".", 'company/view_applications.php?id=' . $job_id);
                }
                set_flash('success', 'Application submitted successfully.');
            }
        }
    }
    redirect('freelancer/view_job.php?id=' . $job_id);
}

// Fetch job details
$stmt = $conn->prepare("
    SELECT j.*, c.company_name, c.logo_image, c.location AS company_location, c.website, c.industry, c.company_size, c.description AS company_description,
           u.id AS client_user_id, u.username AS client_username, u.profile_image AS client_profile_image,
           COALESCE(c.company_name, u.username) AS client_display_name
    FROM jobs j
    JOIN companies c ON j.company_id = c.id
    JOIN users u ON c.user_id = u.id
    WHERE j.id = ? AND j.status IN ('approved', 'completed', 'position_filled')
");
$stmt->bind_param('i', $job_id);
$stmt->execute();
$job = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Job not found
if (!$job) {
    require __DIR__ . '/../includes/freelancer_layout.php';
?>
<div class="max-w-xl mx-auto px-4 pt-16 pb-24 text-center">
    <div class="glass rounded-3xl p-12">
        <div class="w-20 h-20 mx-auto mb-6 rounded-full bg-gradient-to-br from-red-100 to-red-50 dark:from-red-900/30 dark:to-red-800/20 flex items-center justify-center">
            <svg class="w-10 h-10 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        </div>
        <h1 class="text-2xl font-bold mb-2" style="color:var(--color-text-primary)">Job Not Found</h1>
        <p class="mb-6" style="color:var(--color-text-muted)">This job may have been removed or is no longer available.</p>
        <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="inline-flex items-center gap-2 btn-grad px-6 py-3 text-sm font-semibold rounded-2xl text-white shadow-lg shadow-primary-500/20">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            Browse Jobs
        </a>
    </div>
</div>
<?php
    require __DIR__ . '/../includes/freelancer_footer.php';
    exit;
}

// Fetch skills for this job
$skills = [];
$ss = $conn->prepare('SELECT s.skill_name FROM job_skills js JOIN skills s ON js.skill_id = s.id WHERE js.job_id = ?');
$ss->bind_param('i', $job_id);
$ss->execute();
$sr = $ss->get_result();
while ($row = $sr->fetch_assoc()) $skills[] = $row['skill_name'];
$ss->close();

// Check if freelancer already applied
$my_status = null;
$st = $conn->prepare("SELECT status FROM job_applications WHERE job_id = ? AND freelancer_id = ? LIMIT 1");
$st->bind_param('ii', $job_id, $fl_freelancer_id);
$st->execute();
$app = $st->get_result()->fetch_assoc();
$my_status = $app ? $app['status'] : null;
$st->close();

// Check if job is already fully filled
$is_assigned = false;
$positions_filled = 0;
$freelancers_needed = (int) ($job['freelancers_needed'] ?? 1);
$st = $conn->prepare("SELECT COUNT(*) AS cnt FROM assignments WHERE job_id = ? AND status != 'completed'");
$st->bind_param('i', $job_id);
$st->execute();
$positions_filled = (int) $st->get_result()->fetch_assoc()['cnt'];
$st->close();
$is_assigned = $positions_filled >= $freelancers_needed;

// Total proposals
$proposal_count = 0;
$st = $conn->prepare("SELECT COUNT(*) AS cnt FROM job_applications WHERE job_id = ?");
$st->bind_param('i', $job_id);
$st->execute();
$proposal_count = (int) $st->get_result()->fetch_assoc()['cnt'];
$st->close();

// Similar jobs (same category, exclude current)
$similar = [];
$st = $conn->prepare("
    SELECT j.id, j.title, j.budget, j.created_at, j.experience_level, j.duration,
           c.company_name, c.logo_image
    FROM jobs j
    JOIN companies c ON j.company_id = c.id
    WHERE j.category = ? AND j.id != ? AND j.status = 'approved'
    ORDER BY j.created_at DESC
    LIMIT 4
");
$st->bind_param('si', $job['category'], $job_id);
$st->execute();
$sr = $st->get_result();
while ($row = $sr->fetch_assoc()) {
    $similar[] = $row;
}
$st->close();

// Batch-fetch skills for similar jobs
if (!empty($similar)) {
    $sim_ids = array_column($similar, 'id');
    $placeholders = implode(',', array_fill(0, count($sim_ids), '?'));
    $ssk = $conn->prepare("SELECT js.job_id, s.skill_name FROM job_skills js JOIN skills s ON js.skill_id = s.id WHERE js.job_id IN ({$placeholders})");
    $ssk->bind_param(str_repeat('i', count($sim_ids)), ...$sim_ids);
    $ssk->execute();
    $skr = $ssk->get_result();
    $sim_skills = [];
    while ($sk = $skr->fetch_assoc()) {
        $sim_skills[$sk['job_id']][] = $sk['skill_name'];
    }
    $ssk->close();
    foreach ($similar as &$row) {
        $row['skills'] = $sim_skills[$row['id']] ?? [];
    }
    unset($row);
}

$job_type = $job['duration'] ?? 'Full Time';
$is_urgent = $job['deadline'] && strtotime($job['deadline']) < strtotime('+7 days');

// Client stats
$client_company_id = (int) $job['company_id'];

$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM jobs WHERE company_id = ?");
$stmt->bind_param('i', $client_company_id);
$stmt->execute();
$client_total_jobs = (int) $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM assignments a JOIN jobs j ON a.job_id = j.id WHERE j.company_id = ? AND a.status = 'completed'");
$stmt->bind_param('i', $client_company_id);
$stmt->execute();
$client_hired = (int) $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$stmt = $conn->prepare("SELECT COALESCE(SUM(j.budget), 0) AS total FROM assignments a JOIN jobs j ON a.job_id = j.id WHERE j.company_id = ? AND a.status = 'completed'");
$stmt->bind_param('i', $client_company_id);
$stmt->execute();
$client_paid = (float) $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

require __DIR__ . '/../includes/freelancer_layout.php';
?>

<style>
@keyframes fadeUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes slideRight { from { opacity: 0; transform: translateX(-20px); } to { opacity: 1; transform: translateX(0); } }
.animate-fade-up { animation: fadeUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) both; }
.animate-fade-up-d1 { animation: fadeUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) 0.1s both; }
.animate-fade-up-d2 { animation: fadeUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) 0.2s both; }
.animate-fade-up-d3 { animation: fadeUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) 0.3s both; }
.animate-fade-in { animation: fadeIn 0.5s ease both; }
.animate-slide-right { animation: slideRight 0.5s cubic-bezier(0.16, 1, 0.3, 1) both; }

.view-hero {
    position: relative; overflow: hidden;
    background: linear-gradient(135deg, #1e1b4b 0%, #312e81 20%, #4338ca 45%, #6366f1 70%, #818cf8 100%);
    border-radius: 24px; padding: 2.5rem 2rem;
}
.view-hero::before {
    content: ''; position: absolute; inset: 0;
    background: radial-gradient(ellipse at 20% 50%, rgba(255,255,255,0.1) 0%, transparent 60%);
    pointer-events: none;
}
.view-hero::after {
    content: ''; position: absolute; top: -80px; right: -40px;
    width: 300px; height: 300px; border-radius: 50%;
    background: radial-gradient(circle, rgba(167,139,250,0.15) 0%, transparent 70%);
    pointer-events: none;
}
.hero-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 14px; border-radius: 9999px;
    font-size: 0.75rem; font-weight: 600;
    background: rgba(255,255,255,0.12); color: rgba(255,255,255,0.9);
    backdrop-filter: blur(8px); border: 1px solid rgba(255,255,255,0.15);
    transition: all 0.2s ease;
}
.hero-badge:hover { background: rgba(255,255,255,0.2); }

.glass-card {
    background: var(--color-card);
    border: 1px solid var(--color-border);
    border-radius: 20px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
.glass-card:hover {
    box-shadow: 0 8px 32px rgba(99, 102, 241, 0.08);
}

.skill-chip {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px; border-radius: 12px;
    font-size: 0.8125rem; font-weight: 500;
    background: linear-gradient(135deg, rgba(99,102,241,0.08), rgba(139,92,246,0.08));
    color: #6366f1; border: 1px solid rgba(99,102,241,0.12);
    transition: all 0.25s ease;
}
.skill-chip:hover {
    background: linear-gradient(135deg, rgba(99,102,241,0.15), rgba(139,92,246,0.15));
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.15);
}

.detail-row {
    display: flex; align-items: center; gap: 14px;
    padding: 14px 0;
}
.detail-row:not(:last-child) { border-bottom: 1px solid var(--color-border); }
.detail-icon {
    flex-shrink: 0; width: 40px; height: 40px;
    border-radius: 12px; display: flex; align-items: center; justify-content: center;
}

.similar-card {
    border-radius: 16px;
    background: var(--color-card);
    border: 1px solid var(--color-border);
    transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
    overflow: hidden;
}
.similar-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 20px 50px rgba(99, 102, 241, 0.12);
    border-color: rgba(99, 102, 241, 0.25);
}

.btn-primary {
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    padding: 14px 28px; border-radius: 14px;
    font-size: 0.9375rem; font-weight: 600;
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    color: white; border: none; cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 4px 16px rgba(79, 70, 229, 0.3);
}
.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 28px rgba(79, 70, 229, 0.4);
}
.btn-primary:active { transform: translateY(0); }

.btn-outline {
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    padding: 14px 28px; border-radius: 14px;
    font-size: 0.9375rem; font-weight: 600;
    background: transparent; cursor: pointer;
    color: #6366f1; border: 2px solid rgba(99, 102, 241, 0.25);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
.btn-outline:hover {
    background: rgba(99, 102, 241, 0.06);
    border-color: rgba(99, 102, 241, 0.4);
    transform: translateY(-1px);
}

.stat-card {
    border-radius: 16px;
    background: var(--color-card);
    border: 1px solid var(--color-border);
    padding: 20px;
    text-align: center;
    transition: all 0.3s ease;
}
.stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.06); }
</style>

<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

    <!-- Back Button -->
    <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="inline-flex items-center gap-2 text-sm font-medium mb-6 px-4 py-2.5 rounded-xl transition-all hover:bg-gray-100 dark:hover:bg-gray-800 animate-slide-right" style="color:var(--color-text-secondary)">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        Back to Browse Jobs
    </a>

    <!-- Hero Section -->
    <div class="view-hero mb-8 animate-fade-up">
        <div class="relative z-10">
            <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-6">
                <div class="flex-1 min-w-0">
                    <!-- Badges -->
                    <div class="flex items-center gap-2 flex-wrap mb-4">
                        <span class="hero-badge">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            <?= e($job['category'] ?: 'General') ?>
                        </span>
                        <?php if ($job['experience_level']): ?>
                            <span class="hero-badge">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                <?= e(ucfirst($job['experience_level'])) ?>
                            </span>
                        <?php endif; ?>
                        <span class="hero-badge">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            Remote
                        </span>
                        <?php if ($is_urgent): ?>
                            <span class="hero-badge" style="background:rgba(239,68,68,0.3);border-color:rgba(239,68,68,0.3);animation:pulse 2s infinite">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                Urgent
                            </span>
                        <?php endif; ?>
                        <?php if ($is_assigned): ?>
                            <span class="hero-badge" style="background:rgba(245,158,11,0.3);border-color:rgba(245,158,11,0.3)">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                Filled
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Title -->
                    <h1 class="text-3xl sm:text-4xl font-extrabold text-white mb-4 leading-tight"><?= e($job['title']) ?></h1>

                    <!-- Meta Info -->
                    <div class="flex items-center gap-4 flex-wrap text-white/75 text-sm">
                        <span class="flex items-center gap-2">
                            <?php if (!empty($job['logo_image'])): ?>
                                <img src="<?= e(base_url('uploads/images/' . $job['logo_image'])) ?>" alt="" class="w-7 h-7 rounded-lg object-contain bg-white/20">
                            <?php else: ?>
                                <div class="w-7 h-7 rounded-lg bg-white/20 flex items-center justify-center text-white text-[10px] font-bold"><?= strtoupper(mb_substr($job['company_name'] ?? 'C', 0, 1)) ?></div>
                            <?php endif; ?>
                            <?= e($job['company_name']) ?>
                        </span>
                        <span class="w-1 h-1 rounded-full bg-white/30"></span>
                        <span class="flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <?= e($job['company_location'] ?: 'Remote') ?>
                        </span>
                        <span class="w-1 h-1 rounded-full bg-white/30"></span>
                        <span class="flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            <?= $job['deadline'] ? 'Deadline: ' . date('M j, Y', strtotime($job['deadline'])) : 'No deadline' ?>
                        </span>
                    </div>
                </div>

                <!-- Budget -->
                <div class="lg:text-right flex-shrink-0">
                    <div class="inline-flex flex-col items-center lg:items-end px-6 py-4 rounded-2xl" style="background:rgba(255,255,255,0.1);backdrop-filter:blur(8px);border:1px solid rgba(255,255,255,0.12)">
                        <span class="text-white/60 text-xs font-medium uppercase tracking-wider mb-1">Budget</span>
                        <span class="text-4xl sm:text-5xl font-extrabold text-white">$<?= number_format((float) $job['budget'], 2) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Stats -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8 animate-fade-up-d1">
        <div class="stat-card">
            <div class="w-10 h-10 mx-auto mb-2 rounded-xl flex items-center justify-center" style="background:rgba(99,102,241,0.1)">
                <svg class="w-5 h-5 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            </div>
            <p class="text-2xl font-bold" style="color:var(--color-text-primary)"><?= $proposal_count ?></p>
            <p class="text-xs mt-0.5" style="color:var(--color-text-muted)">Proposals</p>
        </div>
        <div class="stat-card">
            <div class="w-10 h-10 mx-auto mb-2 rounded-xl flex items-center justify-center" style="background:rgba(16,185,129,0.1)">
                <svg class="w-5 h-5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"/></svg>
            </div>
            <p class="text-2xl font-bold" style="color:var(--color-text-primary)">$<?= number_format((float) $job['budget'], 0) ?></p>
            <p class="text-xs mt-0.5" style="color:var(--color-text-muted)">Budget</p>
        </div>
        <div class="stat-card">
            <div class="w-10 h-10 mx-auto mb-2 rounded-xl flex items-center justify-center" style="background:rgba(245,158,11,0.1)">
                <svg class="w-5 h-5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <p class="text-2xl font-bold" style="color:var(--color-text-primary)"><?= e($job['duration'] ?: 'N/A') ?></p>
            <p class="text-xs mt-0.5" style="color:var(--color-text-muted)">Duration</p>
        </div>
        <div class="stat-card">
            <div class="w-10 h-10 mx-auto mb-2 rounded-xl flex items-center justify-center" style="background:rgba(139,92,246,0.1)">
                <svg class="w-5 h-5 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            </div>
            <p class="text-2xl font-bold capitalize" style="color:var(--color-text-primary)"><?= e($job['experience_level'] ?: 'N/A') ?></p>
            <p class="text-xs mt-0.5" style="color:var(--color-text-muted)">Level</p>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

        <!-- Left: Job Details (Main Content) -->
        <div class="lg:col-span-2 space-y-6">

            <!-- Job Description -->
            <div class="glass-card p-6 sm:p-8 animate-fade-up-d1">
                <h2 class="text-xl font-bold mb-5 flex items-center gap-3" style="color:var(--color-text-primary)">
                    <div class="w-9 h-9 rounded-xl flex items-center justify-center" style="background:linear-gradient(135deg,#4f46e5,#7c3aed)">
                        <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7"/></svg>
                    </div>
                    Job Description
                </h2>
                <div class="text-sm leading-relaxed" style="color:var(--color-text-secondary); line-height: 1.9;">
                    <?= nl2br(e($job['description'] ?: 'No description provided.')) ?>
                </div>

                <?php if ($job['attachment']): ?>
                <div class="mt-6 pt-6 border-t" style="border-color:var(--color-border)">
                    <h3 class="text-sm font-semibold mb-3 flex items-center gap-2" style="color:var(--color-text-primary)">
                        <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                        Attachments
                    </h3>
                    <a href="<?= e(attachment_url($job['attachment'])) ?>" target="_blank" class="inline-flex items-center gap-3 px-5 py-3.5 rounded-xl text-sm font-medium transition-all hover:shadow-md" style="background:rgba(99,102,241,0.06);color:var(--color-text-secondary);border:1px solid var(--color-border)">
                        <div class="w-10 h-10 rounded-lg flex items-center justify-center" style="background:rgba(99,102,241,0.1)">
                            <svg class="w-5 h-5 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        </div>
                        <div>
                            <p class="font-medium" style="color:var(--color-text-primary)"><?= e(basename($job['attachment'])) ?></p>
                            <p class="text-xs" style="color:var(--color-text-muted)">Click to download</p>
                        </div>
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <!-- Required Skills -->
            <?php if (!empty($skills)): ?>
            <div class="glass-card p-6 sm:p-8 animate-fade-up-d2">
                <h2 class="text-xl font-bold mb-5 flex items-center gap-3" style="color:var(--color-text-primary)">
                    <div class="w-9 h-9 rounded-xl flex items-center justify-center" style="background:linear-gradient(135deg,#8b5cf6,#a855f7)">
                        <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                    </div>
                    Required Skills
                </h2>
                <div class="flex flex-wrap gap-2.5">
                    <?php foreach ($skills as $sk): ?>
                        <span class="skill-chip">
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            <?= e($sk) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- About the Client -->
            <div class="glass-card p-6 sm:p-8 animate-fade-up-d3">
                <h2 class="text-xl font-bold mb-5 flex items-center gap-3" style="color:var(--color-text-primary)">
                    <div class="w-9 h-9 rounded-xl flex items-center justify-center" style="background:linear-gradient(135deg,#0ea5e9,#06b6d4)">
                        <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    </div>
                    About the Client
                </h2>
                <div class="flex items-center gap-4 mb-5">
                    <?php $client_img = profile_image_url($job['client_profile_image']); ?>
                    <?php if ($client_img): ?>
                        <img src="<?= e($client_img) ?>" alt="" class="w-14 h-14 rounded-2xl object-cover shadow-md">
                    <?php else: ?>
                        <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-indigo-500 to-purple-500 flex items-center justify-center text-white font-bold text-xl shadow-md">
                            <?= strtoupper(mb_substr($job['client_display_name'] ?? 'C', 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                    <div>
                        <div class="flex items-center gap-2">
                            <p class="font-bold text-lg" style="color:var(--color-text-primary)"><?= e($job['client_display_name']) ?></p>
                            <span class="w-5 h-5 rounded-full bg-blue-500 flex items-center justify-center" title="Verified Client">
                                <svg class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            </span>
                        </div>
                        <p class="text-sm" style="color:var(--color-text-muted)">@<?= e($job['client_username']) ?></p>
                    </div>
                </div>

                <!-- Client Stats -->
                <div class="grid grid-cols-3 gap-3 mb-5">
                    <div class="relative overflow-hidden rounded-xl p-4 text-center" style="background:var(--color-card-hover,rgba(0,0,0,0.03))">
                        <div class="absolute top-0 right-0 w-12 h-12 rounded-bl-full opacity-[0.08] bg-indigo-500"></div>
                        <div class="relative">
                            <div class="w-8 h-8 rounded-lg flex items-center justify-center mx-auto mb-2" style="background:rgba(99,102,241,0.1)">
                                <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            </div>
                            <p class="text-xl font-extrabold" style="color:var(--color-text-primary)"><?= number_format($client_total_jobs) ?></p>
                            <p class="text-xs mt-0.5" style="color:var(--color-text-muted)">Jobs Posted</p>
                        </div>
                    </div>
                    <div class="relative overflow-hidden rounded-xl p-4 text-center" style="background:var(--color-card-hover,rgba(0,0,0,0.03))">
                        <div class="absolute top-0 right-0 w-12 h-12 rounded-bl-full opacity-[0.08] bg-emerald-500"></div>
                        <div class="relative">
                            <div class="w-8 h-8 rounded-lg flex items-center justify-center mx-auto mb-2" style="background:rgba(16,185,129,0.1)">
                                <svg class="w-4 h-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </div>
                            <p class="text-xl font-extrabold" style="color:var(--color-text-primary)"><?= number_format($client_hired) ?></p>
                            <p class="text-xs mt-0.5" style="color:var(--color-text-muted)">Jobs Hired</p>
                        </div>
                    </div>
                    <div class="relative overflow-hidden rounded-xl p-4 text-center" style="background:var(--color-card-hover,rgba(0,0,0,0.03))">
                        <div class="absolute top-0 right-0 w-12 h-12 rounded-bl-full opacity-[0.08] bg-violet-500"></div>
                        <div class="relative">
                            <div class="w-8 h-8 rounded-lg flex items-center justify-center mx-auto mb-2" style="background:rgba(139,92,246,0.1)">
                                <svg class="w-4 h-4 text-violet-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"/></svg>
                            </div>
                            <p class="text-xl font-extrabold" style="color:var(--color-text-primary)">$<?= number_format($client_paid, 0) ?></p>
                            <p class="text-xs mt-0.5" style="color:var(--color-text-muted)">Amount Paid</p>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <?php if ($job['company_location']): ?>
                        <div class="flex items-center gap-3 p-3 rounded-xl" style="background:var(--color-card-hover,rgba(0,0,0,0.03))">
                            <div class="w-8 h-8 rounded-lg flex items-center justify-center" style="background:rgba(99,102,241,0.1)">
                                <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            </div>
                            <span class="text-sm" style="color:var(--color-text-secondary)"><?= e($job['company_location']) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($job['website']): ?>
                        <div class="flex items-center gap-3 p-3 rounded-xl" style="background:var(--color-card-hover,rgba(0,0,0,0.03))">
                            <div class="w-8 h-8 rounded-lg flex items-center justify-center" style="background:rgba(99,102,241,0.1)">
                                <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
                            </div>
                            <a href="<?= e($job['website']) ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg transition-colors" style="background:rgba(99,102,241,0.1);color:#4f46e5">&#127760; Visit Website</a>
                        </div>
                    <?php endif; ?>
                    <?php if ($job['industry']): ?>
                        <div class="flex items-center gap-3 p-3 rounded-xl" style="background:var(--color-card-hover,rgba(0,0,0,0.03))">
                            <div class="w-8 h-8 rounded-lg flex items-center justify-center" style="background:rgba(99,102,241,0.1)">
                                <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                            </div>
                            <span class="text-sm" style="color:var(--color-text-secondary)"><?= e($job['industry']) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($job['company_size']): ?>
                        <div class="flex items-center gap-3 p-3 rounded-xl" style="background:var(--color-card-hover,rgba(0,0,0,0.03))">
                            <div class="w-8 h-8 rounded-lg flex items-center justify-center" style="background:rgba(99,102,241,0.1)">
                                <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                            </div>
                            <span class="text-sm" style="color:var(--color-text-secondary)"><?= e($job['company_size']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right: Sidebar -->
        <div class="space-y-6 animate-fade-up-d2">

            <!-- Apply Card -->
            <div class="glass-card p-6 sticky top-24">
                <?php if ($is_assigned): ?>
                    <div class="text-center py-6">
                        <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center">
                            <svg class="w-8 h-8 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        </div>
                        <p class="font-bold text-lg" style="color:var(--color-text-primary)">All Positions Filled</p>
                        <p class="text-sm mt-1" style="color:var(--color-text-muted)">All <?= $freelancers_needed ?> position(s) for this job have been filled.</p>
                    </div>
                <?php elseif ($my_status): ?>
                    <div class="text-center py-6">
                        <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center">
                            <svg class="w-8 h-8 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <p class="font-bold text-lg" style="color:var(--color-text-primary)">Applied!</p>
                        <p class="text-sm mt-1 mb-3" style="color:var(--color-text-muted)">You have already applied for this job.</p>
                        <div class="inline-flex"><?= status_badge($my_status) ?></div>
                    </div>
                <?php else: ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="apply">
                        <button type="submit" class="btn-primary w-full text-base">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                            Apply Now
                        </button>
                    </form>
                    <p class="text-xs text-center mt-3" style="color:var(--color-text-muted)">Submit your proposal for this job</p>
                <?php endif; ?>

                <!-- Job Details List -->
                <div class="mt-6 pt-6 border-t" style="border-color:var(--color-border)">
                    <h3 class="text-sm font-bold mb-4 flex items-center gap-2" style="color:var(--color-text-primary)">
                        <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Job Details
                    </h3>
                    <div class="space-y-1">
                        <div class="detail-row">
                            <div class="detail-icon" style="background:rgba(99,102,241,0.08)">
                                <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-xs" style="color:var(--color-text-muted)">Category</p>
                                <p class="text-sm font-medium" style="color:var(--color-text-primary)"><?= e($job['category'] ?: 'Not specified') ?></p>
                            </div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-icon" style="background:rgba(245,158,11,0.08)">
                                <svg class="w-4 h-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-xs" style="color:var(--color-text-muted)">Duration</p>
                                <p class="text-sm font-medium" style="color:var(--color-text-primary)"><?= e($job['duration'] ?: 'Not specified') ?></p>
                            </div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-icon" style="background:rgba(139,92,246,0.08)">
                                <svg class="w-4 h-4 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-xs" style="color:var(--color-text-muted)">Experience</p>
                                <p class="text-sm font-medium capitalize" style="color:var(--color-text-primary)"><?= e($job['experience_level'] ?: 'Not specified') ?></p>
                            </div>
                        </div>
                        <?php if ($job['gender_requirement'] !== 'any'): ?>
                        <div class="detail-row">
                            <div class="detail-icon" style="background:rgba(236,72,153,0.08)">
                                <svg class="w-4 h-4 text-pink-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-xs" style="color:var(--color-text-muted)">Gender</p>
                                <p class="text-sm font-medium capitalize" style="color:var(--color-text-primary)"><?= e($job['gender_requirement']) ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="detail-row">
                            <div class="detail-icon" style="background:rgba(245,158,11,0.08)">
                                <svg class="w-4 h-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-xs" style="color:var(--color-text-muted)">Positions</p>
                                <p class="text-sm font-medium" style="color:var(--color-text-primary)"><?= $positions_filled ?>/<?= $freelancers_needed ?> filled</p>
                            </div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-icon" style="background:rgba(99,102,241,0.08)">
                                <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-xs" style="color:var(--color-text-muted)">Posted</p>
                                <p class="text-sm font-medium" style="color:var(--color-text-primary)"><?= date('M j, Y', strtotime($job['created_at'])) ?></p>
                            </div>
                        </div>
                        <?php if ($job['deadline']): ?>
                        <div class="detail-row">
                            <div class="detail-icon" style="background:rgba(239,68,68,0.08)">
                                <svg class="w-4 h-4 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-xs" style="color:var(--color-text-muted)">Deadline</p>
                                <p class="text-sm font-medium" style="color:var(--color-text-primary)"><?= date('M j, Y', strtotime($job['deadline'])) ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Similar Jobs (Bottom) -->
    <?php if (!empty($similar)): ?>
    <div class="mt-12 animate-fade-up-d3">
        <h2 class="text-2xl font-bold mb-6 flex items-center gap-3" style="color:var(--color-text-primary)">
            <div class="w-9 h-9 rounded-xl flex items-center justify-center" style="background:linear-gradient(135deg,#f59e0b,#f97316)">
                <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            </div>
            Similar Jobs
        </h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
            <?php foreach ($similar as $sj): ?>
            <a href="<?= e(base_url('freelancer/view_job.php?id=' . $sj['id'])) ?>" class="similar-card p-5 block">
                <div class="flex items-center gap-3 mb-4">
                    <?php if ($sj['logo_image']): ?>
                        <img src="<?= e(base_url('uploads/images/' . $sj['logo_image'])) ?>" alt="" class="w-11 h-11 rounded-xl object-contain border" style="border-color:var(--color-border)">
                    <?php else: ?>
                        <div class="w-11 h-11 rounded-xl flex items-center justify-center text-indigo-600 font-bold text-lg" style="background:rgba(99,102,241,0.1)"><?= strtoupper(mb_substr($sj['company_name'] ?? 'C', 0, 1)) ?></div>
                    <?php endif; ?>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold truncate" style="color:var(--color-text-primary)"><?= e($sj['title']) ?></p>
                        <p class="text-xs truncate" style="color:var(--color-text-muted)"><?= e($sj['company_name']) ?></p>
                    </div>
                </div>
                <div class="flex items-center justify-between mb-3">
                    <span class="text-base font-bold text-indigo-600">$<?= number_format((float) $sj['budget'], 0) ?></span>
                    <span class="text-xs px-2 py-1 rounded-lg font-medium" style="background:rgba(99,102,241,0.08);color:#6366f1"><?= e(ucfirst($sj['experience_level'])) ?></span>
                </div>
                <?php if (!empty($sj['skills'])): ?>
                    <div class="flex flex-wrap gap-1.5">
                        <?php foreach ($sj['skills'] as $sk): ?>
                            <span class="text-[10px] px-2 py-0.5 rounded-full font-medium" style="background:rgba(99,102,241,0.06);color:#6366f1"><?= e($sk) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/freelancer_footer.php'; ?>
