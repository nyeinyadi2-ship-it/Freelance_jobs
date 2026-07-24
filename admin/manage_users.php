<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

require_role('admin');

$has_status_col = has_account_status_column();

$search = trim($_GET['q'] ?? '');
$filter_role = $_GET['role'] ?? '';
$filter_status = $_GET['status'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 15;
$offset = ($page - 1) * $per_page;

// Build query
$where = ['1=1'];
$params = [];
$types = '';

if ($search !== '') {
    $where[] = '(u.username LIKE ? OR u.email LIKE ? OR c.company_name LIKE ? OR f.full_name LIKE ?)';
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like, $like]);
    $types .= 'ssss';
}

if (in_array($filter_role, ['company', 'freelancer'], true)) {
    $where[] = 'u.role = ?';
    $params[] = $filter_role;
    $types .= 's';
}

if ($has_status_col) {
    if (in_array($filter_status, ['active', 'suspended', 'blocked'], true)) {
        $where[] = 'u.account_status = ?';
        $params[] = $filter_status;
        $types .= 's';
    } else {
        // Exclude admin users from the list
        $where[] = "u.role != 'admin'";
    }
} else {
    $where[] = "u.role != 'admin'";
}

$where_sql = implode(' AND ', $where);

// Count total
$total = 0;
$total_pages = 1;
try {
    $count_sql = "SELECT COUNT(*) AS cnt FROM users u LEFT JOIN companies c ON u.id = c.user_id LEFT JOIN freelancers f ON u.id = f.user_id WHERE {$where_sql}";
    $count_stmt = $conn->prepare($count_sql);
    if ($types !== '') {
        $count_stmt->bind_param($types, ...$params);
    }
    $count_stmt->execute();
    $total = (int) $count_stmt->get_result()->fetch_assoc()['cnt'];
    $count_stmt->close();
    $total_pages = max(1, ceil($total / $per_page));
} catch (mysqli_sql_exception $e) {}

// Fetch users
$users = [];
try {
    $status_col = $has_status_col ? 'u.account_status,' : "'active' AS account_status,";
    $sql = "
        SELECT u.id, u.username, u.email, u.role, {$status_col} u.created_at, u.profile_image,
               c.company_name, c.logo_image,
               f.full_name
        FROM users u
        LEFT JOIN companies c ON u.id = c.user_id
        LEFT JOIN freelancers f ON u.id = f.user_id
        WHERE {$where_sql}
        ORDER BY u.created_at DESC
        LIMIT ? OFFSET ?
    ";
    $stmt = $conn->prepare($sql);
    $param_types = $types . 'ii';
    $param_values = array_merge($params, [$per_page, $offset]);
    $stmt->bind_param($param_types, ...$param_values);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    $stmt->close();
} catch (mysqli_sql_exception $e) {}

$page_title = 'Manage Users';
require __DIR__ . '/includes/admin_header.php';
?>

<!-- Page Header -->
<div class="mb-6 admin-fade">
    <div class="flex items-center gap-3 mb-1">
        <a href="<?= e(base_url('admin/admin_dashboard.php')) ?>" class="text-sm hover:underline" style="color:var(--color-text-muted)"><?= e('Admin Dashboard') ?></a>
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-text-placeholder)"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        <span class="text-sm font-medium" style="color:var(--color-text-primary)"><?= e('Manage Users') ?></span>
    </div>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="text-2xl font-bold" style="color:var(--color-text-primary)"><?= e('Manage Users') ?></h1>
        <div class="text-sm" style="color:var(--color-text-muted)"><?= e('Total Users') ?>: <?= $total ?></div>
    </div>
</div>

<?php if (!$has_status_col): ?>
    <div class="card mb-6 admin-fade" style="background:var(--color-flash-error-bg);border-color:var(--color-flash-error-border)">
        <div class="flex items-start gap-3">
            <svg class="w-5 h-5 mt-0.5 flex-shrink-0" style="color:var(--color-flash-error-text)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <div>
                <p class="font-semibold mb-1" style="color:var(--color-flash-error-text)"><?= e('Database migration required') ?></p>
                <p class="text-sm" style="color:var(--color-flash-error-text)"><?= e('The account_status column is missing from the users table. Run this SQL command:') ?></p>
                <code class="block mt-2 p-2 rounded text-xs" style="background:rgba(0,0,0,0.05);color:var(--color-flash-error-text)">ALTER TABLE users ADD COLUMN account_status ENUM('active', 'suspended', 'blocked') DEFAULT 'active' AFTER last_activity;</code>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Search and Filters -->
<div class="card mb-6">
    <form method="GET" class="flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[200px]">
            <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)"><?= e('Search Users') ?></label>
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="<?= e('Search by name or email...') ?>" class="form-input">
        </div>
        <div class="min-w-[140px]">
            <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)"><?= e('Role') ?></label>
            <select name="role" class="form-input">
                <option value=""><?= e('All Roles') ?></option>
                <option value="company" <?= $filter_role === 'company' ? 'selected' : '' ?>><?= e('Company') ?></option>
                <option value="freelancer" <?= $filter_role === 'freelancer' ? 'selected' : '' ?>><?= e('Freelancer') ?></option>
            </select>
        </div>
        <?php if ($has_status_col): ?>
        <div class="min-w-[140px]">
            <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)"><?= e('Status') ?></label>
            <select name="status" class="form-input">
                <option value=""><?= e('All Statuses') ?></option>
                <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>><?= e('Active') ?></option>
                <option value="suspended" <?= $filter_status === 'suspended' ? 'selected' : '' ?>><?= e('Suspended') ?></option>
                <option value="blocked" <?= $filter_status === 'blocked' ? 'selected' : '' ?>><?= e('Blocked') ?></option>
            </select>
        </div>
        <?php endif; ?>
        <div class="flex gap-2">
            <button type="submit" class="btn-primary"><?= e('Search') ?></button>
            <a href="<?= e(base_url('admin/manage_users.php')) ?>" class="btn-secondary"><?= e('Clear') ?></a>
        </div>
    </form>
</div>

<!-- Users Table -->
<?php if (empty($users)): ?>
    <div class="card text-center" style="color:var(--color-text-muted)"><?= e('No users found.') ?></div>
<?php else: ?>
    <div class="card overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr style="border-bottom:1px solid var(--color-border)">
                    <th class="text-left py-3 px-3 font-semibold" style="color:var(--color-text-secondary)"><?= e('User') ?></th>
                    <th class="text-left py-3 px-3 font-semibold" style="color:var(--color-text-secondary)"><?= e('Email') ?></th>
                    <th class="text-left py-3 px-3 font-semibold" style="color:var(--color-text-secondary)"><?= e('Role') ?></th>
                    <?php if ($has_status_col): ?>
                    <th class="text-left py-3 px-3 font-semibold" style="color:var(--color-text-secondary)"><?= e('Status') ?></th>
                    <?php endif; ?>
                    <th class="text-left py-3 px-3 font-semibold" style="color:var(--color-text-secondary)"><?= e('Joined') ?></th>
                    <th class="text-right py-3 px-3 font-semibold" style="color:var(--color-text-secondary)"><?= e('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr style="border-bottom:1px solid var(--color-border)" class="hover:opacity-80">
                        <td class="py-3 px-3">
                            <div class="flex items-center gap-3">
                                <?php $imgUrl = profile_image_url($u['profile_image']); ?>
                                <?php if ($imgUrl): ?>
                                    <img src="<?= e($imgUrl) ?>" alt="" class="w-8 h-8 rounded-full object-cover">
                                <?php else: ?>
                                    <div class="w-8 h-8 rounded-full bg-indigo-100 dark:bg-indigo-900 flex items-center justify-center text-indigo-600 dark:text-indigo-300 font-bold text-xs">
                                        <?= e(_first_char($u['username'])) ?>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <div class="font-medium" style="color:var(--color-text-primary)"><?= e($u['username']) ?></div>
                                    <?php if ($u['role'] === 'company' && $u['company_name']): ?>
                                        <div class="text-xs" style="color:var(--color-text-muted)"><?= e($u['company_name']) ?></div>
                                    <?php elseif ($u['role'] === 'freelancer' && $u['full_name']): ?>
                                        <div class="text-xs" style="color:var(--color-text-muted)"><?= e($u['full_name']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="py-3 px-3" style="color:var(--color-text-secondary)"><?= e($u['email']) ?></td>
                        <td class="py-3 px-3">
                            <?php
                            $role_class = $u['role'] === 'company'
                                ? 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300'
                                : 'bg-purple-100 dark:bg-purple-900/30 text-purple-800 dark:text-purple-300';
                            ?>
                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?= $role_class ?>"><?= e(ucfirst($u['role'])) ?></span>
                        </td>
                        <?php if ($has_status_col): ?>
                        <td class="py-3 px-3">
                            <?php
                            $status = $u['account_status'] ?? 'active';
                            $status_classes = [
                                'active' => 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300',
                                'suspended' => 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-300',
                                'blocked' => 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300',
                            ];
                            $sc = $status_classes[$status] ?? $status_classes['active'];
                            ?>
                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?= $sc ?>"><?= e(__('user_status.' . $status)) ?></span>
                        </td>
                        <?php endif; ?>
                        <td class="py-3 px-3 text-xs" style="color:var(--color-text-muted)"><?= e($u['created_at']) ?></td>
                        <td class="py-3 px-3 text-right">
                            <a href="<?= e(base_url('admin/view_user.php?id=' . $u['id'])) ?>" class="btn-secondary text-xs py-1 px-2">
                                <?= e('View Profile') ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
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
