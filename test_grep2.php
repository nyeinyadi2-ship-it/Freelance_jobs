<?php
$c = file_get_contents('company/view_freelancer.php');
if (preg_match_all('/SELECT.*?FROM milestones/is', $c, $m)) {
    foreach ($m[0] as $match) {
        echo trim(preg_replace('/\s+/', ' ', $match)) . "\n---\n";
    }
}
