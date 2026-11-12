<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$uid = (int)$user['id'];

// Abrir um aviso: dá baixa e vai para o destino
if ($go = get_int('go')) {
    $n = q('SELECT link FROM notifications WHERE id = ? AND user_id = ?', [$go, $uid])->fetch();
    if ($n) {
        q('UPDATE notifications SET read_at = COALESCE(read_at, NOW()) WHERE id = ?', [$go]);
        redirect($n['link'] && !preg_match('#^[a-z]+:#i', $n['link']) ? $n['link'] : 'notifications.php');
    }
    redirect('notifications.php');
}

if (is_post()) {
    csrf_check();
    if (post('action') === 'read_all') {
        q('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [$uid]);
        flash('success', 'Avisos marcados como lidos.');
    } elseif (post('action') === 'read' && ($id = post_int('id'))) {
        q('UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ?', [$id, $uid]);
    }
    redirect('notifications.php');
}

$rows = q('SELECT * FROM notifications WHERE user_id = ? ORDER BY (read_at IS NULL) DESC, id DESC LIMIT 60', [$uid])->fetchAll();
$unread = unread_notifications($uid);

$pageTitle = 'Notificações';
$active = '';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
    <div class="card-head">
        <h2>Avisos para você <?= $unread ? '<small class="muted">(' . $unread . ' pendente' . ($unread > 1 ? 's' : '') . ')</small>' : '' ?></h2>
        <?php if ($unread): ?>
        <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="read_all">
            <button class="btn btn-ghost btn-sm" type="submit"><?= icon('check', 14) ?> Marcar todos como lidos</button></form>
        <?php endif; ?>
    </div>
    <p class="muted" style="margin-top:0">Você só recebe aviso de algo que <strong>precisa da sua ação</strong>. Quando a ação é feita, o aviso some sozinho.</p>
    <?php if (!$rows): ?>
        <p class="empty">Nada pendente por aqui.</p>
    <?php else: ?>
    <ul class="notif-list">
        <?php foreach ($rows as $n): ?>
            <li class="<?= $n['read_at'] ? 'read' : 'unread' ?>">
                <a href="notifications.php?go=<?= (int)$n['id'] ?>">
                    <span class="notif-dot" aria-hidden="true"></span>
                    <span class="notif-text"><?= e($n['message']) ?><small><?= fmt_dt($n['created_at']) ?><?= $n['read_at'] ? ' · concluído/lido' : '' ?></small></span>
                    <span class="notif-go"><?= icon('eye', 14) ?> Abrir</span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
