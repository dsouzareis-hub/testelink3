<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$uid = (int)$user['id'];
$role = $user['role'];

$fStatus = $_GET['status'] ?? '';
$fProject = get_int('project');
$fSeverity = $_GET['severity'] ?? '';

// Escopo por perfil: dev vê só os seus; tester vê os que reportou ou de seus casos
$where = ['1=1'];
$params = [];
if ($role === 'developer') {
    // Só o que está com ele; depois de concluir, o chamado volta ao Admin e some daqui
    $where[] = "d.assigned_to = ? AND d.status IN ('open','fixing')"; $params[] = $uid;
} elseif ($role === 'tester') {
    $where[] = '(d.reported_by = ? OR d.case_id IN (SELECT case_id FROM assignments WHERE tester_id = ?))';
    array_push($params, $uid, $uid);
}
if ($fStatus === 'open_all') {
    $where[] = "d.status IN ('triage','open','fixing','fixed','retest','reopened')";
} elseif ($fStatus === 'decision') {
    // Com o Admin, aguardando a tomada de decisão
    $where[] = "d.status IN ('triage','fixed','reopened')";
} elseif (isset(DEFECT_STATUS[$fStatus])) {
    $where[] = 'd.status = ?'; $params[] = $fStatus;
}
if ($fProject) { $where[] = 'd.project_id = ?'; $params[] = $fProject; }
if (isset(LEVELS[$fSeverity])) { $where[] = 'd.severity = ?'; $params[] = $fSeverity; }

$rows = q('SELECT d.id, d.title, d.severity, d.priority, d.status, d.updated_at, tc.code, p.name project,
                  dev.name developer, rep.name reporter
           FROM defects d JOIN test_cases tc ON tc.id = d.case_id JOIN projects p ON p.id = d.project_id
           LEFT JOIN users dev ON dev.id = d.assigned_to JOIN users rep ON rep.id = d.reported_by
           WHERE ' . implode(' AND ', $where) . ' ORDER BY d.updated_at DESC LIMIT 300', $params)->fetchAll();

$pageTitle = 'Defeitos';
$active = 'defects.php';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
    <form method="get" class="filters">
        <label class="field">Status
            <select name="status"><?= options(['open_all' => 'Em aberto (exceto fechados)', 'decision' => 'Aguardando decisão do Admin'] + DEFECT_STATUS, $fStatus, true) ?></select>
        </label>
        <label class="field">Severidade <select name="severity"><?= options(LEVELS, $fSeverity, true) ?></select></label>
        <?php if ($role !== 'developer'): ?>
        <label class="field">Projeto <select name="project"><?= options(project_list(), $fProject ?: null, true) ?></select></label>
        <?php endif; ?>
        <button class="btn" type="submit">Filtrar</button>
    </form>
    <div class="table-wrap"><table>
        <thead><tr><th>#</th><th>Título</th><th>Caso</th><th>Severidade</th><th>Prioridade</th><th>Status</th><th>Responsável</th><th>Atualizado</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $d): ?>
            <tr>
                <td><a href="defect_view.php?id=<?= (int)$d['id'] ?>"><?= (int)$d['id'] ?></a></td>
                <td><a href="defect_view.php?id=<?= (int)$d['id'] ?>"><?= e($d['title']) ?></a><br><small class="muted"><?= e($d['project']) ?> · por <?= e($d['reporter']) ?></small></td>
                <td><?= e($d['code']) ?></td>
                <td><?= level_badge($d['severity']) ?></td>
                <td><?= level_badge($d['priority']) ?></td>
                <td><?= defect_badge($d['status']) ?></td>
                <td><?= $d['developer'] ? e($d['developer']) : '<span class="muted">Não encaminhado</span>' ?></td>
                <td><?= fmt_dt($d['updated_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="8" class="empty">Nenhum defeito encontrado.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
