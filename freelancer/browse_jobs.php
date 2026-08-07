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
        $st = $conn->prepare("SELECT id, freelancers_needed, status FROM jobs WHERE id = ? AND status IN ('open', 'position_filled')");
        $st->bind_param('i', $job_id); $st->execute();
        $job = $st->get_result()->fetch_assoc(); $st->close();
        if (!$job) { set_flash('error', 'Job is not available for application.'); }
        else {
            // Check position limit
            $needed = max(1, (int) ($job['freelancers_needed'] ?? 1));
            $st = $conn->prepare('SELECT COUNT(*) AS cnt FROM assignments WHERE job_id = ? AND status != \'completed\'');
            $st->bind_param('i', $job_id); $st->execute();
            $filled = (int) $st->get_result()->fetch_assoc()['cnt']; $st->close();
            if ($filled >= $needed) {
                set_flash('error', 'All positions for this job have been filled.');
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
                    if ($ji) create_notification($conn, (int) $ji['user_id'], 'new_application', $fl_user['username'] . " applied for your job \"{$ji['title']}\".", 'company/view_applications.php?id=' . $job_id);
                    set_flash('success', 'Application submitted successfully.');
                }
            }
        }
    }
    redirect('freelancer/browse_jobs.php' . (!empty($_GET['q']) ? '?q=' . urlencode($_GET['q']) : ''));
}

$search = trim($_GET['q'] ?? '');
$filter_cat = $_GET['category'] ?? '';
$filter_exp = $_GET['experience'] ?? '';

$where = "j.status IN ('open', 'position_filled') AND j.category != 'Direct Hire'";
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

$sql = "SELECT j.id,j.title,j.description,j.budget,j.created_at,j.category,j.experience_level,j.gender_requirement,j.deadline,j.duration,j.freelancers_needed,j.visibility,j.attachment,j.status,
        c.company_name,c.logo_image,
        (SELECT ja.status FROM job_applications ja WHERE ja.job_id=j.id AND ja.freelancer_id=?) AS my_status,
        (SELECT COUNT(*) FROM assignments a WHERE a.job_id=j.id AND a.status != 'completed') AS assigned_count
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
/* Filter Chips */
.filter-chip {
    display: inline-flex;
    align-items: center;
    padding: 0.5rem 1rem;
    border-radius: 9999px;
    font-size: 0.8125rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    border: 1.5px solid var(--color-border);
    color: var(--color-text-secondary);
    background: var(--color-card);
    text-decoration: none;
}
.filter-chip:hover {
    border-color: #6366f1;
    color: #6366f1;
    background: rgba(99, 102, 241, 0.05);
}
.filter-chip.active {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: #fff;
    border-color: transparent;
    box-shadow: 0 2px 8px rgba(99, 102, 241, 0.25);
}

/* Job Card */
.job-card {
    background: var(--color-card);
    border: 1px solid var(--color-border);
    border-radius: 1rem;
    overflow: hidden;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    flex-direction: column;
}
.job-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 20px 40px rgba(99, 102, 241, 0.12), 0 8px 16px rgba(0, 0, 0, 0.04);
    border-color: rgba(99, 102, 241, 0.2);
}

/* Thumbnail */
.job-card-thumb {
    position: relative;
    width: 100%;
    height: 200px;
    overflow: hidden;
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.08), rgba(139, 92, 246, 0.08));
}
.job-card-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.5s cubic-bezier(0.4, 0, 0.2, 1);
}
.job-card:hover .job-card-thumb img {
    transform: scale(1.08);
}
.job-card-thumb .thumb-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Card Body */
.job-card-body {
    padding: 1.25rem 1.5rem;
    flex: 1;
    display: flex;
    flex-direction: column;
}

/* Meta Items */
.job-card-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    align-items: center;
    margin-bottom: 0.75rem;
}
.job-card-meta .meta-item {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    font-size: 0.75rem;
    color: var(--color-text-muted);
}
.job-card-meta .meta-item svg {
    width: 0.875rem;
    height: 0.875rem;
    flex-shrink: 0;
}

/* Title */
.job-card-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--color-text-primary);
    margin-bottom: 0.5rem;
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

/* Budget */
.job-card-budget {
    font-size: 1.25rem;
    font-weight: 800;
    background: linear-gradient(135deg, #10b981, #059669);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    margin-bottom: 0.75rem;
}

/* Description */
.job-card-desc {
    font-size: 0.8125rem;
    color: var(--color-text-secondary);
    line-height: 1.6;
    margin-bottom: 0.75rem;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    flex: 1;
}

/* Skills */
.job-card-skills {
    display: flex;
    flex-wrap: wrap;
    gap: 0.375rem;
    margin-bottom: 0;
}
.skill-tag {
    display: inline-flex;
    padding: 0.25rem 0.625rem;
    border-radius: 9999px;
    font-size: 0.6875rem;
    font-weight: 500;
    background: rgba(99, 102, 241, 0.08);
    color: #6366f1;
    border: 1px solid rgba(99, 102, 241, 0.15);
}

/* Footer */
.job-card-footer {
    padding: 1rem 1.5rem;
    border-top: 1px solid var(--color-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
}

/* Buttons */
.btn-view {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.625rem 1.25rem;
    font-size: 0.8125rem;
    font-weight: 600;
    border-radius: 0.75rem;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: #fff;
    text-decoration: none;
    transition: all 0.2s ease;
    box-shadow: 0 2px 8px rgba(99, 102, 241, 0.25);
}
.btn-view:hover {
    box-shadow: 0 4px 16px rgba(99, 102, 241, 0.4);
    transform: translateY(-2px);
}

.btn-apply {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.625rem 1.25rem;
    font-size: 0.8125rem;
    font-weight: 600;
    border-radius: 0.75rem;
    background: linear-gradient(135deg, #10b981, #059669);
    color: #fff;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.25);
}
.btn-apply:hover {
    box-shadow: 0 4px 16px rgba(16, 185, 129, 0.4);
    transform: translateY(-2px);
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.5rem 1rem;
    font-size: 0.75rem;
    font-weight: 600;
    border-radius: 0.75rem;
}
.status-assigned {
    background: rgba(107, 114, 128, 0.1);
    color: #6b7280;
}

/* Badges */
.badge-private {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.375rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.6875rem;
    font-weight: 600;
    background: rgba(245, 158, 11, 0.9);
    color: #fff;
}
.badge-attachment {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.375rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.6875rem;
    font-weight: 600;
    background: rgba(99, 102, 241, 0.9);
    color: #fff;
}
</style>

<!-- Search & Filters -->
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 pt-4 mb-8">
    <form method="GET" class="space-y-4">
        <!-- Search Bar -->
        <div class="flex gap-3">
            <div class="relative flex-1">
                <svg class="w-5 h-5 absolute left-4 top-1/2 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input type="text" name="q" placeholder="Search jobs by title, description, or category..." class="w-full pl-12 pr-4 py-3.5 rounded-2xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-all" style="background:var(--color-card);border:1px solid var(--color-border);color:var(--color-text-primary)" value="<?= e($search) ?>">
            </div>
            <button type="submit" class="px-6 py-3.5 text-sm font-semibold rounded-2xl text-white transition-all" style="background:linear-gradient(135deg,#6366f1,#8b5cf6);box-shadow:0 4px 12px rgba(99,102,241,0.25)">Search</button>
            <?php if ($search !== '' || $filter_cat !== '' || $filter_exp !== ''): ?>
                <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="px-5 py-3.5 text-sm font-semibold rounded-2xl border transition-all hover:bg-gray-50" style="border-color:var(--color-border);color:var(--color-text-primary)">Clear</a>
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

<!-- Job Cards Grid -->
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 pb-12">
<?php if (empty($jobs)): ?>
    <div class="rounded-2xl text-center py-20" style="background:var(--color-card);border:1px solid var(--color-border)">
        <svg class="w-20 h-20 mx-auto mb-6 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        <p class="text-xl font-semibold mb-2" style="color:var(--color-text-primary)"><?= ($search !== '' || $filter_cat !== '' || $filter_exp !== '') ? 'No jobs match your filters.' : 'No approved jobs available at the moment.' ?></p>
        <p class="text-sm mb-6" style="color:var(--color-text-muted)">Try adjusting your search or filters</p>
        <?php if ($search !== '' || $filter_cat !== '' || $filter_exp !== ''): ?>
            <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl text-sm font-semibold text-white" style="background:linear-gradient(135deg,#6366f1,#8b5cf6)">Clear Filters</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="flex items-center justify-between mb-6">
        <p class="text-sm font-medium" style="color:var(--color-text-muted)"><?= count($jobs) ?> job<?= count($jobs) !== 1 ? 's' : '' ?> found</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <?php foreach ($jobs as $i => $job): ?>
            <?php
            $is_image = false;
            $thumb_url = null;
            if ($job['attachment']) {
                $ext = strtolower(pathinfo($job['attachment'], PATHINFO_EXTENSION));
                $is_image = in_array($ext, ['jpg','jpeg','png','gif','webp']);
                $thumb_url = base_url('uploads/attachments/' . $job['attachment']);
            }
            ?>
            <div class="job-card reveal" style="transition-delay:<?= ($i % 4) * 0.08 ?>s">
                <!-- Thumbnail -->
                <div class="job-card-thumb">
                    <?php if ($is_image && $thumb_url): ?>
                        <img src="<?= e($thumb_url) ?>" alt="<?= e($job['title']) ?>" loading="lazy">
                    <?php else: ?>
                        <div class="thumb-placeholder">
                            <svg width="72" height="72" viewBox="0 0 72 72" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <rect width="72" height="72" rx="18" fill="url(#grad-card)" fill-opacity="0.1"/>
                                <path d="M24 30L32 22L40 30" stroke="url(#grad-card)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M32 22V40" stroke="url(#grad-card)" stroke-width="2.5" stroke-linecap="round"/>
                                <path d="M44 40V30H48V40C48 42.2 46.2 44 44 44H28C25.8 44 24 42.2 24 40" stroke="url(#grad-card)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                                <defs>
                                    <linearGradient id="grad-card" x1="0" y1="0" x2="72" y2="72" gradientUnits="userSpaceOnUse">
                                        <stop stop-color="#6366f1"/>
                                        <stop offset="1" stop-color="#8b5cf6"/>
                                    </linearGradient>
                                </defs>
                            </svg>
                        </div>
                    <?php endif; ?>
                    <!-- Badges -->
                    <div class="absolute top-3 left-3 flex gap-2 flex-wrap">
                        <?php if ($job['visibility'] === 'private'): ?>
                            <span class="badge-private">
                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                Private
                            </span>
                        <?php endif; ?>
                        <?php if ($job['attachment']): ?>
                            <span class="badge-attachment">
                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                Attachment
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Body -->
                <div class="job-card-body">
                    <!-- Meta Row -->
                    <div class="job-card-meta">
                        <?php if ($job['category']): ?>
                            <span class="meta-item" style="color:#6366f1;font-weight:600">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                                <?= e($job['category']) ?>
                            </span>
                        <?php endif; ?>
                        <span class="meta-item">
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            Remote
                        </span>
                        <span class="meta-item">
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <?= e(str_replace('_', ' ', ucfirst($job['experience_level']))) ?>
                        </span>
                        <?php if ($job['deadline']): ?>
                            <span class="meta-item">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                <?= e(date('M j, Y', strtotime($job['deadline']))) ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Title -->
                    <h3 class="job-card-title"><?= e($job['title']) ?></h3>

                    <!-- Budget -->
                    <div class="job-card-budget">$<?= number_format((float) $job['budget'], 2) ?></div>

                    <!-- Description -->
                    <p class="job-card-desc"><?= e(mb_strimwidth($job['description'] ?? '', 0, 150, '...')) ?></p>

                    <!-- Skills -->
                    <?php if (!empty($job['skills'])): ?>
                        <div class="job-card-skills">
                            <?php foreach (array_slice($job['skills'], 0, 3) as $sk): ?>
                                <span class="skill-tag"><?= e($sk) ?></span>
                            <?php endforeach; ?>
                            <?php if (count($job['skills']) > 3): ?>
                                <span class="skill-tag">+<?= count($job['skills']) - 3 ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Footer -->
                <div class="job-card-footer">
                    <a href="<?= e(base_url('freelancer/view_job.php?id=' . $job['id'])) ?>" class="btn-view">
                        View Details
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                    </a>
                    <?php
                    $assigned = (int) ($job['assigned_count'] ?? 0);
                    $needed = max(1, (int) ($job['freelancers_needed'] ?? 1));
                    $is_filled = $assigned >= $needed || $job['status'] === 'position_filled';
                    ?>
                    <?php if ($is_filled): ?>
                        <span class="status-badge status-assigned">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            Positions Filled
                        </span>
                    <?php elseif ($job['my_status']): ?>
                        <?= status_badge($job['my_status']) ?>
                    <?php else: ?>
                        <form method="POST" style="margin:0">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                            <button type="submit" class="btn-apply">
                                Apply Now
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/freelancer_footer.php'; ?>
