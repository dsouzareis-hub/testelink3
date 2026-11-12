<?php
/**
 * Bootstrap: carrega configuração, inicia sessão segura e inclui os módulos.
 */
require __DIR__ . '/../config/config.php';

session_name('testlink_sid');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']),
]);
session_start();

// Cabeçalhos básicos de segurança
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/upload.php';
require __DIR__ . '/defects_lib.php';
