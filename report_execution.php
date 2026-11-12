<?php
require __DIR__ . '/includes/bootstrap.php';
require_role('admin', 'viewer');

$fProject = get_int('project');
$fFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '';
$fTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '';

$where = ["tc.status = 'active'"];
$params = [];
if ($fProject) { $where[] = 'tc.project_id = ?'; $params[] = $fProject; }
// O período filtra pela data da última execução
if ($fFrom) { $where[] = 'ex.executed_at >= ?'; $params[] = $fFrom . ' 00:00:00'; }
if ($fTo)   { $where[] = 'ex.executed_at <= ?'; $params[] = $fTo . ' 23:59:59'; }

$rows = q('SELECT tc.code, tc.title, tc.priority, p.name project, s.name suite,
                  ex.id exec_id, ex.result, ex.executed_at, u.name tester,
                  (SELECT COUNT(*) FROM defects d WHERE d.case_id = tc.id AND d.status IN (\'triage\',\'open\',\'fixing\',\'reopened\')) open_defects
           FROM test_cases tc JOIN projects p ON p.id = tc.project_id JOIN suites s ON s.id = tc.suite_id
           LEFT JOIN executions ex ON ex.id = (SELECT MAX(id) FROM executions WHERE case_id = tc.id)
           LEFT JOIN users u ON u.id = ex.tester_id
           WHERE ' . implode(' AND ', $where) . ' ORDER BY p.name, s.name, tc.code', $params)->fetchAll();

$sum = ['passed' => 0, 'failed' => 0, 'blocked' => 0, 'none' => 0];
foreach ($rows as $r) { $sum[$r['result'] ?? 'none']++; }
$total = count($rows);

$pageTitle = 'Relatório de Execução';
$active = 'reports.php';
include __DIR__ . '/includes/header.php';
?>
<?php report_toolbar($pageTitle . ($fProject ? ' — ' . (project_list()[$fProject] ?? '') : ''), 'execution'); ?>

<form method="get" class="filters no-print">
    <label class="field">Projeto <select name="project"><?= options(project_list(), $fProject ?: null, true, 'Todos') ?></select></label>
    <label class="field">De <input type="date" name="from" value="<?= e($fFrom) ?>"></label>
    <label class="field">Até <input type="date" name="to" value="<?= e($fTo) ?>"></label>
    <button class="btn" type="submit">Gerar</button>
</form>

<div class="stats">
    <div class="stat"><div class="num"><?= $total ?></div><div class="lbl">Casos</div></div>
    <div class="stat green"><div class="num"><?= $sum['passed'] ?></div><div class="lbl">Aprovados (<?= pct($sum['passed'], $total) ?>%)</div></div>
    <div class="stat red"><div class="num"><?= $sum['failed'] ?></div><div class="lbl">Reprovados (<?= pct($sum['failed'], $total) ?>%)</div></div>
    <div class="stat amber"><div class="num"><?= $sum['blocked'] ?></div><div class="lbl">Bloqueados</div></div>
    <div class="stat gray"><div class="num"><?= $sum['none'] ?></div><div class="lbl">Não executados</div></div>
</div>

<div class="card">
    <div class="table-wrap"><table>
        <thead><tr><th>Código</th><th>Título</th><th>Projeto / Suíte</th><th>Prior.</th><th>Resultado</th><th>Tester</th><th>Data</th><th>Def. abertos</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><strong><?= e($r['code']) ?></strong></td>
                <td><?= e($r['title']) ?></td>
                <td><?= e($r['project']) ?> / <?= e($r['suite']) ?></td>
                <td><?= e(LEVELS[$r['priority']]) ?></td>
                <td><?= result_badge($r['result']) ?></td>
                <td><?= e($r['tester'] ?: '—') ?></td>
                <td><?= fmt_dt($r['executed_at']) ?></td>
                <td><?= (int)$r['open_defects'] ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="8" class="empty">Nenhum caso no filtro selecionado.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
