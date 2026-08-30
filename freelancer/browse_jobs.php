<?php
$page_title = 'Browse Jobs';
$public_access = true;

// Basic init for public pages
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

// Fetch all skills for filter
$all_skills = [];
$sr = $conn->query('SELECT id, skill_name FROM skills ORDER BY skill_name');
while ($row = $sr->fetch_assoc()) {
    $all_skills[] = $row;
}

// Handle apply
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    if (!$fl_freelancer_id) {
        set_flash('error', 'Please login as a freelancer to apply for jobs.');
        redirect('auth/login.php');
    }

    $job_id = (int) ($_POST['job_id'] ?? 0);
    if ($job_id > 0) {
        $st = $conn->prepare("SELECT id, status FROM jobs WHERE id = ? AND status = 'open'");
        $st->bind_param('i', $job_id); $st->execute();
        $job = $st->get_result()->fetch_assoc(); $st->close();
        if (!$job) { set_flash('error', 'Job is not available for application.'); }
        else {
            $st = $conn->prepare('SELECT COUNT(*) AS cnt FROM assignments WHERE job_id = ? AND status NOT IN (\'rejected\', \'cancelled\')');
            $st->bind_param('i', $job_id); $st->execute();
            $filled = (int) $st->get_result()->fetch_assoc()['cnt']; $st->close();
            if ($filled >= 1) {
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

$search = trim(urldecode($_GET['q'] ?? ''));
$filter_cat = trim(urldecode($_GET['category'] ?? ''));
$filter_exp = trim(urldecode($_GET['experience'] ?? ''));
$filter_skill_raw = trim(urldecode($_GET['skill'] ?? ''));
$filter_skill = $filter_skill_raw;

$filter_skill_id = 0;
$filter_skill_name = '';

if ($filter_skill_raw !== '') {
    if (is_numeric($filter_skill_raw) && (int)$filter_skill_raw > 0) {
        $filter_skill_id = (int)$filter_skill_raw;
        $st_sk = $conn->prepare('SELECT skill_name FROM skills WHERE id = ?');
        $st_sk->bind_param('i', $filter_skill_id);
        $st_sk->execute();
        $res_sk = $st_sk->get_result()->fetch_assoc();
        if ($res_sk) {
            $filter_skill_name = $res_sk['skill_name'];
        }
        $st_sk->close();
    } else {
        $st_sk = $conn->prepare('SELECT id, skill_name FROM skills WHERE LOWER(skill_name) = LOWER(?) LIMIT 1');
        $st_sk->bind_param('s', $filter_skill_raw);
        $st_sk->execute();
        $res_sk = $st_sk->get_result()->fetch_assoc();
        $st_sk->close();
        if (!$res_sk) {
            $like = '%' . $filter_skill_raw . '%';
            $st_sk = $conn->prepare('SELECT id, skill_name FROM skills WHERE LOWER(skill_name) LIKE LOWER(?) LIMIT 1');
            $st_sk->bind_param('s', $like);
            $st_sk->execute();
            $res_sk = $st_sk->get_result()->fetch_assoc();
            $st_sk->close();
        }
        if ($res_sk) {
            $filter_skill_id = (int)$res_sk['id'];
            $filter_skill_name = $res_sk['skill_name'];
        } else {
            $filter_skill_name = $filter_skill_raw;
        }
    }
}

check_and_update_expired_jobs($conn);

$where = "j.status NOT IN ('closed', 'cancelled', 'expired') AND NOT EXISTS (SELECT 1 FROM assignments a_dh WHERE a_dh.job_id = j.id AND a_dh.assignment_type = 'direct_hire')";
$params = [$fl_freelancer_id];
$types = 'i';

if ($search !== '') {
    $where .= " AND (j.title LIKE ? OR j.description LIKE ? OR j.category LIKE ? OR EXISTS (SELECT 1 FROM job_skills js_s JOIN skills s_s ON js_s.skill_id = s_s.id WHERE js_s.job_id = j.id AND s_s.skill_name LIKE ?))";
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'ssss';
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
if ($filter_skill_id > 0 || $filter_skill_name !== '') {
    $where .= " AND ((? > 0 AND EXISTS (SELECT 1 FROM job_skills js_filter WHERE js_filter.job_id = j.id AND js_filter.skill_id = ?)) OR (? != '' AND LOWER(j.category) = LOWER(?)))";
    $params[] = $filter_skill_id;
    $params[] = $filter_skill_id;
    $params[] = $filter_skill_name;
    $params[] = $filter_skill_name;
    $types .= 'iiss';
}


$sql = "SELECT j.id,j.title,j.description,j.budget,j.created_at,j.category,j.experience_level,j.deadline,j.duration,j.attachment,j.status,
        c.company_name,c.logo_image,
        ja.status AS my_status,
        (SELECT COUNT(*) FROM assignments a WHERE a.job_id = j.id AND a.status NOT IN ('rejected', 'cancelled')) AS assigned_count,
        (SELECT GROUP_CONCAT(s.skill_name SEPARATOR ',') FROM job_skills js JOIN skills s ON js.skill_id = s.id WHERE js.job_id = j.id) AS skills_concat
        FROM jobs j 
        LEFT JOIN companies c ON j.company_id=c.id
        LEFT JOIN job_applications ja ON ja.job_id = j.id AND ja.freelancer_id = ?
        WHERE {$where}
        ORDER BY j.created_at DESC";

$st = $conn->prepare($sql);
$st->bind_param($types, ...$params);
$st->execute(); $r = $st->get_result();
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
<div id="browse-wrapper">
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 pt-4 mb-8">
    <form method="GET" class="space-y-4" id="filter-form">
        <!-- Search Bar -->
        <div class="flex gap-3">
            <div class="relative flex-1">
                <svg class="w-5 h-5 absolute left-4 top-1/2 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input type="text" name="q" placeholder="Search jobs by title, description, category, or skill..." class="w-full pl-12 pr-4 py-3.5 rounded-2xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-all" style="background:var(--color-card);border:1px solid var(--color-border);color:var(--color-text-primary)" value="<?= e($search) ?>">
            </div>
            <button type="submit" class="px-6 py-3.5 text-sm font-semibold rounded-2xl text-white transition-all" style="background:linear-gradient(135deg,#6366f1,#8b5cf6);box-shadow:0 4px 12px rgba(99,102,241,0.25)">Search</button>
            <?php if ($search !== '' || $filter_cat !== '' || $filter_exp !== '' || $filter_skill !== ''): ?>
                <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="px-5 py-3.5 text-sm font-semibold rounded-2xl border transition-all hover:bg-gray-50" style="border-color:var(--color-border);color:var(--color-text-primary)">Clear</a>
            <?php endif; ?>
        </div>

        <!-- Skill Filter -->
        <div class="relative max-w-sm">
            <select name="skill" id="skill-filter" class="w-full pl-4 pr-10 py-3 rounded-2xl text-sm font-medium focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-all cursor-pointer appearance-none" style="background:var(--color-card);border:1px solid var(--color-border);color:var(--color-text-primary)">
                <option value="">All Skills</option>
                <?php foreach ($all_skills as $sk): ?>
                    <option value="<?= e($sk['skill_name']) ?>" <?= ($filter_skill_name === $sk['skill_name'] || $filter_skill === $sk['skill_name'] || $filter_skill == $sk['id']) ? 'selected' : '' ?>><?= e($sk['skill_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-4 text-gray-500">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            </div>
        </div>

        <!-- Category Filter -->
        <div class="flex flex-wrap gap-2">
            <a href="<?= e(base_url('freelancer/browse_jobs.php?' . http_build_query(array_filter(['q'=>$search,'experience'=>$filter_exp,'skill'=>$filter_skill])))) ?>" class="filter-chip <?= $filter_cat === '' ? 'active' : '' ?>">All Categories</a>
            <?php
            $cats = [];
            $res = $conn->query("SELECT name FROM categories WHERE LOWER(name) NOT IN ('direct hire', 'direct offer') ORDER BY CASE WHEN LOWER(name) = 'other' THEN 1 ELSE 0 END, name ASC");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $cats[] = $row['name'];
                }
            }
            foreach ($cats as $cat):
            ?>
                <a href="<?= e(base_url('freelancer/browse_jobs.php?' . http_build_query(array_filter(['q'=>$search,'category'=>$cat,'experience'=>$filter_exp,'skill'=>$filter_skill])))) ?>" class="filter-chip <?= $filter_cat === $cat ? 'active' : '' ?>"><?= e($cat) ?></a>
            <?php endforeach; ?>
        </div>

        <!-- Experience Filter -->
        <div class="flex flex-wrap gap-2">
            <a href="<?= e(base_url('freelancer/browse_jobs.php?' . http_build_query(array_filter(['q'=>$search,'category'=>$filter_cat,'skill'=>$filter_skill])))) ?>" class="filter-chip <?= $filter_exp === '' ? 'active' : '' ?>">All Levels</a>
            <a href="<?= e(base_url('freelancer/browse_jobs.php?' . http_build_query(array_filter(['q'=>$search,'category'=>$filter_cat,'experience'=>'beginner','skill'=>$filter_skill])))) ?>" class="filter-chip <?= $filter_exp === 'beginner' ? 'active' : '' ?>">Beginner</a>
            <a href="<?= e(base_url('freelancer/browse_jobs.php?' . http_build_query(array_filter(['q'=>$search,'category'=>$filter_cat,'experience'=>'intermediate','skill'=>$filter_skill])))) ?>" class="filter-chip <?= $filter_exp === 'intermediate' ? 'active' : '' ?>">Intermediate</a>
            <a href="<?= e(base_url('freelancer/browse_jobs.php?' . http_build_query(array_filter(['q'=>$search,'category'=>$filter_cat,'experience'=>'expert','skill'=>$filter_skill])))) ?>" class="filter-chip <?= $filter_exp === 'expert' ? 'active' : '' ?>">Expert</a>
        </div>
    </form>
</div>

<!-- Job Cards Grid -->
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 pb-12" id="jobs-container">
<?php if (empty($jobs)): ?>
    <div class="rounded-2xl text-center py-20" style="background:var(--color-card);border:1px solid var(--color-border)">
        <svg class="w-20 h-20 mx-auto mb-6 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        <p class="text-xl font-semibold mb-2" style="color:var(--color-text-primary)"><?= ($search !== '' || $filter_cat !== '' || $filter_exp !== '' || $filter_skill !== '') ? 'No jobs match your filters.' : 'No approved jobs available at the moment.' ?></p>
        <p class="text-sm mb-6" style="color:var(--color-text-muted)">Try adjusting your search or filters</p>
        <?php if ($search !== '' || $filter_cat !== '' || $filter_exp !== '' || $filter_skill !== ''): ?>
            <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl text-sm font-semibold text-white" style="background:linear-gradient(135deg,#6366f1,#8b5cf6)">Clear Filters</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="flex items-center justify-between mb-6">
        <p class="text-sm font-medium" style="color:var(--color-text-muted)"><?= count($jobs) ?> job<?= count($jobs) !== 1 ? 's' : '' ?> found</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
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
                    <!-- Company Info -->
                    <div class="flex items-center gap-3 mb-4 pb-3 border-b" style="border-color:var(--color-border)">
                        <div class="flex-shrink-0 w-10 h-10 rounded-lg overflow-hidden" style="background:var(--color-bg);border:1px solid var(--color-border)">
                            <?php if (!empty($job['logo_image'])): ?>
                                <img src="<?= e(base_url('uploads/images/' . $job['logo_image'])) ?>" alt="<?= e($job['company_name']) ?> Logo" class="w-full h-full object-cover">
                            <?php else: ?>
                                <div class="w-full h-full flex items-center justify-center bg-indigo-50 text-indigo-600 dark:bg-indigo-900/20 dark:text-indigo-400">
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                    </svg>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-bold text-gray-900 dark:text-white truncate"><?= e($job['company_name'] ?: 'Unknown Company') ?></p>
                        </div>
                    </div>

                    <!-- Meta Row -->
                    <div class="job-card-meta">
                        <?php if ($job['category']): ?>
                            <span class="meta-item" style="color:#6366f1;font-weight:600">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                                <?= e($job['category']) ?>
                            </span>
                        <?php endif; ?>
                        <span class="meta-item">
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <?= e(str_replace('_', ' ', ucfirst($job['experience_level']))) ?>
                        </span>
                        <span class="meta-item">
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            Posted: <?= e(date('M j, Y', strtotime($job['created_at']))) ?>
                        </span>
                        <?php if ($job['deadline']): ?>
                            <span class="meta-item">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                Deadline: <?= e(date('M j, Y', strtotime($job['deadline']))) ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Title -->
                    <h3 class="job-card-title"><?= e($job['title']) ?></h3>

                    <!-- Budget -->
                    <div class="job-card-budget"><?= number_format((float) $job['budget'], 2) ?> MMK</div>

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
                    $is_open = $job['status'] === 'open';
                    ?>
                    <?php if (!$is_open): ?>
                        <div class="inline-flex"><?= status_badge($job['status']) ?></div>
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
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('filter-form');
    const jobsContainer = document.getElementById('jobs-container');
    if (!form || !jobsContainer) return;

    let fetchController = null;
    let searchTimeout = null;

    function fetchJobs(url) {
        if (fetchController) fetchController.abort();
        fetchController = new AbortController();
        
        jobsContainer.style.opacity = '0.5';
        jobsContainer.style.pointerEvents = 'none';

        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            signal: fetchController.signal
        })
        .then(r => r.text())
        .then(html => {
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const newContainer = doc.getElementById('jobs-container');
            if (newContainer) {
                jobsContainer.innerHTML = newContainer.innerHTML;
            }
            
            // Also update the filter form to reflect any UI changes (like the Clear button showing up)
            // But we must be careful not to replace the currently focused input.
            const newForm = doc.getElementById('filter-form');
            if (newForm) {
                // Update the clear button visibility
                const oldClearBtn = form.querySelector('a.px-5');
                const newClearBtn = newForm.querySelector('a.px-5');
                
                if (newClearBtn && !oldClearBtn) {
                    // Inject the new clear button next to the search button
                    const searchBtn = form.querySelector('button[type="submit"]');
                    if (searchBtn) {
                        searchBtn.insertAdjacentHTML('afterend', newClearBtn.outerHTML);
                    }
                } else if (!newClearBtn && oldClearBtn) {
                    oldClearBtn.remove();
                } else if (newClearBtn && oldClearBtn) {
                    oldClearBtn.outerHTML = newClearBtn.outerHTML;
                }
            }

            jobsContainer.style.opacity = '1';
            jobsContainer.style.pointerEvents = 'auto';
            window.history.pushState({}, '', url);
        })
        .catch(err => {
            if (err.name === 'AbortError') return;
            jobsContainer.style.opacity = '1';
            jobsContainer.style.pointerEvents = 'auto';
            console.error(err);
        });
    }

    function handleFilterUpdate() {
        const url = new URL(window.location.href.split('?')[0]); // Start clean
        const formData = new FormData(form);
        for (const [key, value] of formData.entries()) {
            if (value) url.searchParams.set(key, value);
        }
        
        // Also capture active category and experience filters which are links, not form inputs
        const activeCat = document.querySelector('.filter-chip.active[href*="category="]');
        if (activeCat) {
            const catUrl = new URL(activeCat.href);
            if (catUrl.searchParams.get('category')) {
                url.searchParams.set('category', catUrl.searchParams.get('category'));
            }
        }
        
        const activeExp = document.querySelector('.filter-chip.active[href*="experience="]');
        if (activeExp) {
            const expUrl = new URL(activeExp.href);
            if (expUrl.searchParams.get('experience')) {
                url.searchParams.set('experience', expUrl.searchParams.get('experience'));
            }
        }

        fetchJobs(url.toString());
    }

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        handleFilterUpdate();
    });

    form.addEventListener('change', function(e) {
        if (e.target.name === 'skill') {
            handleFilterUpdate();
        }
    });

    // Live search as you type
    const searchInput = form.querySelector('input[name="q"]');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                handleFilterUpdate();
            }, 300);
        });
    }

    // Intercept clicks on category/experience filter chips and the Clear button
    document.addEventListener('click', function(e) {
        const link = e.target.closest('a');
        if (link && link.href.includes('browse_jobs.php') && !link.href.includes('view_job.php')) {
            e.preventDefault();
            
            // If it's a filter chip, update active state visually immediately for better UX
            if (link.classList.contains('filter-chip')) {
                const parent = link.parentElement;
                parent.querySelectorAll('.filter-chip').forEach(el => el.classList.remove('active'));
                link.classList.add('active');
            }
            
            // If it's the clear button, we also want to reset the form inputs
            if (link.textContent.trim() === 'Clear' || link.textContent.trim() === 'Clear Filters') {
                form.reset();
                if (searchInput) searchInput.value = '';
                // The URL of the clear button is just browse_jobs.php, so fetching it will reset everything
            }

            fetchJobs(link.href);
        }
    });
});
</script>

<?php require __DIR__ . '/../includes/freelancer_footer.php'; ?>
