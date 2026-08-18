<?php
require 'c:/wamp64/www/freelancer_job/config/db.php';
foreach($conn->query('SELECT id, role FROM users LIMIT 10')->fetch_all(MYSQLI_ASSOC) as $r) {
    echo $r['id'].':'.$r['role']."\n";
}
