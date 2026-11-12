<?php
require __DIR__ . '/includes/bootstrap.php';
$me = require_role('admin');

$projects = project_list();
$testers = users_by_role('tester');
$projectId = get_int('project') ?: (int)($_POST['project'] ?? 0);

if (is_post()) {
    csrf_check();
    $action = post('action');

    if ($action === 'assign') {
        $caseIds = array_map('intval', (array)($_POST['cases'] ?? []));
        $testerId = post_int('tester_id');
        if (!$caseIds || !isset($testers[$testerId])) {
            flash('error', 'Selecione ao menos um caso e um tester.');
        } else {
            $n = 0;
            foreach ($caseIds as $cid) {
                $st = q('INSERT IGNORE INTO assignments (case_id, tester_id, assigned_by)
                         SELECT id, ?, ? FROM test_cases WHERE id = ?', [$testerId, $me['id'], $cid]);
                $n += $st->rowCount();
            }
            flash('success', "$n atribuição(ões) criada(s) para {$testers[$testerId]}.");
        }
    } elseif ($action === 'unassign') {
        q('DELETE FROM assignments WHERE id = ?', [post_int('id')]);
        flash('success', 'Atribuição removida.');
    }
    redirect('assignments.php' . ($projectId ? '?project=' . $projectId : ''));
}

$cases = [];
if ($projectId) {
    $cases = q('SELECT tc.id, tc.code, tc.title, s.name suite FROM test_cases tc JOIN suites s ON s.id = tc.suite_id
                WHERE tc.project_id = ? AND tc.status = ? ORDER BY s.name, tc.code', [$projectId, 'active'])->fetchAll();
    $assigned = [];
    foreach (q('SELECT a.id, a.case_id, u.name FROM assignments a JOIN users u ON u.id = a.tester_id
                JOIN test_cases tc ON tc.id = a.case_id WHERE tc.project_id = ?', [$projectId]) as $a) {
        $assigned[$a['case_id']][] = $a;
    }
}

$pageTitle = 'Atribuição de Casos';
$active = 'assignments.php';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
    <form method="get" class="filters">
        <label class="field">Projeto <select name="project" onchange="this.form.submit()"><?= options($projects, $projectId ?: null, true, 'Selecione…') ?></select></label>
    </form>

    <?php if (!$projectId): ?>
        <p class="muted">Selecione um projeto para atribuir seus casos de teste a um tester.</p>
    <?php elseif (!$testers): ?>
        <p class="alert alert-warn">Não há testers ativos. <a href="users.php?new=1">Cadastre um usuário</a> com perfil Tester.</p>
    <?php else: ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="assign">
        <input type="hidden" name="project" value="<?= $projectId ?>">
        <div class="filters">
            <label class="field">Atribuir os casos marcados ao tester
                <select name="tester_id" required><?= options($testers) ?></select>
            </label>
            <button class="btn" type="submit"><?= icon('send', 16) ?> Atribuir selecionados</button>
        </div>
        <div class="table-wrap"><table>
            <thead><tr><th><input type="checkbox" data-check-all aria-label="Marcar todos"></th><th>Código</th><th>Título</th><th>Suíte</th><th>Atribuído a</th></tr></thead>
            <tbody>
            <?php foreach ($cases as $c): ?>
                <tr>
                    <td><input type="checkbox" name="cases[]" value="<?= (int)$c['id'] ?>" data-check-item></td>
                    <td><a href="case_view.php?id=<?= (int)$c['id'] ?>"><?= e($c['code']) ?></a></td>
                    <td><?= e($c['title']) ?></td>
                    <td><?= e($c['suite']) ?></td>
                    <td>
                        <?php foreach ($assigned[$c['id']] ?? [] as $a): ?>
                            <span class="badge badge-blue"><?= e($a['name']) ?>
                                <button type="submit" form="un-<?= (int)$a['id'] ?>" class="btn-icon" style="padding:0 0 0 4px;color:inherit" title="Remover" aria-label="Remover atribuição">×</button>
                            </span>
                        <?php endforeach; ?>
                        <?php if (empty($assigned[$c['id']])): ?><span class="muted">—</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$cases): ?><tr><td colspan="5" class="empty">Nenhum caso ativo neste projeto.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </form>
    <?php /* Formulários de remoção fora do form principal (HTML não permite aninhar forms) */ ?>
    <?php foreach ($assigned ?? [] as $list): foreach ($list as $a): ?>
        <form method="post" id="un-<?= (int)$a['id'] ?>" hidden>
            <?= csrf_field() ?><input type="hidden" name="action" value="unassign"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
            <input type="hidden" name="project" value="<?= $projectId ?>">
        </form>
    <?php endforeach; endforeach; ?>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
