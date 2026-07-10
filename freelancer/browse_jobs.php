<?php
$page_title = 'Browse Jobs';
require __DIR__ . '/../includes/freelancer_init.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $job_id = (int) ($_POST['job_id'] ?? 0);
    if ($job_id > 0) {
        $st = $conn->prepare("SELECT id FROM jobs WHERE id = ? AND status = 'approved'");
        $st->bind_param('i', $job_id); $st->execute();
        $job = $st->get_result()->fetch_assoc(); $st->close();
        if (!$job) { set_flash('error', __('error.job_not_available')); }
        else {
            $st = $conn->prepare('SELECT id FROM assignments WHERE job_id = ?');
            $st->bind_param('i', $job_id); $st->execute();
            $has = $st->get_result()->num_rows > 0; $st->close();
            if ($has) { set_flash('error', __('error.job_already_assigned')); }
            else {
                $st = $conn->prepare('SELECT id FROM job_applications WHERE job_id = ? AND freelancer_id = ?');
                $st->bind_param('ii', $job_id, $fl_freelancer_id); $st->execute();
                $exists = $st->get_result()->num_rows > 0; $st->close();
                if ($exists) { set_flash('error', __('error.already_applied')); }
                else {
                    $st = $conn->prepare('INSERT INTO job_applications (job_id, freelancer_id) VALUES (?, ?)');
                    $st->bind_param('ii', $job_id, $fl_freelancer_id); $st->execute(); $st->close();
                    $st = $conn->prepare("SELECT j.title, c.user_id FROM jobs j JOIN companies c ON j.company_id = c.id WHERE j.id = ?");
                    $st->bind_param('i', $job_id); $st->execute();
                    $ji = $st->get_result()->fetch_assoc(); $st->close();
                    if ($ji) create_notification($conn, (int) $ji['user_id'], 'new_application', $fl_user['username'] . " applied for your job \"{$ji['title']}\".", 'company/view_applications.php?id=' . $job_id);
                    set_flash('success', __('success.application_submitted'));
                }
            }
        }
    }
    redirect('freelancer/browse_jobs.php' . (!empty($_GET['q']) ? '?q=' . urlencode($_GET['q']) : ''));
}

$search = trim($_GET['q'] ?? '');
$jobs = [];
if ($search !== '') {
    $like = '%' . $search . '%';
    $st = $conn->prepare("SELECT j.id,j.title,j.description,j.budget,j.created_at,c.company_name,c.logo_image,(SELECT ja.status FROM job_applications ja WHERE ja.job_id=j.id AND ja.freelancer_id=?) AS my_status,(SELECT COUNT(*) FROM assignments a WHERE a.job_id=j.id) AS is_assigned FROM jobs j JOIN companies c ON j.company_id=c.id WHERE j.status='approved' AND (j.title LIKE ? OR j.description LIKE ?) ORDER BY j.created_at DESC");
    $st->bind_param('iss', $fl_freelancer_id, $like, $like);
} else {
    $st = $conn->prepare("SELECT j.id,j.title,j.description,j.budget,j.created_at,c.company_name,c.logo_image,(SELECT ja.status FROM job_applications ja WHERE ja.job_id=j.id AND ja.freelancer_id=?) AS my_status,(SELECT COUNT(*) FROM assignments a WHERE a.job_id=j.id) AS is_assigned FROM jobs j JOIN companies c ON j.company_id=c.id WHERE j.status='approved' ORDER BY j.created_at DESC");
    $st->bind_param('i', $fl_freelancer_id);
}
$st->execute(); $r = $st->get_result();
while ($row = $r->fetch_assoc()) $jobs[] = $row;
$st->close();
require __DIR__ . '/../includes/freelancer_layout.php';
?>

<!-- Search -->
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-2 mb-8 reveal">
    <form method="GET" class="flex gap-3">
        <div class="relative flex-1">
            <svg class="w-5 h-5 absolute left-4 top-1/2 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="text" name="q" placeholder="Search jobs by title or description..." class="w-full pl-12 pr-4 py-3.5 rounded-2xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 transition-all" style="background:var(--color-card);border:1px solid var(--color-border);color:var(--color-text-primary);box-shadow:0 4px 20px rgba(99,102,241,0.06)" value="<?= e($search) ?>">
        </div>
        <button type="submit" class="btn-grad px-8 py-3.5 text-sm font-semibold rounded-2xl text-white shadow-lg shadow-primary-500/20 flex-shrink-0">Search</button>
        <?php if ($search !== ''): ?><a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="px-5 py-3.5 text-sm font-semibold rounded-2xl border" style="border-color:var(--color-border);color:var(--color-text-primary)">Clear</a><?php endif; ?>
    </form>
</div>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-12">
<?php if (empty($jobs)): ?>
    <div class="glass rounded-2xl text-center py-20" style="color:var(--color-text-placeholder)">
        <svg class="w-24 h-24 mx-auto mb-6 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        <p class="text-xl font-semibold mb-2"><?= $search !== '' ? 'No jobs match your search.' : 'No approved jobs available at the moment.' ?></p>
    </div>
<?php else: ?>
    <div class="space-y-4">
        <?php foreach ($jobs as $i => $job): ?>
            <div class="glass rounded-2xl p-6 hover-lift reveal" style="transition-delay:<?= ($i % 5) * 0.06 ?>s">
                <div class="flex flex-col sm:flex-row sm:items-center gap-4">
                    <div class="flex items-center gap-3 flex-shrink-0">
                        <?php if ($job['logo_image']): ?><img src="<?= e(base_url('uploads/' . $job['logo_image'])) ?>" alt="" class="w-14 h-14 rounded-xl object-contain border" style="border-color:var(--color-border)"><?php else: ?><div class="w-14 h-14 rounded-xl flex items-center justify-center text-indigo-600 font-bold text-xl" style="background:rgba(99,102,241,0.1)"><?= strtoupper(mb_substr($job['company_name'], 0, 1)) ?></div><?php endif; ?>
                    </div>
                    <div class="flex-1 min-w-0">
                        <h2 class="text-lg font-bold" style="color:var(--color-text-primary)"><?= e($job['title']) ?></h2>
                        <p class="text-sm flex items-center gap-2" style="color:var(--color-text-muted)"><?= e($job['company_name']) ?><span class="w-1 h-1 rounded-full bg-gray-300"></span>Budget: <span class="font-bold text-primary-600">$<?= number_format((float) $job['budget'], 2) ?></span></p>
                        <p class="text-sm mt-2 line-clamp-2" style="color:var(--color-text-secondary)"><?= e(mb_strimwidth($job['description'] ?? '', 0, 200, '...')) ?></p>
                        <p class="text-xs mt-2" style="color:var(--color-text-placeholder)">Posted <?= e($job['created_at']) ?></p>
                    </div>
                    <div class="flex-shrink-0">
                        <?php if ((int) $job['is_assigned'] > 0): ?>
                            <span class="text-sm font-medium px-5 py-2.5 rounded-xl" style="background:var(--color-bg);color:var(--color-text-muted)">Assigned</span>
                        <?php elseif ($job['my_status']): ?>
                            <?= status_badge($job['my_status']) ?>
                        <?php else: ?>
                            <form method="POST"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>"><button type="submit" class="btn-grad px-6 py-2.5 text-sm font-semibold rounded-xl text-white shadow-lg shadow-primary-500/20">Apply</button></form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/freelancer_footer.php'; ?>
