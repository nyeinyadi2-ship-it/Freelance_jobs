<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/upload.php';

require_role('freelancer');

$user = current_user();
$freelancer_id = get_freelancer_id($conn, (int) $user['user_id']);

if (!$freelancer_id) {
    set_flash('error', __('error.freelancer_not_found'));
    redirect('index.php');
}

$stmt = $conn->prepare("
    SELECT f.*, u.email, u.profile_image, u.created_at
    FROM freelancers f
    JOIN users u ON u.id = f.user_id
    WHERE f.id = ?
");
$stmt->bind_param('i', $freelancer_id);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$profile) {
    set_flash('error', __('profile.not_found'));
    redirect('index.php');
}

$profile_skills = [];
$result = $conn->query("SELECT skill_id FROM freelancer_skills WHERE freelancer_id = $freelancer_id");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $profile_skills[] = (int) $row['skill_id'];
    }
}

$all_skills = [];
$result = $conn->query('SELECT id, skill_name FROM skills ORDER BY skill_name');
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $all_skills[] = $row;
    }
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['edit'])) {
    if (!verify_csrf()) {
        $error = __('error.invalid_request');
    } else {
        $full_name = trim($_POST['full_name'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        $portfolio_url = trim($_POST['portfolio_url'] ?? '');
        $experience_years = (int) ($_POST['experience_years'] ?? 0);
        $hourly_rate = (float) ($_POST['hourly_rate'] ?? 0);
        $selected_skills = $_POST['skills'] ?? [];

        if ($full_name === '') {
            $error = __('profile.name_required');
        } elseif ($hourly_rate < 0) {
            $error = __('profile.rate_min');
        } else {
            $old_profile_image = $profile['profile_image'];
            $new_profile_image = $old_profile_image;

            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $uploaded = upload_image($_FILES['profile_image']);
                if ($uploaded) {
                    $new_profile_image = $uploaded;
                } else {
                    $error = __('upload.invalid_profile');
                }
            }

            if ($error === '') {
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
                    $stmt->bind_param('si', $new_profile_image, $user['user_id']);
                    $stmt->execute();
                    $stmt->close();

                    $ey = $experience_years > 0 ? $experience_years : null;
                    $hr = $hourly_rate > 0 ? $hourly_rate : null;
                    $stmt = $conn->prepare("UPDATE freelancers SET full_name = ?, title = ?, phone = ?, location = ?, bio = ?, portfolio_url = ?, experience_years = ?, hourly_rate = ? WHERE id = ?");
                    $stmt->bind_param('ssssssidi', $full_name, $title, $phone, $location, $bio, $portfolio_url, $ey, $hr, $freelancer_id);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare("DELETE FROM freelancer_skills WHERE freelancer_id = ?");
                    $stmt->bind_param('i', $freelancer_id);
                    $stmt->execute();
                    $stmt->close();

                    if (!empty($selected_skills)) {
                        $skill_stmt = $conn->prepare('INSERT INTO freelancer_skills (freelancer_id, skill_id) VALUES (?, ?)');
                        foreach ($selected_skills as $skill_id) {
                            $skill_id = (int) $skill_id;
                            $skill_stmt->bind_param('ii', $freelancer_id, $skill_id);
                            $skill_stmt->execute();
                        }
                        $skill_stmt->close();
                    }

                    $conn->commit();

                    if ($new_profile_image !== $old_profile_image && $old_profile_image) delete_upload($old_profile_image);

                    $_SESSION['profile_image'] = $new_profile_image;

                    $success = __('profile.updated');
                    $profile['full_name'] = $full_name;
                    $profile['title'] = $title;
                    $profile['phone'] = $phone;
                    $profile['location'] = $location;
                    $profile['bio'] = $bio;
                    $profile['portfolio_url'] = $portfolio_url;
                    $profile['experience_years'] = $ey;
                    $profile['hourly_rate'] = $hr;
                    $profile['profile_image'] = $new_profile_image;
                    $profile_skills = array_map('intval', $selected_skills);
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = $e->getMessage();
                }
            }
        }
    }
}

$is_edit = isset($_GET['edit']);
$page_title = $is_edit ? __('profile.freelancer_edit') : __('profile.freelancer');
require __DIR__ . '/../includes/header.php';

$skill_names = [];
foreach ($all_skills as $s) {
    $skill_names[$s['id']] = $s['skill_name'];
}
?>

<div class="max-w-3xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-4">
            <?php $profileImgUrl = profile_image_url($profile['profile_image']); ?>
            <?php if ($profileImgUrl): ?>
                <img src="<?= e($profileImgUrl) ?>" alt="" class="w-14 h-14 rounded-full object-cover" style="border:1px solid var(--color-border)">
            <?php else: ?>
                <div class="w-14 h-14 rounded-full flex items-center justify-center font-bold text-xl" style="background:rgba(99,102,241,0.15);color:#4338ca;border:1px solid var(--color-border)">
                    <?= e(_first_char($profile['full_name'] ?? $user['username'])) ?>
                </div>
            <?php endif; ?>
            <div>
                <h1 class="text-2xl font-bold" style="color:var(--color-text-primary)"><?= e($profile['full_name']) ?></h1>
                <?php if ($profile['title']): ?>
                    <p class="text-sm text-indigo-600 font-medium"><?= e($profile['title']) ?></p>
                <?php endif; ?>
                <p class="text-xs" style="color:var(--color-text-placeholder)"><?= e($user['username']) ?> &middot; <?= __('profile.joined') ?> <?= e(date('M Y', strtotime($profile['created_at']))) ?></p>
            </div>
        </div>
        <div class="flex gap-2">
            <?php if ($is_edit): ?>
                <a href="<?= e(base_url('freelancer/profile.php')) ?>" class="btn-secondary text-sm"><?= __('profile.cancel') ?></a>
            <?php else: ?>
                <a href="<?= e(base_url('freelancer/profile.php?edit=1')) ?>" class="btn-primary text-sm"><?= __('profile.edit') ?></a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="mb-4 p-3 rounded-lg" style="background:rgba(239,68,68,0.1);color:var(--color-error)"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="mb-4 p-3 rounded-lg" style="background:rgba(34,197,94,0.1);color:var(--color-success)"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($is_edit): ?>
        <form method="POST" class="space-y-6" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <div class="card">
                <h2 class="text-lg font-semibold mb-4"><?= __('profile.basic_info') ?></h2>
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)"><?= __('profile.full_name') ?></label>
                        <input type="text" name="full_name" required class="form-input" value="<?= e($profile['full_name']) ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)"><?= __('profile.title') ?></label>
                        <input type="text" name="title" class="form-input" placeholder="e.g. Full Stack Developer" value="<?= e($profile['title'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)"><?= __('profile.email') ?></label>
                        <input type="email" class="form-input" value="<?= e($profile['email']) ?>" readonly>
                        <p class="text-xs mt-1" style="color:var(--color-text-placeholder)"><?= __('profile.email_readonly') ?></p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)"><?= __('profile.phone') ?></label>
                        <input type="text" name="phone" class="form-input" placeholder="+1 234 567 8900" value="<?= e($profile['phone'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)"><?= __('profile.location') ?></label>
                        <input type="text" name="location" class="form-input" placeholder="City, Country" value="<?= e($profile['location'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)"><?= __('profile.experience') ?></label>
                        <input type="number" name="experience_years" class="form-input" min="0" max="100" placeholder="5" value="<?= e($profile['experience_years'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)"><?= __('profile.hourly_rate') ?></label>
                        <input type="number" name="hourly_rate" class="form-input" min="0" step="0.50" placeholder="50.00" value="<?= e($profile['hourly_rate'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)"><?= __('profile.portfolio_url') ?></label>
                        <input type="url" name="portfolio_url" class="form-input" placeholder="https://your-portfolio.com" value="<?= e($profile['portfolio_url'] ?? '') ?>">
                    </div>
                </div>
                <div class="mt-4">
                    <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)"><?= __('profile.bio') ?></label>
                    <textarea name="bio" rows="4" class="form-input" placeholder="Tell clients about yourself..."><?= e($profile['bio'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="card">
                <h2 class="text-lg font-semibold mb-4"><?= __('profile.skills') ?></h2>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                    <?php foreach ($all_skills as $skill): ?>
                        <label class="flex items-center gap-2 text-sm p-2 rounded cursor-pointer">
                            <input type="checkbox" name="skills[]" value="<?= (int) $skill['id'] ?>"
                                <?= in_array((int) $skill['id'], $profile_skills) ? 'checked' : '' ?>>
                            <?= e($skill['skill_name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card">
                <h2 class="text-lg font-semibold mb-4"><?= __('profile.profile_image') ?></h2>
                <div class="flex items-center gap-4">
                    <?php if ($profile['profile_image']): ?>
                        <img src="<?= e(profile_image_url($profile['profile_image'])) ?>" class="w-16 h-16 rounded-full object-cover" style="border:1px solid var(--color-border)" id="profilePreview">
                    <?php else: ?>
                        <div class="w-16 h-16 rounded-full flex items-center justify-center font-bold text-xl" style="background:rgba(99,102,241,0.15);color:#4338ca;border:1px solid var(--color-border)" id="profilePreviewPlaceholder">
                            <?= e(_first_char($profile['full_name'] ?? $user['username'])) ?>
                        </div>
                    <?php endif; ?>
                    <div>
                        <input type="file" name="profile_image" accept="image/jpeg,image/png,image/gif,image/webp" class="text-sm" onchange="previewImage(this, 'profilePreview')">
                        <p class="text-xs mt-1" style="color:var(--color-text-placeholder)"><?= __('profile.upload_hint') ?></p>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-3">
                <a href="<?= e(base_url('freelancer/profile.php')) ?>" class="btn-secondary"><?= __('profile.cancel') ?></a>
                <button type="submit" class="btn-primary"><?= __('profile.save') ?></button>
            </div>
        </form>
    <?php else: ?>
        <div class="grid md:grid-cols-2 gap-6">
            <div class="card">
                <h2 class="text-lg font-semibold mb-4"><?= __('profile.about') ?></h2>
                <?php if ($profile['bio']): ?>
                    <p class="text-sm leading-relaxed whitespace-pre-wrap" style="color:var(--color-text-secondary)"><?= e($profile['bio']) ?></p>
                <?php else: ?>
                    <p class="text-sm italic" style="color:var(--color-text-placeholder)"><?= __('profile.no_bio') ?></p>
                <?php endif; ?>

                <?php if (!empty($profile_skills)): ?>
                <div class="mt-6">
                    <h3 class="text-sm font-medium mb-2" style="color:var(--color-text-muted)"><?= __('profile.skills') ?></h3>
                    <div class="flex flex-wrap gap-1.5">
                        <?php foreach ($profile_skills as $sid): ?>
                            <span class="inline-flex px-2.5 py-1 text-xs font-medium rounded-full" style="background:rgba(99,102,241,0.15);color:#4338ca"><?= e($skill_names[$sid] ?? 'Unknown') ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2 class="text-lg font-semibold mb-4"><?= __('profile.details') ?></h2>
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <dt style="color:var(--color-text-muted)"><?= __('profile.full_name') ?></dt>
                        <dd class="font-medium" style="color:var(--color-text-primary)"><?= e($profile['full_name']) ?></dd>
                    </div>
                    <?php if ($profile['title']): ?>
                    <div class="flex justify-between">
                        <dt style="color:var(--color-text-muted)"><?= __('profile.title') ?></dt>
                        <dd class="font-medium text-indigo-600"><?= e($profile['title']) ?></dd>
                    </div>
                    <?php endif; ?>
                    <div class="flex justify-between">
                        <dt style="color:var(--color-text-muted)"><?= __('profile.email') ?></dt>
                        <dd class="font-medium" style="color:var(--color-text-primary)"><?= e($profile['email']) ?></dd>
                    </div>
                    <?php if ($profile['phone']): ?>
                    <div class="flex justify-between">
                        <dt style="color:var(--color-text-muted)"><?= __('profile.phone') ?></dt>
                        <dd class="font-medium" style="color:var(--color-text-primary)"><?= e($profile['phone']) ?></dd>
                    </div>
                    <?php endif; ?>
                    <?php if ($profile['location']): ?>
                    <div class="flex justify-between">
                        <dt style="color:var(--color-text-muted)"><?= __('profile.location') ?></dt>
                        <dd class="font-medium" style="color:var(--color-text-primary)"><?= e($profile['location']) ?></dd>
                    </div>
                    <?php endif; ?>
                    <?php if ($profile['experience_years'] !== null): ?>
                    <div class="flex justify-between">
                        <dt style="color:var(--color-text-muted)"><?= __('profile.experience') ?></dt>
                        <dd class="font-medium" style="color:var(--color-text-primary)"><?= (int) $profile['experience_years'] ?> year<?= (int) $profile['experience_years'] !== 1 ? 's' : '' ?></dd>
                    </div>
                    <?php endif; ?>
                    <?php if ($profile['hourly_rate'] !== null): ?>
                    <div class="flex justify-between">
                        <dt style="color:var(--color-text-muted)"><?= __('profile.hourly_rate') ?></dt>
                        <dd class="font-medium text-green-600">$<?= e(number_format((float) $profile['hourly_rate'], 2)) ?> / hr</dd>
                    </div>
                    <?php endif; ?>
                    <?php if ($profile['portfolio_url']): ?>
                    <div class="flex justify-between">
                        <dt style="color:var(--color-text-muted)"><?= __('profile.portfolio_url') ?></dt>
                        <dd class="font-medium"><a href="<?= e($profile['portfolio_url']) ?>" target="_blank" class="text-indigo-600 hover:underline text-xs"><?= __('profile.view_portfolio') ?></a></dd>
                    </div>
                    <?php endif; ?>
                    <div class="flex justify-between">
                        <dt style="color:var(--color-text-muted)"><?= __('profile.joined') ?></dt>
                        <dd class="font-medium" style="color:var(--color-text-primary)"><?= e(date('F j, Y', strtotime($profile['created_at']))) ?></dd>
                    </div>
                </dl>
            </div>
        </div>

        <div class="mt-6 card">
            <h2 class="text-lg font-semibold mb-4"><?= __('profile.activity') ?></h2>
            <?php
            $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM job_applications WHERE freelancer_id = ?");
            $stmt->bind_param('i', $freelancer_id);
            $stmt->execute();
            $app_count = (int) $stmt->get_result()->fetch_assoc()['cnt'];
            $stmt->close();

            $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM assignments WHERE freelancer_id = ? AND status IN ('assigned', 'submitted', 'completed')");
            $stmt->bind_param('i', $freelancer_id);
            $stmt->execute();
            $assign_count = (int) $stmt->get_result()->fetch_assoc()['cnt'];
            $stmt->close();
            ?>
            <div class="grid grid-cols-2 gap-4 text-center">
                <div class="p-4" style="background:rgba(99,102,241,0.1);border-radius:0.5rem">
                    <p class="text-2xl font-bold text-indigo-600"><?= $app_count ?></p>
                    <p class="text-xs" style="color:var(--color-text-muted)"><?= __('profile.applications_sent') ?></p>
                </div>
                <div class="p-4" style="background:rgba(34,197,94,0.1);border-radius:0.5rem">
                    <p class="text-2xl font-bold text-green-600"><?= $assign_count ?></p>
                    <p class="text-xs" style="color:var(--color-text-muted)"><?= __('profile.assignments') ?></p>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
function previewImage(input, imgId) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            var img = document.getElementById(imgId);
            if (!img) {
                img = new Image();
                img.id = imgId;
                img.className = 'w-16 h-16 rounded-full object-cover';
                input.parentNode.insertBefore(img, input);
                var placeholder = document.getElementById(imgId + 'Placeholder');
                if (placeholder) placeholder.style.display = 'none';
            }
            img.src = e.target.result;
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
