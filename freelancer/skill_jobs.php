<?php
$page_title = 'Skill Jobs';
$public_access = true;

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';

$user = current_user();
$fl_user = null;
$fl_freelancer_id = 0;
if ($user && ($user['role'] ?? '') === 'freelancer') {
    $fl_user = $user;
    $fl_freelancer_id = get_freelancer_id($conn, (int)$user['user_id']);
}

$skill_id = (int) ($_GET['id'] ?? 0);

if ($skill_id <= 0) {
    redirect('freelancer/browse_jobs.php');
}

// Fetch skill info
$skill_info = null;
$st = $conn->prepare('SELECT id, skill_name FROM skills WHERE id = ?');
$st->bind_param('i', $skill_id);
$st->execute();
$skill_info = $st->get_result()->fetch_assoc();
$st->close();

if (!$skill_info) {
    set_flash('error', 'Skill not found.');
    redirect('freelancer/browse_jobs.php');
}

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
                    if ($ji) create_notification($conn, (int) $ji['user_id'], 'new_application', "Applied for your job \"{$ji['title']}\".", 'company/view_applications.php?id=' . $job_id, $user_id);
                    set_flash('success', 'Application submitted successfully.');
                }
            }
        }
    }
    redirect('freelancer/skill_jobs.php?id=' . $skill_id);
}

// Fetch jobs for this skill
$params = [$fl_freelancer_id, $skill_id];
$types = 'ii';

$sql = "SELECT j.id,j.title,j.description,j.budget,j.created_at,j.category,j.experience_level,j.gender_requirement,j.deadline,j.duration,j.freelancers_needed,j.visibility,j.attachment,j.status,
        c.company_name,c.logo_image,
        (SELECT ja.status FROM job_applications ja WHERE ja.job_id=j.id AND ja.freelancer_id=?) AS my_status,
        (SELECT COUNT(*) FROM assignments a WHERE a.job_id=j.id AND a.status NOT IN ('rejected', 'cancelled')) AS assigned_count,
        (SELECT GROUP_CONCAT(DISTINCT s.skill_name SEPARATOR ',') FROM job_skills js2 JOIN skills s ON js2.skill_id = s.id WHERE js2.job_id = j.id) AS skills_concat
        FROM jobs j LEFT JOIN companies c ON j.company_id=c.id
        WHERE j.status IN ('open', 'in_review', 'hired', 'in_progress', 'completed', 'cancelled', 'closed') AND (j.category != 'Direct Hire' OR j.category IS NULL) AND EXISTS (SELECT 1 FROM job_skills js_filter WHERE js_filter.job_id = j.id AND js_filter.skill_id = ?)
        ORDER BY j.created_at DESC";

$st = $conn->prepare($sql);
$st->bind_param($types, ...$params);
$st->execute(); 
$r = $st->get_result();
$jobs = [];
$completed_count = 0;
while ($row = $r->fetch_assoc()) {
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
.skill-tag { display:inline-flex; padding:0.15rem 0.5rem; border-radius:9999px; font-size:0.7rem; font-weight:500; background:rgba(99,102,241,0.08); color:#6366f1; }
</style>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-2 mb-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <div class="flex items-center gap-3 mb-2">
                <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="inline-flex items-center gap-1.5 text-sm font-medium" style="color:var(--color-text-muted);text-decoration:none">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                    All Jobs
                </a>
            </div>
            <h1 class="text-2xl sm:text-3xl font-bold" style="color:var(--color-text-primary)">
                Jobs requiring
                <span class="inline-flex items-center px-3 py-1 rounded-xl text-white font-bold" style="background:linear-gradient(135deg,#6366f1,#8b5cf6)">
                    <?= e($skill_info['skill_name']) ?>
                </span>
            </h1>
        </div>
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center px-4 py-2 rounded-xl text-sm font-semibold" style="background:rgba(99,102,241,0.1);color:#6366f1">
                <?= count($jobs) ?> job<?= count($jobs) !== 1 ? 's' : '' ?> found
            </span>
        </div>
    </div>
</div>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-12">
<?php if (empty($jobs)): ?>
    <div class="glass rounded-2xl text-center py-20" style="color:var(--color-text-placeholder)">
        <svg class="w-24 h-24 mx-auto mb-6 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
        </svg>
        <p class="text-xl font-semibold mb-2">No jobs available for this skill.</p>
        <p class="text-sm mb-6">Check back later or browse other skills.</p>
        <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl text-sm font-semibold text-white" style="background:linear-gradient(135deg,#6366f1,#8b5cf6)">
            Browse All Jobs
        </a>
    </div>
<?php else: ?>
    <div class="space-y-4">
        <?php foreach ($jobs as $i => $job): ?>
            <div class="glass rounded-2xl p-6 hover-lift reveal" style="transition-delay:<?= ($i % 5) * 0.06 ?>s">
                <div class="flex flex-col sm:flex-row sm:items-start gap-4">
                    <div class="flex items-center gap-3 flex-shrink-0">
                        <?php if ($job['logo_image']): ?>
                            <img src="<?= e(base_url('uploads/images/' . $job['logo_image'])) ?>" alt="" class="w-14 h-14 rounded-xl object-contain border" style="border-color:var(--color-border)">
                        <?php else: ?>
                            <div class="w-14 h-14 rounded-xl flex items-center justify-center text-indigo-600 font-bold text-xl" style="background:rgba(99,102,241,0.1)"><?= strtoupper(mb_substr($job['company_name'], 0, 1)) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap mb-1">
                            <h2 class="text-lg font-bold" style="color:var(--color-text-primary)"><?= e($job['title']) ?></h2>
                        </div>
                        <p class="text-sm flex items-center gap-2 mb-2" style="color:var(--color-text-muted)">
                            <?= e($job['company_name']) ?>
                            <span class="w-1 h-1 rounded-full bg-gray-300"></span>
                            Budget: <span class="font-bold" style="color:#6366f1"><?= number_format((float) $job['budget'], 2) ?> MMK</span>
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
                            <span>Posted: <?= e(date('M j, Y', strtotime($job['created_at']))) ?></span>
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
                        <?php
                        $is_open = $job['status'] === 'open';
                        ?>
                        <?php if (!$is_open): ?>
                            <div class="inline-flex"><?= status_badge($job['status']) ?></div>
                        <?php elseif ($job['my_status']): ?>
                            <?= status_badge($job['my_status']) ?>
                        <?php else: ?>
                            <?php if (!$fl_freelancer_id): ?>
                                <a href="<?= e(base_url('auth/login.php')) ?>" class="btn-grad px-6 py-2.5 text-sm font-semibold rounded-xl text-white shadow-lg shadow-primary-500/20" style="text-decoration:none;">Apply</a>
                            <?php else: ?>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                                    <button type="submit" class="btn-grad px-6 py-2.5 text-sm font-semibold rounded-xl text-white shadow-lg shadow-primary-500/20">Apply</button>
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
