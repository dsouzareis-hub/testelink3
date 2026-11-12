<?php
/**
 * Endpoint único de movimentação do Kanban.
 * - fetch (drag and drop): responde JSON.
 * - formulário comum (seletor "Mover para" sem JavaScript): grava flash e volta ao quadro.
 * Ambos passam por kanban_move(), então a regra de conclusão vale para qualquer forma de alteração.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/kanban.php';

$ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
$user = current_user();

function kanban_reply(bool $ajax, array $res, int $code = 200): void
{
    if ($ajax) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($res['message'] !== '') {
        flash($res['ok'] ? 'success' : 'error', $res['message']);
    }
    redirect('my_tests.php' . (!empty($_POST['project']) ? '?project=' . (int)$_POST['project'] : ''));
}

if (!$user || $user['role'] !== 'tester') {
    kanban_reply($ajax, ['ok' => false, 'message' => 'Sessão expirada ou sem permissão. Recarregue a página.', 'status' => ''], 403);
}
if (!is_post()) {
    redirect('my_tests.php');
}
$token = $_POST['csrf'] ?? '';
if (!is_string($token) || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
    kanban_reply($ajax, ['ok' => false, 'message' => 'Sessão expirada (CSRF). Recarregue a página.', 'status' => ''], 419);
}

kanban_reply($ajax, kanban_move(post_int('id'), post('status'), (int)$user['id']));
