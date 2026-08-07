<?php
$page_title = 'My Portfolio';
require __DIR__ . '/../includes/freelancer_init.php';
require_once __DIR__ . '/../config/upload.php';

$all_skills = [];
$r = $conn->query('SELECT id, skill_name FROM skills ORDER BY skill_name');
if ($r) while ($row = $r->fetch_assoc()) $all_skills[] = $row;

// --- POST handlers ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $do = $_POST['do'] ?? '';

    // ADD new item
    if ($do === 'add') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $project_url = trim($_POST['project_url'] ?? '');
        $github_url = trim($_POST['github_url'] ?? '');
        $completion_date = $_POST['completion_date'] ?? null;
        $selected_skills = $_POST['skills'] ?? [];

        if ($title === '') {
            set_flash('error', 'Project title is required.');
        } else {
            $cover = null;
            if (!empty($_FILES['cover_image']['name'])) {
                $cover = upload_image($_FILES['cover_image'], 10 * 1024 * 1024, $upload_err);
                if (!$cover) { set_flash('error', $upload_err ?: 'Invalid cover image. Allowed: JPG, PNG, GIF, WebP. Max 10MB.'); redirect('freelancer/portfolio.php'); }
            }
            $att = null; $att_name = null;
            if (!empty($_FILES['attachment']['name'])) {
                $att = upload_attachment($_FILES['attachment'], 10 * 1024 * 1024, $upload_err);
                if (!$att) { set_flash('error', $upload_err ?: 'Invalid attachment. Allowed: PDF, DOC, DOCX, ZIP, RAR, images. Max 10MB.'); redirect('freelancer/portfolio.php'); }
                $att_name = $_FILES['attachment']['name'];
            }

            $conn->begin_transaction();
            try {
                $st = $conn->prepare("INSERT INTO portfolio_items (freelancer_id, title, description, project_url, github_url, completion_date, cover_image, attachment, attachment_original_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $cd = $completion_date !== '' ? $completion_date : null;
                $st->bind_param('issssssss', $fl_freelancer_id, $title, $description, $project_url, $github_url, $cd, $cover, $att, $att_name);
                $st->execute();
                $item_id = $st->insert_id;
                $st->close();

                if (!empty($selected_skills)) {
                    $ss = $conn->prepare("INSERT INTO portfolio_skills (portfolio_item_id, skill_id) VALUES (?, ?)");
                    foreach ($selected_skills as $sid) {
                        $sid = (int) $sid;
                        $ss->bind_param('ii', $item_id, $sid);
                        $ss->execute();
                    }
                    $ss->close();
                }

                // Handle multiple images
                if (!empty($_FILES['images']['name'][0])) {
                    $img_st = $conn->prepare("INSERT INTO portfolio_images (portfolio_item_id, image_path, sort_order) VALUES (?, ?, ?)");
                    $order = 0;
                    foreach ($_FILES['images']['name'] as $idx => $img_name) {
                        if ($_FILES['images']['error'][$idx] !== UPLOAD_ERR_OK) continue;
                        $tmp = ['name' => $img_name, 'type' => $_FILES['images']['type'][$idx], 'tmp_name' => $_FILES['images']['tmp_name'][$idx], 'error' => $_FILES['images']['error'][$idx], 'size' => $_FILES['images']['size'][$idx]];
                        $uploaded = upload_image($tmp, 10 * 1024 * 1024, $upload_err);
                        if ($uploaded) {
                            $img_st->bind_param('isi', $item_id, $uploaded, $order);
                            $img_st->execute();
                            $order++;
                        }
                    }
                    $img_st->close();
                }

                $conn->commit();
                set_flash('success', 'Portfolio item added successfully!');
            } catch (Exception $e) {
                $conn->rollback();
                if ($cover) delete_upload($cover);
                if ($att) delete_attachment($att);
                set_flash('error', 'Failed to add portfolio item.');
            }
        }
        redirect('freelancer/portfolio.php');
    }

    // EDIT item
    if ($do === 'edit') {
        $item_id = (int) ($_POST['item_id'] ?? 0);
        if ($item_id <= 0) { redirect('freelancer/portfolio.php'); }

        // Verify ownership
        $chk = $conn->prepare("SELECT id, cover_image, attachment FROM portfolio_items WHERE id = ? AND freelancer_id = ?");
        $chk->bind_param('ii', $item_id, $fl_freelancer_id);
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$existing) { set_flash('error', 'Portfolio item not found.'); redirect('freelancer/portfolio.php'); }

        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $project_url = trim($_POST['project_url'] ?? '');
        $github_url = trim($_POST['github_url'] ?? '');
        $completion_date = $_POST['completion_date'] ?? null;
        $selected_skills = $_POST['skills'] ?? [];

        if ($title === '') {
            set_flash('error', 'Project title is required.');
            redirect('freelancer/portfolio.php?edit=' . $item_id);
        }

        $cover = $existing['cover_image'];
        if (!empty($_FILES['cover_image']['name'])) {
            $new_cover = upload_image($_FILES['cover_image'], 10 * 1024 * 1024, $upload_err);
            if ($new_cover) { $cover = $new_cover; } else { set_flash('error', $upload_err ?: 'Invalid cover image. Max 10MB.'); redirect('freelancer/portfolio.php?edit=' . $item_id); }
        }

        $att = $existing['attachment'];
        $att_name = $_POST['existing_attachment_name'] ?? null;
        if (!empty($_FILES['attachment']['name'])) {
            $new_att = upload_attachment($_FILES['attachment'], 10 * 1024 * 1024, $upload_err);
            if ($new_att) { $att = $new_att; $att_name = $_FILES['attachment']['name']; } else { set_flash('error', $upload_err ?: 'Invalid attachment. Max 10MB.'); redirect('freelancer/portfolio.php?edit=' . $item_id); }
        }

        // Handle new gallery images
        $new_images = [];
        if (!empty($_FILES['images']['name'][0])) {
            foreach ($_FILES['images']['name'] as $idx => $img_name) {
                if ($_FILES['images']['error'][$idx] !== UPLOAD_ERR_OK) continue;
                $tmp = ['name' => $img_name, 'type' => $_FILES['images']['type'][$idx], 'tmp_name' => $_FILES['images']['tmp_name'][$idx], 'error' => $_FILES['images']['error'][$idx], 'size' => $_FILES['images']['size'][$idx]];
                $uploaded = upload_image($tmp, 10 * 1024 * 1024, $upload_err);
                if ($uploaded) $new_images[] = $uploaded;
            }
        }

        $conn->begin_transaction();
        try {
            $cd = $completion_date !== '' ? $completion_date : null;
            $st = $conn->prepare("UPDATE portfolio_items SET title=?, description=?, project_url=?, github_url=?, completion_date=?, cover_image=?, attachment=?, attachment_original_name=? WHERE id=?");
            $st->bind_param('ssssssssi', $title, $description, $project_url, $github_url, $cd, $cover, $att, $att_name, $item_id);
            $st->execute(); $st->close();

            // Replace skills
            $conn->prepare("DELETE FROM portfolio_skills WHERE portfolio_item_id = ?")->bind_param('i', $item_id);
            $conn->query("DELETE FROM portfolio_skills WHERE portfolio_item_id = $item_id");
            if (!empty($selected_skills)) {
                $ss = $conn->prepare("INSERT INTO portfolio_skills (portfolio_item_id, skill_id) VALUES (?, ?)");
                foreach ($selected_skills as $sid) {
                    $sid = (int) $sid;
                    $ss->bind_param('ii', $item_id, $sid);
                    $ss->execute();
                }
                $ss->close();
            }

            // Add new gallery images
            if (!empty($new_images)) {
                $img_st = $conn->prepare("INSERT INTO portfolio_images (portfolio_item_id, image_path, sort_order) VALUES (?, ?, ?)");
                $max_order = 0;
                $mo = $conn->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 AS next FROM portfolio_images WHERE portfolio_item_id = ?");
                $mo->bind_param('i', $item_id); $mo->execute(); $max_order = (int) $mo->get_result()->fetch_assoc()['next']; $mo->close();
                foreach ($new_images as $img) {
                    $img_st->bind_param('isi', $item_id, $img, $max_order);
                    $img_st->execute();
                    $max_order++;
                }
                $img_st->close();
            }

            // Delete old cover if replaced
            if ($cover !== $existing['cover_image'] && $existing['cover_image']) delete_upload($existing['cover_image']);
            // Delete old attachment if replaced
            if ($att !== $existing['attachment'] && $existing['attachment']) delete_attachment($existing['attachment']);

            $conn->commit();
            set_flash('success', 'Portfolio item updated!');
        } catch (Exception $e) {
            $conn->rollback();
            set_flash('error', 'Failed to update portfolio item.');
        }
        redirect('freelancer/portfolio.php');
    }

    // DELETE item
    if ($do === 'delete') {
        $item_id = (int) ($_POST['item_id'] ?? 0);
        if ($item_id > 0) {
            $chk = $conn->prepare("SELECT cover_image, attachment FROM portfolio_items WHERE id = ? AND freelancer_id = ?");
            $chk->bind_param('ii', $item_id, $fl_freelancer_id);
            $chk->execute();
            $existing = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($existing) {
                // Delete gallery images
                $imgs = $conn->prepare("SELECT image_path FROM portfolio_images WHERE portfolio_item_id = ?");
                $imgs->bind_param('i', $item_id); $imgs->execute();
                $ir = $imgs->get_result();
                while ($row = $ir->fetch_assoc()) delete_upload($row['image_path']);
                $imgs->close();

                $conn->query("DELETE FROM portfolio_images WHERE portfolio_item_id = $item_id");
                $conn->query("DELETE FROM portfolio_skills WHERE portfolio_item_id = $item_id");
                $conn->query("DELETE FROM portfolio_items WHERE id = $item_id AND freelancer_id = $fl_freelancer_id");

                if ($existing['cover_image']) delete_upload($existing['cover_image']);
                if ($existing['attachment']) delete_attachment($existing['attachment']);

                set_flash('success', 'Portfolio item deleted.');
            }
        }
        redirect('freelancer/portfolio.php');
    }

    // DELETE single gallery image
    if ($do === 'delete_image') {
        $img_id = (int) ($_POST['image_id'] ?? 0);
        if ($img_id > 0) {
            $chk = $conn->prepare("SELECT pi.id, pi.image_path FROM portfolio_images pi JOIN portfolio_items pi2 ON pi.portfolio_item_id = pi2.id WHERE pi.id = ? AND pi2.freelancer_id = ?");
            $chk->bind_param('ii', $img_id, $fl_freelancer_id);
            $chk->execute();
            $row = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($row) {
                delete_upload($row['image_path']);
                $conn->query("DELETE FROM portfolio_images WHERE id = $img_id");
                set_flash('success', 'Image removed.');
            }
        }
        $back_item = (int) ($_POST['item_id'] ?? 0);
        redirect('freelancer/portfolio.php' . ($back_item > 0 ? '?edit=' . $back_item : ''));
    }

    // REORDER items
    if ($do === 'reorder') {
        $order = $_POST['order'] ?? [];
        if (!empty($order)) {
            $st = $conn->prepare("UPDATE portfolio_items SET sort_order = ? WHERE id = ? AND freelancer_id = ?");
            foreach ($order as $pos => $iid) {
                $iid = (int) $iid;
                $pos = (int) $pos;
                $st->bind_param('iii', $pos, $iid, $fl_freelancer_id);
                $st->execute();
            }
            $st->close();
            set_flash('success', 'Portfolio order updated.');
        }
        redirect('freelancer/portfolio.php');
    }
}

// --- Fetch data ---
$portfolio_items = [];
$st = $conn->prepare("SELECT * FROM portfolio_items WHERE freelancer_id = ? ORDER BY sort_order ASC, id DESC");
$st->bind_param('i', $fl_freelancer_id);
$st->execute();
$rr = $st->get_result();
while ($row = $rr->fetch_assoc()) { $portfolio_items[] = $row; }
$st->close();

// Fetch skills for each item
foreach ($portfolio_items as &$item) {
    $item['skills'] = [];
    $item['images'] = [];
    $ps = $conn->prepare("SELECT s.skill_name FROM portfolio_skills ps JOIN skills s ON ps.skill_id = s.id WHERE ps.portfolio_item_id = ?");
    $ps->bind_param('i', $item['id']); $ps->execute();
    $sr = $ps->get_result();
    while ($row = $sr->fetch_assoc()) $item['skills'][] = $row['skill_name'];
    $ps->close();

    $pi = $conn->prepare("SELECT * FROM portfolio_images WHERE portfolio_item_id = ? ORDER BY sort_order ASC");
    $pi->bind_param('i', $item['id']); $pi->execute();
    $ir = $pi->get_result();
    while ($row = $ir->fetch_assoc()) $item['images'][] = $row;
    $pi->close();
}
unset($item);

$editing = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$edit_item = null;
$edit_skills = [];
$edit_images = [];
if ($editing > 0) {
    foreach ($portfolio_items as $item) {
        if ($item['id'] === $editing) { $edit_item = $item; $edit_skills = $item['skills']; $edit_images = $item['images']; break; }
    }
    if (!$edit_item) { redirect('freelancer/portfolio.php'); }
}

require __DIR__ . '/../includes/freelancer_layout.php';
?>

<style>
.portfolio-card{transition:all .3s ease;}
.portfolio-card:hover{transform:translateY(-4px);box-shadow:0 16px 40px rgba(79,70,229,0.12);}
.portfolio-card:hover .portfolio-overlay{opacity:1;}
.portfolio-overlay{opacity:0;transition:opacity .3s ease;}
.upload-area{border:2px dashed var(--color-border);border-radius:12px;padding:1.5rem;text-align:center;cursor:pointer;transition:all .3s;}
.upload-area:hover{border-color:#6366f1;background:rgba(99,102,241,0.03);}
</style>

<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 pt-2 pb-12">

<!-- Header -->
<div class="flex flex-wrap items-center justify-between gap-4 mb-6 reveal">
    <div>
        <h1 class="text-2xl font-bold" style="color:var(--color-text-primary)">My Portfolio</h1>
        <p class="text-sm mt-1" style="color:var(--color-text-muted)"><?= count($portfolio_items) ?> project<?= count($portfolio_items) !== 1 ? 's' : '' ?> showcased</p>
    </div>
    <button onclick="document.getElementById('addForm').scrollIntoView({behavior:'smooth'})" class="btn-grad inline-flex items-center gap-2 px-5 py-2.5 text-sm font-semibold rounded-xl text-white shadow-lg shadow-primary-500/20">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
        Add Project
    </button>
</div>

<!-- Add / Edit Form -->
<div id="addForm" class="glass rounded-2xl p-6 mb-8 reveal">
    <h2 class="text-lg font-bold mb-4" style="color:var(--color-text-primary)"><?= $edit_item ? 'Edit Project' : 'Add New Project' ?></h2>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="do" value="<?= $edit_item ? 'edit' : 'add' ?>">
        <?php if ($edit_item): ?><input type="hidden" name="item_id" value="<?= $edit_item['id'] ?>"><?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Project Title *</label>
                <input type="text" name="title" required maxlength="200" value="<?= e($edit_item['title'] ?? '') ?>" class="w-full px-4 py-2.5 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" placeholder="e.g. E-commerce Platform">
            </div>
            <div>
                <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Completion Date</label>
                <input type="date" name="completion_date" value="<?= e($edit_item['completion_date'] ?? '') ?>" class="w-full px-4 py-2.5 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)">
            </div>
        </div>

        <div class="mb-4">
            <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Description</label>
            <textarea name="description" rows="3" class="w-full px-4 py-2.5 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 resize-y" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" placeholder="Describe the project, your role, key features..."><?= e($edit_item['description'] ?? '') ?></textarea>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Project URL</label>
                <input type="url" name="project_url" maxlength="500" value="<?= e($edit_item['project_url'] ?? '') ?>" class="w-full px-4 py-2.5 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" placeholder="https://example.com">
            </div>
            <div>
                <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">GitHub URL</label>
                <input type="url" name="github_url" maxlength="500" value="<?= e($edit_item['github_url'] ?? '') ?>" class="w-full px-4 py-2.5 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" placeholder="https://github.com/...">
            </div>
        </div>

        <div class="mb-4">
            <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Skills Used</label>
            <div class="flex flex-wrap gap-2 p-3 rounded-xl max-h-40 overflow-y-auto" style="background:var(--color-bg);border:1px solid var(--color-border)">
                <?php foreach ($all_skills as $sk): ?>
                    <label class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium cursor-pointer transition-all" style="background:var(--color-card);border:1px solid var(--color-border);color:var(--color-text-secondary)">
                        <input type="checkbox" name="skills[]" value="<?= $sk['id'] ?>" <?= in_array($sk['skill_name'], $edit_skills) ? 'checked' : '' ?> class="rounded text-primary-600 focus:ring-primary-500">
                        <?= e($sk['skill_name']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Cover Image <?= $edit_item ? '(leave empty to keep current)' : '' ?></label>
                <input type="file" name="cover_image" accept=".jpg,.jpeg,.png,.gif,.webp" class="w-full text-sm file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:text-white file:cursor-pointer" style="color:var(--color-text-muted)">
                <?php if ($edit_item && $edit_item['cover_image']): ?>
                    <div class="mt-2"><img src="<?= e(base_url('uploads/images/' . $edit_item['cover_image'])) ?>" class="w-20 h-14 rounded-lg object-cover border" style="border-color:var(--color-border)"></div>
                <?php endif; ?>
            </div>
            <div>
                <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Attachment <?= $edit_item ? '(leave empty to keep current)' : '' ?></label>
                <input type="file" name="attachment" accept=".pdf,.doc,.docx,.zip,.rar,.jpg,.jpeg,.png,.gif,.webp" class="w-full text-sm file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:text-white file:cursor-pointer" style="color:var(--color-text-muted)">
                <?php if ($edit_item && $edit_item['attachment']): ?>
                    <p class="text-xs mt-1" style="color:var(--color-text-muted)">Current: <?= e($edit_item['attachment_original_name'] ?? $edit_item['attachment']) ?></p>
                    <input type="hidden" name="existing_attachment_name" value="<?= e($edit_item['attachment_original_name']) ?>">
                <?php endif; ?>
            </div>
        </div>

        <div class="mb-4">
            <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Gallery Images (multiple)</label>
            <input type="file" name="images[]" multiple accept=".jpg,.jpeg,.png,.gif,.webp" class="w-full text-sm file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:text-white file:cursor-pointer" style="color:var(--color-text-muted)">
            <?php if ($edit_item && !empty($edit_images)): ?>
                <div class="flex flex-wrap gap-2 mt-2">
                    <?php foreach ($edit_images as $img): ?>
                        <div class="relative group">
                            <img src="<?= e(base_url('uploads/images/' . $img['image_path'])) ?>" class="w-20 h-14 rounded-lg object-cover border" style="border-color:var(--color-border)">
                            <form method="POST" class="absolute -top-1.5 -right-1.5 opacity-0 group-hover:opacity-100 transition-opacity" onsubmit="return confirm('Remove this image?')">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="do" value="delete_image">
                                <input type="hidden" name="image_id" value="<?= $img['id'] ?>">
                                <input type="hidden" name="item_id" value="<?= $edit_item['id'] ?>">
                                <button type="submit" class="w-5 h-5 rounded-full bg-red-500 text-white flex items-center justify-center text-xs font-bold">&times;</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="flex gap-3">
            <button type="submit" class="btn-grad px-6 py-2.5 text-sm font-semibold rounded-xl text-white shadow-lg shadow-primary-500/20">
                <?= $edit_item ? 'Update Project' : 'Add Project' ?>
            </button>
            <?php if ($edit_item): ?>
                <a href="<?= e(base_url('freelancer/portfolio.php')) ?>" class="px-6 py-2.5 text-sm font-semibold rounded-xl border" style="border-color:var(--color-border);color:var(--color-text-primary)">Cancel</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Portfolio Grid -->
<?php if (empty($portfolio_items)): ?>
    <div class="glass rounded-2xl text-center py-16 reveal">
        <svg class="w-20 h-20 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v13.5A1.5 1.5 0 003.75 21z"/></svg>
        <p class="text-lg font-semibold mb-2" style="color:var(--color-text-primary)">No portfolio items yet</p>
        <p class="text-sm mb-4" style="color:var(--color-text-muted)">Showcase your best work to attract more clients.</p>
        <button onclick="document.getElementById('addForm').scrollIntoView({behavior:'smooth'})" class="btn-grad inline-flex items-center gap-2 px-5 py-2.5 text-sm font-semibold rounded-xl text-white">Add Your First Project</button>
    </div>
<?php else: ?>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <?php foreach ($portfolio_items as $item):
            $coverUrl = $item['cover_image'] ? base_url('uploads/images/' . $item['cover_image']) : null;
        ?>
        <div class="glass rounded-2xl overflow-hidden portfolio-card reveal">
            <!-- Cover -->
            <div class="relative h-48 overflow-hidden" style="background:linear-gradient(135deg,#6366f1,#8b5cf6)">
                <?php if ($coverUrl): ?>
                    <img src="<?= e($coverUrl) ?>" alt="" class="w-full h-full object-cover">
                <?php else: ?>
                    <div class="w-full h-full flex items-center justify-center">
                        <svg class="w-16 h-16 text-white/30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v13.5A1.5 1.5 0 003.75 21z"/></svg>
                    </div>
                <?php endif; ?>
                <!-- Actions overlay -->
                <div class="portfolio-overlay absolute inset-0 bg-black/40 flex items-center justify-center gap-3">
                    <a href="<?= e(base_url('freelancer/portfolio.php?edit=' . $item['id'])) ?>" class="px-4 py-2 rounded-xl text-xs font-semibold text-white" style="background:rgba(255,255,255,0.2);backdrop-filter:blur(8px)">Edit</a>
                    <form method="POST" onsubmit="return confirm('Delete this project? This cannot be undone.')">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="do" value="delete">
                        <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                        <button type="submit" class="px-4 py-2 rounded-xl text-xs font-semibold text-white" style="background:rgba(239,68,68,0.8);backdrop-filter:blur(8px)">Delete</button>
                    </form>
                </div>
            </div>
            <!-- Content -->
            <div class="p-5">
                <h3 class="text-base font-bold mb-1" style="color:var(--color-text-primary)"><?= e($item['title']) ?></h3>
                <?php if ($item['completion_date']): ?>
                    <p class="text-xs mb-2" style="color:var(--color-text-muted)"><?= date('M Y', strtotime($item['completion_date'])) ?></p>
                <?php endif; ?>
                <?php if ($item['description']): ?>
                    <p class="text-sm mb-3 leading-relaxed" style="color:var(--color-text-secondary)"><?= e(mb_strimwidth($item['description'], 0, 150, '...')) ?></p>
                <?php endif; ?>

                <!-- Skills -->
                <?php if (!empty($item['skills'])): ?>
                    <div class="flex flex-wrap gap-1.5 mb-3">
                        <?php foreach (array_slice($item['skills'], 0, 5) as $sk): ?>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-semibold" style="background:rgba(99,102,241,0.1);color:#6366f1"><?= e($sk) ?></span>
                        <?php endforeach; ?>
                        <?php if (count($item['skills']) > 5): ?>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-semibold" style="background:var(--color-card);color:var(--color-text-muted)">+<?= count($item['skills']) - 5 ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Links -->
                <div class="flex items-center gap-3">
                    <?php if ($item['project_url']): ?>
                        <a href="<?= e($item['project_url']) ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-xs font-semibold" style="color:#6366f1">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
                            Live Demo
                        </a>
                    <?php endif; ?>
                    <?php if ($item['github_url']): ?>
                        <a href="<?= e($item['github_url']) ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-xs font-semibold" style="color:var(--color-text-secondary)">
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/></svg>
                            GitHub
                        </a>
                    <?php endif; ?>
                    <?php if ($item['attachment']): ?>
                        <a href="<?= e(attachment_url($item['attachment'])) ?>" target="_blank" class="inline-flex items-center gap-1 text-xs font-semibold" style="color:var(--color-text-muted)">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13"/></svg>
                            Attachment
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($item['images'])): ?>
                        <span class="inline-flex items-center gap-1 text-xs font-semibold" style="color:var(--color-text-muted)">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v13.5A1.5 1.5 0 003.75 21z"/></svg>
                            <?= count($item['images']) ?> photo<?= count($item['images']) !== 1 ? 's' : '' ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

</div>

<script>
document.querySelectorAll('.reveal').forEach(function(el) { el.classList.add('visible'); });
</script>

<?php require __DIR__ . '/../includes/freelancer_footer.php'; ?>
