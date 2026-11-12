<?php
/**
 * Notificações dentro do sistema (sino na barra superior).
 * Regra: só é notificado quem PRECISA REALIZAR UMA AÇÃO — nunca quem acabou de fazê-la.
 * Cada aviso pode ter um "ref" (ex.: decide:12, dev:12, retest:12, user:5); quando a ação é feita,
 * notify_resolve() dá baixa automática no aviso.
 */

function notify(int $userId, string $message, ?string $link = null, ?string $ref = null): void
{
    q('INSERT INTO notifications (user_id, message, link, ref) VALUES (?,?,?,?)',
        [$userId, mb_substr($message, 0, 255), $link, $ref]);
}

/** Avisa todos os administradores ativos (menos quem executou a ação). */
function notify_admins(string $message, ?string $link = null, ?string $ref = null, ?int $exceptUserId = null): void
{
    $ids = q("SELECT id FROM users WHERE role = 'admin' AND active = 1 AND status = 'approved'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        if ((int)$id !== (int)$exceptUserId) {
            notify((int)$id, $message, $link, $ref);
        }
    }
}

/** Dá baixa nos avisos pendentes de uma ação já realizada (opcionalmente só de um usuário). */
function notify_resolve(string $ref, ?int $userId = null): void
{
    if ($userId === null) {
        q('UPDATE notifications SET read_at = NOW() WHERE ref = ? AND read_at IS NULL', [$ref]);
    } else {
        q('UPDATE notifications SET read_at = NOW() WHERE ref = ? AND user_id = ? AND read_at IS NULL', [$ref, $userId]);
    }
}

function unread_notifications(int $userId): int
{
    return (int)q('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId])->fetchColumn();
}
