<?php
require __DIR__ . '/includes/bootstrap.php';
require_role('admin');

$f = basename($_GET['file'] ?? '');
$path = BACKUP_DIR . $f;
if (!preg_match(BACKUP_NAME_RE, $f) || !is_file($path)) {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}
header('Content-Type: ' . (str_ends_with($f, '.gz') ? 'application/gzip' : 'application/sql'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . $f . '"');
header('Cache-Control: private, no-store');
readfile($path);
