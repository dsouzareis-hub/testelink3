<?php
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('dashboard.php');
}

$error = null;
if (is_post()) {
    csrf_check();
    $error = attempt_login(mb_strtolower(post('email')), $_POST['password'] ?? '');
    if ($error === null) {
        redirect('dashboard.php');
    }
}

$pageTitle = 'Entrar';
include __DIR__ . '/includes/header.php';
?>
<div class="auth-card">
    <div class="logo">
        <img src="assets/img/favicon.png" alt="">
        <h1><?= e(get_setting('system_name', APP_NAME)) ?></h1>
    </div>
    <p class="muted">Gerenciamento de testes manuais</p>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="on">
        <?= csrf_field() ?>
        <label class="field">E-mail
            <input type="email" name="email" required autofocus value="<?= e($_POST['email'] ?? '') ?>">
        </label>
        <label class="field">Senha
            <input type="password" name="password" required>
        </label>
        <button class="btn" type="submit">Entrar</button>
    </form>
    <p class="auth-switch">Não tem acesso? <a href="register.php">Solicitar cadastro</a></p>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
