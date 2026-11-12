<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/kanban.php';
$user = require_role('admin', 'viewer', 'tester');
$uid = (int)$user['id'];
$role = $user['role'];

$id = get_int('id');
$ex = q('SELECT ex.*, u.name tester, tc.code, tc.title, tc.project_id, p.name project, s.name suite
         FROM executions ex JOIN users u ON u.id = ex.tester_id JOIN test_cases tc ON tc.id = ex.case_id
         JOIN projects p ON p.id = tc.project_id JOIN suites s ON s.id = tc.suite_id WHERE ex.id = ?', [$id])->fetch();
if (!$ex) { not_found(); }
if ($role === 'tester' && (int)$ex['tester_id'] !== $uid) { forbidden(); }

// Quem pode seguir comentando e anexando fotos: o tester dono da execução e o administrador
$canComment = $role === 'admin' || ($role === 'tester' && (int)$ex['tester_id'] === $uid);

if (is_post() && $canComment) {
    csrf_check();
    $comment = post('comment');
    $hasFiles = !empty($_FILES['evidences']['name']) && is_array($_FILES['evidences']['name'])
        && count(array_filter($_FILES['evidences']['error'], fn($e) => $e !== UPLOAD_ERR_NO_FILE)) > 0;

    if ($comment === '' && !$hasFiles) {
        flash('error', 'Escreva um comentário ou anexe ao menos uma foto.');
        redirect('execution_view.php?id=' . $id . '#comentarios');
    }
    if ($comment !== '') {
        q('INSERT INTO execution_comments (execution_id, user_id, comment) VALUES (?,?,?)', [$id, $uid, $comment]);
    }
    [$saved, $uploadErrors] = save_evidences($id);
    foreach ($uploadErrors as $m) { flash('warn', $m); }

    $msg = 'Registro adicionado' . ($saved ? " ($saved foto(s))" : '') . '.';
    // Se esta é a última execução do tester neste caso, o novo comentário/foto pode concluir o cartão (Aprovado + comentário)
    if ($role === 'tester') {
        $latest = (int)q('SELECT MAX(id) FROM executions WHERE case_id = ? AND tester_id = ?', [$ex['case_id'], $uid])->fetchColumn();
        if ($latest === $id) {
            $km = kanban_after_execution((int)$ex['case_id'], $uid, $ex['result']);
            $msg .= $km ? ' ' . $km : '';
        }
    }
    flash('success', $msg);
    redirect('execution_view.php?id=' . $id . '#comentarios');
}

$defect = q('SELECT id, title, status FROM defects WHERE execution_id = ?', [$id])->fetch();
$evidences = execution_evidences($id);
$comments = q('SELECT c.*, u.name author FROM execution_comments c JOIN users u ON u.id = c.user_id
               WHERE c.execution_id = ? ORDER BY c.id', [$id])->fetchAll();
$cardStatus = $role === 'tester'
    ? q('SELECT kanban_status FROM assignments WHERE case_id = ? AND tester_id = ?', [$ex['case_id'], $uid])->fetchColumn() : null;

$pageTitle = 'Execução #' . $id;
$active = $role === 'tester' ? 'my_tests.php' : 'history.php';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
    <div class="card-head">
        <h2>Execução #<?= $id ?> — <a href="case_view.php?id=<?= (int)$ex['case_id'] ?>"><?= e($ex['code']) ?></a> <?= e($ex['title']) ?></h2>
        <div><?= result_badge($ex['result']) ?>
            <?php if ($cardStatus): ?> <?= badge('Kanban: ' . KANBAN_COLUMNS[$cardStatus], $cardStatus === 'done' ? 'green' : 'gray') ?><?php endif; ?></div>
    </div>
    <dl class="detail-grid">
        <dt>Projeto / Suíte</dt><dd><?= e($ex['project']) ?> / <?= e($ex['suite']) ?></dd>
        <dt>Tester</dt><dd><?= e($ex['tester']) ?></dd>
        <dt>Data</dt><dd><?= fmt_dt($ex['executed_at']) ?></dd>
        <dt>Tipo</dt><dd><?= badge(EXEC_TYPES[$ex['exec_type']] ?? $ex['exec_type'], $ex['exec_type'] === 'automated' ? 'purple' : 'blue') ?></dd>
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

<div class="card" id="comentarios">
    <h3>Comentários</h3>
    <?php if ($comments): ?>
    <ul class="timeline">
        <?php foreach ($comments as $c): ?>
            <li>
                <div class="meta"><strong><?= e($c['author']) ?></strong> · <?= fmt_dt($c['created_at']) ?></div>
                <div class="pre"><?= e($c['comment']) ?></div>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php else: ?><p class="muted">Nenhum comentário adicional. O assentamento acima é o registro original da execução.</p><?php endif; ?>

    <?php if ($canComment): ?>
    <form method="post" enctype="multipart/form-data" style="margin-top:14px">
        <?= csrf_field() ?>
        <div class="grid">
            <label class="field">Adicionar comentário
                <textarea name="comment" rows="3" placeholder="Observações adicionais, esclarecimentos…"></textarea>
            </label>
            <label class="field">Fotos (JPG, PNG ou WEBP — até <?= (int)(max_upload_bytes() / 1048576) ?> MB cada)
                <input type="file" name="evidences[]" accept="image/jpeg,image/png,image/webp" multiple data-preview-input>
            </label>
            <div class="gallery preview-list" data-preview-box></div>
        </div>
        <div class="form-actions"><button class="btn" type="submit">Adicionar</button></div>
    </form>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
