<?php
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['email'] = 'admin@platform.com';
$_POST['password'] = 'admin123';
$_POST['csrf_token'] = 'test';
session_start();
$_SESSION['csrf_token'] = 'test';
$_SERVER['REQUEST_URI'] = '/freelancer_job/admin/login.php';
$_SERVER['HTTP_HOST'] = 'localhost';

ob_start();
require 'admin/login.php';
$output = ob_get_clean();

echo "Session after login: \n";
var_dump($_SESSION);

$headers = headers_list();
echo "\nHeaders:\n";
var_dump($headers);
