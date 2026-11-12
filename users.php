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
            flash('success', 'Usuário atualizado.');
        } else {
            q('INSERT INTO users (name, email, password_hash, role, active) VALUES (?,?,?,?,?)',
                [$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $active]);
            flash('success', 'Usuário criado.');
        }
    } elseif ($action === 'approve' && $id) {
        // Aprova a solicitação; o administrador pode ajustar a função antes de liberar
        $role = post('role');
        if (!isset(ROLES[$role])) {
            flash('error', 'Função inválida.');
        } else {
            $n = q("UPDATE users SET status = 'approved', active = 1, role = ? WHERE id = ? AND status = 'pending'", [$role, $id])->rowCount();
            flash($n ? 'success' : 'error', $n ? 'Cadastro aprovado. O usuário já pode entrar.' : 'Solicitação não encontrada.');
        }
    } elseif ($action === 'reject' && $id) {
        $n = q("UPDATE users SET status = 'rejected', active = 0 WHERE id = ? AND status = 'pending'", [$id])->rowCount();
        flash($n ? 'success' : 'error', $n ? 'Solicitação recusada.' : 'Solicitação não encontrada.');
    } elseif ($action === 'toggle' && $id && $id !== (int)$me['id']) {
        q("UPDATE users SET active = 1 - active WHERE id = ? AND status = 'approved'", [$id]);
        flash('success', 'Status do usuário alterado.');
    } elseif ($action === 'delete' && $id && $id !== (int)$me['id']) {
        // Remove também as evidências em disco das execuções do usuário
        $caseIds = q('SELECT DISTINCT case_id FROM executions WHERE tester_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN);
        $files = [];
        if ($caseIds) {
            $files = q('SELECT ev.stored_name FROM evidences ev JOIN executions ex ON ex.id = ev.execution_id WHERE ex.tester_id = ?', [$id])
                ->fetchAll(PDO::FETCH_COLUMN);
        }
        try {
            q('DELETE FROM users WHERE id = ?', [$id]);
            foreach ($files as $f) { @unlink(UPLOAD_DIR . basename($f)); }
            flash('success', 'Usuário excluído (suas execuções e defeitos vinculados também foram removidos).');
        } catch (PDOException $e) {
            flash('error', 'Não foi possível excluir. Prefira desativar o usuário.');
        }
    }
    redirect('users.php');
}

/* ---------- Visões ---------- */
$editId = get_int('edit');
$showForm = isset($_GET['new']) || $editId;
$editing = $editId ? q('SELECT * FROM users WHERE id = ?', [$editId])->fetch() : null;
if ($editId && !$editing) { not_found(); }

$pageTitle = 'Usuários';
$active = 'users.php';
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
            <label class="field">Perfil <select name="role"><?= options(ROLES, $u['role']) ?></select></label>
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
    <div class="table-wrap"><table>
        <thead><tr><th>Nome</th><th>E-mail</th><th>Função solicitada</th><th>Solicitado em</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($pending as $p): ?>
            <tr>
                <td><?= e($p['name']) ?></td>
                <td><?= e($p['email']) ?></td>
                <td>
                    <form method="post" class="inline-form" id="ap-<?= (int)$p['id'] ?>">
                        <?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                        <select name="role" aria-label="Função"><?= options(ROLES, $p['role']) ?></select>
                    </form>
                </td>
                <td><?= fmt_dt($p['created_at']) ?></td>
                <td class="actions">
                    <button class="btn btn-sm btn-success" type="submit" form="ap-<?= (int)$p['id'] ?>"><?= icon('check', 14) ?> Aprovar</button>
                    <form method="post" class="inline-form" data-confirm="Recusar esta solicitação de cadastro?">
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
                    <a class="btn btn-sm btn-ghost" href="users.php?edit=<?= (int)$u['id'] ?>"><?= icon('edit', 14) ?> Editar</a>
                    <?php if ((int)$u['id'] !== (int)$me['id']): ?>
                    <?php if ($u['status'] === 'approved'): ?>
                    <form method="post" class="inline-form">
                        <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                        <button class="btn btn-sm btn-ghost" type="submit"><?= $u['active'] ? 'Desativar' : 'Ativar' ?></button>
                    </form>
                    <?php endif; ?>
                    <form method="post" class="inline-form" data-confirm="Excluir este usuário? Execuções e defeitos dele também serão removidos. Prefira desativar.">
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
