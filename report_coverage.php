<?php
require __DIR__ . '/includes/bootstrap.php';
require_role('admin', 'viewer');

$fProject = get_int('project');
$where = $fProject ? 'AND s.project_id = ?' : '';

// Cobertura por suíte: casos ativos x casos com ao menos uma execução
$rows = q("SELECT p.id project_id, p.name project, s.name suite,
                  COUNT(tc.id) total,
                  COALESCE(SUM(ex.id IS NOT NULL), 0) executed,
                  COALESCE(SUM(ex.result = 'passed'), 0) passed,
                  COALESCE(SUM(ex.result = 'failed'), 0) failed,
                  COALESCE(SUM(ex.result = 'blocked'), 0) blocked
           FROM suites s JOIN projects p ON p.id = s.project_id
           LEFT JOIN test_cases tc ON tc.suite_id = s.id AND tc.status = 'active'
           LEFT JOIN executions ex ON ex.id = (SELECT MAX(id) FROM executions WHERE case_id = tc.id)
           WHERE 1=1 $where GROUP BY s.id, p.id, p.name, s.name ORDER BY p.name, s.name", $fProject ? [$fProject] : [])->fetchAll();

$totals = ['total' => 0, 'executed' => 0, 'passed' => 0];
foreach ($rows as $r) {
    $totals['total'] += $r['total'];
    $totals['executed'] += $r['executed'];
    $totals['passed'] += $r['passed'];
}

// Casos ainda sem nenhuma execução (lacunas de cobertura)
$gaps = q("SELECT tc.code, tc.title, p.name project, s.name suite, tc.priority,
                  (SELECT GROUP_CONCAT(u.name SEPARATOR ', ') FROM assignments a JOIN users u ON u.id = a.tester_id WHERE a.case_id = tc.id) testers
           FROM test_cases tc JOIN projects p ON p.id = tc.project_id JOIN suites s ON s.id = tc.suite_id
           WHERE tc.status = 'active' AND NOT EXISTS (SELECT 1 FROM executions WHERE case_id = tc.id)
           " . ($fProject ? 'AND tc.project_id = ?' : '') . ' ORDER BY p.name, s.name, tc.code', $fProject ? [$fProject] : [])->fetchAll();

$pageTitle = 'Cobertura de Testes';
$active = 'reports.php';
$backUrl = 'reports.php';
include __DIR__ . '/includes/header.php';
?>
<?php report_toolbar($pageTitle, 'coverage'); ?>

<form method="get" class="filters no-print">
    <label class="field">Projeto <select name="project"><?= options(project_list(), $fProject ?: null, true, 'Todos') ?></select></label>
    <button class="btn" type="submit">Gerar</button>
</form>

<div class="stats">
    <div class="stat"><div class="num"><?= pct($totals['executed'], $totals['total']) ?>%</div><div class="lbl">Cobertura geral</div></div>
    <div class="stat gray"><div class="num"><?= $totals['executed'] ?>/<?= $totals['total'] ?></div><div class="lbl">Casos executados</div></div>
    <div class="stat green"><div class="num"><?= pct($totals['passed'], $totals['executed']) ?>%</div><div class="lbl">Taxa de aprovação</div></div>
</div>

<div class="card">
    <h2>Cobertura por suíte</h2>
    <div class="table-wrap"><table>
        <thead><tr><th>Projeto</th><th>Suíte</th><th>Casos</th><th>Executados</th><th>Aprov.</th><th>Reprov.</th><th>Bloq.</th><th>Cobertura</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $c = pct((int)$r['executed'], (int)$r['total']); ?>
            <tr>
                <td><?= e($r['project']) ?></td>
                <td><?= e($r['suite']) ?></td>
                <td><?= (int)$r['total'] ?></td>
                <td><?= (int)$r['executed'] ?></td>
                <td><?= (int)$r['passed'] ?></td>
                <td><?= (int)$r['failed'] ?></td>
                <td><?= (int)$r['blocked'] ?></td>
                <td><div class="progress <?= $c === 100 ? 'ok' : '' ?>"><span style="width: <?= $c ?>%"></span></div><small><?= $c ?>%</small></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="8" class="empty">Sem dados.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<div class="card">
    <h2>Casos sem execução (<?= count($gaps) ?>)</h2>
    <div class="table-wrap"><table>
        <thead><tr><th>Código</th><th>Título</th><th>Projeto / Suíte</th><th>Prioridade</th><th>Atribuído a</th></tr></thead>
        <tbody>
        <?php foreach ($gaps as $g): ?>
            <tr><td><?= e($g['code']) ?></td><td><?= e($g['title']) ?></td><td><?= e($g['project']) ?> / <?= e($g['suite']) ?></td>
                <td><?= level_badge($g['priority']) ?></td><td><?= e($g['testers'] ?: '—') ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$gaps): ?><tr><td colspan="5" class="empty">Todos os casos ativos possuem execução.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
