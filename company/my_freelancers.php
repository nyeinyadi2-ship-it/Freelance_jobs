<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

require_role('company');

$user = current_user();
$company_id = get_company_id($conn, (int) $user['user_id']);

if (!$company_id) {
    set_flash('error', 'Company profile not found.');
    redirect('auth/login.php');
}

// Handle Review Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_review') {
    if (!verify_csrf()) {
        set_flash('error', 'Invalid request.');
    } else {
        $r_freelancer_id = (int) ($_POST['freelancer_id'] ?? 0);
        $r_assignment_id = (int) ($_POST['assignment_id'] ?? 0);
        $rating = (int) ($_POST['rating'] ?? 0);
        $comment = trim($_POST['comment'] ?? '');

        if ($rating < 1 || $rating > 5) {
            set_flash('error', 'Please select a valid rating from 1 to 5 stars.');
        } elseif ($r_freelancer_id <= 0 || $r_assignment_id <= 0) {
            set_flash('error', 'Invalid submission data.');
        } else {
            // Validate that the assignment is completed and belongs to this company
            $stmt = $conn->prepare("
                SELECT a.id 
                FROM assignments a
                JOIN jobs j ON a.job_id = j.id
                WHERE a.id = ? AND a.freelancer_id = ? AND j.company_id = ? AND a.status = 'completed'
            ");
            $stmt->bind_param('iii', $r_assignment_id, $r_freelancer_id, $company_id);
            $stmt->execute();
            $is_completed = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$is_completed) {
                set_flash('error', 'This project is not completed yet, or does not belong to you.');
            } else {
                // Check duplicate
                $stmt = $conn->prepare("SELECT id FROM reviews WHERE assignment_id = ? AND company_user_id = ?");
                $stmt->bind_param('ii', $r_assignment_id, $user['user_id']);
                $stmt->execute();
                $dup = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($dup) {
                    set_flash('error', 'A review has already been submitted for this project.');
                } else {
                    $stmt = $conn->prepare("INSERT INTO reviews (company_user_id, freelancer_id, assignment_id, rating, comment) VALUES (?, ?, ?, ?, ?)");
                    $stmt->bind_param('iiiis', $user['user_id'], $r_freelancer_id, $r_assignment_id, $rating, $comment);
                    $stmt->execute();
                    $stmt->close();
                    
                    set_flash('success', 'Review submitted successfully!');
                    redirect('company/my_freelancers.php');
                }
            }
        }
    }
}

// Fetch all freelancers with their assignments, jobs, skills, milestone progress, and company name
$freelancers_data = [];
$stmt = $conn->prepare("
    SELECT
        f.id AS freelancer_id,
        f.full_name,
        f.title AS freelancer_title,

        u.email,
        u.profile_image,
        c.company_name,
        a.id AS assignment_id,
        a.status AS assignment_status,
        a.assigned_at,
        a.deadline,
        a.submission_link,
        a.project_title,
        a.budget AS assignment_budget,
        j.id AS job_id,
        j.title AS job_title,
        j.budget AS job_budget,
        j.status AS job_status,
        j.category
    FROM assignments a
    JOIN jobs j ON a.job_id = j.id
    JOIN companies c ON j.company_id = c.id
    JOIN freelancers f ON a.freelancer_id = f.id
    JOIN users u ON f.user_id = u.id
    WHERE j.company_id = ?
    ORDER BY f.full_name, a.assigned_at ASC
");
$stmt->bind_param('i', $company_id);
$stmt->execute();
$result = $stmt->get_result();
$raw_rows = [];
while ($row = $result->fetch_assoc()) {
    $raw_rows[] = $row;
}
$stmt->close();

// Batch-fetch skills for all freelancer IDs
$freelancer_ids = array_unique(array_column($raw_rows, 'freelancer_id'));
$skills_map = [];
if (!empty($freelancer_ids)) {
    $placeholders = implode(',', array_fill(0, count($freelancer_ids), '?'));
    $types = str_repeat('i', count($freelancer_ids));
    $sk_stmt = $conn->prepare("
        SELECT f.id AS freelancer_id, GROUP_CONCAT(s.skill_name SEPARATOR ',') AS skills_concat
        FROM freelancers f
        JOIN freelancer_skills fs ON fs.freelancer_id = f.id
        JOIN skills s ON s.id = fs.skill_id
        WHERE f.id IN ($placeholders)
        GROUP BY f.id
    ");
    $sk_stmt->bind_param($types, ...$freelancer_ids);
    $sk_stmt->execute();
    $sk_result = $sk_stmt->get_result();
    while ($sk_row = $sk_result->fetch_assoc()) {
        $skills_map[$sk_row['freelancer_id']] = $sk_row['skills_concat'];
    }
    $sk_stmt->close();
}

// Batch-fetch milestone progress for all assignments
$assignment_ids = array_column($raw_rows, 'assignment_id');
$ms_map = [];
if (!empty($assignment_ids)) {
    $placeholders = implode(',', array_fill(0, count($assignment_ids), '?'));
    $types = str_repeat('i', count($assignment_ids));
    $ms_stmt = $conn->prepare("
        SELECT
            job_id,
            freelancer_id,
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
            SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) AS submitted_count,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_count,
            SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS draft_count
        FROM milestones
        WHERE (job_id, freelancer_id) IN (
            SELECT job_id, freelancer_id FROM assignments WHERE id IN ($placeholders)
        )
        GROUP BY job_id, freelancer_id
    ");
    $ms_stmt->bind_param($types, ...$assignment_ids);
    $ms_stmt->execute();
    $ms_result = $ms_stmt->get_result();
    while ($ms_row = $ms_result->fetch_assoc()) {
        $key = $ms_row['job_id'] . '_' . $ms_row['freelancer_id'];
        $ms_map[$key] = $ms_row;
    }
    $ms_stmt->close();
}

// Batch-fetch reviewed assignment IDs AND full review data
$reviewed_assignment_ids = [];
$reviews_full_data = [];
if (!empty($assignment_ids)) {
    $placeholders = implode(',', array_fill(0, count($assignment_ids), '?'));
    $types = str_repeat('i', count($assignment_ids));
    $rev_stmt = $conn->prepare("
        SELECT assignment_id, rating, comment, created_at FROM reviews WHERE assignment_id IN ($placeholders)
    ");
    $rev_stmt->bind_param($types, ...$assignment_ids);
    $rev_stmt->execute();
    $rev_result = $rev_stmt->get_result();
    while ($rev_row = $rev_result->fetch_assoc()) {
        $reviewed_assignment_ids[] = $rev_row['assignment_id'];
        $reviews_full_data[$rev_row['assignment_id']] = [
            'rating' => (int) $rev_row['rating'],
            'comment' => $rev_row['comment'],
            'created_at' => $rev_row['created_at']
        ];
    }
    $rev_stmt->close();
}

// Batch-fetch reviews
$reviews_map = [];
if (!empty($freelancer_ids)) {
    $placeholders = implode(',', array_fill(0, count($freelancer_ids), '?'));
    $types = str_repeat('i', count($freelancer_ids));
    $rev_stmt = $conn->prepare("
        SELECT freelancer_id, AVG(rating) as avg_rating, COUNT(id) as review_count
        FROM reviews
        WHERE freelancer_id IN ($placeholders)
        GROUP BY freelancer_id
    ");
    $rev_stmt->bind_param($types, ...$freelancer_ids);
    $rev_stmt->execute();
    $rev_result = $rev_stmt->get_result();
    while ($rev_row = $rev_result->fetch_assoc()) {
        $reviews_map[$rev_row['freelancer_id']] = $rev_row;
    }
    $rev_stmt->close();
}

// Group by freelancer (no duplicates)
foreach ($raw_rows as $row) {
    $fid = $row['freelancer_id'];
    if (!isset($freelancers_data[$fid])) {
        $freelancers_data[$fid] = [
            'freelancer_id'    => $fid,
            'full_name'        => $row['full_name'],
            'freelancer_title' => $row['freelancer_title'],

            'email'            => $row['email'],
            'profile_image'    => $row['profile_image'],
            'company_name'     => $row['company_name'],
            'joined_at'        => $row['assigned_at'],
            'skills'           => !empty($skills_map[$fid]) ? explode(',', $skills_map[$fid]) : [],
            'rating'           => $reviews_map[$fid] ?? ['avg_rating' => null, 'review_count' => 0],
            'projects'         => [],
        ];
    }
    // Track earliest assignment as join date
    if ($row['assigned_at'] < $freelancers_data[$fid]['joined_at']) {
        $freelancers_data[$fid]['joined_at'] = $row['assigned_at'];
    }

    $key = $row['job_id'] . '_' . $fid;
    $ms = $ms_map[$key] ?? ['total' => 0, 'approved_count' => 0, 'submitted_count' => 0, 'in_progress_count' => 0, 'draft_count' => 0];
    $total = (int) $ms['total'];
    $approved = (int) $ms['approved_count'];
    $submitted = (int) $ms['submitted_count'];

    $status = $row['assignment_status'];
    $status_label = match($status) {
        'assigned'   => 'Pending',
        'working'    => 'In Progress',
        'submitted'  => 'Submitted',
        'completed'  => 'Completed',
        'rejected'   => 'Rejected',
        'cancelled'  => 'Cancelled',
        default      => ucfirst($status),
    };

    $freelancers_data[$fid]['projects'][] = [
        'assignment_id'       => $row['assignment_id'],
        'job_id'              => $row['job_id'],
        'job_title'           => $row['project_title'] ?: $row['job_title'],
        'job_status'          => $row['job_status'],
        'category'            => $row['category'],
        'budget'              => $row['assignment_budget'] ?: $row['job_budget'],
        'assigned_at'         => $row['assigned_at'],
        'deadline'            => $row['deadline'],
        'status'              => $status,
        'status_label'        => $status_label,
        'milestone_total'     => $total,
        'milestone_approved'  => $approved,
        'milestone_submitted' => $submitted,
        'is_reviewed'         => in_array($row['assignment_id'], $reviewed_assignment_ids),
        'review_data'         => $reviews_full_data[$row['assignment_id']] ?? null,
    ];
}

$page_title = 'My Freelancers';
require __DIR__ . '/../includes/header.php';
?>

<style>
/* Base table styles */
.table-container {
    background: var(--color-card);
    border: 1px solid var(--color-border);
    border-radius: 1rem;
    overflow-x: auto;
    box-shadow: 0 2px 10px rgba(0,0,0,0.03);
}
.fl-table {
    width: 100%;
    border-collapse: collapse;
    text-align: left;
}
.fl-table th {
    padding: 1rem 1.25rem;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-muted);
    border-bottom: 1px solid var(--color-border);
    background: rgba(0,0,0,0.01);
}
.fl-table td {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--color-border);
    vertical-align: top;
}
.fl-table tr:last-child td {
    border-bottom: none;
}
.fl-table tr:hover {
    background: var(--color-card-hover, rgba(99,102,241,0.02));
}

.skill-tag {
    display: inline-flex;
    align-items: center;
    padding: 0.2rem 0.6rem;
    border-radius: 9999px;
    font-size: 0.7rem;
    font-weight: 600;
    background: rgba(99,102,241,0.08);
    color: #6366f1;
    border: 1px solid rgba(99,102,241,0.15);
}
@media (prefers-color-scheme: dark) {
    .skill-tag { background: rgba(99,102,241,0.15); color: #a5b4fc; border-color: rgba(99,102,241,0.25); }
    .fl-table th { background: rgba(255,255,255,0.02); }
}

/* Mobile responsive table */
@media (max-width: 768px) {
    .fl-table, .fl-table tbody, .fl-table tr, .fl-table td {
        display: block;
        width: 100%;
    }
    .fl-table thead {
        display: none;
    }
    .fl-table tr {
        margin-bottom: 1rem;
        border: 1px solid var(--color-border);
        border-radius: 0.75rem;
        background: var(--color-bg);
        overflow: hidden;
    }
    .fl-table td {
        position: relative;
        padding: 0.75rem 1rem 0.75rem 35%;
        border-bottom: 1px solid rgba(0,0,0,0.05);
    }
    .fl-table td:last-child {
        border-bottom: 0;
    }
    .fl-table td::before {
        content: attr(data-label);
        position: absolute;
        left: 1rem;
        top: 0.75rem;
        width: 30%;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        color: var(--color-text-muted);
    }
    /* Specific overrides for mobile card */
    .fl-table td[data-label="Freelancer"] {
        padding-left: 1rem;
        padding-top: 1rem;
    }
    .fl-table td[data-label="Freelancer"]::before {
        display: none;
    }
}
</style>

<div class="max-w-7xl mx-auto" style="padding-bottom:3rem; padding-left:1rem; padding-right:1rem;">

    <!-- Back Button -->
    <div class="mb-4">
        <button onclick="history.back()" class="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium rounded-lg transition-colors" style="color:var(--color-text-muted);background:var(--color-card);border:1px solid var(--color-border)">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            Back
        </button>
    </div>

    <!-- Page Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold" style="color:var(--color-text-primary)">My Freelancers</h1>
            <p class="mt-1 text-sm" style="color:var(--color-text-muted)">All freelancers you've hired across your projects</p>
        </div>
        <span class="text-sm font-medium px-3 py-1.5 rounded-lg" style="background:rgba(99,102,241,0.08);color:#6366f1">
            <?= count($freelancers_data) ?> Freelancer<?= count($freelancers_data) !== 1 ? 's' : '' ?>
        </span>
    </div>

    <?php if (empty($freelancers_data)): ?>
    <div class="text-center py-20">
        <svg class="w-16 h-16 mx-auto mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1" style="color:var(--color-text-muted)">
            <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
        </svg>
        <h3 class="text-lg font-semibold mb-2" style="color:var(--color-text-primary)">No freelancers hired yet</h3>
        <p class="text-sm mb-4" style="color:var(--color-text-muted)">When you hire freelancers, they will appear here.</p>
        <a href="<?= e(base_url('company/find_freelancers.php')) ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold text-white" style="background:linear-gradient(135deg,#6366f1,#8b5cf6)">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            Find Freelancers
        </a>
    </div>
    <?php else: ?>

    <div class="table-container">
        <table class="fl-table">
            <thead>
                <tr>
                    <th>Freelancer</th>
                    <th>Project</th>
                    <th>Joined Date</th>
                    <th>Rating</th>
                    <th class="md:text-right">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($freelancers_data as $fl): ?>
                <tr>
                    <td data-label="Freelancer">
                        <div class="flex items-center gap-3">
                            <?php if (!empty($fl['profile_image'])): ?>
                                <img src="<?= e(base_url('uploads/images/' . $fl['profile_image'])) ?>" alt="<?= e($fl['full_name']) ?>" class="w-10 h-10 rounded-full object-cover flex-shrink-0" style="border:1px solid var(--color-border)">
                            <?php else: ?>
                                <div class="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0 font-bold text-white text-sm" style="background:linear-gradient(135deg,#6366f1,#8b5cf6)">
                                    <?= e(mb_strtoupper(mb_substr($fl['full_name'], 0, 1))) ?>
                                </div>
                            <?php endif; ?>
                            <div class="min-w-0 flex-1">
                                <h3 class="text-sm font-bold truncate" style="color:var(--color-text-primary)"><?= e($fl['full_name']) ?></h3>
                                <p class="text-xs truncate" style="color:var(--color-text-muted)"><?= e($fl['email']) ?></p>
                            </div>
                        </div>
                    </td>
                    <td data-label="Project">
                        <div class="flex flex-col gap-2">
                            <?php if (!empty($fl['projects'])): ?>
                                <?php foreach ($fl['projects'] as $proj): ?>
                                    <div>
                                        <p class="text-sm font-semibold" style="color:var(--color-text-primary)"><?= e($proj['job_title']) ?></p>
                                        <p class="text-xs font-medium text-emerald-600 dark:text-emerald-400"><?= number_format($proj['budget']) ?> MMK</p>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-xs" style="color:var(--color-text-muted)">-</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td data-label="Joined Date">
                        <span class="text-sm font-semibold" style="color:var(--color-text-primary)">
                            <?= date('M j, Y', strtotime($fl['joined_at'])) ?>
                        </span>
                    </td>
                    <td data-label="Rating">
                        <?php if ($fl['rating'] && $fl['rating']['review_count'] > 0): ?>
                            <div class="flex items-center gap-1">
                                <svg class="w-4 h-4 text-yellow-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                                <span class="text-sm font-bold" style="color:var(--color-text-primary)"><?= number_format($fl['rating']['avg_rating'], 1) ?></span>
                                <span class="text-xs" style="color:var(--color-text-muted)">(<?= $fl['rating']['review_count'] ?>)</span>
                            </div>
                        <?php else: ?>
                            <span class="text-xs font-semibold px-2 py-1 rounded-md" style="background:rgba(0,0,0,0.05);color:var(--color-text-muted)">Not Rated</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Action" class="md:text-right">
                        <div class="flex flex-col items-end gap-2">
                            <a href="<?= e(base_url('company/view_freelancer.php?id=' . $fl['freelancer_id'])) ?>" class="inline-flex items-center justify-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-semibold transition-all hover:-translate-y-0.5 whitespace-nowrap w-full md:w-auto" style="background:var(--color-card);border:1px solid var(--color-border);color:var(--color-text-primary)">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                View Detail
                            </a>
                            
                            <?php foreach ($fl['projects'] as $proj): ?>
                                <?php if ($proj['status'] === 'completed'): ?>
                                    <?php if (!$proj['is_reviewed']): ?>
                                        <button type="button" onclick="openRateModal(<?= $fl['freelancer_id'] ?>, <?= $proj['assignment_id'] ?>, '<?= e(addslashes($fl['full_name'])) ?>', '<?= e(addslashes($proj['job_title'])) ?>')" class="inline-flex items-center justify-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-semibold transition-all hover:-translate-y-0.5 whitespace-nowrap w-full md:w-auto" style="background:linear-gradient(135deg,#6366f1,#8b5cf6);color:white;box-shadow:0 2px 8px rgba(99,102,241,0.2)">
                                            <svg class="w-4 h-4 text-yellow-300" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                                            Rate: <?= e(strlen($proj['job_title']) > 15 ? substr($proj['job_title'], 0, 15) . '...' : $proj['job_title']) ?>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" onclick="openViewModal('<?= e(addslashes($fl['full_name'])) ?>', <?= $proj['review_data']['rating'] ?>, '<?= e(addslashes(str_replace(["\r\n", "\r", "\n"], "\\n", $proj['review_data']['comment']))) ?>', '<?= date('M j, Y', strtotime($proj['review_data']['created_at'])) ?>')" class="inline-flex items-center justify-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-semibold transition-all hover:-translate-y-0.5 whitespace-nowrap w-full md:w-auto" style="background:rgba(16,185,129,0.1);color:#10b981;border:1px solid rgba(16,185,129,0.2)">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                            View Review: <?= e(strlen($proj['job_title']) > 15 ? substr($proj['job_title'], 0, 15) . '...' : $proj['job_title']) ?>
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php endif; ?>
</div>

<!-- ===== Rate & Review Modal ===== -->
<div id="rateModal" class="hidden fixed inset-0 z-[105] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4">
    <div class="relative z-[110] pointer-events-auto w-full max-w-md bg-white dark:bg-slate-900 rounded-2xl shadow-2xl flex flex-col overflow-hidden">
        <form method="POST" id="rateForm" class="flex flex-col h-full">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="submit_review">
            <input type="hidden" name="freelancer_id" id="rate_freelancer_id" value="">
            <input type="hidden" name="assignment_id" id="rate_assignment_id" value="">
            
            <div class="shrink-0 p-6 pb-4 border-b border-slate-100 dark:border-slate-800">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-xl font-bold text-slate-900 dark:text-white" id="rateTitle">Rate Freelancer</h3>
                        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1" id="rateSubtitle">How was your experience working on this project?</p>
                    </div>
                    <button type="button" onclick="closeRateModal()" class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-400 hover:text-slate-600 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>
            <div class="p-6 space-y-6 flex-1 min-h-0 overflow-y-auto">
                <div class="flex justify-center gap-2" id="starContainer">
                    <!-- Stars will be handled by JS -->
                    <?php for($i=1; $i<=5; $i++): ?>
                    <button type="button" class="star-btn text-slate-300 dark:text-slate-600 hover:text-yellow-400 transition-colors focus:outline-none" data-rating="<?= $i ?>">
                        <svg class="w-10 h-10" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                    </button>
                    <?php endfor; ?>
                    <input type="hidden" name="rating" id="ratingValue" value="0" required>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5 text-slate-700 dark:text-slate-300">Your Review</label>
                    <textarea name="comment" rows="4" class="w-full px-4 py-3 rounded-xl text-sm border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent resize-none" placeholder="Describe your experience working with this freelancer..."></textarea>
                </div>
            </div>
            <div class="shrink-0 p-6 pt-4 border-t border-slate-100 dark:border-slate-800 flex justify-end gap-3 bg-slate-50 dark:bg-slate-900/50">
                <button type="button" onclick="closeRateModal()" class="px-5 py-2.5 rounded-xl text-sm font-semibold text-slate-600 bg-white border border-slate-200 hover:bg-slate-50 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700 dark:hover:bg-slate-700 transition-colors">Cancel</button>
                <button type="submit" id="submitReviewBtn" class="px-5 py-2.5 rounded-xl text-sm font-bold text-white transition-all shadow-lg hover:shadow-xl hover:-translate-y-0.5" style="background:linear-gradient(135deg,#6366f1,#8b5cf6)">Submit Review</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== View Review Modal ===== -->
<div id="viewReviewModal" class="hidden fixed inset-0 z-[105] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4">
    <div class="relative z-[110] pointer-events-auto w-full max-w-md bg-white dark:bg-slate-900 rounded-2xl shadow-2xl flex flex-col overflow-hidden">
        <div class="shrink-0 p-6 pb-4 border-b border-slate-100 dark:border-slate-800">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-xl font-bold text-slate-900 dark:text-white" id="viewReviewTitle">Review</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-1" id="viewReviewDate"></p>
                </div>
                <button type="button" onclick="closeViewModal()" class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-400 hover:text-slate-600 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
        <div class="p-6 space-y-4 flex-1 min-h-0 overflow-y-auto">
            <div class="flex gap-1" id="viewReviewStars">
                <!-- Stars rendered via JS -->
            </div>
            <p class="text-sm leading-relaxed text-slate-700 dark:text-slate-300 whitespace-pre-wrap" id="viewReviewComment"></p>
        </div>
        <div class="shrink-0 p-6 pt-4 border-t border-slate-100 dark:border-slate-800 flex justify-end gap-3 bg-slate-50 dark:bg-slate-900/50">
            <button type="button" onclick="closeViewModal()" class="px-5 py-2.5 rounded-xl text-sm font-semibold text-slate-600 bg-white border border-slate-200 hover:bg-slate-50 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700 dark:hover:bg-slate-700 transition-colors">Close</button>
        </div>
    </div>
</div>

<script>
    // Rate Modal Logic
    const rateModal = document.getElementById('rateModal');
    const rateForm = document.getElementById('rateForm');
    const submitReviewBtn = document.getElementById('submitReviewBtn');
    
    function openRateModal(freelancerId, assignmentId, freelancerName, projectName) {
        document.getElementById('rate_freelancer_id').value = freelancerId;
        document.getElementById('rate_assignment_id').value = assignmentId;
        document.getElementById('rateTitle').innerText = 'Rate ' + freelancerName;
        // Optionally show project name in subtitle
        
        // Reset stars
        setRating(0);
        rateForm.querySelector('textarea[name="comment"]').value = '';
        
        rateModal.classList.remove('hidden');
    }
    
    function closeRateModal() {
        rateModal.classList.add('hidden');
    }
    
    // Star Rating Interactivity
    const stars = document.querySelectorAll('.star-btn');
    const ratingValueInput = document.getElementById('ratingValue');
    
    function setRating(rating) {
        ratingValueInput.value = rating;
        stars.forEach((star, index) => {
            if (index < rating) {
                star.classList.add('text-yellow-400');
                star.classList.remove('text-slate-300', 'dark:text-slate-600');
            } else {
                star.classList.remove('text-yellow-400');
                star.classList.add('text-slate-300', 'dark:text-slate-600');
            }
        });
    }
    
    stars.forEach((star, index) => {
        star.addEventListener('click', () => {
            setRating(index + 1);
        });
        
        star.addEventListener('mouseenter', () => {
            const hoverRating = index + 1;
            stars.forEach((s, i) => {
                if (i < hoverRating) {
                    s.classList.add('text-yellow-300');
                } else {
                    s.classList.remove('text-yellow-300');
                }
            });
        });
        
        star.addEventListener('mouseleave', () => {
            stars.forEach(s => s.classList.remove('text-yellow-300'));
            setRating(ratingValueInput.value);
        });
    });
    
    rateForm.addEventListener('submit', function(e) {
        if (ratingValueInput.value === '0') {
            e.preventDefault();
            alert('Please select a star rating.');
            return;
        }
        submitReviewBtn.disabled = true;
        submitReviewBtn.innerText = 'Submitting...';
    });
    
    // View Modal Logic
    const viewModal = document.getElementById('viewReviewModal');
    
    function openViewModal(freelancerName, rating, comment, dateStr) {
        document.getElementById('viewReviewTitle').innerText = 'Review for ' + freelancerName;
        document.getElementById('viewReviewDate').innerText = dateStr;
        
        let starsHtml = '';
        for (let i = 1; i <= 5; i++) {
            if (i <= rating) {
                starsHtml += '<svg class="w-5 h-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>';
            } else {
                starsHtml += '<svg class="w-5 h-5 text-slate-200 dark:text-slate-700" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>';
            }
        }
        document.getElementById('viewReviewStars').innerHTML = starsHtml;
        
        document.getElementById('viewReviewComment').innerText = comment ? comment : 'No comment provided.';
        
        viewModal.classList.remove('hidden');
    }
    
    function closeViewModal() {
        viewModal.classList.add('hidden');
    }
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
