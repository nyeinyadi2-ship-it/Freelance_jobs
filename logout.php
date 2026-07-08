<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

session_destroy();
session_start();

redirect('index.php');
