<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';

require_role('admin');

$search = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// --- POST handlers ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $do = $_POST['do'] ?? '';

    if ($do === 'add') {
        $name = trim($_POST['skill_name'] ?? '');
        if ($name === '') {
            set_flash('error', 'Skill name is required.');
        } else {
            $chk = $conn->prepare("SELECT id FROM skills WHERE skill_name = ?");
            $chk->bind_param('s', $name);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $chk->close();
                set_flash('error', 'This skill already exists.');
            } else {
                $chk->close();
                $st = $conn->prepare("INSERT INTO skills (skill_name) VALUES (?)");
                $st->bind_param('s', $name);
                if ($st->execute()) {
                    set_flash('success', "Skill \"{$name}\" added successfully.");
                } else {
                    set_flash('error', 'Failed to add skill.');
                }
                $st->close();
            }
        }
        redirect('admin/manage_skills.php');
    }

    if ($do === 'edit') {
        $id = (int) ($_POST['skill_id'] ?? 0);
        $name = trim($_POST['skill_name'] ?? '');
        if ($id <= 0 || $name === '') {
            set_flash('error', 'Invalid skill data.');
        } else {
            // Check for duplicate name (excluding current)
            $chk = $conn->prepare("SELECT id FROM skills WHERE skill_name = ? AND id != ?");
            $chk->bind_param('si', $name, $id);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $chk->close();
                set_flash('error', 'A skill with this name already exists.');
            } else {
                $chk->close();
                $st = $conn->prepare("UPDATE skills SET skill_name = ? WHERE id = ?");
                $st->bind_param('si', $name, $id);
                if ($st->execute()) {
                    set_flash('success', "Skill updated to \"{$name}\".");
                } else {
                    set_flash('error', 'Failed to update skill.');
                }
                $st->close();
            }
        }
        redirect('admin/manage_skills.php');
    }

    if ($do === 'delete') {
        $id = (int) ($_POST['skill_id'] ?? 0);
        if ($id > 0) {
            // Get name for flash message
            $chk = $conn->prepare("SELECT skill_name FROM skills WHERE id = ?");
            $chk->bind_param('i', $id);
            $chk->execute();
            $row = $chk->get_result()->fetch_assoc();
            $chk->close();

            if ($row) {
                // Delete from junction tables first (MyISAM doesn't cascade)
                $conn->query("DELETE FROM freelancer_skills WHERE skill_id = $id");
                $conn->query("DELETE FROM job_skills WHERE skill_id = $id");

                $conn->query("DELETE FROM skills WHERE id = $id");
                set_flash('success', "Skill \"{$row['skill_name']}\" deleted.");
            } else {
                set_flash('error', 'Skill not found.');
            }
        }
        redirect('admin/manage_skills.php');
    }
}

// --- Fetch data ---
$where = '1=1';
$params = [];
$types = '';

if ($search !== '') {
    $where .= ' AND skill_name LIKE ?';
    $like = '%' . $search . '%';
    $params[] = $like;
    $types .= 's';
}

$total = 0;
$total_pages = 1;
try {
    $count_sql = "SELECT COUNT(*) AS cnt FROM skills WHERE {$where}";
    $count_stmt = $conn->prepare($count_sql);
    if ($types !== '') { $count_stmt->bind_param($types, ...$params); }
    $count_stmt->execute();
    $total = (int) $count_stmt->get_result()->fetch_assoc()['cnt'];
    $count_stmt->close();
    $total_pages = max(1, ceil($total / $per_page));
} catch (mysqli_sql_exception $e) {}

$skills = [];
try {
    $sql = "SELECT s.id, s.skill_name,
            (SELECT COUNT(*) FROM freelancer_skills fs WHERE fs.skill_id = s.id) AS freelancer_count,
            (SELECT COUNT(*) FROM job_skills js WHERE js.skill_id = s.id) AS job_count
            FROM skills s WHERE {$where} ORDER BY s.skill_name ASC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $param_types = $types . 'ii';
    $param_values = array_merge($params, [$per_page, $offset]);
    $stmt->bind_param($param_types, ...$param_values);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $skills[] = $row; }
    $stmt->close();
} catch (mysqli_sql_exception $e) {}

$page_title = 'Manage Skills';
require __DIR__ . '/includes/admin_header.php';
?>

<!-- Page Header -->
<div class="mb-6 admin-fade">
    <div class="flex items-center gap-3 mb-1">
        <a href="<?= e(base_url('admin/admin_dashboard.php')) ?>" class="text-sm hover:underline" style="color:var(--color-text-muted)">Dashboard</a>
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-text-placeholder)"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        <span class="text-sm font-medium" style="color:var(--color-text-primary)">Manage Skills</span>
    </div>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="text-2xl font-bold" style="color:var(--color-text-primary)">Manage Skills</h1>
        <div class="text-sm" style="color:var(--color-text-muted)">Total: <?= $total ?> skill<?= $total !== 1 ? 's' : '' ?></div>
    </div>
</div>

<!-- Add Skill Form -->
<div class="card mb-6 admin-fade">
    <h2 class="text-sm font-semibold mb-3" style="color:var(--color-text-primary)">Add New Skill</h2>
    <form method="POST" class="flex gap-3 items-end">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="do" value="add">
        <div class="flex-1 min-w-[200px]">
            <input type="text" name="skill_name" required maxlength="50" placeholder="Enter skill name (e.g. React.js)" class="form-input">
        </div>
        <button type="submit" class="btn-primary inline-flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            Add Skill
        </button>
    </form>
</div>

<!-- Search -->
<div class="card mb-6">
    <form method="GET" class="flex gap-3 items-end">
        <div class="flex-1 min-w-[200px]">
            <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)">Search Skills</label>
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search by name..." class="form-input">
        </div>
        <div class="flex gap-2">
            <button type="submit" class="btn-primary">Search</button>
            <a href="<?= e(base_url('admin/manage_skills.php')) ?>" class="btn-secondary">Clear</a>
        </div>
    </form>
</div>

<!-- Skills Table -->
<?php if (empty($skills)): ?>
    <div class="card text-center py-12" style="color:var(--color-text-muted)">
        <svg class="w-16 h-16 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A2 2 0 013 12V7a4 4 0 014-4z"/></svg>
        <p class="text-lg font-semibold mb-1" style="color:var(--color-text-primary)"><?= $search ? 'No skills match your search.' : 'No skills yet.' ?></p>
        <p class="text-sm"><?= $search ? 'Try a different search term.' : 'Add your first skill above.' ?></p>
    </div>
<?php else: ?>
    <div class="card overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr style="border-bottom:1px solid var(--color-border)">
                    <th class="text-left py-3 px-4 font-semibold" style="color:var(--color-text-secondary)">Skill Name</th>
                    <th class="text-center py-3 px-4 font-semibold" style="color:var(--color-text-secondary)">Freelancers</th>
                    <th class="text-center py-3 px-4 font-semibold" style="color:var(--color-text-secondary)">Jobs</th>
                    <th class="text-right py-3 px-4 font-semibold" style="color:var(--color-text-secondary)">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($skills as $sk): ?>
                <tr style="border-bottom:1px solid var(--color-border)" class="hover:opacity-80 transition-opacity">
                    <td class="py-3 px-4">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" style="background:rgba(99,102,241,0.1)">
                                <svg class="w-4 h-4" style="color:#6366f1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A2 2 0 013 12V7a4 4 0 014-4z"/></svg>
                            </div>
                            <span class="font-medium" style="color:var(--color-text-primary)"><?= e($sk['skill_name']) ?></span>
                        </div>
                    </td>
                    <td class="py-3 px-4 text-center">
                        <span class="inline-flex items-center justify-center min-w-[24px] px-2 py-0.5 rounded-full text-xs font-semibold" style="background:rgba(99,102,241,0.1);color:#6366f1"><?= $sk['freelancer_count'] ?></span>
                    </td>
                    <td class="py-3 px-4 text-center">
                        <span class="inline-flex items-center justify-center min-w-[24px] px-2 py-0.5 rounded-full text-xs font-semibold" style="background:rgba(16,185,129,0.1);color:#10b981"><?= $sk['job_count'] ?></span>
                    </td>
                    <td class="py-3 px-4">
                        <div class="flex items-center justify-end gap-1">
                            <!-- Edit Button -->
                            <button type="button" onclick="openEditModal(<?= (int) $sk['id'] ?>, '<?= e(addslashes($sk['skill_name'])) ?>')" class="p-2 rounded-lg transition-colors" style="color:var(--color-text-muted)" title="Edit">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </button>
                            <!-- Delete Button -->
                            <button type="button" onclick="confirmDelete(<?= (int) $sk['id'] ?>, '<?= e(addslashes($sk['skill_name'])) ?>')" class="p-2 rounded-lg transition-colors hover:bg-red-50 dark:hover:bg-red-900/20" style="color:var(--color-text-placeholder)" title="Delete">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="flex items-center justify-between mt-4">
        <p class="text-sm" style="color:var(--color-text-muted)">Showing <?= $offset + 1 ?>-<?= min($offset + $per_page, $total) ?> of <?= $total ?></p>
        <div class="flex gap-1">
            <?php if ($page > 1): ?>
                <a href="?q=<?= e($search) ?>&page=<?= $page - 1 ?>" class="px-3 py-1.5 rounded-lg text-sm font-medium border transition-colors hover:bg-gray-50 dark:hover:bg-gray-800" style="border-color:var(--color-border);color:var(--color-text-secondary)">Prev</a>
            <?php endif; ?>
            <?php
            $start = max(1, $page - 2);
            $end = min($total_pages, $page + 2);
            for ($p = $start; $p <= $end; $p++):
            ?>
                <a href="?q=<?= e($search) ?>&page=<?= $p ?>" class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors <?= $p === $page ? 'text-white' : 'border hover:bg-gray-50 dark:hover:bg-gray-800' ?>" style="<?= $p === $page ? 'background:linear-gradient(135deg,#4f46e5,#7c3aed);color:white' : 'border-color:var(--color-border);color:var(--color-text-secondary)' ?>"><?= $p ?></a>
            <?php endfor; ?>
            <?php if ($page < $total_pages): ?>
                <a href="?q=<?= e($search) ?>&page=<?= $page + 1 ?>" class="px-3 py-1.5 rounded-lg text-sm font-medium border transition-colors hover:bg-gray-50 dark:hover:bg-gray-800" style="border-color:var(--color-border);color:var(--color-text-secondary)">Next</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 z-50 hidden" style="background:rgba(0,0,0,0.5);backdrop-filter:blur(4px)">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="card w-full max-w-md" style="background:var(--color-card);border:1px solid var(--color-border)">
            <div class="flex items-center justify-between p-4 border-b" style="border-color:var(--color-border)">
                <h3 class="text-lg font-semibold" style="color:var(--color-text-primary)">Edit Skill</h3>
                <button type="button" onclick="closeEditModal()" class="p-1 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors" style="color:var(--color-text-muted)">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <form method="POST" class="p-4">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="do" value="edit">
                <input type="hidden" name="skill_id" id="editSkillId">
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)">Skill Name</label>
                    <input type="text" name="skill_name" id="editSkillName" required maxlength="50" class="form-input">
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="closeEditModal()" class="btn-secondary">Cancel</button>
                    <button type="submit" class="btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="fixed inset-0 z-50 hidden" style="background:rgba(0,0,0,0.5);backdrop-filter:blur(4px)">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="card w-full max-w-md" style="background:var(--color-card);border:1px solid var(--color-border)">
            <div class="p-6 text-center">
                <div class="w-14 h-14 rounded-full mx-auto mb-4 flex items-center justify-center" style="background:rgba(239,68,68,0.1)">
                    <svg class="w-7 h-7 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                </div>
                <h3 class="text-lg font-semibold mb-2" style="color:var(--color-text-primary)">Delete Skill</h3>
                <p class="text-sm mb-6" style="color:var(--color-text-muted)">Are you sure you want to delete "<span id="deleteSkillName"></span>"? This will remove it from all freelancers and jobs.</p>
                <form method="POST" class="flex justify-center gap-3">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="do" value="delete">
                    <input type="hidden" name="skill_id" id="deleteSkillId">
                    <button type="button" onclick="closeDeleteModal()" class="btn-secondary">Cancel</button>
                    <button type="submit" class="px-5 py-2 rounded-lg text-sm font-semibold text-white transition-colors" style="background:#ef4444">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function openEditModal(id, name) {
    document.getElementById('editSkillId').value = id;
    document.getElementById('editSkillName').value = name;
    document.getElementById('editModal').classList.remove('hidden');
    document.getElementById('editSkillName').focus();
}
function closeEditModal() {
    document.getElementById('editModal').classList.add('hidden');
}
function confirmDelete(id, name) {
    document.getElementById('deleteSkillId').value = id;
    document.getElementById('deleteSkillName').textContent = name;
    document.getElementById('deleteModal').classList.remove('hidden');
}
function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { closeEditModal(); closeDeleteModal(); }
});
document.getElementById('editModal').addEventListener('click', function(e) { if (e.target === this) closeEditModal(); });
document.getElementById('deleteModal').addEventListener('click', function(e) { if (e.target === this) closeDeleteModal(); });
</script>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>
