<?php
// ============================================
// FILE: logout.php
// PURPOSE: Destroy session and logout
// ============================================

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed');
}

try {
    requireCSRF();
} catch (RuntimeException $e) {
    http_response_code(403);
    exit('Invalid or expired request token. Please reload and try again.');
}

$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
?>