<?php
require __DIR__ . '/includes/bootstrap.php';
$me = require_role('admin');

if (is_post()) {
    csrf_check();
    $action = post('action');
    $id = post_int('id');

    if ($action === 'save') {
        $name = post('name');
        $desc = post('description');
        $status = post('status') === 'archived' ? 'archived' : 'active';
        if ($name === '') {
            flash('error', 'Informe o nome do projeto.');
            redirect('projects.php?' . ($id ? 'edit=' . $id : 'new=1'));
        }
        if ($id) {
            q('UPDATE projects SET name=?, description=?, status=? WHERE id=?', [$name, $desc, $status, $id]);
            audit('projeto_editado', 'project', $id, $name);
            flash('success', 'Projeto atualizado.');
        } else {
            q('INSERT INTO projects (name, description, status, created_by) VALUES (?,?,?,?)', [$name, $desc, $status, $me['id']]);
            audit('projeto_criado', 'project', (int)db()->lastInsertId(), $name);
            flash('success', 'Projeto criado.');
        }
    } elseif ($action === 'delete' && $id) {
        // Vai para a Lixeira (com suítes, casos, execuções, defeitos e fotos) e pode ser restaurado
        $ok = trash_delete_project($id, (int)$me['id']);
        flash($ok ? 'success' : 'error', $ok ? 'Projeto enviado para a Lixeira, com suítes, casos, execuções e defeitos. Dá para restaurar em Lixeira.' : 'Projeto não encontrado.');
    }
    redirect('projects.php');
}

$editId = get_int('edit');
$showForm = isset($_GET['new']) || $editId;
$editing = $editId ? q('SELECT * FROM projects WHERE id = ?', [$editId])->fetch() : null;
if ($editId && !$editing) { not_found(); }

$pageTitle = 'Projetos';
$active = 'projects.php';
$backUrl = $showForm ? 'projects.php' : null;
include __DIR__ . '/includes/header.php';

if ($showForm):
    $p = $editing ?: ['id' => 0, 'name' => '', 'description' => '', 'status' => 'active'];
?>
<div class="card" style="max-width:640px">
    <h2><?= $editing ? 'Editar projeto' : 'Novo projeto' ?></h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
        <div class="grid">
            <label class="field">Nome <input type="text" name="name" required maxlength="150" value="<?= e($p['name']) ?>"></label>
            <label class="field">Descrição <textarea name="description" rows="4"><?= e($p['description']) ?></textarea></label>
            <label class="field">Status
                <select name="status"><?= options(['active' => 'Ativo', 'archived' => 'Arquivado'], $p['status']) ?></select>
            </label>
        </div>
        <div class="form-actions">
            <button class="btn" type="submit">Salvar</button>
            <a class="btn btn-ghost" href="projects.php">Cancelar</a>
        </div>
    </form>
</div>
<?php else:
    $rows = q('SELECT p.*,
                (SELECT COUNT(*) FROM suites s WHERE s.project_id = p.id) suites,
                (SELECT COUNT(*) FROM test_cases c WHERE c.project_id = p.id) cases
               FROM projects p ORDER BY p.name')->fetchAll();
?>
<div class="card">
    <div class="card-head"><h2>Projetos</h2><a class="btn" href="projects.php?new=1"><?= icon('plus', 16) ?> Novo projeto</a></div>
    <div class="table-wrap"><table>
        <thead><tr><th>Nome</th><th>Descrição</th><th>Suítes</th><th>Casos</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $p): ?>
            <tr>
                <td><strong><?= e($p['name']) ?></strong></td>
                <td><?= e(mb_strimwidth((string)$p['description'], 0, 90, '…')) ?></td>
                <td><a href="suites.php?project=<?= (int)$p['id'] ?>"><?= (int)$p['suites'] ?></a></td>
                <td><a href="cases.php?project=<?= (int)$p['id'] ?>"><?= (int)$p['cases'] ?></a></td>
                <td><?= $p['status'] === 'active' ? badge('Ativo', 'green') : badge('Arquivado', 'gray') ?></td>
                <td class="actions">
                    <a class="btn btn-sm btn-ghost" href="projects.php?edit=<?= (int)$p['id'] ?>"><?= icon('edit', 14) ?> Editar</a>
                    <form method="post" class="inline-form" data-confirm="O projeto, suas suítes, casos, execuções e defeitos vão para a Lixeira. Você poderá restaurar tudo depois." data-confirm-title="Enviar projeto para a lixeira?" data-confirm-ok="Enviar para a lixeira" data-confirm-type="warning">
                        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                        <button class="btn btn-sm btn-danger" type="submit"><?= icon('trash', 14) ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="empty">Nenhum projeto cadastrado.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?php endif;
include __DIR__ . '/includes/footer.php';
