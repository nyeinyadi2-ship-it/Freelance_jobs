<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';

// Set CSRF cookie early (before any HTML output)
csrf_cookie();

require_role('admin');

$has_status_col = has_account_status_column();

$user_id = (int) ($_GET['id'] ?? 0);
if ($user_id <= 0) {
    set_flash('error', 'Invalid request. Please try again.');
    redirect('admin/manage_users.php');
}

// Fetch user
$status_col = $has_status_col ? 'account_status,' : "'active' AS account_status,";
$stmt = $conn->prepare("SELECT id, username, email, role, {$status_col} created_at, profile_image FROM users WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$target_user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$target_user) {
    set_flash('error', 'User not found.');
    redirect('admin/manage_users.php');
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf() && $has_status_col) {
    $new_status = $_POST['account_status'] ?? '';
    if (in_array($new_status, ['active', 'suspended', 'blocked'], true)) {
        $stmt = $conn->prepare('UPDATE users SET account_status = ? WHERE id = ?');
        $stmt->bind_param('si', $new_status, $user_id);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            $status_label = __('user_status.' . $new_status);
            $msg = __('admin.user_status_updated', [':status' => $status_label]);

            // Send notification to the user
            $link = null;
            if ($new_status === 'suspended') {
                $notif_msg = 'Your account has been suspended by an administrator.';
                $notif_type = 'account_suspended';
            } elseif ($new_status === 'blocked') {
                $notif_msg = 'Your account has been blocked by an administrator.';
                $notif_type = 'account_suspended';
            } else {
                $notif_msg = 'Your account has been reactivated.';
                $notif_type = 'account_activated';
            }
            create_notification($conn, $user_id, $notif_type, $notif_msg, $link);

            // Clear cached status
            unset($_SESSION['account_status']);

            set_flash('success', $msg);
        } else {
            set_flash('error', 'Could not update user status.');
        }
        $stmt->close();
    }
    redirect('admin/view_user.php?id=' . $user_id);
}

// Fetch profile data based on role
$profile = null;
$profile_extra = [];

if ($target_user['role'] === 'company') {
    try {
        $stmt = $conn->prepare('SELECT * FROM companies WHERE user_id = ?');
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $profile = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (mysqli_sql_exception $e) {}

    if ($profile) {
        try {
            $stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM jobs WHERE company_id = ?');
            $stmt->bind_param('i', $profile['id']);
            $stmt->execute();
            $profile_extra['jobs_count'] = (int) $stmt->get_result()->fetch_assoc()['cnt'];
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            $profile_extra['jobs_count'] = 0;
        }

        try {
            $stmt = $conn->prepare('SELECT id, title, budget, status, created_at FROM jobs WHERE company_id = ? AND category != \'Direct Hire\' ORDER BY created_at DESC LIMIT 5');
            $stmt->bind_param('i', $profile['id']);
            $stmt->execute();
            $profile_extra['recent_jobs'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            $profile_extra['recent_jobs'] = [];
        }
    }
} elseif ($target_user['role'] === 'freelancer') {
    try {
        $stmt = $conn->prepare('SELECT * FROM freelancers WHERE user_id = ?');
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $profile = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (mysqli_sql_exception $e) {}

    if ($profile) {
        try {
            $stmt = $conn->prepare('SELECT s.skill_name FROM freelancer_skills fs JOIN skills s ON fs.skill_id = s.id WHERE fs.freelancer_id = ?');
            $stmt->bind_param('i', $profile['id']);
            $stmt->execute();
            $skills_result = $stmt->get_result();
            $profile_extra['skills'] = [];
            while ($row = $skills_result->fetch_assoc()) {
                $profile_extra['skills'][] = $row['skill_name'];
            }
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            $profile_extra['skills'] = [];
        }

        try {
            $stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM job_applications WHERE freelancer_id = ?');
            $stmt->bind_param('i', $profile['id']);
            $stmt->execute();
            $profile_extra['applications_count'] = (int) $stmt->get_result()->fetch_assoc()['cnt'];
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            $profile_extra['applications_count'] = 0;
        }

        try {
            $stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM assignments WHERE freelancer_id = ?');
            $stmt->bind_param('i', $profile['id']);
            $stmt->execute();
            $profile_extra['assignments_count'] = (int) $stmt->get_result()->fetch_assoc()['cnt'];
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            $profile_extra['assignments_count'] = 0;
        }

        try {
            $stmt = $conn->prepare('
                SELECT ja.id, ja.status, ja.applied_at, j.title, j.budget
                FROM job_applications ja
                JOIN jobs j ON ja.job_id = j.id
                WHERE ja.freelancer_id = ?
                ORDER BY ja.applied_at DESC LIMIT 5
            ');
            $stmt->bind_param('i', $profile['id']);
            $stmt->execute();
            $profile_extra['recent_applications'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            $profile_extra['recent_applications'] = [];
        }
    }
}

$status = $target_user['account_status'] ?? 'active';

$page_title = 'User Profile' . ' - ' . e($target_user['username']);
require __DIR__ . '/includes/admin_header.php';
?>

<!-- Breadcrumb -->
<div class="mb-6 admin-fade">
    <div class="flex items-center gap-3 mb-1">
        <a href="<?= e(base_url('admin/admin_dashboard.php')) ?>" class="text-sm hover:underline" style="color:var(--color-text-muted)"><?= e('Admin Dashboard') ?></a>
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-text-placeholder)"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        <a href="<?= e(base_url('admin/manage_users.php')) ?>" class="text-sm hover:underline" style="color:var(--color-text-muted)"><?= e('Manage Users') ?></a>
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-text-placeholder)"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        <span class="text-sm font-medium" style="color:var(--color-text-primary)"><?= e($target_user['username']) ?></span>
    </div>
</div>

<!-- User Header Card -->
<div class="card mb-6">
    <div class="flex flex-wrap items-start gap-6">
        <?php $imgUrl = profile_image_url($target_user['profile_image']); ?>
        <?php if ($imgUrl): ?>
            <img src="<?= e($imgUrl) ?>" alt="" class="w-20 h-20 rounded-full object-cover border" style="border-color:var(--color-border)">
        <?php else: ?>
            <div class="w-20 h-20 rounded-full bg-indigo-100 dark:bg-indigo-900 flex items-center justify-center text-indigo-600 dark:text-indigo-300 font-bold text-2xl border" style="border-color:var(--color-border)">
                <?= e(_first_char($target_user['username'])) ?>
            </div>
        <?php endif; ?>

        <div class="flex-1 min-w-0">
            <div class="flex flex-wrap items-center gap-3 mb-2">
                <h1 class="text-2xl font-bold" style="color:var(--color-text-primary)"><?= e($target_user['username']) ?></h1>
                <?php
                $role_class = $target_user['role'] === 'company'
                    ? 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300'
                    : 'bg-purple-100 dark:bg-purple-900/30 text-purple-800 dark:text-purple-300';
                ?>
                <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?= $role_class ?>"><?= e(ucfirst($target_user['role'])) ?></span>
                <?php
                $status_classes = [
                    'active' => 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300',
                    'suspended' => 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-300',
                    'blocked' => 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300',
                ];
                $sc = $status_classes[$status] ?? $status_classes['active'];
                ?>
                <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?= $sc ?>"><?= e(__('user_status.' . $status)) ?></span>
            </div>
            <p class="text-sm mb-1" style="color:var(--color-text-secondary)"><?= e($target_user['email']) ?></p>
            <p class="text-xs" style="color:var(--color-text-muted)"><?= e('Joined on') ?> <?= e($target_user['created_at']) ?></p>
        </div>
    </div>
</div>

<div class="grid md:grid-cols-3 gap-6">
    <!-- Left Column: Profile Details -->
    <div class="md:col-span-2 space-y-6">
        <?php if ($target_user['role'] === 'company' && $profile): ?>
            <!-- Company Profile -->
            <div class="card">
                <h2 class="text-lg font-semibold mb-4" style="color:var(--color-text-primary)"><?= e('Company Profile') ?></h2>
                <div class="grid sm:grid-cols-2 gap-4">
                    <?php if ($profile['company_name']): ?>
                        <div>
                            <p class="text-xs font-medium" style="color:var(--color-text-muted)"><?= e('Company Name *') ?></p>
                            <p class="text-sm" style="color:var(--color-text-primary)"><?= e($profile['company_name']) ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if ($profile['phone']): ?>
                        <div>
                            <p class="text-xs font-medium" style="color:var(--color-text-muted)"><?= e('Phone') ?></p>
                            <p class="text-sm" style="color:var(--color-text-primary)"><?= e($profile['phone']) ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if ($profile['website']): ?>
                        <div>
                            <p class="text-xs font-medium" style="color:var(--color-text-muted)"><?= e('Website') ?></p>
                            <a href="<?= e($profile['website']) ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg transition-colors" style="background:rgba(99,102,241,0.1);color:#4f46e5">&#127760; Visit Website</a>
                        </div>
                    <?php endif; ?>
                    <?php if ($profile['location']): ?>
                        <div>
                            <p class="text-xs font-medium" style="color:var(--color-text-muted)"><?= e('Location') ?></p>
                            <p class="text-sm" style="color:var(--color-text-primary)"><?= e($profile['location']) ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if ($profile['established_year']): ?>
                        <div>
                            <p class="text-xs font-medium" style="color:var(--color-text-muted)"><?= e('Established Year') ?></p>
                            <p class="text-sm" style="color:var(--color-text-primary)"><?= e($profile['established_year']) ?></p>
                        </div>
                    <?php endif; ?>
                    <div>
                        <p class="text-xs font-medium" style="color:var(--color-text-muted)"><?= e('Total Jobs') ?></p>
                        <p class="text-sm font-semibold" style="color:var(--color-text-primary)"><?= $profile_extra['jobs_count'] ?? 0 ?></p>
                    </div>
                </div>
                <?php if ($profile['description']): ?>
                    <div class="mt-4">
                        <p class="text-xs font-medium mb-1" style="color:var(--color-text-muted)"><?= e('Description') ?></p>
                        <p class="text-sm" style="color:var(--color-text-secondary)"><?= nl2br(e($profile['description'])) ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Recent Jobs -->
            <?php if (!empty($profile_extra['recent_jobs'])): ?>
                <div class="card">
                    <h2 class="text-lg font-semibold mb-4" style="color:var(--color-text-primary)"><?= e('Recent Jobs') ?></h2>
                    <div class="space-y-3">
                        <?php foreach ($profile_extra['recent_jobs'] as $job): ?>
                            <div class="flex flex-wrap justify-between items-center gap-2 py-2" style="border-bottom:1px solid var(--color-border)">
                                <div>
                                    <p class="text-sm font-medium" style="color:var(--color-text-primary)"><?= e($job['title']) ?></p>
                                    <p class="text-xs" style="color:var(--color-text-muted)">$<?= e(number_format((float) $job['budget'], 2)) ?> &middot; <?= e($job['created_at']) ?></p>
                                </div>
                                <?= status_badge($job['status']) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

        <?php elseif ($target_user['role'] === 'freelancer' && $profile): ?>
            <!-- Freelancer Profile -->
            <div class="card">
                <h2 class="text-lg font-semibold mb-4" style="color:var(--color-text-primary)"><?= e('Freelancer Profile') ?></h2>
                <div class="grid sm:grid-cols-2 gap-4">
                    <?php if ($profile['full_name']): ?>
                        <div>
                            <p class="text-xs font-medium" style="color:var(--color-text-muted)"><?= e('Full Name *') ?></p>
                            <p class="text-sm" style="color:var(--color-text-primary)"><?= e($profile['full_name']) ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if ($profile['title']): ?>
                        <div>
                            <p class="text-xs font-medium" style="color:var(--color-text-muted)"><?= e('Professional Title') ?></p>
                            <p class="text-sm" style="color:var(--color-text-primary)"><?= e($profile['title']) ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if ($profile['phone']): ?>
                        <div>
                            <p class="text-xs font-medium" style="color:var(--color-text-muted)"><?= e('Phone') ?></p>
                            <p class="text-sm" style="color:var(--color-text-primary)"><?= e($profile['phone']) ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if ($profile['location']): ?>
                        <div>
                            <p class="text-xs font-medium" style="color:var(--color-text-muted)"><?= e('Location') ?></p>
                            <p class="text-sm" style="color:var(--color-text-primary)"><?= e($profile['location']) ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if ($profile['experience_years']): ?>
                        <div>
                            <p class="text-xs font-medium" style="color:var(--color-text-muted)"><?= e('Experience (Years)') ?></p>
                            <p class="text-sm" style="color:var(--color-text-primary)"><?= e($profile['experience_years']) ?> years</p>
                        </div>
                    <?php endif; ?>
                    <?php if ($profile['hourly_rate']): ?>
                        <div>
                            <p class="text-xs font-medium" style="color:var(--color-text-muted)"><?= e('Hourly Rate ($)') ?></p>
                            <p class="text-sm" style="color:var(--color-text-primary)">$<?= e(number_format((float) $profile['hourly_rate'], 2)) ?></p>
                        </div>
                    <?php endif; ?>
                    <div>
                        <p class="text-xs font-medium" style="color:var(--color-text-muted)"><?= e('Applications Sent') ?></p>
                        <p class="text-sm font-semibold" style="color:var(--color-text-primary)"><?= $profile_extra['applications_count'] ?? 0 ?></p>
                    </div>
                    <div>
                        <p class="text-xs font-medium" style="color:var(--color-text-muted)"><?= e('Assignments') ?></p>
                        <p class="text-sm font-semibold" style="color:var(--color-text-primary)"><?= $profile_extra['assignments_count'] ?? 0 ?></p>
                    </div>
                </div>

                <?php if ($profile['bio']): ?>
                    <div class="mt-4">
                        <p class="text-xs font-medium mb-1" style="color:var(--color-text-muted)"><?= e('Bio') ?></p>
                        <p class="text-sm" style="color:var(--color-text-secondary)"><?= nl2br(e($profile['bio'])) ?></p>
                    </div>
                <?php endif; ?>
                <?php if (!empty($profile_extra['skills'])): ?>
                    <div class="mt-4">
                        <p class="text-xs font-medium mb-2" style="color:var(--color-text-muted)"><?= e('Skills') ?></p>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($profile_extra['skills'] as $skill): ?>
                                <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full bg-indigo-100 dark:bg-indigo-900/30 text-indigo-800 dark:text-indigo-300"><?= e($skill) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Recent Applications -->
            <?php if (!empty($profile_extra['recent_applications'])): ?>
                <div class="card">
                    <h2 class="text-lg font-semibold mb-4" style="color:var(--color-text-primary)"><?= e('Recent Applications') ?></h2>
                    <div class="space-y-3">
                        <?php foreach ($profile_extra['recent_applications'] as $app): ?>
                            <div class="flex flex-wrap justify-between items-center gap-2 py-2" style="border-bottom:1px solid var(--color-border)">
                                <div>
                                    <p class="text-sm font-medium" style="color:var(--color-text-primary)"><?= e($app['title']) ?></p>
                                    <p class="text-xs" style="color:var(--color-text-muted)">$<?= e(number_format((float) $app['budget'], 2)) ?> &middot; <?= e($app['applied_at']) ?></p>
                                </div>
                                <?= status_badge($app['status']) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Right Column: Account Status Management -->
    <div class="space-y-6">
        <?php if ($has_status_col): ?>
        <div class="card">
            <h2 class="text-lg font-semibold mb-4" style="color:var(--color-text-primary)"><?= e('Account Status') ?></h2>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-2" style="color:var(--color-text-secondary)"><?= e('Change Account Status') ?></label>
                    <select name="account_status" class="form-input">
                        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>><?= e('Active') ?></option>
                        <option value="suspended" <?= $status === 'suspended' ? 'selected' : '' ?>><?= e('Suspended') ?></option>
                        <option value="blocked" <?= $status === 'blocked' ? 'selected' : '' ?>><?= e('Blocked') ?></option>
                    </select>
                </div>
                <button type="submit" class="btn-primary w-full" onclick="return confirm('<?= e('Are you sure you want to change this user\'s status?') ?>')">
                    <?= e('Update Status') ?>
                </button>
            </form>
        </div>
        <?php else: ?>
        <div class="card" style="background:var(--color-flash-error-bg);border-color:var(--color-flash-error-border)">
            <p class="text-sm" style="color:var(--color-flash-error-text)"><?= e('Database migration required') ?></p>
            <code class="block mt-2 p-2 rounded text-xs" style="background:rgba(0,0,0,0.05);color:var(--color-flash-error-text)">ALTER TABLE users ADD COLUMN account_status ENUM('active', 'suspended', 'blocked') DEFAULT 'active' AFTER last_activity;</code>
        </div>
        <?php endif; ?>

        <!-- Account Summary -->
        <div class="card">
            <h2 class="text-lg font-semibold mb-3" style="color:var(--color-text-primary)"><?= e('Account Summary') ?></h2>
            <div class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <span style="color:var(--color-text-muted)"><?= e('User ID') ?></span>
                    <span style="color:var(--color-text-primary)">#<?= $target_user['id'] ?></span>
                </div>
                <div class="flex justify-between">
                    <span style="color:var(--color-text-muted)"><?= e('Role') ?></span>
                    <span style="color:var(--color-text-primary)"><?= e(ucfirst($target_user['role'])) ?></span>
                </div>
                <div class="flex justify-between">
                    <span style="color:var(--color-text-muted)"><?= e('Status') ?></span>
                    <span style="color:var(--color-text-primary)"><?= e(__('user_status.' . $status)) ?></span>
                </div>
                <div class="flex justify-between">
                    <span style="color:var(--color-text-muted)"><?= e('Joined on') ?></span>
                    <span style="color:var(--color-text-primary)"><?= e($target_user['created_at']) ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

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
