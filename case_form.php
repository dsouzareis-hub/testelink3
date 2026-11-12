<?php
require __DIR__ . '/includes/bootstrap.php';
$me = require_role('admin');

$id = get_int('id');
$case = $id ? q('SELECT * FROM test_cases WHERE id = ?', [$id])->fetch() : null;
if ($id && !$case) { not_found(); }

$projects = project_list();
$suites = q('SELECT id, project_id, name FROM suites ORDER BY name')->fetchAll();
$suiteById = array_column($suites, null, 'id');

$allTesters = users_by_role('tester');
$allDevs = users_by_role('developer');
// Modo Express: só o administrador que criou o caso pode ligar/desligar e escolher os responsáveis
$canExpress = !$case || $case['created_by'] === null || (int)$case['created_by'] === (int)$me['id'];

if (is_post()) {
    csrf_check();
    $suiteId = post_int('suite_id');
    $data = [
        'code'            => post('code'),
        'title'           => post('title'),
        'preconditions'   => post('preconditions'),
        'steps'           => steps_from_post(),          // passos em tópicos (steps[]), um por linha no banco
        'expected_result' => post('expected_result'),
        'priority'        => post('priority'),
        'status'          => post('status') === 'inactive' ? 'inactive' : 'active',
    ];
    $note = post('change_note');
    $err = null;

    // Responsáveis e Express: valores enviados só valem para o admin criador; os demais mantêm o que está gravado
    $respT = $case['responsible_tester_id'] ?? null;
    $respD = $case['responsible_dev_id'] ?? null;
    $express = (int)($case['express'] ?? 0);
    if ($canExpress) {
        $respT = post_int('responsible_tester_id') ?: null;
        $respD = post_int('responsible_dev_id') ?: null;
        $express = post('express') === '1' ? 1 : 0;
        // só aceita quem já é o responsável gravado ou um usuário ativo do perfil certo
        if ($respT && !isset($allTesters[$respT]) && $respT !== (int)($case['responsible_tester_id'] ?? 0)) { $err = 'Tester responsável inválido.'; }
        if ($respD && !isset($allDevs[$respD]) && $respD !== (int)($case['responsible_dev_id'] ?? 0)) { $err = 'Desenvolvedor responsável inválido.'; }
        if (!$err && $express && (!$respT || !$respD)) {
            $err = 'O modo Express exige o Tester responsável e o Desenvolvedor responsável.';
        }
    }

    if ($err) {
        // já definido acima
    } elseif (!isset($suiteById[$suiteId])) {
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
        $case = array_merge($case ?: ['id' => 0, 'version' => 1], $data, ['suite_id' => $suiteId, 'project_id' => $projectId,
            'responsible_tester_id' => $respT, 'responsible_dev_id' => $respD, 'express' => $express]);
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
                   priority=?, status=?, version=?, responsible_tester_id=?, responsible_dev_id=?, express=? WHERE id=?', [
                    $projectId, $suiteId, $data['code'], $data['title'], $data['preconditions'], $data['steps'],
                    $data['expected_result'], $data['priority'], $data['status'], $version, $respT, $respD, $express, $id,
                ]);
                $caseId = $id;
            } else {
                $version = 1;
                $changed = true;
                $note = $note ?: 'Criação do caso';
                q('INSERT INTO test_cases (project_id, suite_id, code, title, preconditions, steps, expected_result, priority, status, created_by,
                   responsible_tester_id, responsible_dev_id, express)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [
                    $projectId, $suiteId, $data['code'], $data['title'], $data['preconditions'], $data['steps'],
                    $data['expected_result'], $data['priority'], $data['status'], $me['id'], $respT, $respD, $express,
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
            audit($id ? 'caso_editado' : 'caso_criado', 'case', $caseId, $data['code'] . ' — ' . $data['title'] . ' (v' . $version . ')');
            if ($express !== (int)($case['express'] ?? 0)) {
                audit('caso_express', 'case', $caseId, $data['code'] . ($express ? ' — Express ATIVADO' : ' — Express desativado'));
            }
            flash('success', $id ? 'Caso de teste atualizado.' : 'Caso de teste criado.');
            // Atribuição aos testers marcados (avisa quem recebeu; não remove quem já executou)
            $picked = (array)($_POST['testers'] ?? []);
            if ($express && $respT) { $picked[] = $respT; }       // o tester responsável sempre recebe o caso
            $as = sync_case_testers($caseId, $picked, (int)$me['id']);
            if ($as['added']) { flash('success', 'Atribuído a: ' . implode(', ', $as['added']) . '.'); }
            if ($as['removed']) { flash('success', 'Atribuição removida de: ' . implode(', ', $as['removed']) . '.'); }
            if ($as['kept']) { flash('warn', implode(', ', $as['kept']) . ' já executou este caso e continua atribuído (para não esconder o histórico).'); }
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

// Quais testers estão marcados (após erro de validação mantém a marcação enviada)
$selTesters = is_post()
    ? array_map('intval', (array)($_POST['testers'] ?? []))
    : ($case['id'] ? array_map('intval', q('SELECT tester_id FROM assignments WHERE case_id = ?', [$case['id']])->fetchAll(PDO::FETCH_COLUMN)) : []);

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
            <div class="field" style="grid-column:1/-1">
                <span>Atribuir a testers <small>— quem for marcado recebe o caso em “Em aberto” no Kanban e um aviso</small></span>
                <?php if (!$allTesters): ?>
                    <p class="alert alert-warn" style="margin:4px 0 0">Não há testers ativos. <a href="users.php?new=1">Cadastre um usuário</a> com perfil Tester.</p>
                <?php else: ?>
                <div class="tester-pick">
                    <?php foreach ($allTesters as $tid => $tname): ?>
                        <label class="check pick"><input type="checkbox" name="testers[]" value="<?= (int)$tid ?>" <?= in_array((int)$tid, $selTesters, true) ? 'checked' : '' ?>> <?= e($tname) ?></label>
                    <?php endforeach; ?>
                </div>
                <?php if ($case['id']): ?><small>Desmarcar remove o tester do caso, exceto se ele já executou (aí continua atribuído, para não esconder o histórico).</small><?php endif; ?>
                <?php endif; ?>
            </div>
            <label class="field">Tester responsável <small>(opcional)</small>
                <select name="responsible_tester_id" data-resp-t <?= $canExpress ? '' : 'disabled' ?>>
                    <?= options($allTesters, $case['responsible_tester_id'] ?? null, true, 'Nenhum') ?>
                </select>
            </label>
            <label class="field">Desenvolvedor responsável <small>(opcional)</small>
                <select name="responsible_dev_id" data-resp-d <?= $canExpress ? '' : 'disabled' ?>>
                    <?= options($allDevs, $case['responsible_dev_id'] ?? null, true, 'Nenhum') ?>
                </select>
            </label>
            <div class="field">
                <span>Fluxo Express</span>
                <label class="check pick"><input type="checkbox" name="express" value="1" data-express <?= !empty($case['express']) ? 'checked' : '' ?> <?= $canExpress ? '' : 'disabled' ?>> Ativar Express</label>
                <small>
                    <?php if ($canExpress): ?>Tester e Desenvolvedor tratam o chamado direto, sem passar pela decisão do admin (você continua vendo tudo). Exige os dois responsáveis.
                    <?php else: ?>Somente o administrador que criou o caso pode alterar o Express e os responsáveis.<?php endif; ?>
                </small>
            </div>
            <div class="field span-2">
                <span>Passos de execução <small>— digite o passo e aperte <strong>Enter</strong> para criar o próximo; cada passo vira um tópico</small></span>
                <ul class="steps-editor" data-steps-editor>
                    <?php foreach (steps_list((string)$case['steps']) ?: [''] as $i => $s): ?>
                        <li class="step-row">
                            <span class="step-box" aria-hidden="true"></span><span class="step-no"><?= $i + 1 ?>.</span>
                            <input type="text" name="steps[]" value="<?= e($s) ?>" maxlength="500" autocomplete="off" placeholder="Descreva o passo e aperte Enter">
                            <button type="button" class="step-del" aria-label="Remover passo" title="Remover passo">×</button>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn btn-ghost btn-sm" data-step-add style="align-self:flex-start">+ Adicionar passo</button>
            </div>
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
