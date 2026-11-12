<?php
require __DIR__ . '/includes/bootstrap.php';
require_role('admin');

$projects = project_list();

if (is_post()) {
    csrf_check();
    $action = post('action');
    $id = post_int('id');

    if ($action === 'save') {
        $projectId = post_int('project_id');
        $name = post('name');
        $desc = post('description');
        if ($name === '' || !isset($projects[$projectId])) {
            flash('error', 'Informe o projeto e o nome da suíte.');
            redirect('suites.php?' . ($id ? 'edit=' . $id : 'new=1'));
        }
        if ($id) {
            // Mantém project_id dos casos coerente caso a suíte mude de projeto
            q('UPDATE suites SET project_id=?, name=?, description=? WHERE id=?', [$projectId, $name, $desc, $id]);
            q('UPDATE test_cases SET project_id=? WHERE suite_id=?', [$projectId, $id]);
            flash('success', 'Suíte atualizada.');
        } else {
            q('INSERT INTO suites (project_id, name, description) VALUES (?,?,?)', [$projectId, $name, $desc]);
            flash('success', 'Suíte criada.');
        }
        redirect('suites.php?project=' . $projectId);
    } elseif ($action === 'delete' && $id) {
        $caseIds = q('SELECT id FROM test_cases WHERE suite_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN);
        purge_evidence_files($caseIds);
        q('DELETE FROM suites WHERE id = ?', [$id]);
        flash('success', 'Suíte excluída com seus casos de teste.');
    }
    redirect('suites.php');
}

$editId = get_int('edit');
$filterProject = get_int('project');
$showForm = isset($_GET['new']) || $editId;
$editing = $editId ? q('SELECT * FROM suites WHERE id = ?', [$editId])->fetch() : null;
if ($editId && !$editing) { not_found(); }

$pageTitle = 'Suítes de Teste';
$active = 'suites.php';
$backUrl = $showForm ? 'suites.php' : null;
include __DIR__ . '/includes/header.php';

if ($showForm):
    $s = $editing ?: ['id' => 0, 'project_id' => $filterProject, 'name' => '', 'description' => ''];
?>
<div class="card" style="max-width:640px">
    <h2><?= $editing ? 'Editar suíte' : 'Nova suíte' ?></h2>
    <?php if (!$projects): ?>
        <p class="alert alert-warn">Cadastre um projeto antes de criar suítes.</p>
    <?php else: ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
        <div class="grid">
            <label class="field">Projeto <select name="project_id" required><?= options($projects, $s['project_id']) ?></select></label>
            <label class="field">Nome <input type="text" name="name" required maxlength="150" value="<?= e($s['name']) ?>"></label>
            <label class="field">Descrição <textarea name="description" rows="3"><?= e($s['description']) ?></textarea></label>
        </div>
        <div class="form-actions">
            <button class="btn" type="submit">Salvar</button>
            <a class="btn btn-ghost" href="suites.php">Cancelar</a>
        </div>
    </form>
    <?php endif; ?>
</div>
<?php else:
    $where = $filterProject ? 'WHERE s.project_id = ?' : '';
    $rows = q("SELECT s.*, p.name project,
                (SELECT COUNT(*) FROM test_cases c WHERE c.suite_id = s.id) cases
               FROM suites s JOIN projects p ON p.id = s.project_id $where ORDER BY p.name, s.name",
              $filterProject ? [$filterProject] : [])->fetchAll();
?>
<div class="card">
    <div class="card-head">
        <h2>Suítes</h2>
        <a class="btn" href="suites.php?new=1<?= $filterProject ? '&project=' . $filterProject : '' ?>"><?= icon('plus', 16) ?> Nova suíte</a>
    </div>
    <form method="get" class="filters">
        <label class="field">Projeto <select name="project" onchange="this.form.submit()"><?= options($projects, $filterProject ?: null, true, 'Todos') ?></select></label>
    </form>
    <div class="table-wrap"><table>
        <thead><tr><th>Suíte</th><th>Projeto</th><th>Descrição</th><th>Casos</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $s): ?>
            <tr>
                <td><strong><?= e($s['name']) ?></strong></td>
                <td><?= e($s['project']) ?></td>
                <td><?= e(mb_strimwidth((string)$s['description'], 0, 80, '…')) ?></td>
                <td><a href="cases.php?suite=<?= (int)$s['id'] ?>"><?= (int)$s['cases'] ?></a></td>
                <td class="actions">
                    <a class="btn btn-sm btn-ghost" href="suites.php?edit=<?= (int)$s['id'] ?>"><?= icon('edit', 14) ?> Editar</a>
                    <form method="post" class="inline-form" data-confirm="Excluir a suíte e todos os seus casos de teste?">
                        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                        <button class="btn btn-sm btn-danger" type="submit"><?= icon('trash', 14) ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5" class="empty">Nenhuma suíte encontrada.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?php endif;
include __DIR__ . '/includes/footer.php';
