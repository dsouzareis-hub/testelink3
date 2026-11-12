<?php
/**
 * Endpoint único de movimentação dos Kanbans.
 * - Tester: move o cartão de teste (kanban_move()).
 * - Desenvolvedor: move o chamado (dev_move()).
 * - fetch (drag and drop): responde JSON. Requisição comum (sem JavaScript): grava flash e volta ao quadro.
 * Em ambos os casos a regra é aplicada no servidor, valendo para qualquer forma de alteração.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/kanban.php';

$ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
$user = current_user();
$isDev = $user && $user['role'] === 'developer';

function kanban_reply(bool $ajax, array $res, string $back, int $code = 200): void
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
    redirect($back . (!empty($_POST['project']) ? '?project=' . (int)$_POST['project'] : ''));
}

$back = $isDev ? 'dev_board.php' : 'my_tests.php';
if (!$user || !in_array($user['role'], ['tester', 'developer'], true)) {
    kanban_reply($ajax, ['ok' => false, 'message' => 'Sessão expirada ou sem permissão. Recarregue a página.', 'status' => ''], $back, 403);
}
if (!is_post()) {
    redirect($back);
}
$token = $_POST['csrf'] ?? '';
if (!is_string($token) || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
    kanban_reply($ajax, ['ok' => false, 'message' => 'Sessão expirada (CSRF). Recarregue a página.', 'status' => ''], $back, 419);
}

$result = $isDev
    ? dev_move(post_int('id'), post('status'), (int)$user['id'])
    : kanban_move(post_int('id'), post('status'), (int)$user['id']);
kanban_reply($ajax, $result, $back);
