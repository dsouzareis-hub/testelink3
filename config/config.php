<?php
/**
 * Configuração do TestLink 3.0
 * Ajuste as credenciais do MySQL conforme o seu ambiente.
 */
define('DB_HOST', 'localhost');
define('DB_NAME', 'testlink3');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'TestLink 3.0');

// Pasta das evidências (protegida por .htaccess; acesso apenas via file.php)
define('UPLOAD_DIR', dirname(__DIR__) . '/uploads/evidences/');
define('MAX_FILES_PER_EXECUTION', 10);

date_default_timezone_set('America/Sao_Paulo');
