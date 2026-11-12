<?php
/**
 * Autenticação por sessão e controle de acesso por perfil.
 */

/** Usuário logado (recarregado do banco a cada requisição para respeitar desativações). */
function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    if (empty($_SESSION['uid'])) {
        return $user = null;
    }
    $row = q('SELECT id, name, email, role, active FROM users WHERE id = ?', [$_SESSION['uid']])->fetch();
    if (!$row || !$row['active']) {
        unset($_SESSION['uid']);
        return $user = null;
    }
    return $user = $row;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        redirect('login.php');
    }
    return $u;
}

/** Exige que o usuário tenha um dos perfis informados; caso contrário exibe 403. */
function require_role(string ...$roles): array
{
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) {
        forbidden();
    }
    return $u;
}

function has_role(string ...$roles): bool
{
    $u = current_user();
    return $u && in_array($u['role'], $roles, true);
}

function forbidden(): void
{
    http_response_code(403);
    $pageTitle = 'Acesso negado';
    $active = '';
    include __DIR__ . '/header.php';
    echo '<div class="card"><h2>Acesso negado</h2><p>Você não tem permissão para acessar esta página.</p>'
        . '<p><a class="btn" href="dashboard.php">Voltar ao painel</a></p></div>';
    include __DIR__ . '/footer.php';
    exit;
}

function not_found(): void
{
    http_response_code(404);
    $pageTitle = 'Não encontrado';
    $active = '';
    include __DIR__ . '/header.php';
    echo '<div class="card"><h2>Registro não encontrado</h2><p><a class="btn" href="dashboard.php">Voltar ao painel</a></p></div>';
    include __DIR__ . '/footer.php';
    exit;
}

/**
 * Tenta autenticar. Aplica um limite simples de tentativas por sessão.
 * Retorna null em caso de sucesso ou a mensagem de erro.
 */
function attempt_login(string $email, string $password): ?string
{
    $now = time();
    if (($_SESSION['login_block_until'] ?? 0) > $now) {
        return 'Muitas tentativas. Aguarde alguns instantes e tente novamente.';
    }

    $user = q('SELECT id, password_hash, active, status FROM users WHERE email = ?', [$email])->fetch();
    // password_verify sempre executado para não vazar existência do usuário pelo tempo de resposta
    $hash = $user['password_hash'] ?? '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ0123';
    $passwordOk = password_verify($password, $hash) && $user;

    // Só revela o estado do cadastro a quem provou conhecer a senha
    if ($passwordOk && $user['status'] === 'pending') {
        return 'Seu cadastro ainda aguarda aprovação do administrador. Você poderá entrar assim que for aprovado.';
    }
    if ($passwordOk && $user['status'] === 'rejected') {
        return 'Sua solicitação de cadastro foi recusada. Procure o administrador.';
    }
    $ok = $passwordOk && $user['active'] && $user['status'] === 'approved';

    if (!$ok) {
        $_SESSION['login_fails'] = ($_SESSION['login_fails'] ?? 0) + 1;
        if ($_SESSION['login_fails'] >= 5) {
            $_SESSION['login_block_until'] = $now + 60;
            $_SESSION['login_fails'] = 0;
        }
        audit('login_falhou', 'user', $user ? (int)$user['id'] : null, 'E-mail informado: ' . mb_substr($email, 0, 120), $user ? (int)$user['id'] : null, $email);
        return 'E-mail ou senha inválidos, ou usuário inativo.';
    }

    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$user['id'];
    audit('login', 'user', (int)$user['id'], '', (int)$user['id'], $email);
    unset($_SESSION['login_fails'], $_SESSION['login_block_until']);
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    return null;
}

/** O tester pode executar/ver este caso? (precisa estar atribuído a ele) */
function tester_has_case(int $userId, int $caseId): bool
{
    return (bool)q('SELECT 1 FROM assignments WHERE case_id = ? AND tester_id = ?', [$caseId, $userId])->fetchColumn();
}
