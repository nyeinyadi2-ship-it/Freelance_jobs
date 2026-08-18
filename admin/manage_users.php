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
<div class="mb-5 admin-fade">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold" style="color:var(--color-text-primary)"><?= e('Manage Users') ?></h1>
            <p class="text-sm mt-0.5" style="color:var(--color-text-muted)"><?= e('Manage and monitor platform users.') ?></p>
        </div>
        <span class="text-xs" style="color:var(--color-text-muted)"><?= e('Total') ?>: <?= $total ?></span>
    </div>
</div>

<?php if (!$has_status_col): ?>
    <div class="card mb-5 admin-fade" style="background:var(--color-flash-error-bg);border-color:var(--color-flash-error-border)">
        <div class="flex items-start gap-3">
            <svg class="w-4 h-4 mt-0.5 flex-shrink-0" style="color:var(--color-flash-error-text)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <div>
                <p class="font-semibold text-sm mb-1" style="color:var(--color-flash-error-text)"><?= e('Database migration required') ?></p>
                <p class="text-xs" style="color:var(--color-flash-error-text)"><?= e('The account_status column is missing from the users table.') ?></p>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Search and Filters -->
<div class="card mb-5 admin-fade">
    <form method="GET" class="flex flex-wrap gap-2 items-end">
        <div class="flex-1 min-w-[180px]">
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="<?= e('Search by name or email...') ?>" class="form-input">
        </div>
        <div class="min-w-[120px]">
            <select name="role" class="form-input">
                <option value=""><?= e('All Roles') ?></option>
                <option value="company" <?= $filter_role === 'company' ? 'selected' : '' ?>><?= e('Company') ?></option>
                <option value="freelancer" <?= $filter_role === 'freelancer' ? 'selected' : '' ?>><?= e('Freelancer') ?></option>
            </select>
        </div>
        <?php if ($has_status_col): ?>
        <div class="min-w-[120px]">
            <select name="status" class="form-input">
                <option value=""><?= e('All Statuses') ?></option>
                <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>><?= e('Active') ?></option>
                <option value="suspended" <?= $filter_status === 'suspended' ? 'selected' : '' ?>><?= e('Suspended') ?></option>
                <option value="blocked" <?= $filter_status === 'blocked' ? 'selected' : '' ?>><?= e('Blocked') ?></option>
            </select>
        </div>
        <?php endif; ?>
        <div class="flex gap-1.5">
            <button type="submit" class="btn-primary"><?= e('Search') ?></button>
            <a href="<?= e(base_url('admin/manage_users.php')) ?>" class="btn-secondary"><?= e('Clear') ?></a>
        </div>
    </form>
</div>

<!-- Users Table -->
<?php if (empty($users)): ?>
    <div class="card text-center py-10 admin-fade" style="color:var(--color-text-muted)"><?= e('No users found.') ?></div>
<?php else: ?>
    <div class="card admin-fade overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr style="border-bottom:2px solid var(--color-border)">
                    <th class="text-left py-2 px-3 text-xs font-semibold uppercase tracking-wider" style="color:var(--color-text-muted)"><?= e('User') ?></th>
                    <th class="text-left py-2 px-3 text-xs font-semibold uppercase tracking-wider hidden md:table-cell" style="color:var(--color-text-muted)"><?= e('Email') ?></th>
                    <th class="text-left py-2 px-3 text-xs font-semibold uppercase tracking-wider" style="color:var(--color-text-muted)"><?= e('Role') ?></th>
                    <?php if ($has_status_col): ?>
                    <th class="text-left py-2 px-3 text-xs font-semibold uppercase tracking-wider" style="color:var(--color-text-muted)"><?= e('Status') ?></th>
                    <?php endif; ?>
                    <th class="text-left py-2 px-3 text-xs font-semibold uppercase tracking-wider hidden lg:table-cell" style="color:var(--color-text-muted)"><?= e('Joined') ?></th>
                    <th class="text-right py-2 px-3 text-xs font-semibold uppercase tracking-wider" style="color:var(--color-text-muted)"><?= e('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr style="border-bottom:1px solid var(--color-border)" class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
                        <td class="py-2.5 px-3">
                            <div class="flex items-center gap-2.5">
                                <?php $imgUrl = profile_image_url($u['profile_image']); ?>
                                <?php if ($imgUrl): ?>
                                    <img src="<?= e($imgUrl) ?>" alt="" class="w-7 h-7 rounded-full object-cover flex-shrink-0">
                                <?php else: ?>
                                    <div class="w-7 h-7 rounded-full bg-indigo-100 dark:bg-indigo-900 flex items-center justify-center text-indigo-600 dark:text-indigo-300 font-bold text-xs flex-shrink-0">
                                        <?= e(_first_char($u['username'])) ?>
                                    </div>
                                <?php endif; ?>
                                <div class="min-w-0">
                                    <div class="font-medium text-sm truncate" style="color:var(--color-text-primary)"><?= e($u['username']) ?></div>
                                    <div class="text-xs truncate md:hidden" style="color:var(--color-text-muted)"><?= e($u['email']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="py-2.5 px-3 hidden md:table-cell" style="color:var(--color-text-secondary)">
                            <span class="text-sm"><?= e($u['email']) ?></span>
                        </td>
                        <td class="py-2.5 px-3">
                            <?php
                            $role_class = $u['role'] === 'company'
                                ? 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300'
                                : 'bg-purple-100 dark:bg-purple-900/30 text-purple-800 dark:text-purple-300';
                            ?>
                            <span class="inline-flex px-1.5 py-0.5 text-[10px] font-semibold rounded-full <?= $role_class ?>"><?= e(ucfirst($u['role'])) ?></span>
                        </td>
                        <?php if ($has_status_col): ?>
                        <td class="py-2.5 px-3">
                            <?php
                            $status = $u['account_status'] ?? 'active';
                            $status_classes = [
                                'active' => 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300',
                                'suspended' => 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-300',
                                'blocked' => 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300',
                            ];
                            $sc = $status_classes[$status] ?? $status_classes['active'];
                            ?>
                            <span class="inline-flex px-1.5 py-0.5 text-[10px] font-semibold rounded-full <?= $sc ?>"><?= e(__('user_status.' . $status)) ?></span>
                        </td>
                        <?php endif; ?>
                        <td class="py-2.5 px-3 text-xs hidden lg:table-cell" style="color:var(--color-text-muted)"><?= e($u['created_at']) ?></td>
                        <td class="py-2.5 px-3 text-right">
                            <a href="<?= e(base_url('admin/view_user.php?id=' . $u['id'])) ?>" class="btn-secondary text-xs py-1 px-2">
                                <?= e('View') ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
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
