<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

if (is_post()) {
    csrf_check();
    $current = $_POST['current'] ?? '';
    $new = $_POST['new'] ?? '';
    $confirm = $_POST['confirm'] ?? '';
    $hash = q('SELECT password_hash FROM users WHERE id = ?', [$user['id']])->fetchColumn();

    if (!password_verify($current, $hash)) {
        flash('error', 'Senha atual incorreta.');
    } elseif (strlen($new) < 8) {
        flash('error', 'A nova senha deve ter ao menos 8 caracteres.');
    } elseif ($new !== $confirm) {
        flash('error', 'A confirmação não confere com a nova senha.');
    } else {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        session_regenerate_id(true);
        flash('success', 'Senha alterada com sucesso.');
    }
    redirect('profile.php');
}

$pageTitle = 'Meu perfil';
$active = 'profile.php';
include __DIR__ . '/includes/header.php';
?>
<div class="card" style="max-width:520px">
    <h2><?= e($user['name']) ?></h2>
    <p class="muted"><?= e($user['email']) ?> · <?= e(ROLES[$user['role']]) ?></p>
    <h3>Alterar senha</h3>
    <form method="post">
        <?= csrf_field() ?>
        <div class="grid">
            <label class="field">Senha atual <input type="password" name="current" required autocomplete="current-password"></label>
            <label class="field">Nova senha <input type="password" name="new" required minlength="8" autocomplete="new-password"></label>
            <label class="field">Confirmar nova senha <input type="password" name="confirm" required minlength="8" autocomplete="new-password"></label>
        </div>
        <div class="form-actions"><button class="btn" type="submit">Salvar senha</button></div>
    </form>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
