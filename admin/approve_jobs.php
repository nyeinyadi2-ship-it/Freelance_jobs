<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';

require_role('admin');

// Handle moderation actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $job_id = (int) ($_POST['job_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($job_id > 0 && in_array($action, ['hide', 'restore', 'remove'], true)) {
        try {
            if ($action === 'hide') {
                $stmt = $conn->prepare("UPDATE jobs SET status = 'rejected' WHERE id = ? AND status = 'approved'");
                $stmt->bind_param('i', $job_id);
                $stmt->execute();

                if ($stmt->affected_rows > 0) {
                    // Notify company
                    $stmt2 = $conn->prepare("SELECT j.title, c.user_id FROM jobs j JOIN companies c ON j.company_id = c.id WHERE j.id = ?");
                    $stmt2->bind_param('i', $job_id);
                    $stmt2->execute();
                    $job_info = $stmt2->get_result()->fetch_assoc();
                    $stmt2->close();

                    if ($job_info) {
                        create_notification($conn, (int) $job_info['user_id'], 'job_hidden',
                            "Your job \"{$job_info['title']}\" has been hidden by an admin for policy violation.",
                            'company/manage_jobs.php');
                    }
                    set_flash('success', 'Job has been hidden successfully.');
                } else {
                    set_flash('error', 'Could not hide this job.');
                }
                $stmt->close();

            } elseif ($action === 'restore') {
                $stmt = $conn->prepare("UPDATE jobs SET status = 'approved' WHERE id = ? AND status = 'rejected'");
                $stmt->bind_param('i', $job_id);
                $stmt->execute();

                if ($stmt->affected_rows > 0) {
                    $stmt2 = $conn->prepare("SELECT j.title, c.user_id FROM jobs j JOIN companies c ON j.company_id = c.id WHERE j.id = ?");
                    $stmt2->bind_param('i', $job_id);
                    $stmt2->execute();
                    $job_info = $stmt2->get_result()->fetch_assoc();
                    $stmt2->close();

                    if ($job_info) {
                        create_notification($conn, (int) $job_info['user_id'], 'job_restored',
                            "Your job \"{$job_info['title']}\" has been restored and is now visible again.",
                            'company/manage_jobs.php');
                    }
                    set_flash('success', 'Job has been restored successfully.');
                } else {
                    set_flash('error', 'Could not restore this job.');
                }
                $stmt->close();

            } elseif ($action === 'remove') {
                // Hard delete
                $stmt = $conn->prepare("DELETE FROM jobs WHERE id = ?");
                $stmt->bind_param('i', $job_id);
                $stmt->execute();

                if ($stmt->affected_rows > 0) {
                    set_flash('success', 'Job has been permanently removed.');
                } else {
                    set_flash('error', 'Could not remove this job.');
                }
                $stmt->close();
            }
        } catch (mysqli_sql_exception $e) {
            set_flash('error', 'An error occurred while processing the request.');
        }
    }

    redirect('admin/approve_jobs.php');
}

// Filter
$filter = $_GET['filter'] ?? 'active';
if (!in_array($filter, ['all', 'active', 'hidden'], true)) {
    $filter = 'active';
}

// Build query based on filter
$where = match($filter) {
    'active' => "j.status = 'approved'",
    'hidden' => "j.status = 'rejected'",
    default => "j.status IN ('approved', 'rejected')",
};

$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Count total
$total = 0;
$total_pages = 1;
try {
    $count_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM jobs j WHERE {$where}");
    $count_stmt->execute();
    $total = (int) $count_stmt->get_result()->fetch_assoc()['cnt'];
    $count_stmt->close();
    $total_pages = max(1, ceil($total / $per_page));
} catch (mysqli_sql_exception $e) {}

// Fetch jobs
$jobs = [];
try {
    $stmt = $conn->prepare("
        SELECT j.id, j.title, j.description, j.budget, j.created_at, j.category, j.status,
               j.experience_level, j.gender_requirement, j.deadline, j.duration,
               j.freelancers_needed, j.visibility, j.attachment,
               c.company_name, c.logo_image
        FROM jobs j
        JOIN companies c ON j.company_id = c.id
        WHERE {$where}
        ORDER BY j.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param('ii', $per_page, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        // Fetch skills
        $ss = $conn->prepare('SELECT s.skill_name FROM job_skills js JOIN skills s ON js.skill_id = s.id WHERE js.job_id = ?');
        $ss->bind_param('i', $row['id']);
        $ss->execute();
        $sr = $ss->get_result();
        $row['skills'] = [];
        while ($sk = $sr->fetch_assoc()) {
            $row['skills'][] = $sk['skill_name'];
        }
        $ss->close();
        $jobs[] = $row;
    }
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    $jobs = [];
}

$page_title = 'Moderate Jobs';
require __DIR__ . '/includes/admin_header.php';
?>

<style>
.skill-tag { display:inline-flex; padding:0.15rem 0.5rem; border-radius:9999px; font-size:0.7rem; font-weight:500; background:rgba(99,102,241,0.08); color:#6366f1; }
.remote-badge { display:inline-flex; align-items:center; gap:0.25rem; padding:0.2rem 0.5rem; border-radius:9999px; font-size:0.7rem; font-weight:600; background:rgba(16,185,129,0.1); color:#10b981; }
.filter-tab { padding:0.5rem 1rem; border-radius:0.5rem; font-size:0.875rem; font-weight:500; transition:all 0.2s; cursor:pointer; text-decoration:none; }
.filter-tab.active { background:linear-gradient(135deg,#4f46e5,#7c3aed); color:white; box-shadow:0 2px 8px rgba(79,70,229,0.3); }
.filter-tab:not(.active) { color:var(--color-text-muted); background:var(--color-card); border:1px solid var(--color-border); }
.filter-tab:not(.active):hover { border-color:#6366f1; color:#6366f1; }
</style>

<!-- Page Header -->
<div class="mb-6 admin-fade">
    <div class="flex items-center gap-3 mb-1">
        <a href="<?= e(base_url('admin/admin_dashboard.php')) ?>" class="text-sm hover:underline" style="color:var(--color-text-muted)">Dashboard</a>
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-text-placeholder)"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        <span class="text-sm font-medium" style="color:var(--color-text-primary)">Moderate Jobs</span>
    </div>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="text-2xl font-bold" style="color:var(--color-text-primary)">Moderate Jobs</h1>
        <p class="text-sm" style="color:var(--color-text-muted)"><?= $total ?> job<?= $total !== 1 ? 's' : '' ?> found</p>
    </div>
</div>

<!-- Filter Tabs -->
<div class="flex gap-2 mb-6 admin-fade">
    <a href="?filter=active" class="filter-tab <?= $filter === 'active' ? 'active' : '' ?>">
        <span class="flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
            Active
        </span>
    </a>
    <a href="?filter=hidden" class="filter-tab <?= $filter === 'hidden' ? 'active' : '' ?>">
        <span class="flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
            Hidden
        </span>
    </a>
    <a href="?filter=all" class="filter-tab <?= $filter === 'all' ? 'active' : '' ?>">
        <span class="flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
            All
        </span>
    </a>
</div>

<?php if (empty($jobs)): ?>
    <div class="card text-center py-12 admin-fade">
        <svg class="w-16 h-16 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <p class="text-sm" style="color:var(--color-text-muted)">No <?= $filter === 'all' ? '' : $filter ?> jobs found.</p>
    </div>
<?php else: ?>
    <div class="space-y-4">
        <?php foreach ($jobs as $idx => $job): ?>
            <div class="card admin-fade" style="transition-delay:<?= ($idx * 0.05) ?>s; <?= $job['status'] === 'rejected' ? 'border-left:3px solid #ef4444;' : '' ?>">
                <div class="flex flex-col lg:flex-row lg:items-start gap-4">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-3 mb-2">
                            <?php if ($job['logo_image']): ?>
                                <img src="<?= e(base_url('uploads/images/' . $job['logo_image'])) ?>" alt="" class="w-8 h-8 rounded-lg object-cover">
                            <?php else: ?>
                                <div class="w-8 h-8 rounded-lg bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center text-indigo-600 font-bold text-xs">
                                    <?= e(_first_char($job['company_name'])) ?>
                                </div>
                            <?php endif; ?>
                            <span class="text-sm font-medium" style="color:var(--color-text-muted)">Posted by <?= e($job['company_name']) ?></span>
                            <?php if ($job['status'] === 'rejected'): ?>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                                    Hidden
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center gap-2 flex-wrap mb-2">
                            <h2 class="text-lg font-semibold" style="color:var(--color-text-primary)"><?= e($job['title']) ?></h2>
                            <span class="remote-badge">
                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                Remote
                            </span>
                        </div>
                        <p class="text-sm mb-2" style="color:var(--color-text-secondary)"><?= nl2br(e(mb_strimwidth($job['description'] ?? '', 0, 200, '...'))) ?></p>

                        <!-- Job Meta -->
                        <div class="flex items-center gap-4 text-xs mb-2 flex-wrap" style="color:var(--color-text-muted)">
                            <span class="font-bold" style="color:#6366f1">$<?= e(number_format((float) $job['budget'], 2)) ?></span>
                            <?php if ($job['category']): ?>
                                <span class="flex items-center gap-1">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                                    <?= e($job['category']) ?>
                                </span>
                            <?php endif; ?>
                            <span class="capitalize"><?= e(str_replace('_', ' ', $job['experience_level'])) ?></span>
                            <?php if ($job['deadline']): ?>
                                <span class="flex items-center gap-1">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                    <?= e(date('M j, Y', strtotime($job['deadline']))) ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($job['duration']): ?>
                                <span>Duration: <?= e($job['duration']) ?></span>
                            <?php endif; ?>
                            <span><?= (int)$job['freelancers_needed'] ?> freelancer<?= (int)$job['freelancers_needed'] > 1 ? 's' : '' ?> needed</span>
                            <?php if ($job['attachment']): ?>
                                <span class="flex items-center gap-1">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                    <a href="<?= e(attachment_url($job['attachment'])) ?>" target="_blank" style="color:#6366f1">View attachment</a>
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($job['skills'])): ?>
                            <div class="flex flex-wrap gap-1.5 mb-2">
                                <?php foreach (array_slice($job['skills'], 0, 8) as $sk): ?>
                                    <span class="skill-tag"><?= e($sk) ?></span>
                                <?php endforeach; ?>
                                <?php if (count($job['skills']) > 8): ?>
                                    <span class="skill-tag">+<?= count($job['skills']) - 8 ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="flex items-center gap-3 text-xs" style="color:var(--color-text-placeholder)">
                            <span><?= e($job['created_at']) ?></span>
                        </div>
                    </div>

                    <!-- Moderation Actions -->
                    <div class="flex gap-2 flex-shrink-0 lg:flex-col">
                        <?php if ($job['status'] === 'approved'): ?>
                            <form method="POST" onsubmit="return confirm('Are you sure you want to hide this job? It will no longer be visible to freelancers.')">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                                <input type="hidden" name="action" value="hide">
                                <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium transition-all bg-red-50 text-red-600 hover:bg-red-100 dark:bg-red-900/20 dark:text-red-400 dark:hover:bg-red-900/40">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                                    Hide
                                </button>
                            </form>
                        <?php elseif ($job['status'] === 'rejected'): ?>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                                <input type="hidden" name="action" value="restore">
                                <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium transition-all bg-green-50 text-green-600 hover:bg-green-100 dark:bg-green-900/20 dark:text-green-400 dark:hover:bg-green-900/40">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                    Restore
                                </button>
                            </form>
                            <form method="POST" onsubmit="return confirm('Are you sure you want to PERMANENTLY delete this job? This cannot be undone.')">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                                <input type="hidden" name="action" value="remove">
                                <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium transition-all bg-red-50 text-red-600 hover:bg-red-100 dark:bg-red-900/20 dark:text-red-400 dark:hover:bg-red-900/40">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    Delete
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="flex justify-center gap-2 mt-6">
            <?php
            $qs = $_GET;
            for ($i = 1; $i <= $total_pages; $i++):
                $qs['page'] = $i;
            ?>
                <a href="?<?= e(http_build_query($qs)) ?>"
                   class="px-3 py-1 text-sm rounded-lg border <?= $i === $page ? 'bg-indigo-600 text-white border-indigo-600' : 'hover:opacity-80' ?>"
                   style="<?= $i !== $page ? 'color:var(--color-text-secondary);border-color:var(--color-border);background:var(--color-card)' : '' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>

<script>
(function() {
    var els = document.querySelectorAll('.admin-fade');
    els.forEach(function(el) { el.classList.add('animate'); });
    var obs = new IntersectionObserver(function(entries) {
        entries.forEach(function(e) { if (e.isIntersecting) e.target.classList.add('visible'); });
    }, { threshold: 0.1 });
    els.forEach(function(el) { obs.observe(el); });
})();
</script>
