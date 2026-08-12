<?php

$dir = new RecursiveDirectoryIterator(__DIR__, RecursiveDirectoryIterator::SKIP_DOTS);
$it = new RecursiveIteratorIterator($dir);
$files = new RegexIterator($it, '/^.+\.(php|js|html)$/i', RecursiveRegexIterator::GET_MATCH);

$count = 0;
foreach ($files as $fileInfo) {
    $file = $fileInfo[0];
    
    // Skip this script itself
    if (basename($file) === 'replace.php') continue;
    
    // Skip uploads directory, vendor, .git, .gemini
    if (strpos($file, '\\uploads\\') !== false || strpos($file, '/uploads/') !== false) continue;
    if (strpos($file, '\\vendor\\') !== false || strpos($file, '/vendor/') !== false) continue;
    if (strpos($file, '\\.git\\') !== false || strpos($file, '/.git/') !== false) continue;
    if (strpos($file, '\\.gemini\\') !== false || strpos($file, '/.gemini/') !== false) continue;
    if (strpos($file, '\\node_modules\\') !== false || strpos($file, '/node_modules/') !== false) continue;

    $content = file_get_contents($file);
    $newContent = $content;

    // 1. PHP echo values
    $newContent = preg_replace('/\$\<\?\=\s*(.*?)\s*\?\>/s', '<?=' . ' $1 ' . '?> MMK', $newContent);
    $newContent = preg_replace('/(\>)\s*\$\<\?\=\s*(.*?)\s*\?\>/s', '$1<?=' . ' $2 ' . '?> MMK', $newContent);

    // 2. Labels
    $newContent = str_replace('Amount ($)', 'Amount (MMK)', $newContent);
    $newContent = str_replace('Budget ($)', 'Budget (MMK)', $newContent);
    $newContent = str_replace('Hourly Rate ($)', 'Hourly Rate (MMK)', $newContent);

    // 3. String concatenations / Hardcoded messages
    $newContent = str_replace('Withdrawal request for $" . number_format($amount, 2) . "', 'Withdrawal request for " . number_format($amount, 2) . " MMK"', $newContent);
    $newContent = str_replace('Minimum withdrawal amount is $10.00.', 'Minimum withdrawal amount is 10.00 MMK.', $newContent);
    $newContent = str_replace('Budget must be at least $1.00.', 'Budget must be at least 1.00 MMK.', $newContent);
    
    // 4. JS strings / html defaults
    $newContent = str_replace('$0.00', '0.00 MMK', $newContent);
    $newContent = preg_replace('/(?<![a-zA-Z_\.])\$0(?!\.)/', '0 MMK', $newContent);
    $newContent = str_replace("'$' + budget.toLocaleString('en')", "budget.toLocaleString('en') + ' MMK'", $newContent);
    $newContent = str_replace('\'Approve this milestone and release $\' . number_format', '\'Approve this milestone and release \' . number_format', $newContent);
    $newContent = str_replace('? payment?', ' MMK payment?', $newContent);
    $newContent = str_replace('\'Fund this milestone with $\' . number_format', '\'Fund this milestone with \' . number_format', $newContent);
    $newContent = str_replace('via Escrow?', 'MMK via Escrow?', $newContent);
    
    // Inline spans
    $newContent = str_replace('<span class="text-slate-500 sm:text-sm">$</span>', '<span class="text-slate-500 sm:text-sm">MMK</span>', $newContent);
    $newContent = str_replace('<div class="mh-job-budget-icon">$</div>', '<div class="mh-job-budget-icon">MMK</div>', $newContent);

    // specific occurrences
    $newContent = str_replace('$15,000', '15,000 MMK', $newContent);
    
    // For approve and pay:
    $newContent = preg_replace('/Approve \& Pay \$\<\?\=\s*number_format/', 'Approve & Pay <?=' . ' number_format', $newContent);
    
    // For JS float toFixed
    $newContent = str_replace("'$' + parseFloat", "parseFloat", $newContent);
    // Be careful with replacing toFixed globally, let's just use the exact string found
    $newContent = str_replace("document.getElementById('inv-amount').textContent = parseFloat(data.amount).toFixed(2);", "document.getElementById('inv-amount').textContent = parseFloat(data.amount).toFixed(2) + ' MMK';", $newContent);

    // Also look for = '$' + in general JS
    $newContent = preg_replace('/=\s*\'\$\'\s*\+\s*([^;]+);/', '= $1 + \' MMK\';', $newContent);
    
    // Replace span with $ before PHP
    $newContent = preg_replace('/(\>)\s*\$\<\?\=\s*(.*?)\s*\?\>/', '$1<?=' . ' $2 ' . '?> MMK', $newContent);

    if ($newContent !== $content) {
        file_put_contents($file, $newContent);
        echo "Updated: $file\n";
        $count++;
    }
}

echo "Total files updated: $count\n";
