<?php
require __DIR__ . '/includes/bootstrap.php';
require_role('admin', 'viewer');

$fProject = get_int('project');
$fSeverity = $_GET['severity'] ?? '';
$includeAll = !empty($_GET['all']);

$where = [];
$params = [];
if (!$includeAll) { $where[] = "d.status IN ('triage','open','fixing','reopened')"; }
if ($fProject) { $where[] = 'd.project_id = ?'; $params[] = $fProject; }
if (isset(LEVELS[$fSeverity])) { $where[] = 'd.severity = ?'; $params[] = $fSeverity; }

$rows = q('SELECT d.id, d.title, d.severity, d.priority, d.status, d.created_at, tc.code, p.name project,
                  dev.name developer, rep.name reporter
           FROM defects d JOIN test_cases tc ON tc.id = d.case_id JOIN projects p ON p.id = d.project_id
           LEFT JOIN users dev ON dev.id = d.assigned_to JOIN users rep ON rep.id = d.reported_by
           ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . '
           ORDER BY FIELD(d.severity, \'critical\',\'high\',\'medium\',\'low\'), d.created_at', $params)->fetchAll();

$bySev = array_fill_keys(array_keys(LEVELS), 0);
foreach ($rows as $r) { $bySev[$r['severity']]++; }

$pageTitle = $includeAll ? 'Relatório de Defeitos' : 'Relatório de Defeitos Abertos';
$active = 'reports.php';
include __DIR__ . '/includes/header.php';
?>
<?php report_toolbar($pageTitle, 'defects'); ?>

<form method="get" class="filters no-print">
    <label class="field">Projeto <select name="project"><?= options(project_list(), $fProject ?: null, true, 'Todos') ?></select></label>
    <label class="field">Severidade <select name="severity"><?= options(LEVELS, $fSeverity, true) ?></select></label>
    <label class="check"><input type="checkbox" name="all" value="1" <?= $includeAll ? 'checked' : '' ?>> Incluir corrigidos/fechados</label>
    <button class="btn" type="submit">Gerar</button>
</form>

<div class="stats">
    <div class="stat"><div class="num"><?= count($rows) ?></div><div class="lbl">Defeitos listados</div></div>
    <div class="stat red"><div class="num"><?= $bySev['critical'] ?></div><div class="lbl">Críticos</div></div>
    <div class="stat amber"><div class="num"><?= $bySev['high'] ?></div><div class="lbl">Altos</div></div>
    <div class="stat"><div class="num"><?= $bySev['medium'] ?></div><div class="lbl">Médios</div></div>
    <div class="stat gray"><div class="num"><?= $bySev['low'] ?></div><div class="lbl">Baixos</div></div>
</div>

<div class="card">
    <div class="table-wrap"><table>
        <thead><tr><th>#</th><th>Título</th><th>Caso</th><th>Projeto</th><th>Severidade</th><th>Prior.</th><th>Status</th><th>Responsável</th><th>Aberto em</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $d): ?>
            <tr>
                <td><a href="defect_view.php?id=<?= (int)$d['id'] ?>"><?= (int)$d['id'] ?></a></td>
                <td><?= e($d['title']) ?></td>
                <td><?= e($d['code']) ?></td>
                <td><?= e($d['project']) ?></td>
                <td><?= level_badge($d['severity']) ?></td>
                <td><?= e(LEVELS[$d['priority']]) ?></td>
                <td><?= defect_badge($d['status']) ?></td>
                <td><?= $d['developer'] ? e($d['developer']) : 'Não encaminhado' ?></td>
                <td><?= fmt_date($d['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="9" class="empty">Nenhum defeito encontrado.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
