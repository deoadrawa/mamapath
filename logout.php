<?php
// ============================================
// FILE: logout.php
// PURPOSE: Destroy session and logout
// ============================================

session_start();
session_destroy();
header('Location: login.php');
exit;
?>