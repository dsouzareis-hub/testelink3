<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/kanban.php';
$user = require_role('tester');
$uid = (int)$user['id'];

$caseId = get_int('case_id');
$case = q('SELECT tc.*, p.name project, s.name suite FROM test_cases tc
           JOIN projects p ON p.id = tc.project_id JOIN suites s ON s.id = tc.suite_id WHERE tc.id = ?', [$caseId])->fetch();
if (!$case) { not_found(); }
if (!tester_has_case($uid, $caseId)) { forbidden(); }
if ($case['status'] !== 'active') {
    flash('error', 'Este caso de teste está inativo e não pode ser executado.');
    redirect('my_tests.php');
}

// Reteste: defeito corrigido vinculado a este caso
$retest = null;
if ($defectId = get_int('defect_id')) {
    // Reteste só vale quando o Admin enviou o chamado a ESTE tester (status "retest")
    $retest = q("SELECT * FROM defects WHERE id = ? AND case_id = ? AND status = 'retest' AND retest_tester_id = ?", [$defectId, $caseId, $uid])->fetch() ?: null;
}

if (is_post()) {
    csrf_check();
    $result = post('result');
    $execType = post('exec_type', 'manual');
    $notes = post('notes');
    $error = null;

    if (!isset(RESULTS[$result])) {
        $error = 'Selecione o resultado da execução.';
    } elseif (!isset(EXEC_TYPES[$execType])) {
        $error = 'Selecione o tipo da execução (Manual ou Automatizado).';
    } elseif ($result !== 'passed' && $notes === '') {
        $error = 'Para resultado Reprovado ou Bloqueado, descreva o ocorrido no assentamento.';
    }

    if (!$error) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            q('INSERT INTO executions (case_id, tester_id, result, exec_type, notes, case_version) VALUES (?,?,?,?,?,?)',
                [$caseId, $uid, $result, $execType, $notes ?: null, $case['version']]);
            $execId = (int)$pdo->lastInsertId();

            // Reprovado: abre o chamado "Aguardando triagem". O tester NÃO escolhe o desenvolvedor — o Admin encaminha.
            $defectNew = null;
            if (!$retest && $result === 'failed') {
                $defectNew = create_triage_defect($execId, $uid);
            }
            if ($retest) {
                // Reteste: aprovado fecha o defeito; reprovado reabre; bloqueado só comenta
                $newStatus = ['passed' => 'closed', 'failed' => 'reopened'][$result] ?? 'retest';
                set_defect_status((int)$retest['id'], $newStatus, $uid,
                    'Reteste (execução #' . $execId . '): ' . RESULTS[$result] . ($notes ? ' — ' . $notes : ''));
            }
            $pdo->commit();
        } catch (InvalidArgumentException $ex) {
            $pdo->rollBack();
            $error = $ex->getMessage();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $error = 'Erro ao registrar a execução. Tente novamente.';
        }
    }

    if (!$error) {
        [$saved, $uploadErrors] = save_evidences($execId);
        foreach ($uploadErrors as $m) { flash('warn', $m); }
        // Ajusta o Kanban já com as fotos salvas: Aprovado + comentário vai direto para Concluído
        $kanbanMsg = kanban_after_execution($caseId, $uid, $result);
        flash('success', 'Execução registrada' . ($saved ? " com $saved evidência(s)" : '') . '.'
            . ($defectNew ? " Chamado #$defectNew aberto: o Administrador fará o encaminhamento ao desenvolvedor." : '')
            . ($kanbanMsg ? ' ' . $kanbanMsg : ''));
        redirect('execution_view.php?id=' . $execId);
    }
    flash('error', $error);
}

$prev = q('SELECT ex.id, ex.result, ex.executed_at, u.name tester FROM executions ex JOIN users u ON u.id = ex.tester_id
           WHERE ex.case_id = ? ORDER BY ex.id DESC LIMIT 5', [$caseId])->fetchAll();

$pageTitle = ($retest ? 'Reteste: ' : 'Executar: ') . $case['code'];
$active = 'my_tests.php';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
    <div class="card-head">
        <h2><?= e($case['code']) ?> · <?= e($case['title']) ?> <small class="muted">v<?= (int)$case['version'] ?></small></h2>
        <?= level_badge($case['priority']) ?>
    </div>
    <?php if ($retest): ?>
        <div class="alert alert-info">Reteste do defeito <a href="defect_view.php?id=<?= (int)$retest['id'] ?>">#<?= (int)$retest['id'] ?> — <?= e($retest['title']) ?></a>.
            Aprovado fecha o defeito; reprovado o reabre automaticamente.</div>
    <?php endif; ?>
    <p class="muted"><?= e($case['project']) ?> / <?= e($case['suite']) ?></p>
    <dl class="detail-grid">
        <dt>Pré-condições</dt><dd class="pre"><?= e($case['preconditions'] ?: '—') ?></dd>
        <dt>Passos</dt>
        <dd><ol class="steps">
            <?php foreach (preg_split('/\R/', trim($case['steps'])) as $step): if (trim($step) === '') continue; ?>
                <li><?= e($step) ?></li>
            <?php endforeach; ?>
        </ol></dd>
        <dt>Resultado esperado</dt><dd class="pre"><?= e($case['expected_result']) ?></dd>
    </dl>
</div>

<form method="post" enctype="multipart/form-data" class="card">
    <?= csrf_field() ?>
    <h2>Registrar execução</h2>
    <div class="grid grid-2" style="max-width:640px">
        <label class="field">Resultado
            <select name="result" required data-result-select>
                <option value="">Selecione…</option>
                <?= options(RESULTS, $_POST['result'] ?? null) ?>
            </select>
        </label>
        <label class="field">Tipo
            <select name="exec_type" required><?= options(EXEC_TYPES, $_POST['exec_type'] ?? 'manual') ?></select>
        </label>
    </div>
    <div class="grid">

        <label class="field">Assentamento (observações do tester)
            <textarea name="notes" rows="5" placeholder="Descreva o que foi observado, dados usados, comportamento encontrado…"><?= e($_POST['notes'] ?? '') ?></textarea>
        </label>
        <label class="field">Evidências (JPG, PNG ou WEBP — até <?= (int)(max_upload_bytes() / 1048576) ?> MB cada)
            <input type="file" name="evidences[]" accept="image/jpeg,image/png,image/webp" multiple data-preview-input>
        </label>
        <div class="gallery preview-list" data-preview-box></div>
    </div>

    <p class="muted" style="margin-top:14px">Se o resultado for <strong>Reprovado</strong>, explique no assentamento (e anexe fotos, se quiser).
        O chamado vai para o Administrador, que decide o encaminhamento ao desenvolvedor.</p>

    <div class="form-actions">
        <button class="btn" type="submit"><?= icon('check', 16) ?> Salvar execução</button>
        <a class="btn btn-ghost" href="my_tests.php">Cancelar</a>
    </div>
</form>

<?php if ($prev): ?>
<div class="card">
    <h3>Execuções anteriores</h3>
    <div class="table-wrap"><table>
        <thead><tr><th>#</th><th>Resultado</th><th>Tester</th><th>Data</th></tr></thead>
        <tbody>
        <?php foreach ($prev as $x): ?>
            <tr><td><a href="execution_view.php?id=<?= (int)$x['id'] ?>"><?= (int)$x['id'] ?></a></td>
                <td><?= result_badge($x['result']) ?></td><td><?= e($x['tester']) ?></td><td><?= fmt_dt($x['executed_at']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
