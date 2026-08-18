<?php
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('c:\\wamp64\\www\\freelancer_job'));
foreach ($files as $file) {
    if ($file->getExtension() === 'php') {
        $c = file_get_contents($file->getPathname());
        if (preg_match_all('/SELECT.*?FROM milestones/is', $c, $m)) {
            foreach ($m[0] as $match) {
                if (stripos($match, 'SELECT 1 FROM') === false && stripos($match, 'SELECT COUNT(') === false && stripos($match, 'SUM') === false && stripos($match, 'freelancer_id') !== false) {
                    echo $file->getPathname() . "\n";
                    echo trim(preg_replace('/\s+/', ' ', $match)) . "\n---\n";
                }
            }
        }
    }
}
