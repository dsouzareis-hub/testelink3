<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$uid = (int)$user['id'];
$role = $user['role'];

$id = get_int('id');
$d = q('SELECT d.*, tc.code, tc.title case_title, p.name project, dev.name developer, rep.name reporter
        FROM defects d JOIN test_cases tc ON tc.id = d.case_id JOIN projects p ON p.id = d.project_id
        LEFT JOIN users dev ON dev.id = d.assigned_to JOIN users rep ON rep.id = d.reported_by WHERE d.id = ?', [$id])->fetch();
if (!$d) { not_found(); }
// Desenvolvedor: depois de concluir, o chamado deixa de ser acessível a ele (volta ao Admin)
if (!can_view_defect($user, $d)) { forbidden(); }

$isAdmin = $role === 'admin';
$isDev = $role === 'developer';            // can_view_defect garante: é o responsável e o chamado está com ele
$canComment = $role !== 'viewer';

// Status que o desenvolvedor pode escolher (sem "Reaberto"); "Concluído" devolve o chamado ao Admin
const DEV_STATUS_CHOICES = ['open' => 'Aberto', 'fixing' => 'Em andamento', 'fixed' => 'Concluído (devolve o chamado ao Admin)'];

if (is_post()) {
    csrf_check();
    $action = post('action');

    /* ---- Admin: encaminhar ao desenvolvedor (somente urgência + desenvolvedor) ---- */
    if ($isAdmin && $action === 'forward') {
        try {
            forward_defect($id, post_int('developer_id'), post('urgency'), $uid);
            flash('success', 'Chamado encaminhado ao desenvolvedor.');
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        }
        redirect('defect_view.php?id=' . $id);
    }

    /* ---- Admin: fechar o chamado ---- */
    if ($isAdmin && $action === 'close') {
        set_defect_status($id, 'closed', $uid, 'Chamado fechado pelo Administrador.');
        flash('success', 'Chamado fechado.');
        redirect('defect_view.php?id=' . $id);
    }

    /* ---- Comentário + fotos (+ status, no caso do desenvolvedor) ---- */
    if ($action === 'update' && $canComment) {
        $comment = post('comment');
        $newStatus = $isDev ? post('status') : '';
        $hasFiles = !empty($_FILES['photos']['name']) && is_array($_FILES['photos']['name'])
            && count(array_filter($_FILES['photos']['error'], fn($e) => $e !== UPLOAD_ERR_NO_FILE)) > 0;

        if ($isDev && $newStatus !== '' && !isset(DEV_STATUS_CHOICES[$newStatus])) {
            flash('error', 'Status inválido.');
            redirect('defect_view.php?id=' . $id);
        }
        $statusChanged = $isDev && $newStatus !== '' && $newStatus !== $d['status'];
        if ($comment === '' && !$hasFiles && !$statusChanged) {
            flash('error', 'Escreva um comentário, anexe fotos ou escolha um novo status.');
            redirect('defect_view.php?id=' . $id);
        }

        $recordId = $statusChanged
            ? set_defect_status($id, $newStatus, $uid, $comment ?: null)
            : add_defect_comment($id, $uid, $comment ?: null, null, null);
        [$saved, $uploadErrors] = save_defect_images($id, $recordId, $uid);
        foreach ($uploadErrors as $m) { flash('warn', $m); }

        if ($statusChanged && $newStatus === 'fixed') {
            flash('success', 'Chamado #' . $id . ' concluído e devolvido ao Administrador.');
            redirect('dev_board.php');      // o dev já não tem mais acesso a este chamado
        }
        flash('success', $statusChanged ? 'Status atualizado para ' . DEFECT_STATUS[$newStatus] . '.' : 'Registro adicionado.');
        redirect('defect_view.php?id=' . $id);
    }
    redirect('defect_view.php?id=' . $id);
}

$history = q('SELECT c.*, u.name author FROM defect_comments c JOIN users u ON u.id = c.user_id
              WHERE c.defect_id = ? ORDER BY c.id DESC', [$id])->fetchAll();
$attByComment = [];
foreach (q('SELECT id, comment_id, original_name FROM defect_attachments WHERE defect_id = ? ORDER BY id', [$id]) as $a) {
    $attByComment[(int)$a['comment_id']][] = $a;
}
$evidences = execution_evidences((int)$d['execution_id']);
$canRetest = $role === 'tester' && $d['status'] === 'fixed' && tester_has_case($uid, (int)$d['case_id']);
$devs = $isAdmin ? users_by_role('developer') : [];

$pageTitle = 'Chamado #' . $id;
$active = $isDev ? 'dev_board.php' : 'defects.php';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
    <div class="card-head">
        <h2>#<?= $id ?> · <?= e($d['title']) ?></h2>
        <div>
            <?= defect_badge($d['status']) ?>
            <?php if ($canRetest): ?>
                <a class="btn btn-success" href="execute.php?case_id=<?= (int)$d['case_id'] ?>&defect_id=<?= $id ?>"><?= icon('play', 16) ?> Realizar reteste</a>
            <?php endif; ?>
        </div>
    </div>
    <dl class="detail-grid">
        <dt>Projeto</dt><dd><?= e($d['project']) ?></dd>
        <dt>Caso de teste</dt>
        <dd><?php if ($isDev): ?><?= e($d['code']) ?> — <?= e($d['case_title']) ?>
            <?php else: ?><a href="case_view.php?id=<?= (int)$d['case_id'] ?>"><?= e($d['code']) ?></a> — <?= e($d['case_title']) ?><?php endif; ?></dd>
        <dt>Execução de origem</dt>
        <dd><?php if ($isDev): ?>#<?= (int)$d['execution_id'] ?>
            <?php else: ?><a href="execution_view.php?id=<?= (int)$d['execution_id'] ?>">#<?= (int)$d['execution_id'] ?></a><?php endif; ?></dd>
        <dt>Severidade / Urgência</dt>
        <dd><?= level_badge($d['severity']) ?> <?= $d['status'] === 'triage' ? '<span class="muted">urgência definida no encaminhamento</span>' : level_badge($d['priority']) ?></dd>
        <dt>Reportado por</dt><dd><?= e($d['reporter']) ?> em <?= fmt_dt($d['created_at']) ?></dd>
        <dt>Desenvolvedor</dt><dd><?= $d['developer'] ? e($d['developer']) : '<span class="muted">Ainda não encaminhado</span>' ?></dd>
        <dt>Relato do tester</dt><dd class="pre"><?= e($d['description']) ?></dd>
        <dt>Evidências do teste</dt><dd><?= evidence_gallery($evidences) ?></dd>
    </dl>
</div>

<?php if ($isAdmin && $d['status'] !== 'closed'): ?>
<div class="card">
    <h3><?= in_array($d['status'], ['triage'], true) ? 'Encaminhar para o Desenvolvedor' : 'Reencaminhar para o Desenvolvedor' ?></h3>
    <?php if (!$devs): ?>
        <p class="alert alert-warn">Não há desenvolvedores ativos. <a href="users.php?new=1">Cadastre ou aprove um usuário</a> com perfil Desenvolvedor.</p>
    <?php else: ?>
    <form method="post" class="filters" style="margin-bottom:0">
        <?= csrf_field() ?><input type="hidden" name="action" value="forward">
        <label class="field">Urgência
            <select name="urgency" required><?= options(LEVELS, $d['status'] === 'triage' ? 'medium' : $d['priority']) ?></select>
        </label>
        <label class="field">Desenvolvedor
            <select name="developer_id" required><?= options($devs, $d['assigned_to']) ?></select>
        </label>
        <button class="btn" type="submit"><?= icon('send', 16) ?> Encaminhar</button>
    </form>
    <?php endif; ?>
    <form method="post" data-confirm="Fechar este chamado?" style="margin-top:14px">
        <?= csrf_field() ?><input type="hidden" name="action" value="close">
        <button class="btn btn-ghost btn-sm" type="submit">Fechar chamado sem encaminhar</button>
    </form>
</div>
<?php endif; ?>

<?php if ($canComment && $d['status'] !== 'closed'): ?>
<form method="post" enctype="multipart/form-data" class="card">
    <?= csrf_field() ?><input type="hidden" name="action" value="update">
    <h3><?= $isDev ? 'Retorno do desenvolvedor' : 'Comentário e fotos' ?></h3>
    <div class="grid">
        <?php if ($isDev): ?>
        <label class="field" style="max-width:380px">Status
            <select name="status"><?= options(DEV_STATUS_CHOICES, $d['status']) ?></select>
            <small>Ao concluir, você deixa de ter acesso ao chamado e ele volta para o Administrador.</small>
        </label>
        <?php endif; ?>
        <label class="field">Comentário
            <textarea name="comment" rows="4" placeholder="Causa raiz, correção aplicada, observações…"></textarea>
        </label>
        <label class="field">Fotos (JPG, PNG ou WEBP — até <?= (int)(max_upload_bytes() / 1048576) ?> MB cada)
            <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple data-preview-input>
        </label>
        <div class="gallery preview-list" data-preview-box></div>
    </div>
    <div class="form-actions"><button class="btn" type="submit">Salvar</button></div>
</form>
<?php endif; ?>

<div class="card">
    <h3>Histórico</h3>
    <ul class="timeline">
        <?php foreach ($history as $h): ?>
            <li>
                <div class="meta"><strong><?= e($h['author']) ?></strong> · <?= fmt_dt($h['created_at']) ?>
                    <?php if ($h['new_status']): ?>
                        · <?= $h['old_status'] ? defect_badge($h['old_status']) . ' → ' : '' ?><?= defect_badge($h['new_status']) ?>
                    <?php endif; ?>
                </div>
                <?php if ($h['comment']): ?><div class="pre"><?= e($h['comment']) ?></div><?php endif; ?>
                <?php if (!empty($attByComment[(int)$h['id']])): ?><?= image_gallery($attByComment[(int)$h['id']], 'att') ?><?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
