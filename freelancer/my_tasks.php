<?php
$page_title = 'My Tasks';
require __DIR__ . '/../includes/freelancer_init.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $assignment_id = (int) ($_POST['assignment_id'] ?? 0);
    $submission_link = trim($_POST['submission_link'] ?? '');
    if ($assignment_id <= 0 || $submission_link === '') { set_flash('error', __('error.submission_link_required')); }
    elseif (!filter_var($submission_link, FILTER_VALIDATE_URL)) { set_flash('error', __('error.invalid_url')); }
    else {
        $st = $conn->prepare("UPDATE assignments SET submission_link=?, status='submitted' WHERE id=? AND freelancer_id=? AND status='assigned'");
        $st->bind_param('sii', $submission_link, $assignment_id, $fl_freelancer_id); $st->execute();
        if ($st->affected_rows > 0) {
            $ns = $conn->prepare("SELECT j.title, c.user_id FROM assignments a JOIN jobs j ON a.job_id=j.id JOIN companies c ON j.company_id=c.id WHERE a.id=?");
            $ns->bind_param('i', $assignment_id); $ns->execute();
            $ni = $ns->get_result()->fetch_assoc(); $ns->close();
            if ($ni) {
                $js = $conn->prepare('SELECT job_id FROM assignments WHERE id=?');
                $js->bind_param('i', $assignment_id); $js->execute();
                $jr = $js->get_result()->fetch_assoc(); $js->close();
                $jid = $jr ? (int) $jr['job_id'] : 0;
                create_notification($conn, (int) $ni['user_id'], 'work_submitted', $fl_user['username'] . " has submitted work for \"{$ni['title']}\".", $jid > 0 ? 'company/view_applications.php?id=' . $jid : null);
            }
            set_flash('success', __('success.work_submitted'));
        } else { set_flash('error', __('error.could_not_submit')); }
        $st->close();
    }
    redirect('freelancer/my_tasks.php');
}

$tasks = [];
$st = $conn->prepare("SELECT a.id,a.status,a.submission_link,a.assigned_at,j.title,j.description,j.budget,c.company_name,c.logo_image FROM assignments a JOIN jobs j ON a.job_id=j.id JOIN companies c ON j.company_id=c.id WHERE a.freelancer_id=? ORDER BY a.assigned_at DESC");
$st->bind_param('i', $fl_freelancer_id); $st->execute();
$r = $st->get_result(); while ($row = $r->fetch_assoc()) $tasks[] = $row; $st->close();
require __DIR__ . '/../includes/freelancer_layout.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-2">
<?php if (empty($tasks)): ?>
    <div class="glass rounded-2xl text-center py-20" style="color:var(--color-text-placeholder)">
        <svg class="w-24 h-24 mx-auto mb-6 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
        <p class="text-xl font-semibold mb-2">No assigned tasks yet.</p>
        <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="btn-grad inline-flex items-center gap-1.5 px-5 py-2.5 text-sm font-semibold rounded-xl text-white mt-3">Browse available jobs</a>
    </div>
<?php else: ?>
    <div class="space-y-4">
        <?php foreach ($tasks as $task): ?>
            <div class="glass rounded-2xl p-6 hover-lift reveal">
                <div class="flex flex-wrap justify-between items-start gap-3 mb-4">
                    <div class="flex items-center gap-3">
                        <?php if ($task['logo_image']): ?><img src="<?= e(base_url('uploads/' . $task['logo_image'])) ?>" alt="" class="w-12 h-12 rounded-xl object-contain border" style="border-color:var(--color-border)"><?php endif; ?>
                        <div><p class="text-sm font-medium" style="color:var(--color-text-muted)"><?= e($task['company_name']) ?></p><h2 class="text-lg font-bold" style="color:var(--color-text-primary)"><?= e($task['title']) ?></h2></div>
                    </div>
                    <?= status_badge($task['status']) ?>
                </div>
                <p class="text-sm mb-3 leading-relaxed" style="color:var(--color-text-secondary)"><?= e(mb_strimwidth($task['description'] ?? '', 0, 200, '...')) ?></p>
                <div class="flex items-center gap-4 text-sm mb-4"><span style="color:var(--color-text-muted)">Budget: <strong class="text-primary-600">$<?= number_format((float) $task['budget'], 2) ?></strong></span><span style="color:var(--color-text-placeholder)">Assigned <?= date('M j, Y', strtotime($task['assigned_at'])) ?></span></div>
                <?php if ($task['status'] === 'assigned'): ?>
                    <div class="pt-4 border-t" style="border-color:var(--color-border)">
                        <form method="POST" class="flex flex-col sm:flex-row gap-3 items-end">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="assignment_id" value="<?= (int) $task['id'] ?>">
                            <div class="flex-1 min-w-[200px]"><label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)">Submission Link</label><input type="url" name="submission_link" required class="w-full px-4 py-2.5 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 transition-all" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" placeholder="https://drive.google.com/..."></div>
                            <button type="submit" class="btn-grad px-5 py-2.5 text-sm font-semibold rounded-xl text-white flex-shrink-0">Submit Work</button>
                        </form>
                    </div>
                <?php elseif ($task['submission_link']): ?>
                    <div class="pt-4 border-t flex items-center gap-2" style="border-color:var(--color-border)"><svg class="w-4 h-4 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg><span class="text-sm" style="color:var(--color-text-muted)">Submitted: <a href="<?= e($task['submission_link']) ?>" target="_blank" class="text-primary-600 hover:underline">View Link</a></span></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/freelancer_footer.php'; ?>
