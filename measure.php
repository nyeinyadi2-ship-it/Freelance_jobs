<?php
require 'config/db.php';
$freelancer = $conn->query("SELECT email FROM users WHERE role='freelancer' LIMIT 1")->fetch_assoc()['email'];
$company = $conn->query("SELECT email FROM users WHERE role='company' LIMIT 1")->fetch_assoc()['email'];
$conn->query("UPDATE users SET password='" . password_hash('password123', PASSWORD_DEFAULT) . "' WHERE email IN ('$freelancer', '$company')");

function curl_get($url, $cookie_file) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

function curl_post($url, $data, $cookie_file) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

function login($email, $password, $cookie_file) {
    $url = "http://localhost/freelancer_job/auth/login.php";
    $html = curl_get($url, $cookie_file);
    preg_match('/<input type="hidden" name="csrf_token" value="(.*?)">/', $html, $matches);
    $csrf = $matches[1] ?? '';
    curl_post($url, ['email' => $email, 'password' => $password, 'csrf_token' => $csrf], $cookie_file);
}

// Clear perf.log
file_put_contents('perf.log', '');

$fc = __DIR__ . '/fc.txt';
if(file_exists($fc)) unlink($fc);
login($freelancer, 'password123', $fc);
curl_get("http://localhost/freelancer_job/freelancer/browse_jobs.php", $fc);
curl_get("http://localhost/freelancer_job/freelancer/browse_jobs.php?skill=PHP", $fc);
curl_get("http://localhost/freelancer_job/freelancer/view_job.php?id=1", $fc); // ID might be different, let's get a random job ID
$job_id = $conn->query("SELECT id FROM jobs LIMIT 1")->fetch_assoc()['id'] ?? 1;
curl_get("http://localhost/freelancer_job/freelancer/view_job.php?id=$job_id", $fc);
curl_get("http://localhost/freelancer_job/freelancer/find_freelancers.php", $fc);
$fc_id = $conn->query("SELECT id FROM freelancers LIMIT 1")->fetch_assoc()['id'] ?? 1;
curl_get("http://localhost/freelancer_job/freelancer/view_freelancer.php?id=$fc_id", $fc);

$cc = __DIR__ . '/cc.txt';
if(file_exists($cc)) unlink($cc);
login($company, 'password123', $cc);
curl_get("http://localhost/freelancer_job/company/manage_jobs.php", $cc);
curl_get("http://localhost/freelancer_job/company/view_job.php?id=$job_id", $cc);
curl_get("http://localhost/freelancer_job/company/find_freelancers.php", $cc);
curl_get("http://localhost/freelancer_job/company/view_freelancer.php?id=$fc_id", $cc);

echo "Done\n";
