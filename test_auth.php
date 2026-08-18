<?php
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['DOCUMENT_ROOT'] = 'c:/wamp64/www';
$_SERVER['REQUEST_URI'] = '/freelancer_job/';
$_SERVER['SCRIPT_NAME'] = '/freelancer_job/';

session_start();
$_SESSION['user_id'] = 6;
$_SESSION['role'] = 'freelancer';
$_SESSION['username'] = 'test';

require 'c:/wamp64/www/freelancer_job/config/db.php';
require 'c:/wamp64/www/freelancer_job/config/auth.php';

$fl_user = current_user();
$fl_uid = (int) $fl_user['user_id'];
$fl_freelancer_id = get_freelancer_id($conn, $fl_uid);
var_dump($fl_freelancer_id);
