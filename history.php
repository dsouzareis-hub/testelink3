<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_role('admin', 'viewer', 'tester');
$isTester = $user['role'] === 'tester';

$fProject = get_int('project');
$fResult = $_GET['result'] ?? '';
$fTester = get_int('tester');

$where = ['1=1'];
$params = [];
if ($isTester) { $where[] = 'ex.tester_id = ?'; $params[] = $user['id']; }
elseif ($fTester) { $where[] = 'ex.tester_id = ?'; $params[] = $fTester; }
if ($fProject) { $where[] = 'tc.project_id = ?'; $params[] = $fProject; }
if (isset(RESULTS[$fResult])) { $where[] = 'ex.result = ?'; $params[] = $fResult; }

$rows = q('SELECT ex.id, ex.result, ex.exec_type, ex.executed_at, ex.case_version, tc.code, tc.title, p.name project, u.name tester,
                  (SELECT COUNT(*) FROM evidences WHERE execution_id = ex.id) evs,
                  (SELECT id FROM defects WHERE execution_id = ex.id) defect_id
           FROM executions ex JOIN test_cases tc ON tc.id = ex.case_id JOIN projects p ON p.id = tc.project_id
           JOIN users u ON u.id = ex.tester_id WHERE ' . implode(' AND ', $where) . ' ORDER BY ex.id DESC LIMIT 300', $params)->fetchAll();

$pageTitle = $isTester ? 'Meu histórico de execuções' : 'Execuções';
$active = 'history.php';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
    <form method="get" class="filters">
        <label class="field">Projeto <select name="project"><?= options(project_list(), $fProject ?: null, true) ?></select></label>
        <?php if (!$isTester): ?>
        <label class="field">Tester <select name="tester"><?= options(users_by_role('tester'), $fTester ?: null, true) ?></select></label>
        <?php endif; ?>
        <label class="field">Resultado <select name="result"><?= options(RESULTS, $fResult, true) ?></select></label>
        <button class="btn" type="submit">Filtrar</button>
    </form>
    <div class="table-wrap"><table>
        <thead><tr><th>#</th><th>Caso</th><th>Projeto</th><th>Resultado</th><th>Tipo</th><?php if (!$isTester): ?><th>Tester</th><?php endif; ?><th>Data</th><th>Evid.</th><th>Defeito</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><a href="execution_view.php?id=<?= (int)$r['id'] ?>"><?= (int)$r['id'] ?></a></td>
                <td><strong><?= e($r['code']) ?></strong> <?= e($r['title']) ?> <small class="muted">v<?= (int)$r['case_version'] ?></small></td>
                <td><?= e($r['project']) ?></td>
                <td><?= result_badge($r['result']) ?></td>
                <td><?= e(EXEC_TYPES[$r['exec_type']] ?? $r['exec_type']) ?></td>
                <?php if (!$isTester): ?><td><?= e($r['tester']) ?></td><?php endif; ?>
                <td><?= fmt_dt($r['executed_at']) ?></td>
                <td><?= (int)$r['evs'] ?></td>
                <td><?= $r['defect_id'] ? '<a href="defect_view.php?id=' . (int)$r['defect_id'] . '">#' . (int)$r['defect_id'] . '</a>' : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="9" class="empty">Nenhuma execução encontrada.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
