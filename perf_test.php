<?php
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['DOCUMENT_ROOT'] = 'c:/wamp64/www';

$url = $argv[1] ?? '/freelancer_job/index.php';
$user_id = (int)($argv[2] ?? 1);
$role = $argv[3] ?? 'company';
$profile_id = (int)($argv[4] ?? 1);

$_SERVER['REQUEST_URI'] = $url;
$_SERVER['SCRIPT_NAME'] = $url;

$parts = explode('?', $url);
if (count($parts) > 1) {
    $_SERVER['SCRIPT_NAME'] = $parts[0];
    parse_str($parts[1], $_GET);
}

session_start();
$_SESSION['user_id'] = $user_id;
$_SESSION['role'] = $role;
$_SESSION['username'] = 'test';
$_SESSION['profile_id'] = $profile_id;

ob_start();
require "c:/wamp64/www" . $_SERVER['SCRIPT_NAME'];
ob_end_clean();
