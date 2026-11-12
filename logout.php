<?php
require __DIR__ . '/includes/bootstrap.php';

// Encerra a sessão e remove o cookie.
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
}
session_destroy();
redirect('login.php');
