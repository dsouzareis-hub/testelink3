<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_role('admin', 'viewer', 'tester');
$role = $user['role'];

$id = get_int('id');
$case = q('SELECT tc.*, p.name project, s.name suite FROM test_cases tc
           JOIN projects p ON p.id = tc.project_id JOIN suites s ON s.id = tc.suite_id WHERE tc.id = ?', [$id])->fetch();
if (!$case) { not_found(); }
if ($role === 'tester' && !tester_has_case((int)$user['id'], $id)) { forbidden(); }

$versions = q('SELECT v.*, u.name author FROM test_case_versions v LEFT JOIN users u ON u.id = v.changed_by
               WHERE v.case_id = ? ORDER BY v.version DESC, v.id DESC', [$id])->fetchAll();
$testers = q('SELECT u.name FROM assignments a JOIN users u ON u.id = a.tester_id WHERE a.case_id = ? ORDER BY u.name', [$id])
    ->fetchAll(PDO::FETCH_COLUMN);
$execWhere = $role === 'tester' ? 'AND ex.tester_id = ' . (int)$user['id'] : '';
$execs = q("SELECT ex.id, ex.result, ex.executed_at, ex.case_version, u.name tester,
                   (SELECT COUNT(*) FROM evidences WHERE execution_id = ex.id) evs
            FROM executions ex JOIN users u ON u.id = ex.tester_id WHERE ex.case_id = ? $execWhere ORDER BY ex.id DESC", [$id])->fetchAll();

$pageTitle = $case['code'] . ' — ' . $case['title'];
$active = $role === 'tester' ? 'my_tests.php' : 'cases.php';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
    <div class="card-head">
        <h2><?= e($case['code']) ?> · <?= e($case['title']) ?> <small class="muted">v<?= (int)$case['version'] ?></small></h2>
        <div>
            <?php if ($role === 'tester' && $case['status'] === 'active'): ?>
                <a class="btn btn-success" href="execute.php?case_id=<?= $id ?>"><?= icon('play', 16) ?> Executar</a>
            <?php endif; ?>
            <?php if ($role === 'admin'): ?><a class="btn" href="case_form.php?id=<?= $id ?>"><?= icon('edit', 16) ?> Editar</a><?php endif; ?>
            <a class="btn btn-ghost" href="report_case.php?id=<?= $id ?>"><?= icon('printer', 16) ?> Relatório</a>
        </div>
    </div>
    <dl class="detail-grid">
        <dt>Projeto / Suíte</dt><dd><?= e($case['project']) ?> / <?= e($case['suite']) ?></dd>
        <dt>Prioridade</dt><dd><?= level_badge($case['priority']) ?></dd>
        <dt>Status</dt><dd><?= $case['status'] === 'active' ? badge('Ativo', 'green') : badge('Inativo', 'gray') ?></dd>
        <dt>Atribuído a</dt><dd><?= $testers ? e(implode(', ', $testers)) : '<span class="muted">Ninguém</span>' ?></dd>
        <dt>Pré-condições</dt><dd class="pre"><?= e($case['preconditions'] ?: '—') ?></dd>
        <dt>Passos</dt>
        <dd><ol class="steps">
            <?php foreach (preg_split('/\R/', trim($case['steps'])) as $step): if (trim($step) === '') continue; ?>
                <li><?= e($step) ?></li>
            <?php endforeach; ?>
        </ol></dd>
        <dt>Resultado esperado</dt><dd class="pre"><?= e($case['expected_result']) ?></dd>
    </dl>
</div>

<div class="card">
    <h2>Execuções</h2>
    <div class="table-wrap"><table>
        <thead><tr><th>#</th><th>Resultado</th><th>Versão do CT</th><th>Tester</th><th>Data</th><th>Evidências</th></tr></thead>
        <tbody>
        <?php foreach ($execs as $x): ?>
            <tr>
                <td><a href="execution_view.php?id=<?= (int)$x['id'] ?>"><?= (int)$x['id'] ?></a></td>
                <td><?= result_badge($x['result']) ?></td>
                <td>v<?= (int)$x['case_version'] ?></td>
                <td><?= e($x['tester']) ?></td>
                <td><?= fmt_dt($x['executed_at']) ?></td>
                <td><?= (int)$x['evs'] ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$execs): ?><tr><td colspan="6" class="empty">Sem execuções.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<?php if ($role !== 'tester'): ?>
<div class="card">
    <h2>Histórico de versões</h2>
    <div class="table-wrap"><table>
        <thead><tr><th>Versão</th><th>Título</th><th>Prioridade</th><th>Status</th><th>Motivo</th><th>Por</th><th>Data</th></tr></thead>
        <tbody>
        <?php foreach ($versions as $v): ?>
            <tr>
                <td>v<?= (int)$v['version'] ?></td>
                <td><?= e($v['title']) ?></td>
                <td><?= level_badge($v['priority']) ?></td>
                <td><?= $v['status'] === 'active' ? 'Ativo' : 'Inativo' ?></td>
                <td><?= e($v['change_note'] ?: '—') ?></td>
                <td><?= e($v['author'] ?: '—') ?></td>
                <td><?= fmt_dt($v['changed_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
