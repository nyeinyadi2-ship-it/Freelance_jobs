<?php
require 'config/db.php';
$r = $conn->query("SELECT id, company_id, title, status, created_at FROM jobs ORDER BY id DESC LIMIT 10");
while($row = $r->fetch_assoc()) {
    print_r($row);
}
