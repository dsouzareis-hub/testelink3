<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/kanban.php';
$user = require_role('developer');
$uid = (int)$user['id'];

$fProject = get_int('project');

// Chamados que estão com o dev (abertos/em andamento) + os que ele já concluiu (ficam em Concluído, somente leitura)
$where = ["((d.assigned_to = ? AND d.status IN ('open','fixing'))
           OR EXISTS (SELECT 1 FROM defect_comments c WHERE c.defect_id = d.id AND c.user_id = ? AND c.new_status = 'fixed'))"];
$params = [$uid, $uid];
if ($fProject) { $where[] = 'd.project_id = ?'; $params[] = $fProject; }

$rows = q("SELECT d.id, d.title, d.status, d.assigned_to, d.severity, d.priority, d.created_at, tc.code, p.name project,
                  (SELECT COUNT(*) FROM defect_comments c WHERE c.defect_id = d.id AND c.new_status = 'reopened') reopened_times
           FROM defects d JOIN test_cases tc ON tc.id = d.case_id JOIN projects p ON p.id = d.project_id
           WHERE " . implode(' AND ', $where) . " ORDER BY FIELD(d.priority, 'critical','high','medium','low'), d.created_at", $params)->fetchAll();

$cols = array_fill_keys(array_keys(DEV_COLUMNS), []);
foreach ($rows as $r) {
    // Ativo com este dev → coluna do status; qualquer outro caso (já concluído por ele) → Concluído, travado
    $active = (int)$r['assigned_to'] === $uid && in_array($r['status'], DEV_ACTIVE_STATUS, true);
    $r['locked'] = !$active;
    $cols[$active ? $r['status'] : 'fixed'][] = $r;
}

$projects = q("SELECT DISTINCT p.id, p.name FROM projects p JOIN defects d ON d.project_id = p.id
               WHERE d.assigned_to = ? OR EXISTS (SELECT 1 FROM defect_comments c WHERE c.defect_id = d.id AND c.user_id = ? AND c.new_status = 'fixed')
               ORDER BY p.name", [$uid, $uid])->fetchAll(PDO::FETCH_KEY_PAIR);
$rank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
$colClass = ['open' => 'col-waiting', 'fixing' => 'col-doing', 'fixed' => 'col-done'];

$pageTitle = 'Meus Chamados';
$active = 'dev_board.php';
$bodyClass = 'kb';
include __DIR__ . '/includes/header.php';
?>
<form method="get" class="kb-filter">
    <label>Projeto
        <select name="project" onchange="this.form.submit()"><?= options($projects, $fProject ?: null, true, 'Todos os projetos') ?></select>
    </label>
    <ul class="kb-legend" aria-label="Legenda de urgência">
        <?php foreach (KANBAN_LEVELS as $k => $label): ?><li><span class="kb-badge lvl-<?= e($k) ?>"><?= e($label) ?></span></li><?php endforeach; ?>
    </ul>
</form>

<div class="kb-board" data-kanban data-csrf="<?= e(csrf_token()) ?>" data-project="<?= (int)$fProject ?>">
    <?php foreach (DEV_COLUMNS as $status => $label): ?>
    <section class="kb-col <?= $colClass[$status] ?>" data-status="<?= e($status) ?>">
        <header>
            <h2><?= e($label) ?></h2>
            <span class="kb-count" data-count><?= count($cols[$status]) ?></span>
        </header>
        <?php if ($status === 'fixed'): ?><p class="kb-hint">Solte aqui para concluir: o chamado volta ao Administrador e fica nesta coluna, somente leitura.</p><?php endif; ?>
        <div class="kb-list" data-list>
            <?php foreach ($cols[$status] as $r): $locked = !empty($r['locked']); ?>
            <article class="kb-card lvl-<?= e($r['priority']) ?><?= $locked ? ' locked' : '' ?>" <?= $locked ? '' : 'draggable="true"' ?> data-id="<?= (int)$r['id'] ?>" data-rank="<?= $rank[$r['priority']] ?>" data-status="<?= e($status) ?>">
                <div class="kb-top">
                    <span class="kb-badge lvl-<?= e($r['priority']) ?>" title="Urgência"><?= e(KANBAN_LEVELS[$r['priority']]) ?></span>
                    <a class="kb-code" href="defect_view.php?id=<?= (int)$r['id'] ?>" draggable="false">#<?= (int)$r['id'] ?></a>
                </div>
                <h3><?= e($r['title']) ?></h3>
                <p class="kb-meta"><?= e($r['project']) ?> · Caso <?= e($r['code']) ?></p>
                <?php if ($locked): ?><p class="kb-lock" data-lock>Concluído · somente leitura · situação atual: <?= e(DEFECT_STATUS[$r['status']]) ?></p><?php endif; ?>
                <p class="kb-tags">
                    <span class="kb-res">Severidade: <?= e(LEVELS[$r['severity']]) ?></span>
                    <?php if ($r['reopened_times'] && !$locked): ?><span class="kb-res res-retest">Reencaminhado após reteste</span><?php endif; ?>
                </p>
                <div class="kb-actions">
                    <a class="kb-btn<?= $locked ? ' kb-btn-ghost kb-comment' : '' ?>" href="defect_view.php?id=<?= (int)$r['id'] ?>" draggable="false"><?= $locked ? 'Ver chamado' : 'Abrir chamado' ?></a>
                    <?php if (!$locked): ?>
                    <form method="post" action="kanban_move.php" class="move-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="project" value="<?= (int)$fProject ?>">
                        <select name="status" aria-label="Mover chamado #<?= (int)$r['id'] ?> para"><?= options(DEV_COLUMNS, $status) ?></select>
                        <button type="submit" class="kb-btn kb-btn-ghost">Mover</button>
                    </form>
                    <?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
            <p class="kb-empty" data-empty <?= $cols[$status] ? 'hidden' : '' ?>><?= $status === 'fixed' ? 'Arraste aqui para concluir.' : 'Nenhum chamado aqui.' ?></p>
        </div>
    </section>
    <?php endforeach; ?>
</div>
<div class="kb-toast" id="kb-toast" role="alert" aria-live="assertive" hidden></div>
<script src="assets/js/kanban.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/kanban.js') ?>"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
