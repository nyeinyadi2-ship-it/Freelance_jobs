<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

require_role('admin');

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');

        if ($name === '') {
            set_flash('error', 'Category name is required.');
        } else {
            $stmt = $conn->prepare("SELECT id FROM categories WHERE name = ?");
            $stmt->bind_param('s', $name);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                set_flash('error', 'A category with this name already exists.');
            } else {
                $stmt2 = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
                $stmt2->bind_param('s', $name);
                if ($stmt2->execute()) {
                    set_flash('success', 'Category added successfully.');
                } else {
                    set_flash('error', 'Failed to add category.');
                }
                $stmt2->close();
            }
            $stmt->close();
        }
        redirect('admin/categories.php');

    } elseif ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');

        if ($id > 0 && $name !== '') {
            $stmt = $conn->prepare("SELECT id FROM categories WHERE name = ? AND id != ?");
            $stmt->bind_param('si', $name, $id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                set_flash('error', 'Another category with this name already exists.');
            } else {
                $stmt2 = $conn->prepare("SELECT name FROM categories WHERE id = ?");
                $stmt2->bind_param('i', $id);
                $stmt2->execute();
                $old_row = $stmt2->get_result()->fetch_assoc();
                $stmt2->close();

                if ($old_row) {
                    $old_name = $old_row['name'];

                    $conn->begin_transaction();
                    try {
                        $stmt3 = $conn->prepare("UPDATE categories SET name = ? WHERE id = ?");
                        $stmt3->bind_param('si', $name, $id);
                        $stmt3->execute();
                        $stmt3->close();

                        if ($old_name !== $name) {
                            $stmt4 = $conn->prepare("UPDATE jobs SET category = ? WHERE category = ?");
                            $stmt4->bind_param('ss', $name, $old_name);
                            $stmt4->execute();
                            $stmt4->close();
                        }

                        $conn->commit();
                        set_flash('success', 'Category updated successfully.');
                    } catch (Exception $e) {
                        $conn->rollback();
                        set_flash('error', 'Failed to update category.');
                    }
                }
            }
            $stmt->close();
        } else {
            set_flash('error', 'Invalid category data.');
        }
        redirect('admin/categories.php');

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id > 0) {
            $stmt = $conn->prepare("SELECT name FROM categories WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                $name = $row['name'];

                $stmt2 = $conn->prepare("SELECT COUNT(*) AS cnt FROM jobs WHERE category = ?");
                $stmt2->bind_param('s', $name);
                $stmt2->execute();
                $usage = (int) $stmt2->get_result()->fetch_assoc()['cnt'];
                $stmt2->close();

                if ($usage > 0) {
                    set_flash('error', 'This category is currently in use and cannot be deleted.');
                } else {
                    $stmt3 = $conn->prepare("DELETE FROM categories WHERE id = ?");
                    $stmt3->bind_param('i', $id);
                    if ($stmt3->execute()) {
                        set_flash('success', 'Category deleted successfully.');
                    } else {
                        set_flash('error', 'Failed to delete category.');
                    }
                    $stmt3->close();
                }
            }
        }
        redirect('admin/categories.php');
    }
}

// Fetch categories
$categories = [];
$res = $conn->query("
    SELECT c.*, (SELECT COUNT(*) FROM jobs WHERE category = c.name) AS job_count 
    FROM categories c 
    ORDER BY c.name ASC
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $categories[] = $row;
    }
}

$page_title = 'Manage Categories';
require __DIR__ . '/includes/admin_header.php';
?>

<!-- Page Header -->
<div class="mb-5 admin-fade">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold" style="color:var(--color-text-primary)">Manage Categories</h1>
            <p class="text-sm mt-0.5" style="color:var(--color-text-muted)"><?= e('Manage and organize job categories used across the platform.') ?></p>
        </div>
        <button onclick="document.getElementById('addModal').classList.remove('hidden')" class="btn-primary text-xs">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            Add Category
        </button>
    </div>
</div>

<!-- Categories Table -->
<div class="card admin-fade overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr style="border-bottom:2px solid var(--color-border)">
                <th class="text-left py-2 px-3 text-xs font-semibold uppercase tracking-wider" style="color:var(--color-text-muted)">Category Name</th>
                <th class="text-center py-2 px-3 text-xs font-semibold uppercase tracking-wider" style="color:var(--color-text-muted)">Jobs Using</th>
                <th class="text-right py-2 px-3 text-xs font-semibold uppercase tracking-wider" style="color:var(--color-text-muted)">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($categories)): ?>
                <tr>
                    <td colspan="3" class="py-8 text-center text-sm" style="color:var(--color-text-muted)">No categories found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($categories as $cat): ?>
                    <tr style="border-bottom:1px solid var(--color-border)" class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
                        <td class="py-2.5 px-3">
                            <span class="font-medium text-sm" style="color:var(--color-text-primary)"><?= e($cat['name']) ?></span>
                        </td>
                        <td class="py-2.5 px-3 text-center">
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold <?= $cat['job_count'] > 0 ? 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300' : 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300' ?>">
                                <?= $cat['job_count'] ?> jobs
                            </span>
                        </td>
                        <td class="py-2.5 px-3">
                            <div class="flex items-center justify-end gap-1">
                                <button onclick="openEditModal(<?= $cat['id'] ?>, '<?= e(addslashes($cat['name'])) ?>')" class="p-1.5 rounded hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors" style="color:var(--color-text-muted)" title="Edit">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                </button>
                                    <form method="POST" class="inline-block" onsubmit="return confirm('Are you sure you want to delete this category?');">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                                        <button type="submit" class="p-1.5 rounded hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors" style="color:var(--color-text-placeholder)" title="Delete">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>
                                    </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Add Modal -->
<div id="addModal" class="fixed inset-0 z-50 hidden" style="background:rgba(0,0,0,0.4);backdrop-filter:blur(4px)">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="card w-full max-w-sm" style="background:var(--color-card);border:1px solid var(--color-border)">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold" style="color:var(--color-text-primary)">Add Category</h3>
                <button onclick="document.getElementById('addModal').classList.add('hidden')" class="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors" style="color:var(--color-text-muted)">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="add">
                <div class="mb-4">
                    <input type="text" name="name" required maxlength="100" placeholder="Enter category name" class="form-input">
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="document.getElementById('addModal').classList.add('hidden')" class="btn-secondary text-xs">Cancel</button>
                    <button type="submit" class="btn-primary text-xs">Add Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 z-50 hidden" style="background:rgba(0,0,0,0.4);backdrop-filter:blur(4px)">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="card w-full max-w-sm" style="background:var(--color-card);border:1px solid var(--color-border)">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold" style="color:var(--color-text-primary)">Edit Category</h3>
                <button onclick="document.getElementById('editModal').classList.add('hidden')" class="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors" style="color:var(--color-text-muted)">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id" value="">
                <div class="mb-4">
                    <label class="block text-xs font-medium mb-1" style="color:var(--color-text-secondary)">Category Name</label>
                    <input type="text" name="name" id="edit_name" required maxlength="100" class="form-input">
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="document.getElementById('editModal').classList.add('hidden')" class="btn-secondary text-xs">Cancel</button>
                    <button type="submit" class="btn-primary text-xs">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openEditModal(id, name) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('editModal').classList.remove('hidden');
    document.getElementById('edit_name').focus();
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.getElementById('addModal').classList.add('hidden');
        document.getElementById('editModal').classList.add('hidden');
    }
});
document.getElementById('addModal').addEventListener('click', function(e) { if (e.target === this) this.classList.add('hidden'); });
document.getElementById('editModal').addEventListener('click', function(e) { if (e.target === this) this.classList.add('hidden'); });
</script>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>
