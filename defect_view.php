<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$uid = (int)$user['id'];
$role = $user['role'];

$id = get_int('id');
// Autores: Teste = tester que reportou; Correção = dev responsável (ou o último que concluiu); Adm = quem criou o caso de teste
$d = q('SELECT d.*, tc.code, tc.title case_title, p.name project, dev.name developer, rep.name reporter, adm.name case_author,
               (SELECT u2.name FROM defect_comments c JOIN users u2 ON u2.id = c.user_id
                 WHERE c.defect_id = d.id AND c.new_status = \'fixed\' ORDER BY c.id DESC LIMIT 1) fixer_name,
               (SELECT c.created_at FROM defect_comments c WHERE c.defect_id = d.id AND c.new_status = \'fixed\' ORDER BY c.id DESC LIMIT 1) fixed_at
        FROM defects d JOIN test_cases tc ON tc.id = d.case_id JOIN projects p ON p.id = d.project_id
        LEFT JOIN users dev ON dev.id = d.assigned_to LEFT JOIN users adm ON adm.id = tc.created_by
        JOIN users rep ON rep.id = d.reported_by WHERE d.id = ?', [$id])->fetch();
if (!$d) { not_found(); }
// Desenvolvedor: depois de concluir, o chamado deixa de ser acessível a ele (volta ao Admin)
if (!can_view_defect($user, $d)) { forbidden(); }

$isAdmin = $role === 'admin';
$isDev = $role === 'developer';
// Desenvolvedor só age enquanto o chamado está com ele (aberto/em andamento). Concluído = somente leitura.
$devReadOnly = $isDev && !dev_can_act($user, $d);
$canComment = $role !== 'viewer' && !$devReadOnly;

// Status que o desenvolvedor pode escolher (sem "Reaberto"); "Concluído" devolve o chamado ao Admin
const DEV_STATUS_CHOICES = ['open' => 'Aberto', 'fixing' => 'Em andamento', 'fixed' => 'Concluído (devolve o chamado ao Admin)'];

if (is_post()) {
    csrf_check();
    $action = post('action');

    /* ---- Admin: tomada de decisão (urgência + UM destino: desenvolvedor OU tester/reteste) ---- */
    if ($isAdmin && $action === 'decide') {
        $target = post('target');
        try {
            decide_defect($id, $target, post_int($target === 'tester' ? 'tester_id' : 'developer_id'), post('urgency'), $uid);
            flash('success', $target === 'tester' ? 'Chamado enviado ao tester para reteste.' : 'Chamado encaminhado ao desenvolvedor.');
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

    if ($devReadOnly) {
        flash('error', 'Este chamado já foi concluído: ele fica em Concluído, somente leitura.');
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
            flash('success', 'Chamado #' . $id . ' concluído e devolvido ao Administrador. Ele fica na coluna Concluído, somente leitura.');
            redirect('dev_board.php');
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
// Reteste: só o tester escolhido pelo Admin na tomada de decisão
$canRetest = $role === 'tester' && $d['status'] === 'retest' && (int)$d['retest_tester_id'] === $uid && tester_has_case($uid, (int)$d['case_id']);
$devs = $isAdmin ? users_by_role('developer') : [];
$testers = $isAdmin ? case_testers((int)$d['case_id']) : [];
$retestTester = $d['retest_tester_id'] ? q('SELECT name FROM users WHERE id = ?', [$d['retest_tester_id']])->fetchColumn() : null;

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
        <dt>Autor do Teste</dt><dd><strong><?= e($d['reporter']) ?></strong> <span class="muted">· reportado em <?= fmt_dt($d['created_at']) ?></span></dd>
        <dt>Autor da Correção</dt>
        <dd><?php
            // Quem concluiu a correção; se ainda não concluiu, o desenvolvedor responsável no momento
            if ($d['fixer_name']): ?><strong><?= e($d['fixer_name']) ?></strong> <span class="muted">· concluiu em <?= fmt_dt($d['fixed_at']) ?></span>
            <?php elseif ($d['developer']): ?><strong><?= e($d['developer']) ?></strong> <span class="muted">· responsável, correção em andamento</span>
            <?php else: ?><span class="muted">Ainda não encaminhado ao desenvolvedor</span><?php endif; ?></dd>
        <dt>Autor Adm</dt><dd><?= $d['case_author'] ? '<strong>' . e($d['case_author']) . '</strong> <span class="muted">· criou o caso de teste ' . e($d['code']) . '</span>' : '<span class="muted">—</span>' ?></dd>
        <?php if ($retestTester && $d['status'] === 'retest'): ?><dt>Reteste com</dt><dd><?= e($retestTester) ?></dd><?php endif; ?>
        <dt>Relato do tester</dt><dd class="pre"><?= e($d['description']) ?></dd>
        <dt>Evidências do teste</dt><dd><?= evidence_gallery($evidences) ?></dd>
    </dl>
</div>

<?php if ($isAdmin && $d['status'] !== 'closed'):
    // Sugestão de destino: quando o dev devolveu o chamado, o passo natural é o reteste; nos demais casos, o desenvolvedor
    $defaultTarget = ($d['status'] === 'fixed' && $testers) || !$devs ? 'tester' : 'developer';
    if ($defaultTarget === 'tester' && !$testers) { $defaultTarget = 'developer'; }
?>
<div class="card">
    <div class="card-head">
        <h3 style="margin:0">Tomada de decisão</h3>
        <span class="muted">Situação atual: <?= defect_badge($d['status']) ?></span>
    </div>
    <p class="muted" style="margin-top:0">Defina a urgência e escolha <strong>um</strong> destino: o <strong>Desenvolvedor</strong> corrige e devolve o chamado a você;
        o <strong>Tester</strong> recebe o caso de volta no Kanban como <strong>Reteste</strong> (pode ser já na triagem).</p>
    <?php if (!$devs && !$testers): ?>
        <p class="alert alert-warn">Não há desenvolvedor ativo nem tester atribuído ao caso. <a href="users.php">Cadastre/aprove usuários</a> ou <a href="assignments.php?project=<?= (int)$d['project_id'] ?>">atribua o caso a um tester</a>.</p>
    <?php else: ?>
    <form method="post" data-decision>
        <?= csrf_field() ?><input type="hidden" name="action" value="decide">
        <label class="field" style="max-width:260px">Urgência
            <select name="urgency" required><?= options(LEVELS, $d['status'] === 'triage' ? 'medium' : $d['priority']) ?></select>
        </label>
        <div class="decision-choice" role="radiogroup" aria-label="Destino do chamado">
            <label class="choice <?= $devs ? '' : 'off' ?>">
                <input type="radio" name="target" value="developer" <?= $defaultTarget === 'developer' ? 'checked' : '' ?> <?= $devs ? '' : 'disabled' ?>>
                <span><strong><?= icon('bug', 16) ?> Desenvolvedor</strong><small>Corrige e devolve o chamado para a sua decisão.<?= $devs ? '' : ' Nenhum desenvolvedor ativo.' ?></small></span>
            </label>
            <label class="choice <?= $testers ? '' : 'off' ?>">
                <input type="radio" name="target" value="tester" <?= $defaultTarget === 'tester' ? 'checked' : '' ?> <?= $testers ? '' : 'disabled' ?>>
                <span><strong><?= icon('play', 16) ?> Tester — Reteste</strong><small>O caso volta ao Kanban do tester como Reteste.<?= $testers ? '' : ' Nenhum tester atribuído ao caso.' ?></small></span>
            </label>
        </div>
        <?php if ($devs): ?>
        <div data-target-panel="developer">
            <label class="field" style="max-width:360px">Desenvolvedor
                <select name="developer_id"><?= options($devs, $d['assigned_to']) ?></select>
            </label>
        </div>
        <?php endif; ?>
        <?php if ($testers): ?>
        <div data-target-panel="tester">
            <label class="field" style="max-width:360px">Tester
                <select name="tester_id"><?= options($testers, $d['retest_tester_id'] ?: $d['reported_by']) ?></select>
            </label>
        </div>
        <?php endif; ?>
        <div class="form-actions"><button class="btn" type="submit"><?= icon('check', 16) ?> Confirmar decisão</button></div>
    </form>
    <?php endif; ?>
    <form method="post" data-confirm="Fechar este chamado sem encaminhar?" style="margin-top:14px">
        <?= csrf_field() ?><input type="hidden" name="action" value="close">
        <button class="btn btn-ghost btn-sm" type="submit">Fechar chamado sem encaminhar</button>
    </form>
</div>
<?php endif; ?>

<?php if ($devReadOnly): ?>
<div class="alert alert-info">Você concluiu este chamado. Ele está na coluna <strong>Concluído</strong> do seu Kanban, <strong>somente leitura</strong>
    — a situação atual (<?= defect_badge($d['status']) ?>) é conduzida pelo Administrador.</div>
<?php endif; ?>
<?php if ($canComment && $d['status'] !== 'closed'): ?>
<form method="post" enctype="multipart/form-data" class="card">
    <?= csrf_field() ?><input type="hidden" name="action" value="update">
    <h3><?= $isDev ? 'Retorno do desenvolvedor' : 'Comentário e fotos' ?></h3>
    <div class="grid">
        <?php if ($isDev): ?>
        <label class="field" style="max-width:380px">Status
            <select name="status"><?= options(DEV_STATUS_CHOICES, $d['status']) ?></select>
            <small>Ao concluir, o chamado volta para o Administrador e fica na coluna Concluído, somente leitura (você não poderá mais alterá-lo).</small>
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
