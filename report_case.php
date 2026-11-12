<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_role('admin', 'viewer', 'tester');

$id = get_int('id');
$case = q('SELECT tc.*, p.name project, s.name suite FROM test_cases tc
           JOIN projects p ON p.id = tc.project_id JOIN suites s ON s.id = tc.suite_id WHERE tc.id = ?', [$id])->fetch();
if (!$case) { not_found(); }
if ($user['role'] === 'tester' && !tester_has_case((int)$user['id'], $id)) { forbidden(); }

$execs = q('SELECT ex.*, u.name tester FROM executions ex JOIN users u ON u.id = ex.tester_id
            WHERE ex.case_id = ? ORDER BY ex.id DESC', [$id])->fetchAll();
$defects = q('SELECT id, title, status, severity, execution_id FROM defects WHERE case_id = ? ORDER BY id', [$id])->fetchAll();

$pageTitle = 'Relatório do Caso ' . $case['code'];
$active = $user['role'] === 'tester' ? 'my_tests.php' : 'cases.php';
include __DIR__ . '/includes/header.php';
?>
<?php report_toolbar($pageTitle, 'case'); ?>

<div class="card">
    <h2><?= e($case['code']) ?> · <?= e($case['title']) ?> <small class="muted">v<?= (int)$case['version'] ?></small></h2>
    <dl class="detail-grid">
        <dt>Projeto / Suíte</dt><dd><?= e($case['project']) ?> / <?= e($case['suite']) ?></dd>
        <dt>Prioridade</dt><dd><?= e(LEVELS[$case['priority']]) ?></dd>
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

<h2>Execuções (<?= count($execs) ?>)</h2>
<?php foreach ($execs as $x): ?>
<div class="card">
    <div class="card-head">
        <h3>Execução #<?= (int)$x['id'] ?> — <?= e($x['tester']) ?> em <?= fmt_dt($x['executed_at']) ?> <small class="muted">(CT v<?= (int)$x['case_version'] ?>)</small></h3>
        <?= result_badge($x['result']) ?>
    </div>
    <p class="pre"><strong>Assentamento:</strong> <?= e($x['notes'] ?: '—') ?></p>
    <?= evidence_gallery(execution_evidences((int)$x['id'])) ?>
</div>
<?php endforeach; ?>
<?php if (!$execs): ?><div class="card"><p class="muted">Este caso ainda não foi executado.</p></div><?php endif; ?>

<?php if ($defects): ?>
<div class="card">
    <h2>Defeitos vinculados</h2>
    <div class="table-wrap"><table>
        <thead><tr><th>#</th><th>Título</th><th>Severidade</th><th>Status</th><th>Execução</th></tr></thead>
        <tbody>
        <?php foreach ($defects as $d): ?>
            <tr><td><a href="defect_view.php?id=<?= (int)$d['id'] ?>"><?= (int)$d['id'] ?></a></td><td><?= e($d['title']) ?></td>
                <td><?= level_badge($d['severity']) ?></td><td><?= defect_badge($d['status']) ?></td><td>#<?= (int)$d['execution_id'] ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
