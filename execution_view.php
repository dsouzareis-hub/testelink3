<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_role('admin', 'viewer', 'tester');
$uid = (int)$user['id'];
$role = $user['role'];

$id = get_int('id');
$ex = q('SELECT ex.*, u.name tester, tc.code, tc.title, tc.project_id, p.name project, s.name suite
         FROM executions ex JOIN users u ON u.id = ex.tester_id JOIN test_cases tc ON tc.id = ex.case_id
         JOIN projects p ON p.id = tc.project_id JOIN suites s ON s.id = tc.suite_id WHERE ex.id = ?', [$id])->fetch();
if (!$ex) { not_found(); }
if ($role === 'tester' && (int)$ex['tester_id'] !== $uid) { forbidden(); }

$defect = q('SELECT id, title, status FROM defects WHERE execution_id = ?', [$id])->fetch();
$canForward = $role === 'tester' && $ex['result'] === 'failed' && !$defect;

// Encaminhamento tardio (execução reprovada ainda sem defeito)
if (is_post() && $canForward) {
    csrf_check();
    try {
        $dId = create_defect($id, defect_data_from_post(), $uid);
        flash('success', "Defeito #$dId encaminhado ao desenvolvedor.");
        redirect('execution_view.php?id=' . $id);
    } catch (InvalidArgumentException $e) {
        flash('error', $e->getMessage());
    }
}

$evidences = execution_evidences($id);

$pageTitle = 'Execução #' . $id;
$active = 'history.php';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
    <div class="card-head">
        <h2>Execução #<?= $id ?> — <a href="case_view.php?id=<?= (int)$ex['case_id'] ?>"><?= e($ex['code']) ?></a> <?= e($ex['title']) ?></h2>
        <?= result_badge($ex['result']) ?>
    </div>
    <dl class="detail-grid">
        <dt>Projeto / Suíte</dt><dd><?= e($ex['project']) ?> / <?= e($ex['suite']) ?></dd>
        <dt>Tester</dt><dd><?= e($ex['tester']) ?></dd>
        <dt>Data</dt><dd><?= fmt_dt($ex['executed_at']) ?></dd>
        <dt>Versão do CT</dt><dd>v<?= (int)$ex['case_version'] ?></dd>
        <dt>Assentamento</dt><dd class="pre"><?= e($ex['notes'] ?: '—') ?></dd>
        <dt>Evidências</dt><dd><?= evidence_gallery($evidences) ?></dd>
        <dt>Defeito</dt>
        <dd><?= $defect
            ? '<a href="defect_view.php?id=' . (int)$defect['id'] . '">#' . (int)$defect['id'] . ' — ' . e($defect['title']) . '</a> ' . defect_badge($defect['status'])
            : '<span class="muted">Nenhum</span>' ?></dd>
    </dl>
    <p class="no-print"><a class="btn btn-ghost" href="report_case.php?id=<?= (int)$ex['case_id'] ?>"><?= icon('printer', 16) ?> Relatório do caso</a></p>
</div>

<?php if ($canForward): ?>
<form method="post" class="card">
    <?= csrf_field() ?>
    <?php render_forward_fields('[' . $ex['code'] . '] ' . $ex['title']); ?>
    <div class="form-actions"><button class="btn" type="submit"><?= icon('send', 16) ?> Encaminhar para Desenvolvedor</button></div>
</form>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
