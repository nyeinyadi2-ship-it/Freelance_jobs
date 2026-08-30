<?php
$page_title = 'Job Details';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';
require_once __DIR__ . '/../config/chat.php';

require_role('company');

$user = current_user();
$company_id = get_company_id($conn, (int) $user['user_id']);
if (!$company_id) { set_flash('error', 'Company profile not found.'); redirect('auth/login.php'); }

$job_id = (int) ($_GET['id'] ?? 0);
if ($job_id <= 0) { set_flash('error', 'Invalid job ID.'); redirect('index.php'); }

// Fetch job (must belong to this company)
$stmt = $conn->prepare("
    SELECT j.*, c.company_name, c.logo_image,
           (SELECT assignment_type FROM assignments WHERE job_id = j.id LIMIT 1) AS assignment_type
    FROM jobs j JOIN companies c ON j.company_id = c.id
    WHERE j.id = ? AND j.company_id = ?
");
$stmt->bind_param('ii', $job_id, $company_id);
$stmt->execute();
$job = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$job) {
    set_flash('error', 'Job not found.');
    redirect('index.php');
}

// Application count & applicants
$app_count = 0;
$apps = [];
$st = $conn->prepare("
    SELECT ja.id, ja.status, ja.applied_at, ja.freelancer_id, f.full_name, f.title AS fl_title, u.profile_image
    FROM job_applications ja
    JOIN freelancers f ON ja.freelancer_id = f.id
    JOIN users u ON f.user_id = u.id
    WHERE ja.job_id = ?
    ORDER BY ja.applied_at DESC
");
$st->bind_param('i', $job_id);
$st->execute();
$sr = $st->get_result();
while ($row = $sr->fetch_assoc()) { $apps[] = $row; $app_count++; }
$st->close();

// Assignment info
$assignments = [];
$st = $conn->prepare("
    SELECT a.*, f.full_name, u.profile_image
    FROM assignments a
    JOIN freelancers f ON a.freelancer_id = f.id
    JOIN users u ON f.user_id = u.id
    WHERE a.job_id = ? AND a.status NOT IN ('rejected', 'cancelled')
");
$st->bind_param('i', $job_id);
$st->execute();
$assgn_r = $st->get_result();
while ($row = $assgn_r->fetch_assoc()) { $assignments[] = $row; }
$st->close();

// Milestones
$milestones = [];
$ms_st = $conn->prepare("SELECT m.*, f.full_name AS assigned_freelancer FROM milestones m LEFT JOIN freelancers f ON m.freelancer_id = f.id WHERE m.job_id = ? ORDER BY m.sort_order ASC");
$ms_st->bind_param('i', $job_id);
$ms_st->execute();
$ms_r = $ms_st->get_result();
while ($row = $ms_r->fetch_assoc()) { 
    $milestones[] = $row;
}
$ms_st->close();

// Company info
$company_info = null;
$ci_st = $conn->prepare("SELECT c.*, u.username, u.email FROM companies c JOIN users u ON c.user_id = u.id WHERE c.id = ?");
$ci_st->bind_param('i', $job['company_id']);
$ci_st->execute();
$company_info = $ci_st->get_result()->fetch_assoc();
$ci_st->close();

require __DIR__ . '/../includes/header.php';
?>

<style>
.vj-container { max-width: 900px; margin: 0 auto; }
.vj-hero {
    background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 50%, #3b82f6 100%);
    border-radius: 1.25rem; padding: 2.5rem 2rem; color: #fff; position: relative; overflow: hidden; margin-bottom: 2rem;
}
.vj-hero::before { content:''; position:absolute; top:-50%; right:-20%; width:400px; height:400px; background:radial-gradient(circle,rgba(255,255,255,0.08) 0%,transparent 70%); border-radius:50%; }
.vj-status { display:inline-flex; align-items:center; gap:6px; padding:5px 14px; border-radius:999px; font-size:0.75rem; font-weight:700; background:rgba(255,255,255,0.15); backdrop-filter:blur(8px); }
.vj-status::before { content:''; width:7px; height:7px; border-radius:50%; }
.vj-status-open::before { background:#4ade80; }
.vj-status-pending::before { background:#fbbf24; }
.vj-status-completed::before { background:#60a5fa; }
.vj-status-rejected::before { background:#f87171; }
.vj-status-closed::before { background:#9ca3af; }
.vj-status-cancelled::before { background:#9ca3af; }
.vj-section { background:var(--color-surface,#fff); border:1px solid var(--color-border,#e5e7eb); border-radius:1rem; padding:1.5rem; margin-bottom:1.5rem; }
.vj-section h3 { font-size:1rem; font-weight:700; margin-bottom:1rem; color:var(--color-text-primary); }
.vj-app-row { display:flex; align-items:center; gap:12px; padding:0.75rem 0; border-bottom:1px solid var(--color-border,#e5e7eb); }
.vj-app-row:last-child { border-bottom:none; }
.vj-app-avatar { width:40px; height:40px; border-radius:50%; object-fit:cover; border:2px solid var(--color-border); }
.vj-app-initial { width:40px; height:40px; border-radius:50%; background:linear-gradient(135deg,#2563eb,#3b82f6); display:flex; align-items:center; justify-content:center; color:#fff; font-weight:700; font-size:0.85rem; flex-shrink:0; }
.vj-badge { display:inline-block; padding:3px 10px; border-radius:999px; font-size:0.7rem; font-weight:700; }
.vj-badge-pending { background:rgba(251,191,36,0.15); color:#d97706; }
.vj-badge-accepted { background:rgba(5,150,105,0.15); color:#059669; }
.vj-badge-rejected { background:rgba(220,38,38,0.15); color:#dc2626; }
.vj-actions { display:flex; gap:10px; flex-wrap:wrap; }
.vj-btn { display:inline-flex; align-items:center; gap:6px; padding:0.6rem 1.25rem; border-radius:0.75rem; font-size:0.85rem; font-weight:700; text-decoration:none; transition:all 0.2s ease; }
.vj-btn-primary { background:linear-gradient(135deg,#7c3aed,#6366f1); color:#fff; box-shadow:0 4px 14px rgba(99,102,241,0.25); }
.vj-btn-primary:hover { transform:translateY(-1px); box-shadow:0 6px 20px rgba(99,102,241,0.35); }
.vj-btn-outline { border:1.5px solid var(--color-border,#e5e7eb); color:var(--color-text-primary); background:transparent; }
.vj-btn-outline:hover { border-color:#2563eb; color:#2563eb; }
</style>

<div class="vj-container" style="padding:1rem 0 3rem">
    <!-- Hero -->
    <div class="vj-hero">
        <div class="relative z-10">
            <div class="mb-4">
                <button onclick="history.back()" class="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium text-gray-600 hover:text-gray-900 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors dark:text-gray-300 dark:hover:text-white dark:bg-gray-800 dark:hover:bg-gray-700">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    Back
                </button>
            </div>
            <div class="flex items-center gap-3 mb-4">
                <?php if (!empty($job['logo_image'])): ?>
                    <img src="<?= e(base_url('uploads/images/' . $job['logo_image'])) ?>" alt="" style="width:48px;height:48px;border-radius:12px;object-fit:cover;border:2px solid rgba(255,255,255,0.2)">
                <?php else: ?>
                    <div style="width:48px;height:48px;border-radius:12px;background:rgba(255,255,255,0.15);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1.1rem;"><?= e(strtoupper(substr($job['company_name'], 0, 1))) ?></div>
                <?php endif; ?>
                <div>
                    <p class="font-bold text-base"><?= e($job['company_name']) ?></p>
                    <p class="text-blue-200 text-xs">Posted: <?= date('M j, Y', strtotime($job['created_at'])) ?></p>
                </div>
            </div>
            <h1 class="text-2xl md:text-3xl font-extrabold mb-3"><?= e($job['title']) ?></h1>
            <div class="flex flex-wrap items-center gap-3">
                <span class="vj-status vj-status-<?= $job['status'] ?>"><?= ucfirst(e($job['status'])) ?></span>
                <?php if (!empty($job['assignment_type']) && $job['assignment_type'] === 'direct_hire'): ?>
                    <span style="background:rgba(147,51,234,0.3);border:1px solid rgba(147,51,234,0.4);padding:5px 14px;border-radius:999px;font-size:0.75rem;font-weight:700;color:#e9d5ff;display:inline-flex;align-items:center;gap:4px;">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        Direct Hire
                    </span>
                <?php endif; ?>
                <?php
                $active_count = 0;
                $hc_st = $conn->prepare("SELECT SUM(CASE WHEN status NOT IN ('completed', 'rejected', 'cancelled') THEN 1 ELSE 0 END) AS active FROM assignments WHERE job_id = ? AND status NOT IN ('rejected', 'cancelled')");
                $hc_st->bind_param('i', $job_id);
                $hc_st->execute();
                $hc_row = $hc_st->get_result()->fetch_assoc();
                $active_count = (int) ($hc_row['active'] ?? 0);
                $hc_st->close();
                $is_filled = $active_count >= 1;
                ?>
                <?php if ($job['status'] === 'approved'): ?>
                    <span style="display:inline-flex;align-items:center;gap:6px;padding:5px 14px;border-radius:999px;font-size:0.75rem;font-weight:700;background:<?= $is_filled ? 'rgba(74,222,128,0.3)' : 'rgba(255,255,255,0.15)' ?>;border:1px solid <?= $is_filled ? 'rgba(74,222,128,0.3)' : 'rgba(255,255,255,0.15)' ?>"><?= $is_filled ? 'Filled' : 'Open' ?></span>
                <?php endif; ?>
                <?php if ($job['category']): ?>
                    <span style="background:rgba(255,255,255,0.15);padding:5px 14px;border-radius:999px;font-size:0.75rem;font-weight:600;"><?= e($job['category']) ?></span>
                <?php endif; ?>
                <?php if ($job['experience_level']): ?>
                    <span style="background:rgba(255,255,255,0.15);padding:5px 14px;border-radius:999px;font-size:0.75rem;font-weight:600;"><?= e(ucfirst($job['experience_level'])) ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Quick Info -->
    <div class="grid sm:grid-cols-4 gap-4 mb-6">
        <div class="vj-section" style="text-align:center;margin-bottom:0">
            <p style="font-size:0.75rem;color:var(--color-text-placeholder);margin-bottom:4px">Budget (<?= ($job['payment_type'] ?? 'fixed') === 'fixed' ? 'Project Payment' : ucfirst(e($job['payment_type'] ?? 'fixed')) ?>)</p>
            <p style="font-size:1.5rem;font-weight:800;color:#2563eb"><?= e(number_format((float) $job['budget'], 2)) ?> MMK</p>
        </div>

        <div class="vj-section" style="text-align:center;margin-bottom:0">
            <p style="font-size:0.75rem;color:var(--color-text-placeholder);margin-bottom:4px">Posts</p>
            <p style="font-size:1.5rem;font-weight:800;color:var(--color-text-primary)"><?= $app_count ?></p>
        </div>
        <div class="vj-section" style="text-align:center;margin-bottom:0">
            <p style="font-size:0.75rem;color:var(--color-text-placeholder);margin-bottom:4px">Posted</p>
            <p style="font-size:1rem;font-weight:700;color:var(--color-text-primary)"><?= date('M j, Y', strtotime($job['created_at'])) ?></p>
        </div>
        <div class="vj-section" style="text-align:center;margin-bottom:0">
            <p style="font-size:0.75rem;color:var(--color-text-placeholder);margin-bottom:4px">Deadline</p>
            <p style="font-size:1rem;font-weight:700;color:var(--color-text-primary)"><?= $job['deadline'] ? date('M j, Y', strtotime($job['deadline'])) : 'Flexible' ?></p>
        </div>
    </div>

    <!-- Description -->
    <div class="vj-section">
        <h3>Job Description</h3>
        <p style="color:var(--color-text-secondary);line-height:1.8;white-space:pre-wrap"><?= e($job['description'] ?: 'No description provided.') ?></p>
    </div>

    <!-- Requirements -->
    <div class="vj-section">
        <h3>Requirements</h3>
        <p style="color:var(--color-text-secondary);line-height:1.8;white-space:pre-wrap"><?= e($job['requirements'] ?: 'No requirements listed.') ?></p>
    </div>

    <!-- Milestones -->
    <?php if (!empty($milestones)): ?>
        <div class="vj-section">
            <h3>Project Milestones</h3>
            <p style="font-size:0.75rem;color:var(--color-text-muted);margin-bottom:0.75rem"><?= count($milestones) ?> milestone<?= count($milestones) !== 1 ? 's' : '' ?></p>
            <div class="space-y-2">
                <?php foreach ($milestones as $ms): ?>
                    <div class="preview-ms-item flex flex-col sm:flex-row gap-2 items-center">
                        <div class="flex-1 min-w-0">
                            <p style="font-size:0.8125rem;font-weight:600;color:var(--color-text-primary)"><?= e($ms['title']) ?></p>
                            <?php if (!empty($ms['description'])): ?>
                                <p style="font-size:0.75rem;color:var(--color-text-secondary);margin-top:2px;"><?= e($ms['description']) ?></p>
                            <?php endif; ?>
                            <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1">
                                <?php if (!empty($ms['deadline'])): ?>
                                    <p style="font-size:0.7rem;color:var(--color-text-muted);font-weight:600;">Deadline: <?= date('M j, Y, g:i A', strtotime($ms['deadline'])) ?></p>
                                <?php endif; ?>
                                <?php if (!empty($ms['assigned_freelancer'])): ?>
                                    <p style="font-size:0.7rem;color:var(--color-text-muted)">Assigned to: <strong><?= e($ms['assigned_freelancer']) ?></strong></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <p class="preview-ms-amount"><?= number_format((float) $ms['amount'], 2) ?> MMK</p>
                            <?php if ($ms['status'] === 'draft'): ?>
                                <p style="font-size:0.65rem;color:var(--color-text-muted);text-transform:capitalize">Pending</p>
                            <?php else: ?>
                                <p style="font-size:0.65rem;color:var(--color-text-muted);text-transform:capitalize"><?= e(ucfirst(str_replace('_', ' ', $ms['status']))) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Attachment -->
    <?php if (!empty($job['attachment'])): ?>
        <div class="vj-section">
            <h3>Attachment</h3>
            <a href="<?= e(attachment_url($job['attachment'])) ?>" target="_blank" class="vj-btn vj-btn-outline" style="display:inline-flex">
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                Download Attachment
            </a>
        </div>
    <?php endif; ?>

    <!-- Company Information -->
    <?php if ($company_info): ?>
        <div class="vj-section">
            <h3>Company Information</h3>
            <div class="space-y-3">
                <?php if ($company_info['company_name']): ?>
                    <div class="flex items-center gap-3">
                        <?php if (!empty($company_info['logo_image'])): ?>
                            <img src="<?= e(base_url('uploads/images/' . $company_info['logo_image'])) ?>" alt="" style="width:40px;height:40px;border-radius:10px;object-fit:cover">
                        <?php else: ?>
                            <div style="width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,#6366f1,#8b5cf6);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:0.85rem;flex-shrink:0"><?= e(strtoupper(substr($company_info['company_name'], 0, 1))) ?></div>
                        <?php endif; ?>
                        <div>
                            <p style="font-weight:600;color:var(--color-text-primary)"><?= e($company_info['company_name']) ?></p>
                            <p style="font-size:0.75rem;color:var(--color-text-muted)">Posted by <?= e($company_info['username']) ?></p>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($company_info['location']): ?>
                    <div class="flex items-center gap-2" style="font-size:0.8125rem;color:var(--color-text-secondary)">
                        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        <?= e($company_info['location']) ?>
                    </div>
                <?php endif; ?>
                <?php if ($company_info['industry']): ?>
                    <div class="flex items-center gap-2" style="font-size:0.8125rem;color:var(--color-text-secondary)">
                        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                        <?= e($company_info['industry']) ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($company_info['website']): ?>
                    <div class="flex items-center gap-2" style="font-size:0.8125rem">
                        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
                        <a href="<?= e($company_info['website']) ?>" target="_blank" rel="noopener" style="color:#6366f1">Visit Website</a>
                    </div>
                <?php endif; ?>
                <?php if ($company_info['description']): ?>
                    <div style="font-size:0.8125rem;color:var(--color-text-secondary);line-height:1.7;white-space:pre-wrap;padding-top:0.5rem;border-top:1px solid var(--color-border)"><?= e($company_info['description']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Assignments -->
    <?php if (!empty($assignments)): ?>
        <div class="vj-section" style="border-left:4px solid #059669">
            <h3>Assigned Freelancers</h3>
            <div class="space-y-3">
                <?php foreach ($assignments as $assn): ?>
                <div class="vj-app-row" style="border-bottom:1px solid var(--color-border);padding-bottom:10px">
                    <?php if (!empty($assn['profile_image'])): ?>
                        <img src="<?= e(base_url('uploads/images/' . $assn['profile_image'])) ?>" alt="" class="vj-app-avatar">
                    <?php else: ?>
                        <div class="vj-app-initial"><?= e(strtoupper(substr($assn['full_name'], 0, 1))) ?></div>
                    <?php endif; ?>
                    <div class="flex-1">
                        <p class="font-semibold" style="color:var(--color-text-primary)"><?= e($assn['full_name']) ?></p>
                        <p style="font-size:0.75rem;color:var(--color-text-muted)">Status: <?= e(ucfirst($assn['status'])) ?></p>
                    </div>
                    <?= status_badge($assn['status']) ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Applications -->
    <div class="vj-section">
        <div class="flex items-center justify-between mb-4">
            <h3 style="margin-bottom:0">Applications (<?= $app_count ?>)</h3>
            <?php if ($app_count > 0): ?>
                <a href="<?= e(base_url('company/view_applications.php?id=' . $job_id)) ?>" class="text-sm font-semibold" style="color:#2563eb">View All</a>
            <?php endif; ?>
        </div>
        <?php if (empty($apps)): ?>
            <p style="color:var(--color-text-placeholder);text-align:center;padding:1.5rem 0">No applications yet.</p>
        <?php else: ?>
            <?php foreach (array_slice($apps, 0, 5) as $app): ?>
                <div class="vj-app-row">
                    <?php if (!empty($app['profile_image'])): ?>
                        <img src="<?= e(base_url('uploads/images/' . $app['profile_image'])) ?>" alt="" class="vj-app-avatar">
                    <?php else: ?>
                        <div class="vj-app-initial"><?= e(strtoupper(substr($app['full_name'], 0, 1))) ?></div>
                    <?php endif; ?>
                    <div class="flex-1 min-w-0">
                        <p class="font-semibold truncate" style="color:var(--color-text-primary)"><?= e($app['full_name']) ?></p>
                        <p style="font-size:0.75rem;color:var(--color-text-muted)"><?= e($app['fl_title'] ?? 'Freelancer') ?> &middot; <?= date('M j', strtotime($app['applied_at'])) ?></p>
                    </div>
                    <span class="vj-badge vj-badge-<?= $app['status'] ?>"><?= e(ucfirst($app['status'])) ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Actions -->
    <div class="vj-actions">
        <?php if ($job['status'] === 'open'): ?>
            <form action="<?= e(base_url('api/update_job_status.php')) ?>" method="POST" class="inline-block" onsubmit="return confirm('Mark this job as In Review? This will hide it from new applicants.');">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="job_id" value="<?= $job['id'] ?>">
                <input type="hidden" name="status" value="in_review">
                <button type="submit" class="vj-btn vj-btn-outline" style="color:#d97706;border-color:#fcd34d;">
                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    Mark as In Review
                </button>
            </form>
        <?php endif; ?>

        <?php if ($job['status'] === 'closed'): ?>
            <span class="vj-btn vj-btn-outline" style="color:#6b7280;border-color:#d1d5db;background:rgba(107,114,128,0.06);cursor:default;">
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                Job Closed
            </span>
        <?php elseif (!in_array($job['status'], ['completed', 'cancelled'])): ?>
            <form action="<?= e(base_url('api/close_job.php')) ?>" method="POST" class="inline-block" onsubmit="return confirm('Are you sure you want to close this job? All applications, milestones, and project records will be preserved.');">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="job_id" value="<?= $job['id'] ?>">
                <input type="hidden" name="redirect_to" value="company/view_job.php?id=<?= $job['id'] ?>">
                <button type="submit" class="vj-btn vj-btn-outline" style="color:#ef4444;border-color:#fca5a5;">
                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Close Job
                </button>
            </form>
            <form action="<?= e(base_url('api/update_job_status.php')) ?>" method="POST" class="inline-block" onsubmit="return confirm('Are you sure you want to cancel this project?');">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="job_id" value="<?= $job['id'] ?>">
                <input type="hidden" name="status" value="cancelled">
                <button type="submit" class="vj-btn vj-btn-outline" style="color:#ef4444;border-color:#fca5a5;">
                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                    Cancel Project
                </button>
            </form>
        <?php endif; ?>

        <?php if (!in_array($job['status'], ['completed', 'closed', 'cancelled'])): ?>
            <a href="<?= e(base_url('company/edit_job.php?id=' . $job_id)) ?>" class="vj-btn vj-btn-primary">
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                Edit Job
            </a>
        <?php endif; ?>

        <form action="<?= e(base_url('api/delete_job.php')) ?>" method="POST" class="inline-block" onsubmit="return confirm('Are you sure you want to delete this job? If this job has existing applications or assignments, deletion will be prevented and closing recommended.');">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="job_id" value="<?= $job['id'] ?>">
            <input type="hidden" name="redirect_to" value="company/view_job.php?id=<?= $job['id'] ?>">
            <button type="submit" class="vj-btn vj-btn-outline" style="color:#dc2626;border-color:#fca5a5;">
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                Delete Job
            </button>
        </form>

        <a href="<?= e(base_url('company/view_applications.php?id=' . $job_id)) ?>" class="vj-btn vj-btn-outline">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            View Applications
        </a>
        <a href="<?= e(base_url('index.php')) ?>" class="vj-btn vj-btn-outline">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            Back to Home
        </a>
    </div>
</div>


<?php require __DIR__ . '/../includes/footer.php'; ?>
