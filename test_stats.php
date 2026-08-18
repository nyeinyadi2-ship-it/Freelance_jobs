<?php
require 'config/db.php';
require 'config/escrow.php';
try {
    $stats = get_freelancer_earnings_stats($conn, 1);
    print_r($stats);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
