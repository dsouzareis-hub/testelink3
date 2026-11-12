<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$uid = (int)$user['id'];
$role = $user['role'];

/* ---------- Filtro de projeto ---------- */
$projectId = get_int('project');
if ($role === 'tester') {
    $projects = q('SELECT DISTINCT p.id, p.name FROM projects p JOIN test_cases tc ON tc.project_id = p.id
                   JOIN assignments a ON a.case_id = tc.id WHERE a.tester_id = ? ORDER BY p.name', [$uid])
        ->fetchAll(PDO::FETCH_KEY_PAIR);
} else {
    $projects = project_list();
}
if ($projectId && !isset($projects[$projectId])) {
    $projectId = 0;
}

/* ---------- Estatísticas de casos (último resultado de cada CT) ---------- */
$stats = ['total' => 0, 'executed' => 0, 'passed' => 0, 'failed' => 0, 'blocked' => 0];
if ($role !== 'developer') {
    $where = ["tc.status = 'active'"];
    $params = [];
    if ($projectId) { $where[] = 'tc.project_id = ?'; $params[] = $projectId; }
    if ($role === 'tester') {
        $where[] = 'tc.id IN (SELECT case_id FROM assignments WHERE tester_id = ?)';
        $params[] = $uid;
    }
    $row = q("SELECT COUNT(*) total,
                     COALESCE(SUM(ex.id IS NOT NULL), 0) executed,
                     COALESCE(SUM(ex.result = 'passed'), 0) passed,
                     COALESCE(SUM(ex.result = 'failed'), 0) failed,
                     COALESCE(SUM(ex.result = 'blocked'), 0) blocked
              FROM test_cases tc
              LEFT JOIN executions ex ON ex.id = (SELECT MAX(id) FROM executions WHERE case_id = tc.id)
              WHERE " . implode(' AND ', $where), $params)->fetch();
    foreach ($stats as $k => $_) { $stats[$k] = (int)$row[$k]; }
}

/* ---------- Defeitos ---------- */
$dWhere = [];
$dParams = [];
if ($projectId) { $dWhere[] = 'd.project_id = ?'; $dParams[] = $projectId; }
if ($role === 'developer') { $dWhere[] = "d.assigned_to = ? AND d.status IN ('open','fixing')"; $dParams[] = $uid; }
if ($role === 'tester') { $dWhere[] = 'd.reported_by = ?'; $dParams[] = $uid; }
$dSql = $dWhere ? 'WHERE ' . implode(' AND ', $dWhere) : '';
$dCounts = array_fill_keys(array_keys(DEFECT_STATUS), 0);
foreach (q("SELECT d.status, COUNT(*) c FROM defects d $dSql GROUP BY d.status", $dParams) as $r) {
    $dCounts[$r['status']] = (int)$r['c'];
}
$openDefects = $dCounts['triage'] + $dCounts['open'] + $dCounts['fixing'] + $dCounts['reopened'];
// Dev: chamados que ele já concluiu (histórico; ele não tem mais acesso a eles)
$devDone = $role === 'developer'
    ? (int)q("SELECT COUNT(DISTINCT defect_id) FROM defect_comments WHERE user_id = ? AND new_status = 'fixed'", [$uid])->fetchColumn() : 0;
// Admin: chamados esperando encaminhamento (novos reprovados e reabertos pelo reteste) e devolvidos pelo dev
$toForward = $role === 'admin' ? (int)q("SELECT COUNT(*) FROM defects WHERE status IN ('triage','reopened')")->fetchColumn() : 0;
$returned = $role === 'admin' ? (int)q("SELECT COUNT(*) FROM defects WHERE status = 'fixed'")->fetchColumn() : 0;

$recentDefects = q("SELECT d.id, d.title, d.status, d.severity, d.updated_at, tc.code
                    FROM defects d JOIN test_cases tc ON tc.id = d.case_id $dSql
                    ORDER BY d.updated_at DESC LIMIT 6", $dParams)->fetchAll();

/* ---------- Execuções recentes (não exibido ao desenvolvedor) ---------- */
$recentExec = [];
if ($role !== 'developer') {
    $eWhere = [];
    $eParams = [];
    if ($projectId) { $eWhere[] = 'tc.project_id = ?'; $eParams[] = $projectId; }
    if ($role === 'tester') { $eWhere[] = 'ex.tester_id = ?'; $eParams[] = $uid; }
    $eSql = $eWhere ? 'WHERE ' . implode(' AND ', $eWhere) : '';
    $recentExec = q("SELECT ex.id, ex.result, ex.executed_at, tc.code, tc.title, u.name tester
                     FROM executions ex JOIN test_cases tc ON tc.id = ex.case_id JOIN users u ON u.id = ex.tester_id
                     $eSql ORDER BY ex.id DESC LIMIT 6", $eParams)->fetchAll();
}

$pendingUsers = $role === 'admin' ? (int)q("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn() : 0;

$pageTitle = 'Dashboard';
$active = 'dashboard.php';
include __DIR__ . '/includes/header.php';
?>
<?php if ($toForward): ?>
<div class="alert alert-warn"><?= $toForward ?> chamado(s) aguardando encaminhamento ao desenvolvedor.
    <a href="defects.php?status=triage"><strong>Encaminhar agora</strong></a></div>
<?php endif; ?>
<?php if ($returned): ?>
<div class="alert alert-info"><?= $returned ?> chamado(s) concluído(s) pelo desenvolvedor e devolvido(s) ao Administrador (reteste pelo tester).
    <a href="defects.php?status=fixed"><strong>Ver</strong></a></div>
<?php endif; ?>
<?php if ($pendingUsers): ?>
<div class="alert alert-warn"><?= $pendingUsers ?> solicitação(ões) de cadastro aguardando aprovação.
    <a href="users.php"><strong>Analisar agora</strong></a></div>
<?php endif; ?>
<?php if ($role !== 'developer'): ?>
<form method="get" class="filters">
    <label class="field">Projeto
        <select name="project" onchange="this.form.submit()">
            <?= options($projects, $projectId ?: null, true, 'Todos os projetos') ?>
        </select>
    </label>
    <noscript><button class="btn">Filtrar</button></noscript>
</form>
<?php endif; ?>

<?php if ($role === 'developer'): ?>
    <div class="stats">
        <div class="stat red"><div class="num"><?= $dCounts['open'] ?></div><div class="lbl">Abertos</div></div>
        <div class="stat"><div class="num"><?= $dCounts['fixing'] ?></div><div class="lbl">Em andamento</div></div>
        <div class="stat teal"><div class="num"><?= $devDone ?></div><div class="lbl">Concluídos por você</div></div>
    </div>
<?php else: ?>
    <div class="stats">
        <div class="stat"><div class="num"><?= $stats['total'] ?></div><div class="lbl">Total de casos</div></div>
        <div class="stat gray"><div class="num"><?= $stats['executed'] ?></div><div class="lbl">Executados</div></div>
        <div class="stat green"><div class="num"><?= $stats['passed'] ?></div><div class="lbl">Aprovados</div></div>
        <div class="stat red"><div class="num"><?= $stats['failed'] ?></div><div class="lbl">Reprovados</div></div>
        <div class="stat amber"><div class="num"><?= $stats['blocked'] ?></div><div class="lbl">Bloqueados</div></div>
        <div class="stat red"><div class="num"><?= $openDefects ?></div><div class="lbl">Defeitos abertos</div></div>
    </div>
    <div class="card">
        <h3>Progresso da execução</h3>
        <?php $p = pct($stats['executed'], $stats['total']); ?>
        <div class="progress"><span style="width: <?= $p ?>%"></span></div>
        <p class="muted"><?= $p ?>% dos casos já executados (<?= $stats['executed'] ?> de <?= $stats['total'] ?>)</p>
    </div>
<?php endif; ?>

<div class="grid grid-2">
    <?php if ($role !== 'developer'): ?>
    <div class="card">
        <div class="card-head"><h2>Execuções recentes</h2></div>
        <div class="table-wrap"><table>
            <thead><tr><th>Caso</th><th>Resultado</th><th>Tester</th><th>Data</th></tr></thead>
            <tbody>
            <?php foreach ($recentExec as $r): ?>
                <tr>
                    <td><a href="<?= $role === 'viewer' || $role === 'admin' || $role === 'tester' ? 'execution_view.php?id=' . (int)$r['id'] : '#' ?>"><?= e($r['code']) ?></a> <?= e($r['title']) ?></td>
                    <td><?= result_badge($r['result']) ?></td>
                    <td><?= e($r['tester']) ?></td>
                    <td><?= fmt_dt($r['executed_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recentExec): ?><tr><td colspan="4" class="empty">Nenhuma execução registrada.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-head"><h2><?= $role === 'developer' ? 'Meus chamados' : 'Defeitos recentes' ?></h2>
            <a class="btn btn-sm btn-ghost" href="<?= $role === 'developer' ? 'dev_board.php' : 'defects.php' ?>"><?= $role === 'developer' ? 'Abrir Kanban' : 'Ver todos' ?></a></div>
        <div class="table-wrap"><table>
            <thead><tr><th>#</th><th>Título</th><th>Severidade</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($recentDefects as $d): ?>
                <tr>
                    <td><a href="defect_view.php?id=<?= (int)$d['id'] ?>"><?= (int)$d['id'] ?></a></td>
                    <td><?= e($d['title']) ?> <small class="muted">(<?= e($d['code']) ?>)</small></td>
                    <td><?= level_badge($d['severity']) ?></td>
                    <td><?= defect_badge($d['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recentDefects): ?><tr><td colspan="4" class="empty">Nenhum defeito.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
