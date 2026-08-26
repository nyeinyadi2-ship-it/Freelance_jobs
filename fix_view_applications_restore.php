<?php
$file = __DIR__ . '/company/view_applications.php';
$content = file_get_contents($file);

// 1. Change "fund" to "start" in ms_action checks
$content = str_replace(
    "elseif (\$ms_action === 'fund') {",
    "elseif (\$ms_action === 'start') {",
    $content
);

// 2. Fix start action (remove fund deduction completely)
$start_action_orig = <<<EOT
                    \$conn->begin_transaction();
                    try {
                        // Deduct from available balance
                        \$stmt_bal = \$conn->prepare("UPDATE users SET available_balance = available_balance - ? WHERE id = ? AND available_balance >= ?");
                        \$amount = (float) \$ms['amount'];
                        \$stmt_bal->bind_param('did', \$amount, \$user['user_id'], \$amount);
                        \$stmt_bal->execute();
                        if (\$stmt_bal->affected_rows === 0) {
                            \$stmt_bal->close();
                            throw new Exception("Insufficient available balance to fund this milestone (Need " . number_format(\$amount, 2) . " MMK).");
                        }
                        \$stmt_bal->close();

                        // Update milestone to funded
                        \$up = \$conn->prepare("UPDATE milestones SET status = 'funded' WHERE id = ?");
                        \$up->bind_param('i', \$milestone_id);
                        \$up->execute();
                        \$up->close();

                        // Notify freelancer & log transaction
                        if (\$ms['freelancer_id'] > 0) {
                            \$stmt = \$conn->prepare("SELECT user_id FROM freelancers WHERE id = ?");
                            \$stmt->bind_param('i', \$ms['freelancer_id']);
                            \$stmt->execute();
                            \$fl = \$stmt->get_result()->fetch_assoc();
                            \$stmt->close();
                            if (\$fl) {
                                // Log internal wallet transaction
                                \$desc = "Fund Milestone: " . (\$ms['title'] ?? 'Unknown');
                                \$now = date('Y-m-d H:i:s');
                                \$fl_user_id = (int) \$fl['user_id'];
                                \$stmt_wt = \$conn->prepare("INSERT INTO wallet_transactions (user_id, sender_id, receiver_id, job_id, milestone_id, description, amount, type, payment_method, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'funding', 'platform_fund', 'completed', ?)");
                                \$stmt_wt->bind_param('iiiiisds', \$user['user_id'], \$user['user_id'], \$fl_user_id, \$job_id, \$milestone_id, \$desc, \$amount, \$now);
                                \$stmt_wt->execute();
                                \$stmt_wt->close();
                                
                                create_notification(\$conn, \$fl_user_id, 'admin_announcement', "Milestone \\"{\$ms['title']}\\" for \\"{\$job['title']}\\" has been funded! You can now start working.", 'freelancer/my_tasks.php');
                            }
                        }
EOT;

$start_action_new = <<<EOT
                    \$conn->begin_transaction();
                    try {
                        // Update milestone to in_progress
                        \$up = \$conn->prepare("UPDATE milestones SET status = 'in_progress' WHERE id = ?");
                        \$up->bind_param('i', \$milestone_id);
                        \$up->execute();
                        \$up->close();

                        // Notify freelancer
                        if (\$ms['freelancer_id'] > 0) {
                            \$stmt = \$conn->prepare("SELECT user_id FROM freelancers WHERE id = ?");
                            \$stmt->bind_param('i', \$ms['freelancer_id']);
                            \$stmt->execute();
                            \$fl = \$stmt->get_result()->fetch_assoc();
                            \$stmt->close();
                            if (\$fl) {
                                \$fl_user_id = (int) \$fl['user_id'];
                                create_notification(\$conn, \$fl_user_id, 'admin_announcement', "Milestone \\"{\$ms['title']}\\" for \\"{\$job['title']}\\" has been started! You can now start working.", 'freelancer/my_tasks.php');
                            }
                        }
EOT;
$content = str_replace($start_action_orig, $start_action_new, $content);

// 3. Fix approve action (Approve & Pay)
$approve_action_orig = <<<EOT
                    \$conn->begin_transaction();
                    try {
                        // Update milestone to approved
                        \$up = \$conn->prepare("UPDATE milestones SET status = 'approved' WHERE id = ?");
                        \$up->bind_param('i', \$milestone_id);
                        \$up->execute();
                        \$up->close();

                        // Mark submission as approved
                        \$up_sub = \$conn->prepare("UPDATE milestone_submissions SET status = 'approved', updated_at = NOW() WHERE milestone_id = ? AND status = 'pending'");
                        \$up_sub->bind_param('i', \$milestone_id);
                        \$up_sub->execute();
                        \$up_sub->close();
EOT;

$approve_action_new = <<<EOT
                    \$conn->begin_transaction();
                    try {
                        // 1. Deduct from Company
                        \$amount = (float) \$ms['amount'];
                        \$stmt_bal = \$conn->prepare("UPDATE users SET available_balance = available_balance - ? WHERE id = ? AND available_balance >= ?");
                        \$stmt_bal->bind_param('did', \$amount, \$user['user_id'], \$amount);
                        \$stmt_bal->execute();
                        if (\$stmt_bal->affected_rows === 0) {
                            \$stmt_bal->close();
                            throw new Exception("Insufficient Total Fund. Please add funds to pay this milestone.");
                        }
                        \$stmt_bal->close();

                        // 2. Credit Freelancer
                        \$stmt = \$conn->prepare("SELECT user_id, id FROM freelancers WHERE id = ?");
                        \$stmt->bind_param('i', \$ms['freelancer_id']);
                        \$stmt->execute();
                        \$fl = \$stmt->get_result()->fetch_assoc();
                        \$stmt->close();
                        
                        \$fl_user_id = (int) \$fl['user_id'];
                        \$fl_id = (int) \$fl['id'];
                        
                        \$stmt_bal2 = \$conn->prepare("UPDATE users SET available_balance = available_balance + ? WHERE id = ?");
                        \$stmt_bal2->bind_param('di', \$amount, \$fl_user_id);
                        \$stmt_bal2->execute();
                        \$stmt_bal2->close();

                        // 3. Create wallet transaction
                        \$desc = "Payment to Freelancer for: " . (\$ms['title'] ?? 'Unknown');
                        \$now = date('Y-m-d H:i:s');
                        \$stmt_wt = \$conn->prepare("INSERT INTO wallet_transactions (user_id, sender_id, receiver_id, job_id, milestone_id, description, amount, type, payment_method, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'payment', 'platform_fund', 'completed', ?)");
                        \$stmt_wt->bind_param('iiiiisds', \$user['user_id'], \$user['user_id'], \$fl_user_id, \$job_id, \$milestone_id, \$desc, \$amount, \$now);
                        \$stmt_wt->execute();
                        \$stmt_wt->close();

                        // 4. Create payment record
                        \$stmt_pay = \$conn->prepare("INSERT INTO payments (company_id, freelancer_id, job_id, milestone_id, amount, payment_method, payment_status, created_at) VALUES (?, ?, ?, ?, ?, 'platform_fund', 'completed', ?)");
                        \$stmt_pay->bind_param('iiiiis', \$company_id, \$fl_id, \$job_id, \$milestone_id, \$amount, \$now);
                        \$stmt_pay->execute();
                        \$stmt_pay->close();

                        // 5. Update milestone to paid
                        \$up = \$conn->prepare("UPDATE milestones SET status = 'paid', approved_at = NOW() WHERE id = ?");
                        \$up->bind_param('i', \$milestone_id);
                        \$up->execute();
                        \$up->close();

                        // 6. Mark submission as approved
                        \$up_sub = \$conn->prepare("UPDATE milestone_submissions SET status = 'approved', updated_at = NOW() WHERE milestone_id = ? AND status = 'pending'");
                        \$up_sub->bind_param('i', \$milestone_id);
                        \$up_sub->execute();
                        \$up_sub->close();
EOT;
$content = str_replace($approve_action_orig, $approve_action_new, $content);

// 4. Update UI labels
$content = str_replace('Fund Milestone', 'Start Milestone', $content);
$content = str_replace('fund milestone', 'start milestone', $content);
$content = str_replace('handleFundMilestone', 'handleStartMilestone', $content);

$ui_fund_form = <<<EOT
                                            <form method="POST" class="inline" onsubmit="return handleStartMilestone(this, <?= (float) \$ms['amount'] ?>)">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="job_id" value="<?= \$job_id ?>">
                                                <input type="hidden" name="action" value="milestone_action">
                                                <input type="hidden" name="ms_action" value="start">
                                                <input type="hidden" name="milestone_id" value="<?= (int) \$ms['id'] ?>">
                                                <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold text-white transition-all shadow-sm hover:shadow-md" style="background:linear-gradient(135deg,#3b82f6,#2563eb)">
                                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                    Start Milestone
                                                </button>
                                            </form>
EOT;

// Actually wait, let's just use str_replace for JS function handleStartMilestone:
$js_old = <<<EOT
function handleStartMilestone(form, amount) {
    if (companyTotalFund < amount) {
        alert("Insufficient available balance to fund this milestone.");
        return false;
    }
    const btn = form.querySelector('button[type="submit"]');
    if (btn.disabled) return false;
    
    if (confirm('Start this milestone? ' + amount.toFixed(2) + ' MMK will be deducted from your available balance.')) {
        btn.disabled = true;
        btn.innerHTML = '<svg class="animate-spin h-3.5 w-3.5 mr-1.5 inline text-white" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path></svg> Funding...';
        return true;
    }
    return false;
}
EOT;

$js_new = <<<EOT
function handleStartMilestone(form, amount) {
    const btn = form.querySelector('button[type="submit"]');
    if (btn.disabled) return false;
    
    if (confirm('Start this milestone?')) {
        btn.disabled = true;
        btn.innerHTML = '<svg class="animate-spin h-3.5 w-3.5 mr-1.5 inline text-white" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path></svg> Starting...';
        return true;
    }
    return false;
}
EOT;

$content = str_replace($js_old, $js_new, $content);


file_put_contents($file, $content);
echo "Done!\n";
