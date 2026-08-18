<?php
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['email'] = 'admin@platform.com';
$_POST['password'] = 'wrongpassword';
$_POST['csrf_token'] = 'test';
session_start();
$_SESSION['csrf_token'] = 'test';
$_SERVER['REQUEST_URI'] = '/freelancer_job/admin/login.php';
$_SERVER['HTTP_HOST'] = 'localhost';

ob_start();
require 'admin/login.php';
$output = ob_get_clean();

echo "Login failed as expected. Error message: " . $error;
