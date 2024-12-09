<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (time() - $_SESSION['timeout'] > 1800) { // 30 minutes timeout
    session_destroy();
    header('Location: login.php');
    exit;
}
$_SESSION['timeout'] = time();
?>
