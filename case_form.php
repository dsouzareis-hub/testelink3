<?php
require __DIR__ . '/includes/bootstrap.php';
$me = require_role('admin');

$id = get_int('id');
$case = $id ? q('SELECT * FROM test_cases WHERE id = ?', [$id])->fetch() : null;
if ($id && !$case) { not_found(); }

$projects = project_list();
$suites = q('SELECT id, project_id, name FROM suites ORDER BY name')->fetchAll();
$suiteById = array_column($suites, null, 'id');

if (is_post()) {
    csrf_check();
    $suiteId = post_int('suite_id');
    $data = [
        'code'            => post('code'),
        'title'           => post('title'),
        'preconditions'   => post('preconditions'),
        'steps'           => post('steps'),
        'expected_result' => post('expected_result'),
        'priority'        => post('priority'),
        'status'          => post('status') === 'inactive' ? 'inactive' : 'active',
    ];
    $note = post('change_note');
    $err = null;

    if (!isset($suiteById[$suiteId])) {
        $err = 'Selecione uma suíte.';
    } elseif ($data['code'] === '' || $data['title'] === '' || $data['steps'] === '' || $data['expected_result'] === '') {
        $err = 'Código, título, passos e resultado esperado são obrigatórios.';
    } elseif (!isset(LEVELS[$data['priority']])) {
        $err = 'Prioridade inválida.';
    }
    $projectId = $err ? 0 : (int)$suiteById[$suiteId]['project_id'];
    if (!$err && q('SELECT 1 FROM test_cases WHERE project_id = ? AND code = ? AND id <> ?', [$projectId, $data['code'], $id])->fetchColumn()) {
        $err = 'Já existe um caso com este código neste projeto.';
    }

    if ($err) {
        flash('error', $err);
        $case = array_merge($case ?: ['id' => 0, 'version' => 1], $data, ['suite_id' => $suiteId, 'project_id' => $projectId]);
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($id) {
                // Nova versão apenas se o conteúdo mudou
                $changed = false;
                foreach (['title', 'preconditions', 'steps', 'expected_result', 'priority', 'status'] as $f) {
                    if ((string)$case[$f] !== (string)$data[$f]) { $changed = true; break; }
                }
                $version = (int)$case['version'] + ($changed ? 1 : 0);
                q('UPDATE test_cases SET project_id=?, suite_id=?, code=?, title=?, preconditions=?, steps=?, expected_result=?,
                   priority=?, status=?, version=? WHERE id=?', [
                    $projectId, $suiteId, $data['code'], $data['title'], $data['preconditions'], $data['steps'],
                    $data['expected_result'], $data['priority'], $data['status'], $version, $id,
                ]);
                $caseId = $id;
            } else {
                $version = 1;
                $changed = true;
                $note = $note ?: 'Criação do caso';
                q('INSERT INTO test_cases (project_id, suite_id, code, title, preconditions, steps, expected_result, priority, status, created_by)
                   VALUES (?,?,?,?,?,?,?,?,?,?)', [
                    $projectId, $suiteId, $data['code'], $data['title'], $data['preconditions'], $data['steps'],
                    $data['expected_result'], $data['priority'], $data['status'], $me['id'],
                ]);
                $caseId = (int)$pdo->lastInsertId();
            }
            if ($changed) {
                q('INSERT INTO test_case_versions (case_id, version, title, preconditions, steps, expected_result, priority, status, change_note, changed_by)
                   VALUES (?,?,?,?,?,?,?,?,?,?)', [
                    $caseId, $version, $data['title'], $data['preconditions'], $data['steps'], $data['expected_result'],
                    $data['priority'], $data['status'], mb_substr($note, 0, 255) ?: null, $me['id'],
                ]);
            }
            $pdo->commit();
            flash('success', $id ? 'Caso de teste atualizado.' : 'Caso de teste criado.');
            redirect('case_view.php?id=' . $caseId);
        } catch (Throwable $ex) {
            $pdo->rollBack();
            flash('error', 'Erro ao salvar o caso de teste.');
        }
    }
}

// Valores padrão do formulário
if (!$case) {
    $presetSuite = get_int('suite');
    $nextNum = (int)q('SELECT COALESCE(MAX(id),0)+1 FROM test_cases')->fetchColumn();
    $case = [
        'id' => 0, 'version' => 1, 'project_id' => $suiteById[$presetSuite]['project_id'] ?? 0, 'suite_id' => $presetSuite,
        'code' => 'CT-' . str_pad((string)$nextNum, 3, '0', STR_PAD_LEFT), 'title' => '', 'preconditions' => '',
        'steps' => '', 'expected_result' => '', 'priority' => 'medium', 'status' => 'active',
    ];
}

$pageTitle = $case['id'] ? 'Editar caso de teste' : 'Novo caso de teste';
$active = 'cases.php';
$backUrl = $case['id'] ? 'case_view.php?id=' . (int)$case['id'] : 'cases.php';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
    <?php if (!$suites): ?>
        <p class="alert alert-warn">Crie ao menos uma suíte antes de cadastrar casos de teste. <a href="suites.php?new=1">Nova suíte</a></p>
    <?php else: ?>
    <form method="post">
        <?= csrf_field() ?>
        <div class="grid grid-3">
            <label class="field">Projeto
                <select data-project-select><?= options($projects, $case['project_id'], true, 'Selecione…') ?></select>
            </label>
            <label class="field">Suíte
                <select name="suite_id" data-suite-select required>
                    <option value="">Selecione…</option>
                    <?php foreach ($suites as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" data-project="<?= (int)$s['project_id'] ?>" <?= (int)$s['id'] === (int)$case['suite_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">Código <input type="text" name="code" required maxlength="40" value="<?= e($case['code']) ?>"></label>
            <label class="field span-2">Título <input type="text" name="title" required maxlength="200" value="<?= e($case['title']) ?>"></label>
            <label class="field">Prioridade <select name="priority"><?= options(LEVELS, $case['priority']) ?></select></label>
            <label class="field span-2">Pré-condições <textarea name="preconditions" rows="3"><?= e($case['preconditions']) ?></textarea></label>
            <label class="field">Status <select name="status"><?= options(['active' => 'Ativo', 'inactive' => 'Inativo'], $case['status']) ?></select></label>
            <label class="field span-2">Passos de execução <small>(um passo por linha)</small>
                <textarea name="steps" rows="7" required><?= e($case['steps']) ?></textarea>
            </label>
            <label class="field">Resultado esperado <textarea name="expected_result" rows="7" required><?= e($case['expected_result']) ?></textarea></label>
            <label class="field span-2">Motivo da alteração <small>(registrado no histórico de versões)</small>
                <input type="text" name="change_note" maxlength="255" value="<?= e($_POST['change_note'] ?? '') ?>">
            </label>
        </div>
        <div class="form-actions">
            <button class="btn" type="submit">Salvar</button>
            <a class="btn btn-ghost" href="<?= $case['id'] ? 'case_view.php?id=' . (int)$case['id'] : 'cases.php' ?>">Cancelar</a>
        </div>
    </form>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
