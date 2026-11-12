<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/kanban.php';
$user = require_role('tester');
$uid = (int)$user['id'];

$fProject = get_int('project');

$where = ['a.tester_id = ?', "tc.status = 'active'",
          // Concluído mostra só o que foi concluído hoje; a partir da meia-noite o cartão sai do quadro e fica no Histórico
          "(a.kanban_status <> 'done' OR DATE(a.done_at) = CURDATE())"];
$params = [$uid];
if ($fProject) { $where[] = 'tc.project_id = ?'; $params[] = $fProject; }

$rows = q("SELECT a.id aid, a.kanban_status, tc.id, tc.code, tc.title, tc.priority, p.name project, s.name suite,
                  ex.id exec_id, ex.result, ex.exec_type, ex.executed_at,
                  EXISTS(SELECT 1 FROM executions x WHERE x.case_id = tc.id AND x.tester_id = a.tester_id AND x.executed_at >= a.cycle_start) has_activity,
                  (SELECT d.id FROM defects d WHERE d.case_id = tc.id AND d.status = 'retest' AND d.retest_tester_id = a.tester_id ORDER BY d.id DESC LIMIT 1) retest_defect
           FROM assignments a
           JOIN test_cases tc ON tc.id = a.case_id
           JOIN projects p ON p.id = tc.project_id
           JOIN suites s ON s.id = tc.suite_id
           LEFT JOIN executions ex ON ex.id = (SELECT MAX(id) FROM executions WHERE case_id = tc.id AND tester_id = a.tester_id)
           WHERE " . implode(' AND ', $where) . " ORDER BY FIELD(tc.priority, 'critical','high','medium','low'), p.name, tc.code", $params)->fetchAll();

$cols = array_fill_keys(array_keys(KANBAN_COLUMNS), []);
foreach ($rows as $r) { $cols[$r['kanban_status']][] = $r; }

$projects = q('SELECT DISTINCT p.id, p.name FROM projects p JOIN test_cases tc ON tc.project_id = p.id
               JOIN assignments a ON a.case_id = tc.id WHERE a.tester_id = ? ORDER BY p.name', [$uid])->fetchAll(PDO::FETCH_KEY_PAIR);
$rank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];

// Concluídos em dias anteriores: não aparecem no quadro, só no Histórico
$olderDone = (int)q("SELECT COUNT(*) FROM assignments a JOIN test_cases tc ON tc.id = a.case_id
                     WHERE a.tester_id = ? AND tc.status = 'active' AND a.kanban_status = 'done' AND (a.done_at IS NULL OR DATE(a.done_at) < CURDATE())", [$uid])->fetchColumn();

$pageTitle = 'Meus Testes';
$active = 'my_tests.php';
$bodyClass = 'kb';
include __DIR__ . '/includes/header.php';
?>
<form method="get" class="kb-filter">
    <label>Projeto
        <select name="project" onchange="this.form.submit()"><?= options($projects, $fProject ?: null, true, 'Todos os projetos') ?></select>
    </label>
    <ul class="kb-legend" aria-label="Legenda de criticidade">
        <?php foreach (KANBAN_LEVELS as $k => $label): ?><li><span class="kb-badge lvl-<?= e($k) ?>"><?= e($label) ?></span></li><?php endforeach; ?>
    </ul>
</form>

<div class="kb-board" data-kanban data-csrf="<?= e(csrf_token()) ?>" data-project="<?= (int)$fProject ?>">
    <?php foreach (KANBAN_COLUMNS as $status => $label): ?>
    <section class="kb-col col-<?= e($status) ?>" data-status="<?= e($status) ?>">
        <header>
            <h2><?= e($label) ?></h2>
            <span class="kb-count" data-count><?= count($cols[$status]) ?></span>
        </header>
        <?php if ($status === 'done'): ?><p class="kb-hint">Aprovado com comentário vai direto para cá. Reprovado/Bloqueado exigem assentamento + ao menos 1 foto.
            Mostra só o que foi concluído <strong>hoje</strong>: à meia-noite os cartões saem daqui e ficam no <a href="history.php">Histórico</a><?= $olderDone ? " ($olderDone já está lá)" : '' ?>.</p><?php endif; ?>
        <div class="kb-list" data-list>
            <?php foreach ($cols[$status] as $r): ?>
            <article class="kb-card lvl-<?= e($r['priority']) ?>" draggable="true" data-id="<?= (int)$r['aid'] ?>" data-rank="<?= $rank[$r['priority']] ?>" data-status="<?= e($status) ?>">
                <div class="kb-top">
                    <span class="kb-badge lvl-<?= e($r['priority']) ?>"><?= e(KANBAN_LEVELS[$r['priority']]) ?></span>
                    <a class="kb-code" href="case_view.php?id=<?= (int)$r['id'] ?>" draggable="false"><?= e($r['code']) ?></a>
                </div>
                <h3><?= e($r['title']) ?></h3>
                <p class="kb-meta"><?= e($r['project']) ?> · <?= e($r['suite']) ?></p>
                <p class="kb-tags">
                    <?php if ($r['result']): ?><span class="kb-res res-<?= e($r['result']) ?>">Último: <?= e(RESULTS[$r['result']]) ?></span>
                        <span class="kb-res"><?= e(EXEC_TYPES[$r['exec_type']] ?? '') ?></span><?php endif; ?>
                    <?php if ($r['retest_defect']): ?><span class="kb-res res-retest">Reteste · defeito #<?= (int)$r['retest_defect'] ?></span><?php endif; ?>
                </p>
                <div class="kb-actions">
                    <a class="kb-btn" href="execute.php?case_id=<?= (int)$r['id'] ?><?= $r['retest_defect'] ? '&defect_id=' . (int)$r['retest_defect'] : '' ?>" draggable="false">
                        <?= $r['retest_defect'] ? 'Reteste' : ($status === 'done' ? 'Reexecutar' : 'Executar') ?>
                    </a>
                    <?php if ($r['exec_id']): ?>
                    <a class="kb-btn kb-btn-ghost kb-comment" href="execution_view.php?id=<?= (int)$r['exec_id'] ?>#comentarios" draggable="false">Comentar</a>
                    <?php endif; ?>
                    <form method="post" action="kanban_move.php" class="move-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$r['aid'] ?>">
                        <input type="hidden" name="project" value="<?= (int)$fProject ?>">
                        <select name="status" aria-label="Mover <?= e($r['code']) ?> para">
                            <?php foreach (KANBAN_COLUMNS as $k => $lbl):
                                // Com atividade registrada (execução, comentário, foto) não dá para voltar para "Em aberto"
                                $blocked = $k === 'waiting' && $r['has_activity'] && $status !== 'waiting'; ?>
                                <option value="<?= e($k) ?>" <?= $k === $status ? 'selected' : '' ?> <?= $blocked ? 'disabled title="Já há atividade registrada neste teste"' : '' ?>><?= e($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="kb-btn kb-btn-ghost">Mover</button>
                    </form>
                </div>
            </article>
            <?php endforeach; ?>
            <p class="kb-empty" data-empty <?= $cols[$status] ? 'hidden' : '' ?>>Nenhum teste aqui.</p>
        </div>
    </section>
    <?php endforeach; ?>
</div>
<div class="kb-toast" id="kb-toast" role="alert" aria-live="assertive" hidden></div>
<script src="assets/js/kanban.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/kanban.js') ?>"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
