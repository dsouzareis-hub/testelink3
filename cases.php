<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_role('admin', 'viewer');
$isAdmin = $user['role'] === 'admin';

if (is_post() && $isAdmin) {
    csrf_check();
    if (post('action') === 'delete' && ($id = post_int('id'))) {
        purge_evidence_files([$id]);
        q('DELETE FROM test_cases WHERE id = ?', [$id]);
        flash('success', 'Caso de teste excluído.');
    }
    redirect('cases.php');
}

/* ---------- Filtros ---------- */
$fProject = get_int('project');
$fSuite = get_int('suite');
$fStatus = $_GET['status'] ?? '';
$fSearch = trim($_GET['q'] ?? '');

$where = ['1=1'];
$params = [];
if ($fProject) { $where[] = 'tc.project_id = ?'; $params[] = $fProject; }
if ($fSuite)   { $where[] = 'tc.suite_id = ?';   $params[] = $fSuite; }
if (in_array($fStatus, ['active', 'inactive'], true)) { $where[] = 'tc.status = ?'; $params[] = $fStatus; }
if ($fSearch !== '') {
    $where[] = '(tc.code LIKE ? OR tc.title LIKE ?)';
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $fSearch) . '%';
    array_push($params, $like, $like);
}

$rows = q('SELECT tc.id, tc.code, tc.title, tc.priority, tc.status, tc.version, p.name project, s.name suite,
                  ex.result last_result
           FROM test_cases tc
           JOIN projects p ON p.id = tc.project_id
           JOIN suites s ON s.id = tc.suite_id
           LEFT JOIN executions ex ON ex.id = (SELECT MAX(id) FROM executions WHERE case_id = tc.id)
           WHERE ' . implode(' AND ', $where) . ' ORDER BY p.name, s.name, tc.code', $params)->fetchAll();

$projects = project_list();
$suites = $fProject
    ? q('SELECT id, name FROM suites WHERE project_id = ? ORDER BY name', [$fProject])->fetchAll(PDO::FETCH_KEY_PAIR)
    : q('SELECT id, name FROM suites ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);

$pageTitle = 'Casos de Teste';
$active = 'cases.php';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
    <div class="card-head">
        <h2>Casos de Teste (<?= count($rows) ?>)</h2>
        <?php if ($isAdmin): ?><a class="btn" href="case_form.php<?= $fSuite ? '?suite=' . $fSuite : '' ?>"><?= icon('plus', 16) ?> Novo caso</a><?php endif; ?>
    </div>
    <form method="get" class="filters">
        <label class="field">Projeto <select name="project"><?= options($projects, $fProject ?: null, true) ?></select></label>
        <label class="field">Suíte <select name="suite"><?= options($suites, $fSuite ?: null, true) ?></select></label>
        <label class="field">Status <select name="status"><?= options(['active' => 'Ativo', 'inactive' => 'Inativo'], $fStatus, true) ?></select></label>
        <label class="field">Busca <input type="search" name="q" placeholder="Código ou título" value="<?= e($fSearch) ?>"></label>
        <button class="btn" type="submit">Filtrar</button>
    </form>
    <div class="table-wrap"><table>
        <thead><tr><th>Código</th><th>Título</th><th>Projeto / Suíte</th><th>Prioridade</th><th>Último resultado</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><a href="case_view.php?id=<?= (int)$r['id'] ?>"><strong><?= e($r['code']) ?></strong></a> <small class="muted">v<?= (int)$r['version'] ?></small></td>
                <td><?= e($r['title']) ?></td>
                <td><?= e($r['project']) ?> <span class="muted">/ <?= e($r['suite']) ?></span></td>
                <td><?= level_badge($r['priority']) ?></td>
                <td><?= result_badge($r['last_result']) ?></td>
                <td><?= $r['status'] === 'active' ? badge('Ativo', 'green') : badge('Inativo', 'gray') ?></td>
                <td class="actions">
                    <a class="btn btn-sm btn-ghost" href="case_view.php?id=<?= (int)$r['id'] ?>"><?= icon('eye', 14) ?></a>
                    <?php if ($isAdmin): ?>
                    <a class="btn btn-sm btn-ghost" href="case_form.php?id=<?= (int)$r['id'] ?>"><?= icon('edit', 14) ?></a>
                    <form method="post" class="inline-form" data-confirm="Excluir este caso de teste e seu histórico de execuções?">
                        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn-sm btn-danger" type="submit"><?= icon('trash', 14) ?></button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="empty">Nenhum caso de teste encontrado.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
