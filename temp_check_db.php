<?php
require 'config/db.php';
try {
    $res = $conn->query('DESCRIBE milestone_history');
    echo 'Table milestone_history exists. ';
} catch(Exception $e) {
    echo 'Table milestone_history missing. ';
}

try {
    $res2 = $conn->query('DESCRIBE milestones');
    while($row = $res2->fetch_assoc()) {
        if ($row['Field'] == 'status') {
            echo 'Milestone status: ' . $row['Type'] . '. ';
        }
        if (strpos($row['Field'], 'extension') !== false || strpos($row['Field'], 'cancel') !== false) {
            echo 'Has ' . $row['Field'] . '. ';
        }
    }
} catch(Exception $e) {
    echo 'Error checking milestones: ' . $e->getMessage();
}
?>
