<?php
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('dashboard.php');
}

// Funções que podem ser solicitadas no cadastro (administrador só é criado por outro administrador)
$roles = ['tester' => ROLES['tester'], 'developer' => ROLES['developer'], 'viewer' => ROLES['viewer']];

$error = null;
$done = false;
if (is_post()) {
    csrf_check();
    $name = post('name');
    $email = mb_strtolower(post('email'));
    $role = post('role');
    $pass = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    // Limite simples de solicitações por sessão (evita flood de cadastros)
    $_SESSION['reg_count'] = ($_SESSION['reg_count'] ?? 0);

    if ($_SESSION['reg_count'] >= 5) {
        $error = 'Muitas solicitações nesta sessão. Tente novamente mais tarde.';
    } elseif ($name === '' || mb_strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Informe seu nome e um e-mail válido.';
    } elseif (!isset($roles[$role])) {
        $error = 'Selecione a sua função.';
    } elseif (strlen($pass) < 8) {
        $error = 'A senha deve ter ao menos 8 caracteres.';
    } elseif ($pass !== $confirm) {
        $error = 'A confirmação não confere com a senha.';
    } elseif (($existing = q('SELECT id, status FROM users WHERE email = ?', [$email])->fetch()) && $existing['status'] !== 'rejected') {
        $error = $existing['status'] === 'pending'
            ? 'Já existe uma solicitação pendente para este e-mail. Aguarde a análise do administrador.'
            : 'Já existe um cadastro com este e-mail.';
    } else {
        // Fica inativo e pendente até o administrador aprovar. O perfil escolhido AQUI é o único que o admin pode aprovar.
        // Se a solicitação anterior foi recusada (ex.: perfil errado), esta é uma NOVA solicitação com o mesmo e-mail.
        if ($existing) {
            q("UPDATE users SET name = ?, password_hash = ?, role = ?, active = 0, status = 'pending', self_registered = 1 WHERE id = ?",
                [$name, password_hash($pass, PASSWORD_DEFAULT), $role, $existing['id']]);
            $newId = (int)$existing['id'];
        } else {
            q('INSERT INTO users (name, email, password_hash, role, active, status, self_registered) VALUES (?,?,?,?,0,?,1)',
                [$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role, 'pending']);
            $newId = (int)db()->lastInsertId();
        }
        // Só o administrador precisa agir: é ele quem aprova
        notify_admins('Nova solicitação de cadastro: ' . $name . ' (' . ROLES[$role] . ')', 'users.php', 'user:' . $newId);
        audit('cadastro_solicitado', 'user', $newId, $name . ' <' . $email . '> — ' . ROLES[$role], null, $name);
        $_SESSION['reg_count']++;
        $done = true;
    }
}

$pageTitle = 'Solicitar cadastro';
include __DIR__ . '/includes/header.php';
?>
<div class="auth-card">
    <div class="logo">
        <img src="assets/img/favicon.png" alt="">
        <h1><?= e(get_setting('system_name', APP_NAME)) ?></h1>
    </div>
    <?php if ($done): ?>
        <div class="alert alert-success">Solicitação enviada! Um administrador precisa aprovar o seu cadastro antes do primeiro acesso.</div>
        <a class="btn" href="login.php">Voltar ao login</a>
    <?php else: ?>
    <p class="muted">Solicitar cadastro — o acesso só é liberado após aprovação do administrador.</p>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <label class="field">Nome completo <input type="text" name="name" required maxlength="120" value="<?= e($_POST['name'] ?? '') ?>"></label>
        <label class="field">E-mail <input type="email" name="email" required maxlength="160" value="<?= e($_POST['email'] ?? '') ?>"></label>
        <label class="field">Função
            <select name="role" required>
                <option value="">Selecione…</option>
                <?= options($roles, $_POST['role'] ?? null) ?>
            </select>
        </label>
        <label class="field">Senha <input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
        <label class="field">Confirmar senha <input type="password" name="confirm" required minlength="8" autocomplete="new-password"></label>
        <button class="btn" type="submit">Enviar solicitação</button>
    </form>
    <p class="auth-switch">Já tem conta? <a href="login.php">Entrar</a></p>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
