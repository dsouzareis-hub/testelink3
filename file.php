<?php
/**
 * Entrega evidências (imagens) após checar autenticação e permissão.
 * Os arquivos ficam em uploads/evidences, bloqueados para acesso direto.
 */
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$uid = (int)$user['id'];

// Logo da empresa (qualquer usuário logado pode ver)
if (isset($_GET['logo'])) {
    $p = logo_path();
    $mime = $p ? (new finfo(FILEINFO_MIME_TYPE))->file($p) : '';
    if (!$p || !isset(ALLOWED_IMAGE_TYPES[$mime])) { http_response_code(404); exit; }
    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=300');
    readfile($p);
    exit;
}

// Foto anexada a um comentário de defeito: mesma regra de visibilidade do defeito
if (isset($_GET['att'])) {
    $att = q('SELECT da.stored_name, da.mime, d.assigned_to, d.status, d.reported_by, d.case_id
              FROM defect_attachments da JOIN defects d ON d.id = da.defect_id WHERE da.id = ?', [get_int('att')])->fetch();
    $path = $att ? UPLOAD_DIR . basename($att['stored_name']) : '';
    if (!$att || !can_view_defect($user, $att) || !is_file($path) || !isset(ALLOWED_IMAGE_TYPES[$att['mime']])) {
        http_response_code($att ? 403 : 404);
        exit('Arquivo indisponível.');
    }
    header('Content-Type: ' . $att['mime']);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline');
    header('Cache-Control: private, max-age=3600');
    readfile($path);
    exit;
}

$ev = q('SELECT ev.stored_name, ev.mime, ex.id exec_id, ex.tester_id, ex.case_id
         FROM evidences ev JOIN executions ex ON ex.id = ev.execution_id WHERE ev.id = ?', [get_int('id')])->fetch();

$allowed = false;
if ($ev) {
    switch ($user['role']) {
        case 'admin':
        case 'viewer':
            $allowed = true;
            break;
        case 'tester':
            $allowed = (int)$ev['tester_id'] === $uid || tester_has_case($uid, (int)$ev['case_id']);
            break;
        case 'developer':
            // Só enquanto o chamado está com ele (aberto/em andamento)
            $allowed = (bool)q("SELECT 1 FROM defects WHERE execution_id = ? AND assigned_to = ? AND status IN ('open','fixing')", [$ev['exec_id'], $uid])->fetchColumn();
            break;
    }
}

$path = $ev ? UPLOAD_DIR . basename($ev['stored_name']) : '';
if (!$allowed || !is_file($path) || !isset(ALLOWED_IMAGE_TYPES[$ev['mime']])) {
    http_response_code($ev && !$allowed ? 403 : 404);
    exit('Arquivo indisponível.');
}

header('Content-Type: ' . $ev['mime']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline');
header('Cache-Control: private, max-age=3600');
readfile($path);
