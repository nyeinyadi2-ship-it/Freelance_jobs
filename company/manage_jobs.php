<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

require_role('company');

$user = current_user();
$company_id = get_company_id($conn, (int) $user['user_id']);

if (!$company_id) {
    set_flash('error', 'Company profile not found.');
    redirect('auth/login.php');
}

$active_jobs = [];
$expired_jobs = [];
$closed_jobs = [];
$expired_ids_to_update = [];
$stmt = $conn->prepare('
    SELECT j.id, j.title, j.category, j.experience_level, j.budget, j.status, j.created_at,
           j.deadline, j.freelancers_needed, j.visibility, j.attachment,
           COUNT(DISTINCT ja.id) AS app_count,
           GROUP_CONCAT(DISTINCT s.skill_name SEPARATOR \',\') AS skills_concat
    FROM jobs j
    LEFT JOIN job_applications ja ON ja.job_id = j.id
    LEFT JOIN job_skills js ON js.job_id = j.id
    LEFT JOIN skills s ON s.id = js.skill_id
    WHERE j.company_id = ?
    GROUP BY j.id
    ORDER BY j.created_at DESC
');
$stmt->bind_param('i', $company_id);
$stmt->execute();
$result = $stmt->get_result();
$now = new DateTime();
while ($row = $result->fetch_assoc()) {
    $row['skills'] = !empty($row['skills_concat']) ? explode(',', $row['skills_concat']) : [];
    // Check if job should be treated as expired based on deadline date and time
    $is_expired = false;
    if ($row['status'] === 'expired') {
        $is_expired = true;
    } elseif ($row['status'] === 'open' && !empty($row['deadline'])) {
        $deadline = new DateTime($row['deadline']);
        if ($deadline <= $now) {
            $is_expired = true;
            $expired_ids_to_update[] = $row['id'];
            $row['status'] = 'expired';
        }
    }
    if ($is_expired) {
        $expired_jobs[] = $row;
    } elseif ($row['status'] === 'closed') {
        $closed_jobs[] = $row;
    } else {
        $active_jobs[] = $row;
    }
}
$stmt->close();

// Batch update expired jobs in a single query instead of per-row UPDATE
if (!empty($expired_ids_to_update)) {
    $ids_str = implode(',', array_map('intval', $expired_ids_to_update));
    $conn->query("UPDATE jobs SET status = 'expired' WHERE id IN ($ids_str)");
}

$page_title = 'My Jobs';
require __DIR__ . '/../includes/header.php';
?>

<style>
.job-card { border-radius:1rem; padding:1.5rem; transition:all .3s; background:var(--color-card); border:1px solid var(--color-border); box-shadow:0 2px 10px rgba(0,0,0,0.03); }
.job-card:hover { box-shadow:0 8px 30px rgba(99,102,241,0.1); transform:translateY(-2px); }
.btn-gradient-sm { background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; font-weight:600; padding:0.5rem 1rem; border-radius:0.5rem; font-size:0.8125rem; transition:all .2s; }
.btn-gradient-sm:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(99,102,241,0.3); }
</style>

<script>
function switchTab(tab) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active', 'border-indigo-500', 'text-indigo-600'));
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.add('border-transparent', 'text-gray-500'));
    document.querySelectorAll('.job-list-section').forEach(sec => sec.classList.add('hidden'));

    let activeBtn = document.getElementById('tab-' + tab);
    if(activeBtn) {
        activeBtn.classList.add('active', 'border-indigo-500', 'text-indigo-600');
        activeBtn.classList.remove('border-transparent', 'text-gray-500');
    }
    
    let activeSec = document.getElementById('section-' + tab);
    if(activeSec) {
        activeSec.classList.remove('hidden');
    }
}
function openExtendModal(jobId, currentDeadline) {
    document.getElementById('extend_job_id').value = jobId;
    document.getElementById('extendModal').classList.remove('hidden');
}
function closeExtendModal() {
    document.getElementById('extendModal').classList.add('hidden');
}
</script>

<div class="max-w-6xl mx-auto" style="padding-bottom:3rem">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold" style="color:var(--color-text-primary)">My Jobs</h1>
            <p class="mt-1 text-sm" style="color:var(--color-text-muted)">Manage all your posted jobs</p>
        </div>
        <a href="<?= e(base_url('company/post_job.php')) ?>" class="btn-gradient-sm flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            Post Job
        </a>
    </div>

    <!-- Tabs -->
    <div class="border-b border-gray-200 dark:border-gray-700 mb-6">
        <nav class="-mb-px flex space-x-8" aria-label="Tabs">
            <button id="tab-active" onclick="switchTab('active')" class="tab-btn active border-indigo-500 text-indigo-600 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                Active Jobs (<?= count($active_jobs) ?>)
            </button>
            <button id="tab-expired" onclick="switchTab('expired')" class="tab-btn border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                Expired Jobs (<?= count($expired_jobs) ?>)
            </button>
            <button id="tab-closed" onclick="switchTab('closed')" class="tab-btn border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                Closed Jobs (<?= count($closed_jobs) ?>)
            </button>
        </nav>
    </div>

    <?php 
    $sections = [
        'active' => $active_jobs,
        'expired' => $expired_jobs,
        'closed' => $closed_jobs
    ];
    ?>

    <?php foreach ($sections as $secKey => $jobs): ?>
    <div id="section-<?= $secKey ?>" class="job-list-section <?= $secKey === 'active' ? '' : 'hidden' ?>">
        <?php if (empty($jobs)): ?>
            <div class="text-center py-20 rounded-2xl" style="background:var(--color-card);border:1px solid var(--color-border)">
                <svg class="w-20 h-20 mx-auto mb-4 opacity-20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                <p class="text-lg font-semibold mb-2" style="color:var(--color-text-secondary)">No <?= $secKey ?> jobs found</p>
                <?php if($secKey === 'active'): ?>
                    <p class="text-sm mb-4" style="color:var(--color-text-muted)">Start by posting your first job to find talented freelancers</p>
                    <a href="<?= e(base_url('company/post_job.php')) ?>" class="btn-gradient-sm inline-flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                        Post Your First Job
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="space-y-4">
                <?php foreach ($jobs as $idx => $job): ?>
                    <div class="job-card" style="animation-delay:<?= ($idx * 0.05) ?>s">
                        <div class="flex flex-col lg:flex-row lg:items-start gap-4">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-3 mb-2 flex-wrap">
                                    <h3 class="text-lg font-bold" style="color:var(--color-text-primary)"><?= e($job['title']) ?></h3>
                                    <?= status_badge($job['status']) ?>
                                </div>

                                <div class="flex items-center gap-4 text-sm mb-3 flex-wrap" style="color:var(--color-text-muted)">
                                    <span class="font-bold" style="color:#6366f1"><?= e(number_format((float) $job['budget'], 2)) ?> MMK</span>
                                    <?php if ($job['category']): ?>
                                        <span class="flex items-center gap-1">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                                            <?= e($job['category']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <span class="capitalize"><?= e(str_replace('_', ' ', $job['experience_level'])) ?></span>
                                    <span class="flex items-center gap-1">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                        <?= (int) $job['freelancers_needed'] ?> <?= (int) $job['freelancers_needed'] === 1 ? 'Post' : 'Posts' ?>
                                    </span>
                                </div>

                                <p class="text-xs" style="color:var(--color-text-muted)">
                                    Posted: <?= e(date('M j, Y', strtotime($job['created_at']))) ?>
                                    <?php if ($job['deadline']): ?>
                                        <span class="mx-1">·</span>
                                        Deadline: <?= e(date('M j, Y', strtotime($job['deadline']))) ?>
                                    <?php endif; ?>
                                    <span class="mx-1">·</span>
                                    <?= (int) $job['app_count'] ?> application<?= (int) $job['app_count'] !== 1 ? 's' : '' ?>
                                    <?php if ($job['attachment']): ?>
                                        <span class="mx-1">·</span>
                                        <span class="flex items-center gap-1 inline-flex">
                                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                            Attachment
                                        </span>
                                    <?php endif; ?>
                                </p>
                            </div>

                            <div class="flex gap-2 flex-shrink-0 lg:flex-col">
                                <?php if ($job['status'] === 'expired'): ?>
                                    <button type="button" onclick="openExtendModal(<?= $job['id'] ?>)" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium transition-all" style="background:rgba(99,102,241,0.08);color:#6366f1">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                        Extend Deadline
                                    </button>
                                    <form action="<?= e(base_url('api/close_job.php')) ?>" method="POST" class="inline-block" onsubmit="return confirm('Are you sure you want to close this job?');">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="job_id" value="<?= $job['id'] ?>">
                                        <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium transition-all" style="background:rgba(239,68,68,0.08);color:#ef4444">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                            Close Job
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <?php if (!in_array($job['status'], ['completed', 'closed'])): ?>
                                    <a href="<?= e(base_url('company/edit_job.php?id=' . $job['id'])) ?>" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium transition-all" style="background:rgba(99,102,241,0.08);color:#6366f1">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                        Edit
                                    </a>
                                <?php endif; ?>
                                
                                <form action="<?= e(base_url('api/delete_job.php')) ?>" method="POST" class="inline-block" onsubmit="return confirm('Are you sure you want to delete this job?');">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="job_id" value="<?= $job['id'] ?>">
                                    <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium transition-all hover:bg-red-50" style="background:rgba(239,68,68,0.08);color:#ef4444">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        Delete
                                    </button>
                                </form>
                                
                                <?php if ($job['status'] !== 'closed'): ?>
                                <a href="<?= e(base_url('company/view_applications.php?id=' . $job['id'])) ?>" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium transition-all" style="background:rgba(16,185,129,0.08);color:#10b981">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                    Applications (<?= (int) $job['app_count'] ?>)
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- Extend Deadline Modal -->
<div id="extendModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4 backdrop-blur-sm transition-opacity">
    <div class="bg-white dark:bg-gray-800 rounded-2xl max-w-md w-full shadow-2xl overflow-hidden transform transition-all">
        <form action="<?= e(base_url('api/extend_job_deadline.php')) ?>" method="POST" class="p-6">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="job_id" id="extend_job_id" value="">
            
            <div class="flex items-center justify-between mb-5">
                <h3 class="text-xl font-bold" style="color:var(--color-text-primary)">Extend Job Deadline</h3>
                <button type="button" onclick="closeExtendModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)">New Deadline</label>
                <input type="date" name="new_deadline" required min="<?= date('Y-m-d') ?>" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white transition-shadow">
            </div>

            <div class="mb-6">
                <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)">Reason (Optional)</label>
                <textarea name="reason" rows="3" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white transition-shadow" placeholder="Why are you extending the deadline?"></textarea>
            </div>
            
            <div class="flex gap-3 justify-end">
                <button type="button" onclick="closeExtendModal()" class="px-5 py-2.5 rounded-xl font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600 transition-colors">Cancel</button>
                <button type="submit" class="px-5 py-2.5 rounded-xl font-medium text-white bg-indigo-600 hover:bg-indigo-700 transition-colors shadow-lg shadow-indigo-200 dark:shadow-none">Extend Deadline</button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
