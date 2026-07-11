<?php
$page_title = 'Browse Jobs';
require __DIR__ . '/../includes/freelancer_init.php';

// Fetch all skills for filter
$all_skills = [];
$sr = $conn->query('SELECT id, skill_name FROM skills ORDER BY skill_name');
while ($row = $sr->fetch_assoc()) {
    $all_skills[] = $row;
}

// Handle apply
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
$filter_cat = $_GET['category'] ?? '';
$filter_exp = $_GET['experience'] ?? '';

$where = "j.status='approved'";
$params = [];
$types = '';

if ($search !== '') {
    $where .= " AND (j.title LIKE ? OR j.description LIKE ? OR j.category LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'sss';
}
if ($filter_cat !== '') {
    $where .= " AND j.category = ?";
    $params[] = $filter_cat;
    $types .= 's';
}
if ($filter_exp !== '') {
    $where .= " AND j.experience_level = ?";
    $params[] = $filter_exp;
    $types .= 's';
}

// Freelancer ID param for subqueries
$params[] = $fl_freelancer_id;
$types .= 'i';

$sql = "SELECT j.id,j.title,j.description,j.budget,j.created_at,j.category,j.experience_level,j.gender_requirement,j.deadline,j.duration,j.freelancers_needed,j.visibility,j.attachment,
        c.company_name,c.logo_image,
        (SELECT ja.status FROM job_applications ja WHERE ja.job_id=j.id AND ja.freelancer_id=?) AS my_status,
        (SELECT COUNT(*) FROM assignments a WHERE a.job_id=j.id) AS is_assigned
        FROM jobs j JOIN companies c ON j.company_id=c.id
        WHERE {$where}
        ORDER BY j.created_at DESC";

$st = $conn->prepare($sql);
$st->bind_param($types, ...$params);
$st->execute(); $r = $st->get_result();
$jobs = [];
while ($row = $r->fetch_assoc()) {
    // Fetch skills
    $ss = $conn->prepare('SELECT s.skill_name FROM job_skills js JOIN skills s ON js.skill_id = s.id WHERE js.job_id = ?');
    $ss->bind_param('i', $row['id']); $ss->execute();
    $sr2 = $ss->get_result();
    $row['skills'] = [];
    while ($sk = $sr2->fetch_assoc()) { $row['skills'][] = $sk['skill_name']; }
    $ss->close();
    $jobs[] = $row;
}
$st->close();

require __DIR__ . '/../includes/freelancer_layout.php';
?>

<style>
.filter-chip { display:inline-flex; align-items:center; padding:0.375rem 0.875rem; border-radius:9999px; font-size:0.8125rem; font-weight:500; cursor:pointer; transition:all .2s; border:1.5px solid var(--color-border); color:var(--color-text-secondary); background:var(--color-card); }
.filter-chip:hover { border-color:#6366f1; color:#6366f1; }
.filter-chip.active { background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; border-color:transparent; }
.skill-tag { display:inline-flex; padding:0.15rem 0.5rem; border-radius:9999px; font-size:0.7rem; font-weight:500; background:rgba(99,102,241,0.08); color:#6366f1; }
.remote-badge { display:inline-flex; align-items:center; gap:0.25rem; padding:0.2rem 0.5rem; border-radius:9999px; font-size:0.7rem; font-weight:600; background:rgba(16,185,129,0.1); color:#10b981; }
.private-badge { display:inline-flex; align-items:center; gap:0.25rem; padding:0.2rem 0.5rem; border-radius:9999px; font-size:0.7rem; font-weight:600; background:rgba(245,158,11,0.1); color:#f59e0b; }
</style>

<!-- Search & Filters -->
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-2 mb-6 reveal">
    <form method="GET" class="space-y-4">
        <div class="flex gap-3">
            <div class="relative flex-1">
                <svg class="w-5 h-5 absolute left-4 top-1/2 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input type="text" name="q" placeholder="Search jobs by title, description, or category..." class="w-full pl-12 pr-4 py-3.5 rounded-2xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 transition-all" style="background:var(--color-card);border:1px solid var(--color-border);color:var(--color-text-primary);box-shadow:0 4px 20px rgba(99,102,241,0.06)" value="<?= e($search) ?>">
            </div>
            <button type="submit" class="btn-grad px-8 py-3.5 text-sm font-semibold rounded-2xl text-white shadow-lg shadow-primary-500/20 flex-shrink-0">Search</button>
            <?php if ($search !== '' || $filter_cat !== '' || $filter_exp !== ''): ?>
                <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="px-5 py-3.5 text-sm font-semibold rounded-2xl border" style="border-color:var(--color-border);color:var(--color-text-primary)">Clear</a>
            <?php endif; ?>
        </div>

        <!-- Category Filter -->
        <div class="flex flex-wrap gap-2">
            <a href="<?= e(base_url('freelancer/browse_jobs.php?' . http_build_query(array_filter(['q'=>$search,'experience'=>$filter_exp])))) ?>" class="filter-chip <?= $filter_cat === '' ? 'active' : '' ?>">All Categories</a>
            <?php
            $cats = ['Web Development','Mobile Development','UI/UX Design','Graphic Design','Content Writing','Digital Marketing','Data Science','DevOps','Other'];
            foreach ($cats as $cat):
            ?>
                <a href="<?= e(base_url('freelancer/browse_jobs.php?' . http_build_query(array_filter(['q'=>$search,'category'=>$cat,'experience'=>$filter_exp])))) ?>" class="filter-chip <?= $filter_cat === $cat ? 'active' : '' ?>"><?= e($cat) ?></a>
            <?php endforeach; ?>
        </div>

        <!-- Experience Filter -->
        <div class="flex flex-wrap gap-2">
            <a href="<?= e(base_url('freelancer/browse_jobs.php?' . http_build_query(array_filter(['q'=>$search,'category'=>$filter_cat])))) ?>" class="filter-chip <?= $filter_exp === '' ? 'active' : '' ?>">All Levels</a>
            <a href="<?= e(base_url('freelancer/browse_jobs.php?' . http_build_query(array_filter(['q'=>$search,'category'=>$filter_cat,'experience'=>'beginner'])))) ?>" class="filter-chip <?= $filter_exp === 'beginner' ? 'active' : '' ?>">Beginner</a>
            <a href="<?= e(base_url('freelancer/browse_jobs.php?' . http_build_query(array_filter(['q'=>$search,'category'=>$filter_cat,'experience'=>'intermediate'])))) ?>" class="filter-chip <?= $filter_exp === 'intermediate' ? 'active' : '' ?>">Intermediate</a>
            <a href="<?= e(base_url('freelancer/browse_jobs.php?' . http_build_query(array_filter(['q'=>$search,'category'=>$filter_cat,'experience'=>'expert'])))) ?>" class="filter-chip <?= $filter_exp === 'expert' ? 'active' : '' ?>">Expert</a>
        </div>
    </form>
</div>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-12">
<?php if (empty($jobs)): ?>
    <div class="glass rounded-2xl text-center py-20" style="color:var(--color-text-placeholder)">
        <svg class="w-24 h-24 mx-auto mb-6 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        <p class="text-xl font-semibold mb-2"><?= ($search !== '' || $filter_cat !== '' || $filter_exp !== '') ? 'No jobs match your filters.' : 'No approved jobs available at the moment.' ?></p>
        <?php if ($search !== '' || $filter_cat !== '' || $filter_exp !== ''): ?>
            <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="inline-flex items-center gap-2 mt-4 px-6 py-2.5 rounded-xl text-sm font-semibold" style="background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff">Clear Filters</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <p class="text-sm mb-4" style="color:var(--color-text-muted)"><?= count($jobs) ?> job<?= count($jobs) !== 1 ? 's' : '' ?> found</p>
    <div class="space-y-4">
        <?php foreach ($jobs as $i => $job): ?>
            <div class="glass rounded-2xl p-6 hover-lift reveal" style="transition-delay:<?= ($i % 5) * 0.06 ?>s">
                <div class="flex flex-col sm:flex-row sm:items-start gap-4">
                    <div class="flex items-center gap-3 flex-shrink-0">
                        <?php if ($job['logo_image']): ?><img src="<?= e(base_url('uploads/' . $job['logo_image'])) ?>" alt="" class="w-14 h-14 rounded-xl object-contain border" style="border-color:var(--color-border)"><?php else: ?><div class="w-14 h-14 rounded-xl flex items-center justify-center text-indigo-600 font-bold text-xl" style="background:rgba(99,102,241,0.1)"><?= strtoupper(mb_substr($job['company_name'], 0, 1)) ?></div><?php endif; ?>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap mb-1">
                            <h2 class="text-lg font-bold" style="color:var(--color-text-primary)"><?= e($job['title']) ?></h2>
                            <span class="remote-badge">
                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                Remote
                            </span>
                            <?php if ($job['visibility'] === 'private'): ?>
                                <span class="private-badge">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                    Private
                                </span>
                            <?php endif; ?>
                        </div>
                        <p class="text-sm flex items-center gap-2 mb-2" style="color:var(--color-text-muted)">
                            <?= e($job['company_name']) ?>
                            <span class="w-1 h-1 rounded-full bg-gray-300"></span>
                            Budget: <span class="font-bold" style="color:#6366f1">$<?= number_format((float) $job['budget'], 2) ?></span>
                            <span class="w-1 h-1 rounded-full bg-gray-300"></span>
                            <?= e(str_replace('_', ' ', ucfirst($job['experience_level']))) ?>
                        </p>

                        <?php if ($job['category']): ?>
                            <div class="flex items-center gap-2 mb-2">
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium" style="background:rgba(99,102,241,0.08);color:#6366f1">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                                    <?= e($job['category']) ?>
                                </span>
                                <?php if ($job['gender_requirement'] !== 'any'): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium" style="background:rgba(236,72,153,0.08);color:#ec4899"><?= e(ucfirst($job['gender_requirement'])) ?></span>
                                <?php endif; ?>
                                <?php if ((int)$job['freelancers_needed'] > 1): ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium" style="background:rgba(245,158,11,0.08);color:#f59e0b">
                                        <?= (int)$job['freelancers_needed'] ?> freelancers needed
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <p class="text-sm mt-2 line-clamp-2" style="color:var(--color-text-secondary)"><?= e(mb_strimwidth($job['description'] ?? '', 0, 200, '...')) ?></p>

                        <?php if (!empty($job['skills'])): ?>
                            <div class="flex flex-wrap gap-1.5 mt-3">
                                <?php foreach (array_slice($job['skills'], 0, 5) as $sk): ?>
                                    <span class="skill-tag"><?= e($sk) ?></span>
                                <?php endforeach; ?>
                                <?php if (count($job['skills']) > 5): ?>
                                    <span class="skill-tag">+<?= count($job['skills']) - 5 ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="flex items-center gap-3 mt-3 text-xs" style="color:var(--color-text-placeholder)">
                            <span>Posted <?= e($job['created_at']) ?></span>
                            <?php if ($job['deadline']): ?>
                                <span class="flex items-center gap-1">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                    Deadline: <?= e(date('M j, Y', strtotime($job['deadline']))) ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($job['duration']): ?>
                                <span>Duration: <?= e($job['duration']) ?></span>
                            <?php endif; ?>
                            <?php if ($job['attachment']): ?>
                                <span class="flex items-center gap-1">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                    Has attachment
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <a href="<?= e(base_url('freelancer/view_job.php?id=' . $job['id'])) ?>" class="px-4 py-2.5 text-sm font-medium rounded-xl border transition-all hover:bg-gray-50 dark:hover:bg-gray-800" style="color:var(--color-text-secondary);border-color:var(--color-border)">View Post</a>
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
