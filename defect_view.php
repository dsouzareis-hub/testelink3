<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$uid = (int)$user['id'];
$role = $user['role'];

$id = get_int('id');
$d = q('SELECT d.*, tc.code, tc.title case_title, p.name project, dev.name developer, rep.name reporter
        FROM defects d JOIN test_cases tc ON tc.id = d.case_id JOIN projects p ON p.id = d.project_id
        JOIN users dev ON dev.id = d.assigned_to JOIN users rep ON rep.id = d.reported_by WHERE d.id = ?', [$id])->fetch();
if (!$d) { not_found(); }
if (!can_view_defect($user, $d)) { forbidden(); }

// Quem pode alterar o status: desenvolvedor responsável e administrador. Todos (exceto leitura) podem comentar.
$canChangeStatus = $role === 'admin' || ($role === 'developer' && (int)$d['assigned_to'] === $uid);
$canComment = $role !== 'viewer';

if (is_post() && $canComment) {
    csrf_check();
    $comment = post('comment');
    $newStatus = post('status');

    if ($canChangeStatus && isset(DEFECT_STATUS[$newStatus]) && $newStatus !== $d['status']) {
        // O fechamento é feito pelo reteste do tester; dev/admin usam os 4 status do fluxo (admin também pode fechar)
        if ($newStatus === 'closed' && $role !== 'admin') {
            flash('error', 'Somente o reteste aprovado (ou o administrador) fecha o defeito.');
            redirect('defect_view.php?id=' . $id);
        }
        set_defect_status($id, $newStatus, $uid, $comment ?: null);
        flash('success', 'Status atualizado para ' . DEFECT_STATUS[$newStatus] . '.');
    } elseif ($comment !== '') {
        add_defect_comment($id, $uid, $comment, null, null);
        flash('success', 'Comentário adicionado.');
    } else {
        flash('error', 'Escreva um comentário ou escolha um novo status.');
    }
    redirect('defect_view.php?id=' . $id);
}

$history = q('SELECT c.*, u.name author FROM defect_comments c JOIN users u ON u.id = c.user_id
              WHERE c.defect_id = ? ORDER BY c.id DESC', [$id])->fetchAll();
$evidences = execution_evidences((int)$d['execution_id']);
$canRetest = $role === 'tester' && $d['status'] === 'fixed' && tester_has_case($uid, (int)$d['case_id']);

$pageTitle = 'Defeito #' . $id;
$active = 'defects.php';
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
        <dd><?php if ($role === 'developer'): ?><?= e($d['code']) ?> — <?= e($d['case_title']) ?>
            <?php else: ?><a href="case_view.php?id=<?= (int)$d['case_id'] ?>"><?= e($d['code']) ?></a> — <?= e($d['case_title']) ?><?php endif; ?></dd>
        <dt>Execução de origem</dt>
        <dd><?php if ($role === 'developer'): ?>#<?= (int)$d['execution_id'] ?>
            <?php else: ?><a href="execution_view.php?id=<?= (int)$d['execution_id'] ?>">#<?= (int)$d['execution_id'] ?></a><?php endif; ?></dd>
        <dt>Severidade / Prioridade</dt><dd><?= level_badge($d['severity']) ?> <?= level_badge($d['priority']) ?></dd>
        <dt>Reportado por</dt><dd><?= e($d['reporter']) ?> em <?= fmt_dt($d['created_at']) ?></dd>
        <dt>Responsável</dt><dd><?= e($d['developer']) ?></dd>
        <dt>Descrição</dt><dd class="pre"><?= e($d['description']) ?></dd>
        <dt>Evidências</dt><dd><?= evidence_gallery($evidences) ?></dd>
    </dl>
</div>

<?php if ($canComment): ?>
<form method="post" class="card">
    <?= csrf_field() ?>
    <h3>Atualizar defeito</h3>
    <div class="grid">
        <?php if ($canChangeStatus): ?>
        <label class="field" style="max-width:320px">Status
            <select name="status">
                <?php foreach (DEFECT_STATUS as $k => $label):
                    if ($k === 'closed' && $role !== 'admin' && $d['status'] !== 'closed') continue; ?>
                    <option value="<?= e($k) ?>" <?= $k === $d['status'] ? 'selected' : '' ?>><?= e($label) ?><?= $k === 'fixed' ? ' (resolvido — pronto p/ reteste)' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
        <label class="field">Comentário de retorno
            <textarea name="comment" rows="4" placeholder="Causa raiz, correção aplicada, observações…"></textarea>
        </label>
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
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
