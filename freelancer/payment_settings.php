<?php
$page_title = 'Payment Info';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

require_role('freelancer');
$user = current_user();

// Get freelancer ID
$stmt = $conn->prepare("SELECT id FROM freelancers WHERE user_id = ?");
$stmt->bind_param('i', $user['user_id']);
$stmt->execute();
$fl = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$fl) {
    set_flash('error', 'Freelancer profile not found.');
    redirect('freelancer/dashboard.php');
}
$freelancer_id = $fl['id'];

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $method = $_POST['method'] ?? '';
    $account_name = trim($_POST['account_name'] ?? '');
    $account_number = trim($_POST['account_number'] ?? '');
    $bank_name = trim($_POST['bank_name'] ?? '');
    $is_default = isset($_POST['is_default']) ? 1 : 0;

    if (!in_array($method, ['kpay', 'wavepay', 'bank_transfer'])) {
        set_flash('error', 'Invalid payment method.');
    } elseif ($account_name === '' || $account_number === '') {
        set_flash('error', 'Account name and number are required.');
    } elseif ($method === 'bank_transfer' && $bank_name === '') {
        set_flash('error', 'Bank name is required for Bank Transfer.');
    } else {
        if ($is_default) {
            // clear other defaults
            $upd = $conn->prepare("UPDATE freelancer_payment_settings SET is_default = 0 WHERE freelancer_id = ?");
            $upd->bind_param('i', $freelancer_id);
            $upd->execute();
            $upd->close();
        }

        // Insert or update
        $stmt = $conn->prepare("INSERT INTO freelancer_payment_settings (freelancer_id, method, account_name, account_number, bank_name, is_default) 
                                VALUES (?, ?, ?, ?, ?, ?) 
                                ON DUPLICATE KEY UPDATE account_name = VALUES(account_name), account_number = VALUES(account_number), bank_name = VALUES(bank_name), is_default = VALUES(is_default)");
        $stmt->bind_param('issssi', $freelancer_id, $method, $account_name, $account_number, $bank_name, $is_default);
        $stmt->execute();
        $stmt->close();

        set_flash('success', 'Payment information saved successfully.');
    }
    redirect('freelancer/payment_settings.php');
}

// Fetch existing settings
$settings = [
    'kpay' => null,
    'wavepay' => null,
    'bank_transfer' => null
];
$stmt = $conn->prepare("SELECT * FROM freelancer_payment_settings WHERE freelancer_id = ?");
$stmt->bind_param('i', $freelancer_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $settings[$row['method']] = $row;
}
$stmt->close();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold mb-2 text-slate-800 dark:text-white">Payment Information</h1>
    <p class="text-slate-600 dark:text-slate-400">Manage your payment methods to receive funds from clients directly.</p>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <!-- KPay -->
    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 p-6 relative">
        <h3 class="text-lg font-bold mb-4 flex items-center gap-2 text-slate-800 dark:text-slate-100">
            <span class="w-8 h-8 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold text-xs">K</span>
            KBZPay
            <?php if(!empty($settings['kpay']['is_default'])): ?><span class="ml-auto text-[10px] uppercase font-bold bg-green-100 text-green-700 px-2 py-0.5 rounded">Default</span><?php endif; ?>
        </h3>
        
        <form method="POST" action="payment_settings.php" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="method" value="kpay">
            
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Account Name</label>
                <input type="text" name="account_name" required value="<?= e($settings['kpay']['account_name'] ?? '') ?>" class="w-full rounded-lg border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm focus:ring-indigo-500 focus:border-indigo-500">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Phone Number</label>
                <input type="text" name="account_number" required value="<?= e($settings['kpay']['account_number'] ?? '') ?>" placeholder="09xxxxxxxxx" class="w-full rounded-lg border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm focus:ring-indigo-500 focus:border-indigo-500">
            </div>
            
            <div class="flex items-center gap-2 pt-2">
                <input type="checkbox" name="is_default" id="kpay_def" <?= !empty($settings['kpay']['is_default']) ? 'checked' : '' ?> class="rounded text-indigo-600 focus:ring-indigo-500">
                <label for="kpay_def" class="text-sm text-slate-600 dark:text-slate-400">Set as default</label>
            </div>
            
            <button type="submit" class="w-full py-2 bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-800 dark:text-slate-200 text-sm font-semibold rounded-lg transition-colors mt-2">
                Save KBZPay
            </button>
        </form>
    </div>

    <!-- WavePay -->
    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 p-6 relative">
        <h3 class="text-lg font-bold mb-4 flex items-center gap-2 text-slate-800 dark:text-slate-100">
            <span class="w-8 h-8 rounded-full bg-yellow-100 text-yellow-600 flex items-center justify-center font-bold text-xs">W</span>
            WavePay
            <?php if(!empty($settings['wavepay']['is_default'])): ?><span class="ml-auto text-[10px] uppercase font-bold bg-green-100 text-green-700 px-2 py-0.5 rounded">Default</span><?php endif; ?>
        </h3>
        
        <form method="POST" action="payment_settings.php" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="method" value="wavepay">
            
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Account Name</label>
                <input type="text" name="account_name" required value="<?= e($settings['wavepay']['account_name'] ?? '') ?>" class="w-full rounded-lg border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm focus:ring-indigo-500 focus:border-indigo-500">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Phone Number</label>
                <input type="text" name="account_number" required value="<?= e($settings['wavepay']['account_number'] ?? '') ?>" placeholder="09xxxxxxxxx" class="w-full rounded-lg border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm focus:ring-indigo-500 focus:border-indigo-500">
            </div>
            
            <div class="flex items-center gap-2 pt-2">
                <input type="checkbox" name="is_default" id="wave_def" <?= !empty($settings['wavepay']['is_default']) ? 'checked' : '' ?> class="rounded text-indigo-600 focus:ring-indigo-500">
                <label for="wave_def" class="text-sm text-slate-600 dark:text-slate-400">Set as default</label>
            </div>
            
            <button type="submit" class="w-full py-2 bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-800 dark:text-slate-200 text-sm font-semibold rounded-lg transition-colors mt-2">
                Save WavePay
            </button>
        </form>
    </div>

    <!-- Bank Transfer -->
    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 p-6 relative">
        <h3 class="text-lg font-bold mb-4 flex items-center gap-2 text-slate-800 dark:text-slate-100">
            <span class="w-8 h-8 rounded-full bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 flex items-center justify-center font-bold text-xs"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"/></svg></span>
            Bank Transfer
            <?php if(!empty($settings['bank_transfer']['is_default'])): ?><span class="ml-auto text-[10px] uppercase font-bold bg-green-100 text-green-700 px-2 py-0.5 rounded">Default</span><?php endif; ?>
        </h3>
        
        <form method="POST" action="payment_settings.php" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="method" value="bank_transfer">
            
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Bank Name</label>
                <input type="text" name="bank_name" required value="<?= e($settings['bank_transfer']['bank_name'] ?? '') ?>" placeholder="e.g. KBZ Bank, AYA Bank" class="w-full rounded-lg border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm focus:ring-indigo-500 focus:border-indigo-500">
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Account Name</label>
                <input type="text" name="account_name" required value="<?= e($settings['bank_transfer']['account_name'] ?? '') ?>" class="w-full rounded-lg border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm focus:ring-indigo-500 focus:border-indigo-500">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Account Number</label>
                <input type="text" name="account_number" required value="<?= e($settings['bank_transfer']['account_number'] ?? '') ?>" class="w-full rounded-lg border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm focus:ring-indigo-500 focus:border-indigo-500">
            </div>
            
            <div class="flex items-center gap-2 pt-2">
                <input type="checkbox" name="is_default" id="bank_def" <?= !empty($settings['bank_transfer']['is_default']) ? 'checked' : '' ?> class="rounded text-indigo-600 focus:ring-indigo-500">
                <label for="bank_def" class="text-sm text-slate-600 dark:text-slate-400">Set as default</label>
            </div>
            
            <button type="submit" class="w-full py-2 bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-800 dark:text-slate-200 text-sm font-semibold rounded-lg transition-colors mt-2">
                Save Bank Transfer
            </button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
