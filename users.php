<?php
require __DIR__ . '/includes/bootstrap.php';
$me = require_role('admin');

/* ---------- Ações (POST) ---------- */
if (is_post()) {
    csrf_check();
    $action = post('action');
    $id = post_int('id');

    if ($action === 'save') {
        $name = post('name');
        $email = mb_strtolower(post('email'));
        $role = post('role');
        $active = isset($_POST['active']) ? 1 : 0;
        $password = $_POST['password'] ?? '';
        $err = null;

        // Perfil escolhido pela PRÓPRIA pessoa no cadastro não pode ser alterado pelo administrador;
        // solicitações pendentes/recusadas só podem ser aprovadas ou recusadas (nunca editadas).
        $current = $id ? q('SELECT role, status, self_registered FROM users WHERE id = ?', [$id])->fetch() : null;
        if ($current && $current['status'] !== 'approved') {
            flash('error', 'Esta solicitação de cadastro ainda não foi aprovada: use Aprovar ou Recusar.');
            redirect('users.php');
        }
        if ($current && (int)$current['self_registered'] === 1) {
            $role = $current['role'];
        }

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Informe nome e um e-mail válido.';
        } elseif (!isset(ROLES[$role])) {
            $err = 'Perfil inválido.';
        } elseif (q('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $id])->fetchColumn()) {
            $err = 'Já existe um usuário com este e-mail.';
        } elseif (!$id && strlen($password) < 8) {
            $err = 'A senha inicial deve ter ao menos 8 caracteres.';
        } elseif ($id && $password !== '' && strlen($password) < 8) {
            $err = 'A nova senha deve ter ao menos 8 caracteres.';
        } elseif ($id === (int)$me['id'] && ($role !== 'admin' || !$active)) {
            $err = 'Você não pode remover seu próprio acesso de administrador.';
        }

        if ($err) {
            flash('error', $err);
            redirect('users.php?' . ($id ? 'edit=' . $id : 'new=1'));
        }
        if ($id) {
            q('UPDATE users SET name=?, email=?, role=?, active=? WHERE id=?', [$name, $email, $role, $active, $id]);
            if ($password !== '') {
                q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($password, PASSWORD_DEFAULT), $id]);
            }
            audit('usuario_editado', 'user', $id, $name . ' — ' . ROLES[$role] . ($active ? '' : ' (inativo)') . ($password !== '' ? ' — senha redefinida' : ''));
            flash('success', 'Usuário atualizado.');
        } else {
            q('INSERT INTO users (name, email, password_hash, role, active) VALUES (?,?,?,?,?)',
                [$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $active]);
            audit('usuario_criado', 'user', (int)db()->lastInsertId(), $name . ' <' . $email . '> — ' . ROLES[$role]);
            flash('success', 'Usuário criado.');
        }
    } elseif ($action === 'approve' && $id) {
        // Aprova a solicitação COM O PERFIL QUE A PESSOA ESCOLHEU (guardado no banco). O admin não escolhe nem altera:
        // qualquer "role" enviado no formulário é ignorado. Perfil errado = recusar e pedir uma nova solicitação.
        $req = q("SELECT role FROM users WHERE id = ? AND status = 'pending'", [$id])->fetch();
        if (!$req) {
            flash('error', 'Solicitação não encontrada.');
        } else {
            q("UPDATE users SET status = 'approved', active = 1 WHERE id = ? AND status = 'pending'", [$id]);
            notify_resolve('user:' . $id);                          // a ação foi feita: baixa o aviso de todos os admins
            audit('cadastro_aprovado', 'user', $id, 'Perfil solicitado pelo usuário: ' . ROLES[$req['role']]);
            flash('success', 'Cadastro aprovado como ' . ROLES[$req['role']] . ' (perfil escolhido pelo usuário). Ele já pode entrar.');
        }
    } elseif ($action === 'reject' && $id) {
        $n = q("UPDATE users SET status = 'rejected', active = 0 WHERE id = ? AND status = 'pending'", [$id])->rowCount();
        if ($n) {
            notify_resolve('user:' . $id);
            audit('cadastro_recusado', 'user', $id);
        }
        flash($n ? 'success' : 'error', $n ? 'Solicitação recusada. Se o perfil estava errado, peça para a pessoa fazer uma nova solicitação de cadastro com o e-mail dela.' : 'Solicitação não encontrada.');
    } elseif ($action === 'toggle' && $id && $id !== (int)$me['id']) {
        q("UPDATE users SET active = 1 - active WHERE id = ? AND status = 'approved'", [$id]);
        audit('usuario_status', 'user', $id);
        flash('success', 'Status do usuário alterado.');
    } elseif ($action === 'delete' && $id && $id !== (int)$me['id']) {
        // Proteção contra perda: quem já tem histórico (execuções, chamados, comentários) NÃO pode ser excluído, só desativado,
        // porque a exclusão apagaria esse histórico junto.
        $hist = (int)q('SELECT (SELECT COUNT(*) FROM executions WHERE tester_id = ?)
                      + (SELECT COUNT(*) FROM execution_comments WHERE user_id = ?)
                      + (SELECT COUNT(*) FROM defects WHERE reported_by = ? OR assigned_to = ? OR retest_tester_id = ?)
                      + (SELECT COUNT(*) FROM defect_comments WHERE user_id = ?)
                      + (SELECT COUNT(*) FROM defect_attachments WHERE user_id = ?)', array_fill(0, 7, $id))->fetchColumn();
        $victim = q('SELECT name, email FROM users WHERE id = ?', [$id])->fetch();
        if ($hist > 0) {
            flash('error', 'Este usuário tem histórico no sistema (execuções, chamados ou comentários) e não pode ser excluído sem perder esses registros. Use "Desativar".');
        } elseif ($victim) {
            q('DELETE FROM users WHERE id = ?', [$id]);
            audit('usuario_excluido', 'user', $id, $victim['name'] . ' <' . $victim['email'] . '>');
            flash('success', 'Usuário excluído.');
        }
    }
    redirect('users.php');
}

/* ---------- Visões ---------- */
$editId = get_int('edit');
$showForm = isset($_GET['new']) || $editId;
$editing = $editId ? q('SELECT * FROM users WHERE id = ?', [$editId])->fetch() : null;
if ($editId && !$editing) { not_found(); }
if ($editing && $editing['status'] !== 'approved') {
    flash('error', 'Solicitações de cadastro só podem ser aprovadas ou recusadas, não editadas.');
    redirect('users.php');
}

$pageTitle = 'Usuários';
$active = 'users.php';
$backUrl = $showForm ? 'users.php' : null;
include __DIR__ . '/includes/header.php';

if ($showForm):
    $u = $editing ?: ['id' => 0, 'name' => '', 'email' => '', 'role' => 'tester', 'active' => 1];
?>
<div class="card" style="max-width:640px">
    <h2><?= $editing ? 'Editar usuário' : 'Novo usuário' ?></h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
        <div class="grid grid-2">
            <label class="field">Nome <input type="text" name="name" required maxlength="120" value="<?= e($u['name']) ?>"></label>
            <label class="field">E-mail <input type="email" name="email" required maxlength="160" value="<?= e($u['email']) ?>"></label>
            <?php $roleLocked = $editing && !empty($u['self_registered']); ?>
            <label class="field">Perfil
                <select name="role" <?= $roleLocked ? 'disabled' : '' ?>><?= options(ROLES, $u['role']) ?></select>
                <?php if ($roleLocked): ?><small>Perfil escolhido pelo próprio usuário no cadastro — não pode ser alterado. Se precisar de outro perfil, ele deve fazer um novo cadastro.</small><?php endif; ?>
            </label>
            <label class="field"><?= $editing ? 'Nova senha' : 'Senha inicial' ?>
                <input type="password" name="password" minlength="8" autocomplete="new-password" <?= $editing ? '' : 'required' ?>>
                <?php if ($editing): ?><small>Deixe em branco para manter a atual.</small><?php endif; ?>
            </label>
            <label class="check span-2"><input type="checkbox" name="active" value="1" <?= $u['active'] ? 'checked' : '' ?>> Usuário ativo</label>
        </div>
        <div class="form-actions">
            <button class="btn" type="submit">Salvar</button>
            <a class="btn btn-ghost" href="users.php">Cancelar</a>
        </div>
    </form>
</div>
<?php else:
    $users = q("SELECT * FROM users WHERE status <> 'pending' ORDER BY status = 'rejected', active DESC, name")->fetchAll();
    $pending = q("SELECT * FROM users WHERE status = 'pending' ORDER BY created_at")->fetchAll();
?>
<?php if ($pending): ?>
<div class="card" style="border-color:#fcd34d">
    <h2>Solicitações de cadastro pendentes (<?= count($pending) ?>)</h2>
    <p class="muted" style="margin-top:0">O perfil é o que a <strong>própria pessoa escolheu</strong> no cadastro e <strong>não pode ser alterado</strong> pelo administrador.
        Se estiver errado, <strong>recuse</strong>: ela faz uma nova solicitação com o perfil correto.</p>
    <div class="table-wrap"><table>
        <thead><tr><th>Nome</th><th>E-mail</th><th>Perfil solicitado</th><th>Solicitado em</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($pending as $p): ?>
            <tr>
                <td><?= e($p['name']) ?></td>
                <td><?= e($p['email']) ?></td>
                <td>
                    <?= badge(ROLES[$p['role']], 'blue') ?>
                    <form method="post" class="inline-form" id="ap-<?= (int)$p['id'] ?>">
                        <?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    </form>
                </td>
                <td><?= fmt_dt($p['created_at']) ?></td>
                <td class="actions">
                    <button class="btn btn-sm btn-success" type="submit" form="ap-<?= (int)$p['id'] ?>"><?= icon('check', 14) ?> Aprovar</button>
                    <form method="post" class="inline-form" data-confirm="A pessoa não poderá entrar no sistema com esta solicitação." data-confirm-title="Recusar cadastro?" data-confirm-ok="Recusar" data-confirm-type="warning">
                        <?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                        <button class="btn btn-sm btn-danger" type="submit">Recusar</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>
<div class="card">
    <div class="card-head"><h2>Usuários</h2><a class="btn" href="users.php?new=1"><?= icon('plus', 16) ?> Novo usuário</a></div>
    <div class="table-wrap"><table>
        <thead><tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Status</th><th>Criado em</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= e($u['name']) ?></td>
                <td><?= e($u['email']) ?></td>
                <td><?= e(ROLES[$u['role']]) ?></td>
                <td><?= $u['status'] === 'rejected' ? badge('Recusado', 'red') : ($u['active'] ? badge('Ativo', 'green') : badge('Inativo', 'gray')) ?></td>
                <td><?= fmt_date($u['created_at']) ?></td>
                <td class="actions">
                    <?php if ($u['status'] === 'approved'): ?><a class="btn btn-sm btn-ghost" href="users.php?edit=<?= (int)$u['id'] ?>"><?= icon('edit', 14) ?> Editar</a><?php endif; ?>
                    <?php if ((int)$u['id'] !== (int)$me['id']): ?>
                    <?php if ($u['status'] === 'approved'): ?>
                    <form method="post" class="inline-form">
                        <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                        <button class="btn btn-sm btn-ghost" type="submit"><?= $u['active'] ? 'Desativar' : 'Ativar' ?></button>
                    </form>
                    <?php endif; ?>
                    <form method="post" class="inline-form" data-confirm="O usuário será excluído. Quem já tem histórico (execuções, chamados ou comentários) não pode ser excluído — nesse caso use Desativar." data-confirm-title="Excluir usuário?" data-confirm-ok="Sim, excluir" data-confirm-type="danger">
                        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                        <button class="btn btn-sm btn-danger" type="submit"><?= icon('trash', 14) ?></button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif;
include __DIR__ . '/includes/footer.php';
