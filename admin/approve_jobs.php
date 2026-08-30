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
                $stmt = $conn->prepare("UPDATE jobs SET status = 'closed' WHERE id = ? AND status NOT IN ('closed', 'cancelled')");
                $stmt->bind_param('i', $job_id);
                $stmt->execute();

                if ($stmt->affected_rows > 0) {
                    $stmt2 = $conn->prepare("SELECT j.title, c.user_id FROM jobs j JOIN companies c ON j.company_id = c.id WHERE j.id = ?");
                    $stmt2->bind_param('i', $job_id);
                    $stmt2->execute();
                    $job_info = $stmt2->get_result()->fetch_assoc();
                    $stmt2->close();

                    if ($job_info) {
                        create_notification($conn, (int) $job_info['user_id'], 'job_hidden',
                            "Hidden your job \"{$job_info['title']}\" for policy violation.",
                            'company/manage_jobs.php', $_SESSION['user_id'] ?? null);
                    }
                    set_flash('success', 'Job has been hidden successfully.');
                } else {
                    set_flash('error', 'Could not hide this job or it is already hidden.');
                }
                $stmt->close();

            } elseif ($action === 'restore') {
                $stmt = $conn->prepare("UPDATE jobs SET status = 'open' WHERE id = ? AND status IN ('closed', 'cancelled')");
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
                            "Restored your job \"{$job_info['title']}\". It is now visible again.",
                            'company/manage_jobs.php', $_SESSION['user_id'] ?? null);
                    }
                    set_flash('success', 'Job has been restored successfully.');
                } else {
                    set_flash('error', 'Could not restore this job or it is not hidden.');
                }
                $stmt->close();

            } elseif ($action === 'remove') {
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
$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all', 'active', 'hidden'], true)) {
    $filter = 'all';
}

// Build query based on filter
$where = match($filter) {
    'active' => "j.status NOT IN ('closed', 'cancelled')",
    'hidden' => "j.status IN ('closed', 'cancelled')",
    default => "1=1",
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
               j.experience_level, j.deadline, j.duration,
               j.attachment,
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

$page_title = 'Manage Jobs';
require __DIR__ . '/includes/admin_header.php';
?>

<!-- Page Header -->
<div class="mb-5 admin-fade">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold" style="color:var(--color-text-primary)"><?= e('Manage Jobs') ?></h1>
            <p class="text-sm mt-0.5" style="color:var(--color-text-muted)"><?= e('Review and manage jobs posted on the platform.') ?></p>
        </div>
        <span class="text-xs" style="color:var(--color-text-muted)"><?= $total ?> job<?= $total !== 1 ? 's' : '' ?></span>
    </div>
</div>

<!-- Filter Tabs -->
<div class="flex gap-1.5 mb-5 admin-fade">
    <a href="?filter=active"
       class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors <?= $filter === 'active' ? 'bg-indigo-600 text-white' : 'border hover:border-indigo-300 dark:hover:border-indigo-700' ?>"
       style="<?= $filter !== 'active' ? 'color:var(--color-text-muted);border-color:var(--color-border);background:var(--color-card)' : '' ?>">
        Active
    </a>
    <a href="?filter=hidden"
       class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors <?= $filter === 'hidden' ? 'bg-indigo-600 text-white' : 'border hover:border-indigo-300 dark:hover:border-indigo-700' ?>"
       style="<?= $filter !== 'hidden' ? 'color:var(--color-text-muted);border-color:var(--color-border);background:var(--color-card)' : '' ?>">
        Hidden
    </a>
    <a href="?filter=all"
       class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors <?= $filter === 'all' ? 'bg-indigo-600 text-white' : 'border hover:border-indigo-300 dark:hover:border-indigo-700' ?>"
       style="<?= $filter !== 'all' ? 'color:var(--color-text-muted);border-color:var(--color-border);background:var(--color-card)' : '' ?>">
        All
    </a>
</div>

<?php if (empty($jobs)): ?>
    <div class="card text-center py-12 admin-fade">
        <svg class="w-12 h-12 mx-auto mb-3 opacity-20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <p class="text-sm" style="color:var(--color-text-muted)">No <?= $filter === 'all' ? '' : $filter ?> jobs found.</p>
    </div>
<?php else: ?>
    <div class="space-y-3">
        <?php foreach ($jobs as $idx => $job): ?>
            <div class="card admin-fade" style="transition-delay:<?= ($idx * 0.03) ?>s; <?= ($job['status'] === 'closed' || $job['status'] === 'cancelled') ? 'border-left:3px solid #ef4444;' : '' ?>">
                <div class="flex flex-col sm:flex-row sm:items-start gap-3">
                    <div class="flex-1 min-w-0">
                        <!-- Company + Status -->
                        <div class="flex items-center gap-2 mb-1.5">
                            <?php if ($job['logo_image']): ?>
                                <img src="<?= e(base_url('uploads/images/' . $job['logo_image'])) ?>" alt="" class="w-5 h-5 rounded object-cover">
                            <?php else: ?>
                                <div class="w-5 h-5 rounded bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center text-indigo-600 font-bold text-[8px]">
                                    <?= e(_first_char($job['company_name'])) ?>
                                </div>
                            <?php endif; ?>
                            <span class="text-xs" style="color:var(--color-text-muted)"><?= e($job['company_name']) ?></span>
                            <?= status_badge($job['status']) ?>
                        </div>

                        <!-- Job Title -->
                        <h3 class="text-sm font-semibold mb-1" style="color:var(--color-text-primary)"><?= e($job['title']) ?></h3>

                        <!-- Meta -->
                        <div class="flex items-center gap-3 text-xs flex-wrap mb-1.5" style="color:var(--color-text-muted)">
                            <span class="font-semibold text-indigo-600"><?= e(number_format((float) $job['budget'], 0)) ?> MMK</span>
                            <?php if ($job['category']): ?>
                                <span><?= e($job['category']) ?></span>
                            <?php endif; ?>
                            <span class="capitalize"><?= e(str_replace('_', ' ', $job['experience_level'])) ?></span>
                            <?php if ($job['deadline']): ?>
                                <span>Due <?= e(date('M j, Y', strtotime($job['deadline']))) ?></span>
                            <?php endif; ?>
                            <span><?= e($job['created_at']) ?></span>
                        </div>

                        <!-- Description snippet -->
                        <p class="text-xs mb-1.5 line-clamp-2" style="color:var(--color-text-secondary)"><?= e(mb_strimwidth($job['description'] ?? '', 0, 150, '...')) ?></p>

                        <!-- Skills -->
                        <?php if (!empty($job['skills'])): ?>
                            <div class="flex flex-wrap gap-1">
                                <?php foreach (array_slice($job['skills'], 0, 5) as $sk): ?>
                                    <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium" style="background:rgba(99,102,241,0.08);color:#6366f1"><?= e($sk) ?></span>
                                <?php endforeach; ?>
                                <?php if (count($job['skills']) > 5): ?>
                                    <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium" style="background:rgba(99,102,241,0.08);color:#6366f1">+<?= count($job['skills']) - 5 ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Actions -->
                    <div class="flex gap-1.5 flex-shrink-0 sm:flex-col">
                        <a href="<?= e(base_url('admin/view_job.php?id=' . $job['id'])) ?>" class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-md text-xs font-medium border hover:border-indigo-300 dark:hover:border-indigo-700 transition-colors" style="color:var(--color-text-muted);border-color:var(--color-border)">
                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            View Details
                        </a>
                        <?php if (in_array($job['status'], ['open', 'in_review', 'hired', 'in_progress'])): ?>
                            <form method="POST" onsubmit="return confirm('Are you sure you want to close this job?')">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                                <input type="hidden" name="action" value="hide">
                                <button type="submit" class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-md text-xs font-medium bg-red-50 text-red-600 hover:bg-red-100 dark:bg-red-900/20 dark:text-red-400 dark:hover:bg-red-900/40 transition-colors">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                                    Hide
                                </button>
                            </form>
                        <?php elseif (in_array($job['status'], ['closed', 'cancelled'])): ?>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                                <input type="hidden" name="action" value="restore">
                                <button type="submit" class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-md text-xs font-medium bg-green-50 text-green-600 hover:bg-green-100 dark:bg-green-900/20 dark:text-green-400 dark:hover:bg-green-900/40 transition-colors">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                    Restore
                                </button>
                            </form>
                            <form method="POST" onsubmit="return confirm('Are you sure you want to PERMANENTLY delete this job? This cannot be undone.')">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                                <input type="hidden" name="action" value="remove">
                                <button type="submit" class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-md text-xs font-medium bg-red-50 text-red-600 hover:bg-red-100 dark:bg-red-900/20 dark:text-red-400 dark:hover:bg-red-900/40 transition-colors">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
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
        <div class="flex justify-center gap-1.5 mt-5">
            <?php
            $qs = $_GET;
            for ($i = 1; $i <= $total_pages; $i++):
                $qs['page'] = $i;
            ?>
                <a href="?<?= e(http_build_query($qs)) ?>"
                   class="px-2.5 py-1 text-xs rounded-md border transition-colors <?= $i === $page ? 'bg-indigo-600 text-white border-indigo-600' : '' ?>"
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
