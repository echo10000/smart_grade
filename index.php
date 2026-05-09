<?php
// index.php: entry point; redirect to login page or dashboard if already logged in
session_start();
if (isset($_SESSION['user'])) {
    $r = $_SESSION['user']['role'];
    if ($r === 'admin') {
        header('Location: admin_dashboard.php');
    } elseif ($r === 'teacher') {
        header('Location: teacher_dashboard.php');
    } else {
        header('Location: student_dashboard.php');
    }
    exit;
}
// If not logged in, show login page
require 'login.php';