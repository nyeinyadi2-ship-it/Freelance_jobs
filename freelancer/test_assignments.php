<?php
$page_title = 'My Trial Task';
require __DIR__ . '/../includes/freelancer_init.php';

// Fetch all proposal projects for this freelancer
$test_assignments = [];
$s = $conn->prepare("SELECT p.*, j.title AS job_title, c.company_name, c.logo_image 
    FROM proposal_projects p
    JOIN jobs j ON p.job_id = j.id
    JOIN companies c ON p.company_id = c.id
    WHERE p.freelancer_id = ? 
    ORDER BY p.created_at DESC");
if ($s) {
    $s->bind_param('i', $fl_freelancer_id);
    $s->execute();
    $r = $s->get_result();
    while ($row = $r->fetch_assoc()) {
        $test_assignments[] = $row;
    }
    $s->close();
}

require __DIR__ . '/../includes/freelancer_layout.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-2 pb-12">
<?php if (empty($test_assignments)): ?>
    <div class="glass rounded-2xl text-center py-20" style="color:var(--color-text-placeholder)">
        <svg class="w-24 h-24 mx-auto mb-6 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
        </svg>
        <p class="text-xl font-semibold mb-2">No trial tasks yet.</p>
        <p class="text-sm">When a company asks you to complete a trial task, it will appear here.</p>
    </div>
<?php else: ?>
    <div class="space-y-6">
        <?php foreach ($test_assignments as $ta): ?>
            <div class="glass rounded-2xl p-6 hover-lift reveal">
                <!-- Header -->
                <div class="flex flex-wrap justify-between items-start gap-3 mb-4">
                    <div class="flex items-center gap-3">
                        <?php if ($ta['logo_image']): ?>
                            <img src="<?= e(base_url('uploads/images/' . $ta['logo_image'])) ?>" alt="" class="w-12 h-12 rounded-xl object-contain border" style="border-color:var(--color-border)">
                        <?php else: ?>
                            <div class="w-12 h-12 rounded-xl flex items-center justify-center text-indigo-600 font-bold border" style="background:rgba(99,102,241,0.1);border-color:var(--color-border)">
                                <?= strtoupper(mb_substr($ta['company_name'], 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                        <div>
                            <p class="text-sm font-medium" style="color:var(--color-text-muted)"><?= e($ta['company_name']) ?></p>
                            <h2 class="text-lg font-bold" style="color:var(--color-text-primary)"><?= e($ta['title']) ?></h2>
                            <p class="text-xs" style="color:var(--color-text-muted)">For Job: <?= e($ta['job_title']) ?></p>
                        </div>
                    </div>
                    <div>
                        <?= status_badge($ta['status']) ?>
                    </div>
                </div>

                <div class="flex items-center gap-4 text-sm mb-4 border-t pt-4" style="border-color:var(--color-border)">
                    <span style="color:var(--color-text-muted)">Sent Date: <strong style="color:var(--color-text-primary)"><?= date('M j, Y', strtotime($ta['created_at'])) ?></strong></span>
                    <span style="color:var(--color-text-muted)">Deadline: <strong class="text-red-500"><?= $ta['deadline'] ? date('M j, Y', strtotime($ta['deadline'])) : 'N/A' ?></strong></span>
                </div>

                <div class="flex justify-end">
                    <a href="<?= e(base_url('freelancer/view_proposal.php?id=' . $ta['id'])) ?>" class="btn-grad inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-xl text-white shadow-lg">
                        View My Trial Task <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/freelancer_footer.php'; ?>
