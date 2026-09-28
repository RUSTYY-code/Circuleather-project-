<?php
session_start();

$timeout = 5400; // 90 minuten

if (!isset($_SESSION['ingelogd'])) {
    header("Location: login.php");
    exit();
}

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
    session_destroy();
    header("Location: login.php?timeout=1");
    exit();
}

$_SESSION['last_activity'] = time();