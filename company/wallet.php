<?php
$page_title = 'Company Wallet';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

require_role('company');
$user = current_user();

// Ensure upload directory exists
$upload_dir = __DIR__ . '/../uploads/deposits/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_funds') {
    $amount = (float) ($_POST['amount'] ?? 0);
    $payment_method = trim($_POST['payment_method'] ?? '');
    $transaction_id = trim($_POST['transaction_id'] ?? '');
    
    // Handle File Upload
    $proof_image_path = null;
    $uploadOk = 1;
    
    if (isset($_FILES['proof_image']) && $_FILES['proof_image']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['proof_image']['tmp_name'];
        $file_name = basename($_FILES['proof_image']['name']);
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed_ext = ['jpg', 'jpeg', 'png', 'webp'];
        
        if (!in_array($file_ext, $allowed_ext)) {
            set_flash('error', 'Only JPG, JPEG, PNG, and WEBP files are allowed.');
            $uploadOk = 0;
        } elseif ($_FILES['proof_image']['size'] > 5000000) { // 5MB limit
            set_flash('error', 'File size must be less than 5MB.');
            $uploadOk = 0;
        } else {
            $new_file_name = 'proof_' . $user['user_id'] . '_' . time() . '_' . uniqid() . '.' . $file_ext;
            $destination = $upload_dir . $new_file_name;
            if (move_uploaded_file($file_tmp, $destination)) {
                $proof_image_path = 'uploads/deposits/' . $new_file_name;
            } else {
                set_flash('error', 'Failed to upload the proof image.');
                $uploadOk = 0;
            }
        }
    }
    
    if ($uploadOk) {
        if ($amount <= 0) {
            set_flash('error', 'Amount must be greater than zero.');
        } elseif (empty($payment_method)) {
            set_flash('error', 'Please select a payment method.');
        } else {
            try {
                $stmt2 = $conn->prepare("INSERT INTO wallet_transactions (user_id, amount, type, payment_method, transaction_id, status, proof_image) VALUES (?, ?, 'deposit', ?, ?, 'pending', ?)");
                $stmt2->bind_param('idsss', $user['user_id'], $amount, $payment_method, $transaction_id, $proof_image_path);
                if (!$stmt2->execute()) {
                    throw new Exception("Failed to submit deposit request.");
                }
                $stmt2->close();
                
                set_flash('success', "Deposit request for $" . number_format($amount, 2) . " submitted successfully and is pending verification.");
            } catch (Exception $e) {
                set_flash('error', 'Failed to submit request: ' . $e->getMessage());
            }
        }
    }
    redirect('company/wallet.php');
}

// Fetch current balance
$stmt = $conn->prepare("SELECT available_balance FROM users WHERE id = ?");
$stmt->bind_param('i', $user['user_id']);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$available_balance = (float) ($row['available_balance'] ?? 0);
$stmt->close();

// Fetch transaction history
$transactions = [];
$stmt = $conn->prepare("SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY created_at DESC");
$stmt->bind_param('i', $user['user_id']);
$stmt->execute();
$res = $stmt->get_result();
while ($t = $res->fetch_assoc()) {
    $transactions[] = $t;
}
$stmt->close();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold mb-2">Company Wallet</h1>
    <p class="text-gray-600 dark:text-gray-400">Manage your simulated funds for escrow and payments.</p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
    <!-- Left Column: Balance & Actions -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700 p-6 lg:col-span-1 h-fit">
        <h2 class="text-lg font-semibold mb-1">Available Demo Funds</h2>
        <div class="text-3xl font-bold mt-2 text-indigo-600 dark:text-indigo-400 mb-6">
            <?= number_format($available_balance, 2) ?> MMK
        </div>
        
        <h3 class="text-md font-semibold mb-3 border-t pt-4 dark:border-gray-700">Add Funds</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Submit a demo payment to add simulated funds to your wallet.</p>
        
        <button type="button" onclick="document.getElementById('addFundsModal').classList.remove('hidden')" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-medium transition shadow-lg shadow-indigo-600/30 flex items-center justify-center gap-2">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
            Add Funds
        </button>
    </div>

    <!-- Right Column: Transaction History -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700 p-6 lg:col-span-2">
        <h2 class="text-lg font-semibold mb-4">Transaction History</h2>
        
        <?php if (empty($transactions)): ?>
            <div class="text-center py-8 text-gray-500">
                <p>No transactions found.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm whitespace-nowrap">
                    <thead>
                        <tr class="border-b dark:border-gray-700 text-gray-500">
                            <th class="py-3 px-4 font-medium">Date</th>
                            <th class="py-3 px-4 font-medium">Type</th>
                            <th class="py-3 px-4 font-medium">Amount</th>
                            <th class="py-3 px-4 font-medium">Method</th>
                            <th class="py-3 px-4 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y dark:divide-gray-700">
                        <?php foreach ($transactions as $t): 
                            $is_credit = in_array($t['type'], ['deposit', 'refund']);
                            $sign = $is_credit ? '+' : '-';
                            $color = $is_credit ? 'text-emerald-800 dark:text-emerald-300' : 'text-red-800 dark:text-red-300';
                            $bg = $is_credit ? 'bg-emerald-100 dark:bg-emerald-500/20' : 'bg-red-100 dark:bg-red-500/20';
                        ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                <td class="py-3 px-4"><?= date('M j, Y H:i', strtotime($t['created_at'])) ?></td>
                                <td class="py-3 px-4 capitalize">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-medium <?= $bg ?> <?= $color ?>">
                                        <?= e(ucwords(str_replace('_', ' ', $t['type']))) ?>
                                    </span>
                                </td>
                                <td class="py-3 px-4 font-bold text-gray-900 dark:text-gray-100">
                                    <?= $sign ?><?= number_format($t['amount'], 2) ?> MMK
                                </td>
                                <td class="py-3 px-4">
                                    <?php 
                                        echo $t['payment_method'] === 'kbzpay' ? 'KBZPay' : 
                                            ($t['payment_method'] === 'wavepay' ? 'WavePay' : 
                                            ($t['payment_method'] === 'bank_transfer' ? 'Bank Transfer' : 'Demo Payment'));
                                    ?>
                                    <?php if (!empty($t['transaction_id'])): ?>
                                        <div class="text-xs text-gray-500">Ref: <?= e($t['transaction_id']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4">
                                    <?php if($t['status'] === 'pending'): ?>
                                        <span class="px-2 py-1 text-xs font-semibold rounded bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400 capitalize">Pending</span>
                                    <?php elseif($t['status'] === 'completed'): ?>
                                        <span class="px-2 py-1 text-xs font-semibold rounded bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400 capitalize">Completed</span>
                                    <?php elseif($t['status'] === 'rejected'): ?>
                                        <span class="px-2 py-1 text-xs font-semibold rounded bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400 capitalize">Rejected</span>
                                        <?php if(!empty($t['rejection_reason'])): ?>
                                            <p class="text-xs text-red-500 mt-1 whitespace-normal w-40"><?= e($t['rejection_reason']) ?></p>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Funds Modal -->
<div id="addFundsModal" class="hidden fixed inset-0 z-[100] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4 overflow-y-auto">
    <div class="relative w-full max-w-5xl bg-white dark:bg-slate-900 rounded-2xl shadow-2xl flex flex-col my-auto overflow-hidden mt-20 mb-10 lg:my-auto">
        
        <!-- Header -->
        <div class="px-6 py-5 border-b border-slate-100 dark:border-slate-800 flex items-start justify-between bg-slate-50 dark:bg-slate-900/50">
            <div>
                <h3 class="text-xl font-bold text-slate-900 dark:text-white">Add Funds</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Deposit funds into your company wallet</p>
            </div>
            <button type="button" onclick="document.getElementById('addFundsModal').classList.add('hidden')" class="p-2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-800 rounded-full transition-colors">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <!-- Demo Banner -->
        <div class="bg-amber-100 border-l-4 border-amber-500 text-amber-800 dark:bg-amber-900/30 dark:border-amber-500 dark:text-amber-300 p-4">
            <div class="flex">
                <svg class="h-5 w-5 mr-2 text-amber-600 dark:text-amber-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>
                <p class="text-sm font-bold uppercase tracking-wide">DEMO PAYMENT ONLY – This is a simulated payment for demonstration purposes.</p>
            </div>
        </div>

        <div class="p-6 md:p-8 flex flex-col lg:flex-row gap-8">
            <!-- LEFT COLUMN: Payment Info -->
            <div class="w-full lg:w-5/12 space-y-6">
                
                <!-- Dynamic Payment Method Card -->
                <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-6 text-center shadow-sm relative overflow-hidden group">
                    <div class="absolute inset-0 bg-gradient-to-br from-indigo-50 to-white dark:from-slate-800 dark:to-slate-900 opacity-50 z-0"></div>
                    <div class="relative z-10">
                        <div id="method-title-container" class="mb-4 hidden">
                            <h4 id="method-title" class="text-xl font-bold text-slate-800 dark:text-white">KBZPay (DEMO)</h4>
                            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Scan QR code or use phone number</p>
                        </div>

                        <!-- QR Code -->
                        <div id="demo-qr-container" class="hidden mx-auto bg-white p-2 rounded-xl shadow-sm border border-slate-200 w-40 h-40 mb-5 flex items-center justify-center">
                            <img id="demo-qr-img" src="" alt="Demo QR Code" class="w-full h-full object-contain opacity-80">
                        </div>

                        <!-- Phone/Account Number -->
                        <div id="demo-account-container" class="hidden bg-slate-50 dark:bg-slate-900/50 rounded-lg p-4 border border-slate-200 dark:border-slate-700">
                            <p class="text-xs text-slate-500 dark:text-slate-400 font-medium mb-1 uppercase tracking-wider" id="demo-account-label">Phone Number</p>
                            <div class="flex items-center justify-center gap-3">
                                <span id="demo-account-number" class="text-lg font-bold text-slate-800 dark:text-slate-100 tracking-wider">09-123456789</span>
                                <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('demo-account-number').innerText); alert('Copied!');" class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 p-1.5 bg-indigo-50 dark:bg-indigo-900/30 rounded-md transition-colors" title="Copy">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                </button>
                            </div>
                            <p class="text-sm text-slate-600 dark:text-slate-400 mt-2 font-medium" id="demo-account-name">FreelanceHub (DEMO)</p>
                        </div>

                        <!-- Initial Empty State -->
                        <div id="demo-empty-state" class="py-12">
                            <svg class="w-12 h-12 text-slate-300 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                            <p class="text-slate-500 font-medium">Please select a payment method from the right to view demo instructions.</p>
                        </div>
                    </div>
                </div>

                <!-- Warning Banner -->
                <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4 flex gap-3">
                    <svg class="w-5 h-5 text-red-600 dark:text-red-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    <p class="text-sm text-red-700 dark:text-red-300 font-medium leading-tight">This is a demo payment account. Do not make any real payment.</p>
                </div>

                <!-- Amount Summary -->
                <div class="bg-slate-50 dark:bg-slate-800 rounded-lg p-5 border border-slate-200 dark:border-slate-700">
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-sm text-slate-500 dark:text-slate-400">Payment Amount</span>
                        <span class="font-semibold text-slate-800 dark:text-slate-200" id="summary-amount">0.00 MMK</span>
                    </div>
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-sm text-slate-500 dark:text-slate-400">Fee</span>
                        <span class="font-semibold text-slate-800 dark:text-slate-200">0.00 MMK</span>
                    </div>
                    <hr class="my-3 border-slate-200 dark:border-slate-600">
                    <div class="flex justify-between items-center">
                        <span class="text-base font-bold text-slate-800 dark:text-white">Total Amount</span>
                        <span class="text-lg font-bold text-indigo-600 dark:text-indigo-400" id="summary-total">0.00 MMK</span>
                    </div>
                </div>

            </div>

            <!-- RIGHT COLUMN: Form & Instructions -->
            <div class="w-full lg:w-7/12 flex flex-col">
                
                <!-- How to Pay Card -->
                <div class="bg-indigo-50 dark:bg-indigo-900/10 rounded-xl p-5 border border-indigo-100 dark:border-indigo-800/30 mb-6">
                    <h4 class="text-sm font-bold text-indigo-800 dark:text-indigo-300 mb-3 flex items-center gap-2 uppercase tracking-wide">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        How to Pay (Demo)
                    </h4>
                    <ol class="list-decimal list-inside text-sm text-indigo-900/80 dark:text-indigo-200/80 space-y-1.5 font-medium marker:text-indigo-400">
                        <li>Open your banking or wallet app.</li>
                        <li>Scan the QR code or use the demo phone number.</li>
                        <li>Enter the required amount.</li>
                        <li>Complete the simulated payment.</li>
                        <li>Take a screenshot of the payment success screen.</li>
                        <li>Upload the screenshot below and submit.</li>
                    </ol>
                </div>

                <!-- Form -->
                <form method="POST" action="wallet.php" enctype="multipart/form-data" class="flex-1 flex flex-col space-y-5">
                    <input type="hidden" name="action" value="add_funds">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-sm font-medium mb-1.5 text-slate-700 dark:text-slate-300">Payment Method <span class="text-red-500">*</span></label>
                            <select name="payment_method" id="payment_method" required class="w-full px-4 py-2.5 rounded-xl text-sm border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-shadow">
                                <option value="" disabled selected>Select a method</option>
                                <option value="kbzpay">Demo KBZPay</option>
                                <option value="wavepay">Demo WavePay</option>
                                <option value="bank_transfer">Demo Bank Transfer</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1.5 text-slate-700 dark:text-slate-300">Amount (MMK) <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <span class="text-slate-500 sm:text-sm">MMK</span>
                                </div>
                                <input type="number" id="amount_input" name="amount" step="1" min="10" placeholder="100.00" required
                                       class="w-full pl-8 pr-4 py-2.5 rounded-xl text-sm border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-shadow">
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1.5 text-slate-700 dark:text-slate-300">Transaction ID / Reference No. <span class="text-slate-400 font-normal">(Optional)</span></label>
                        <input type="text" name="transaction_id" placeholder="e.g., TXN-12345678"
                               class="w-full px-4 py-2.5 rounded-xl text-sm border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-shadow">
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1.5 text-slate-700 dark:text-slate-300">Payment Proof <span class="text-red-500">*</span></label>
                        <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-slate-300 dark:border-slate-600 border-dashed rounded-xl bg-slate-50 dark:bg-slate-800/50 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors group relative cursor-pointer" onclick="document.getElementById('proof_image').click()">
                            <div class="space-y-1 text-center">
                                <svg class="mx-auto h-10 w-10 text-slate-400 group-hover:text-indigo-500 transition-colors" stroke="currentColor" fill="none" viewBox="0 0 48 48" aria-hidden="true">
                                    <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                <div class="flex text-sm text-slate-600 dark:text-slate-400 justify-center">
                                    <label for="proof_image" class="relative cursor-pointer rounded-md font-medium text-indigo-600 hover:text-indigo-500 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-indigo-500">
                                        <span>Upload a file</span>
                                        <input id="proof_image" name="proof_image" type="file" accept="image/*" class="sr-only" required onchange="document.getElementById('file_name_display').textContent = this.files[0]?.name || 'No file selected'">
                                    </label>
                                    <p class="pl-1">or drag and drop</p>
                                </div>
                                <p class="text-xs text-slate-500">PNG, JPG up to 5MB</p>
                                <p id="file_name_display" class="text-sm font-semibold text-indigo-600 dark:text-indigo-400 mt-2 truncate max-w-[200px] mx-auto"></p>
                            </div>
                        </div>
                    </div>

                    <div class="mt-auto pt-6 border-t border-slate-200 dark:border-slate-700">
                        <p class="text-xs text-slate-500 dark:text-slate-400 mb-4 text-center">Your deposit will remain <span class="font-bold text-yellow-600 dark:text-yellow-500">Pending</span> until verified by an Administrator.</p>
                        <div class="flex gap-4">
                            <button type="button" onclick="document.getElementById('addFundsModal').classList.add('hidden')" class="flex-1 px-6 py-3 rounded-xl text-sm font-bold border-2 border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">Cancel</button>
                            <button type="submit" class="flex-1 px-6 py-3 rounded-xl text-sm font-bold bg-indigo-600 hover:bg-indigo-700 text-white shadow-lg shadow-indigo-600/30 transition-all">Submit Deposit</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    // Close modal when clicking outside
    document.getElementById('addFundsModal').addEventListener('click', function(e) {
        if (e.target === this) this.classList.add('hidden');
    });

    // Amount mirror logic
    document.getElementById('amount_input').addEventListener('input', function() {
        const val = parseFloat(this.value) || 0;
        const formatted = val.toFixed(2) + ' MMK';
        document.getElementById('summary-amount').textContent = formatted;
        document.getElementById('summary-total').textContent = formatted;
    });

    // Handle Demo Payment Instructions Logic
    document.getElementById('payment_method').addEventListener('change', function() {
        const method = this.value;
        const titleContainer = document.getElementById('method-title-container');
        const title = document.getElementById('method-title');
        const qrContainer = document.getElementById('demo-qr-container');
        const qrImg = document.getElementById('demo-qr-img');
        const accountContainer = document.getElementById('demo-account-container');
        const accountLabel = document.getElementById('demo-account-label');
        const accountNumber = document.getElementById('demo-account-number');
        const emptyState = document.getElementById('demo-empty-state');
        
        emptyState.classList.add('hidden');
        titleContainer.classList.remove('hidden');
        accountContainer.classList.remove('hidden');

        if (method === 'kbzpay') {
            title.textContent = 'KBZPay (DEMO)';
            qrImg.src = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=KBZPAY-DEMO';
            qrContainer.classList.remove('hidden');
            accountLabel.textContent = 'Phone Number';
            accountNumber.textContent = '09-123456789';
        } else if (method === 'wavepay') {
            title.textContent = 'WavePay (DEMO)';
            qrImg.src = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=WAVEPAY-DEMO';
            qrContainer.classList.remove('hidden');
            accountLabel.textContent = 'Phone Number';
            accountNumber.textContent = '09-987654321';
        } else if (method === 'bank_transfer') {
            title.textContent = 'Bank Transfer (DEMO)';
            qrContainer.classList.add('hidden'); // Bank transfer usually doesn't have QR
            accountLabel.textContent = 'KBZ Bank Account';
            accountNumber.textContent = '0123 4567 8901 2345';
        }
    });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
