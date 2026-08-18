<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';

csrf_cookie();
require_role('company');

$user = current_user();
$company_id = get_company_id($conn, (int) $user['user_id']);
if (!$company_id) {
    redirect('auth/login.php');
}

$application_id = (int) ($_GET['application_id'] ?? 0);
$proposal_id = (int) ($_GET['proposal_id'] ?? 0);

if (!$application_id) {
    set_flash('error', 'Invalid application.');
    redirect('company/manage_jobs.php');
}

$stmt = $conn->prepare("
    SELECT ja.id, ja.job_id, ja.freelancer_id,
           j.title, j.payment_type, j.budget, j.freelancers_needed,
           f.full_name AS freelancer_name
    FROM job_applications ja
    JOIN jobs j ON ja.job_id = j.id
    JOIN freelancers f ON ja.freelancer_id = f.id
    WHERE ja.id = ? AND j.company_id = ? AND ja.status = 'pending'
");
$stmt->bind_param('ii', $application_id, $company_id);
$stmt->execute();
$app = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$app) {
    set_flash('error', 'Application not found or already processed.');
    redirect('company/manage_jobs.php');
}

$job_id = (int) $app['job_id'];

// Default milestones if payment_type is milestone
$default_milestones = [];
if ($app['payment_type'] === 'milestone') {
    $stmt = $conn->prepare("SELECT title, amount FROM milestones WHERE job_id = ? AND freelancer_id IS NULL ORDER BY sort_order ASC");
    $stmt->bind_param('i', $job_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $default_milestones[] = $row;
    }
    $stmt->close();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_hire'])) {
    if (!verify_csrf()) {
        $error = 'Invalid request.';
    } else {
        $payment_type = $app['payment_type'];
        $budget = 0;
        
        $titles = [];
        $amounts = [];
        
        if ($payment_type === 'milestone') {
            $titles = $_POST['ms_title'] ?? [];
            $amounts = $_POST['ms_amount'] ?? [];
            
            foreach ($amounts as $amt) {
                $budget += (float)$amt;
            }
            if ($budget <= 0) {
                $error = 'Total milestone amount must be greater than zero.';
            }
        } else {
            $budget = (float) $app['budget'];
        }
        
        if (!$error) {
            $conn->begin_transaction();
            try {
                // Determine reservation amount (0 for milestone, full budget for fixed)
                $reserve_amount = ($payment_type === 'milestone') ? 0.0 : $budget;

                // Verify and reserve balance
                if ($reserve_amount > 0) {
                    $stmt = $conn->prepare("UPDATE users SET available_balance = available_balance - ?, reserved_balance = reserved_balance + ? WHERE id = ? AND available_balance >= ?");
                    $stmt->bind_param('ddid', $reserve_amount, $reserve_amount, $user['user_id'], $reserve_amount);
                    $stmt->execute();
                    if ($stmt->affected_rows === 0) {
                        $stmt->close();
                        throw new Exception("Insufficient available fund (need {$reserve_amount} MMK) to reserve project funds.");
                    }
                    $stmt->close();
                }

                // Accept application
                $stmt = $conn->prepare("UPDATE job_applications SET status = 'accepted' WHERE id = ?");
                $stmt->bind_param('i', $application_id);
                $stmt->execute();
                $stmt->close();

                // Create assignment
                $stmt = $conn->prepare("INSERT INTO assignments (job_id, freelancer_id, status, payment_type, budget) VALUES (?, ?, 'assigned', ?, ?)");
                $stmt->bind_param('iisd', $job_id, $app['freelancer_id'], $payment_type, $budget);
                $stmt->execute();
                if ($stmt->affected_rows <= 0) {
                    $stmt->close();
                    throw new Exception('Failed to create assignment.');
                }
                $assignment_id = $stmt->insert_id;
                $stmt->close();
                
                // Add freelancer specific milestones
                if ($payment_type === 'milestone') {
                    $stmt_ms = $conn->prepare("INSERT INTO milestones (job_id, freelancer_id, title, amount, status, sort_order) VALUES (?, ?, ?, ?, 'draft', ?)");
                    foreach ($titles as $idx => $mtitle) {
                        $mamt = (float)$amounts[$idx];
                        $ms_order = $idx + 1;
                        if ($mamt > 0 && trim($mtitle) !== '') {
                            $mtitle_clean = trim($mtitle);
                            $stmt_ms->bind_param('iisdi', $job_id, $app['freelancer_id'], $mtitle_clean, $mamt, $ms_order);
                            $stmt_ms->execute();
                        }
                    }
                    $stmt_ms->close();
                }

                // Check hiring limit and mark job filled if needed
                $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM assignments WHERE job_id = ?");
                $stmt->bind_param('i', $job_id);
                $stmt->execute();
                $new_assigned_count = (int) $stmt->get_result()->fetch_assoc()['cnt'];
                $stmt->close();

                if ($new_assigned_count >= $app['freelancers_needed']) {
                    $stmt = $conn->prepare("UPDATE job_applications SET status = 'rejected' WHERE job_id = ? AND id != ? AND status = 'pending'");
                    $stmt->bind_param('ii', $job_id, $application_id);
                    $stmt->execute();
                    $stmt->close();

                    $new_status = 'position_filled';
                    $stmt = $conn->prepare("UPDATE jobs SET status = ? WHERE id = ?");
                    $stmt->bind_param('si', $new_status, $job_id);
                    $stmt->execute();
                    $stmt->close();
                }

                if ($proposal_id > 0) {
                    $conn->query("UPDATE proposal_projects SET status = 'hired' WHERE id = " . $proposal_id);
                }

                $stmt = $conn->prepare("SELECT user_id FROM freelancers WHERE id = ?");
                $stmt->bind_param('i', $app['freelancer_id']);
                $stmt->execute();
                $fl_user_id = $stmt->get_result()->fetch_assoc()['user_id'];
                $stmt->close();

                if ($fl_user_id) {
                    create_notification($conn, (int) $fl_user_id, 'hired', "You have been hired for \"{$app['title']}\". Check your tasks.", 'freelancer/my_tasks.php');
                }

                $conn->commit();
                set_flash('success', 'Freelancer hired successfully and funds reserved.');
                redirect('company/view_job.php?id=' . $job_id);
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Could not hire freelancer: ' . $e->getMessage();
            }
        }
    }
}

$page_title = 'Confirm Hire';
require __DIR__ . '/../includes/header.php';
?>

<div class="max-w-3xl mx-auto py-8">
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl overflow-hidden border border-gray-100 dark:border-gray-700">
        <div class="p-6 border-b border-gray-100 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/50">
            <h1 class="text-xl font-bold text-gray-900 dark:text-white">Confirm Hire & Payment Arrangement</h1>
            <p class="text-sm text-gray-500 mt-1">You are hiring <span class="font-semibold text-indigo-600"><?= e($app['freelancer_name']) ?></span> for "<?= e($app['title']) ?>"</p>
        </div>

        <?php if ($error): ?>
            <div class="m-6 p-4 rounded-xl bg-red-50 border border-red-100 text-red-600 flex items-start gap-3">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                <span class="text-sm font-medium"><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" class="p-6">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="confirm_hire" value="1">

            <?php if ($app['payment_type'] === 'fixed'): ?>
                <div class="mb-8 p-5 rounded-xl bg-indigo-50 border border-indigo-100 dark:bg-indigo-900/20 dark:border-indigo-800/30">
                    <h3 class="text-sm font-semibold text-indigo-900 dark:text-indigo-300 mb-2">Fixed Payment Total</h3>
                    <div class="text-3xl font-bold text-indigo-600 dark:text-indigo-400">
                        <?= number_format((float)$app['budget'], 2) ?> <span class="text-lg font-medium text-indigo-400">MMK</span>
                    </div>
                    <p class="text-xs text-indigo-600/70 mt-2">This amount will be reserved from your wallet immediately.</p>
                </div>
            <?php else: ?>
                <div class="mb-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Customize Milestones</h3>
                        <button type="button" onclick="addMilestone()" class="text-xs font-medium text-indigo-600 bg-indigo-50 hover:bg-indigo-100 px-3 py-1.5 rounded-lg transition-colors">
                            + Add Milestone
                        </button>
                    </div>
                    
                    <div id="milestonesContainer" class="space-y-3">
                        <?php 
                        $count = 1;
                        if (empty($default_milestones)) {
                            // Empty state if no default
                            $default_milestones[] = ['title' => 'Milestone 1', 'amount' => '0.00'];
                        }
                        foreach ($default_milestones as $ms): ?>
                            <div class="ms-item flex flex-col gap-3 p-4 rounded-xl border border-gray-200 bg-gray-50 dark:bg-gray-800 dark:border-gray-700">
                                <div class="flex justify-between items-center">
                                    <span class="ms-label text-xs font-bold uppercase tracking-wider text-gray-500">Milestone <?= $count ?></span>
                                    <?php if ($count > 1): ?>
                                    <button type="button" onclick="removeMilestone(this)" class="w-6 h-6 rounded flex items-center justify-center text-red-400 hover:text-red-600 hover:bg-red-50 transition-all" title="Remove">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                    <?php endif; ?>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <input type="text" name="ms_title[]" value="<?= e($ms['title']) ?>" required placeholder="e.g. UI Design" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-sm focus:ring-2 focus:ring-indigo-500 outline-none transition-all">
                                    <input type="number" name="ms_amount[]" value="<?= e($ms['amount']) ?>" step="0.01" min="0.01" required placeholder="0.00" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-sm focus:ring-2 focus:ring-indigo-500 outline-none transition-all ms-amount-input" oninput="updateTotal()">
                                </div>
                            </div>
                        <?php $count++; endforeach; ?>
                    </div>
                    
                    <div class="mt-4 p-4 rounded-xl bg-gray-50 border border-gray-200 flex justify-between items-center dark:bg-gray-800 dark:border-gray-700">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Total Reserved Amount:</span>
                        <span class="text-xl font-bold text-indigo-600" id="totalAmount">0.00 MMK</span>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="flex justify-end gap-3 pt-6 border-t border-gray-100 dark:border-gray-700">
                <a href="<?= e(base_url('company/view_applications.php?id=' . $job_id)) ?>" class="px-5 py-2.5 rounded-xl border border-gray-300 text-gray-700 font-medium hover:bg-gray-50 transition-colors text-sm">Cancel</a>
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-medium shadow-lg shadow-indigo-200 transition-all text-sm">Confirm & Hire</button>
            </div>
        </form>
    </div>
</div>

<?php if ($app['payment_type'] === 'milestone'): ?>
<script>
let msCount = <?= count($default_milestones) ?>;

function addMilestone() {
    msCount++;
    const html = `
        <div class="ms-item flex flex-col gap-3 p-4 rounded-xl border border-gray-200 bg-gray-50 dark:bg-gray-800 dark:border-gray-700 mt-3">
            <div class="flex justify-between items-center">
                <span class="ms-label text-xs font-bold uppercase tracking-wider text-gray-500">Milestone ${msCount}</span>
                <button type="button" onclick="removeMilestone(this)" class="w-6 h-6 rounded flex items-center justify-center text-red-400 hover:text-red-600 hover:bg-red-50 transition-all" title="Remove">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <input type="text" name="ms_title[]" required placeholder="Milestone Title" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-sm focus:ring-2 focus:ring-indigo-500 outline-none transition-all">
                <input type="number" name="ms_amount[]" step="0.01" min="0.01" required placeholder="0.00" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-sm focus:ring-2 focus:ring-indigo-500 outline-none transition-all ms-amount-input" oninput="updateTotal()">
            </div>
        </div>
    `;
    document.getElementById('milestonesContainer').insertAdjacentHTML('beforeend', html);
    updateTotal();
}

function removeMilestone(btn) {
    btn.closest('.ms-item').remove();
    // Update labels
    const items = document.querySelectorAll('.ms-item');
    items.forEach((item, index) => {
        item.querySelector('.ms-label').textContent = 'Milestone ' + (index + 1);
    });
    msCount = items.length;
    updateTotal();
}

function updateTotal() {
    let total = 0;
    document.querySelectorAll('.ms-amount-input').forEach(input => {
        const val = parseFloat(input.value);
        if (!isNaN(val)) total += val;
    });
    document.getElementById('totalAmount').textContent = total.toLocaleString('en', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' MMK';
}

// Initial calculation
updateTotal();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
