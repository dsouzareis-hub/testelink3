<?php
/**
 * Regras de negócio dos defeitos (criação, mudança de status, comentários, acesso).
 */

/**
 * Cria um defeito vinculado a uma execução reprovada.
 * $data: title, description, severity, priority, assigned_to
 * Retorna o id do defeito ou lança InvalidArgumentException com mensagem amigável.
 */
function create_defect(int $executionId, array $data, int $userId): int
{
    $ex = q('SELECT ex.id, ex.case_id, ex.result, tc.project_id FROM executions ex
             JOIN test_cases tc ON tc.id = ex.case_id WHERE ex.id = ?', [$executionId])->fetch();
    if (!$ex || $ex['result'] !== 'failed') {
        throw new InvalidArgumentException('Somente execuções reprovadas podem gerar defeito.');
    }
    if (q('SELECT 1 FROM defects WHERE execution_id = ?', [$executionId])->fetchColumn()) {
        throw new InvalidArgumentException('Esta execução já foi encaminhada ao desenvolvedor.');
    }

    $title = trim($data['title'] ?? '');
    $desc = trim($data['description'] ?? '');
    $sev = $data['severity'] ?? 'medium';
    $prio = $data['priority'] ?? 'medium';
    $dev = (int)($data['assigned_to'] ?? 0);

    if ($title === '' || $desc === '') {
        throw new InvalidArgumentException('Informe título e descrição do defeito.');
    }
    if (!isset(LEVELS[$sev]) || !isset(LEVELS[$prio])) {
        throw new InvalidArgumentException('Severidade/prioridade inválida.');
    }
    if (!q("SELECT 1 FROM users WHERE id = ? AND role = 'developer' AND active = 1", [$dev])->fetchColumn()) {
        throw new InvalidArgumentException('Selecione um desenvolvedor válido.');
    }

    q('INSERT INTO defects (execution_id, case_id, project_id, title, description, severity, priority, status, reported_by, assigned_to)
       VALUES (?,?,?,?,?,?,?,?,?,?)', [
        $executionId, $ex['case_id'], $ex['project_id'], mb_substr($title, 0, 200), $desc, $sev, $prio, 'open', $userId, $dev,
    ]);
    $id = (int)db()->lastInsertId();
    add_defect_comment($id, $userId, 'Defeito registrado e encaminhado ao desenvolvedor.', null, 'open');
    return $id;
}

/** Registra comentário e/ou mudança de status no histórico do defeito. */
function add_defect_comment(int $defectId, int $userId, ?string $comment, ?string $old, ?string $new): void
{
    q('INSERT INTO defect_comments (defect_id, user_id, comment, old_status, new_status) VALUES (?,?,?,?,?)',
        [$defectId, $userId, $comment, $old, $new]);
}

/** Altera o status e registra no histórico. */
function set_defect_status(int $defectId, string $new, int $userId, ?string $comment = null): void
{
    $old = q('SELECT status FROM defects WHERE id = ?', [$defectId])->fetchColumn();
    if ($old === false || !isset(DEFECT_STATUS[$new])) {
        return;
    }
    if ($old !== $new) {
        q('UPDATE defects SET status = ? WHERE id = ?', [$new, $defectId]);
        // Defeito corrigido: o caso volta ao Kanban do tester como "Aguardando" para o reteste (novo ciclo)
        if ($new === 'fixed') {
            q("UPDATE assignments SET kanban_status = 'waiting', cycle_start = NOW()
               WHERE case_id = (SELECT case_id FROM defects WHERE id = ?)", [$defectId]);
        }
    }
    add_defect_comment($defectId, $userId, $comment, $old !== $new ? $old : null, $old !== $new ? $new : null);
}

/** O usuário pode ver este defeito? */
function can_view_defect(array $user, array $defect): bool
{
    switch ($user['role']) {
        case 'admin':
        case 'viewer':
            return true;
        case 'developer':
            return (int)$defect['assigned_to'] === (int)$user['id'];
        case 'tester':
            return (int)$defect['reported_by'] === (int)$user['id'] || tester_has_case($user['id'], (int)$defect['case_id']);
    }
    return false;
}

/** Renderiza o formulário de encaminhamento ao desenvolvedor (reutilizado em execute.php e execution_view.php). */
function render_forward_fields(string $defaultTitle = '', bool $hidden = false): void
{
    $devs = users_by_role('developer');
    ?>
    <fieldset class="forward-box" id="forward-box"<?= $hidden ? ' hidden' : '' ?>>
        <legend><?= icon('send', 16) ?> Encaminhar para o Desenvolvedor</legend>
        <?php if (!$devs): ?>
            <p class="alert alert-warn">Nenhum desenvolvedor ativo cadastrado. Peça ao administrador para criar um.</p>
        <?php else: ?>
        <div class="grid grid-2">
            <label class="field span-2">Título do defeito
                <input type="text" name="d_title" maxlength="200" value="<?= e($_POST['d_title'] ?? $defaultTitle) ?>">
            </label>
            <label class="field span-2">Descrição (o que ocorreu, como reproduzir)
                <textarea name="d_description" rows="4"><?= e($_POST['d_description'] ?? '') ?></textarea>
            </label>
            <label class="field">Severidade
                <select name="d_severity"><?= options(LEVELS, $_POST['d_severity'] ?? 'medium') ?></select>
            </label>
            <label class="field">Prioridade
                <select name="d_priority"><?= options(LEVELS, $_POST['d_priority'] ?? 'medium') ?></select>
            </label>
            <label class="field span-2">Desenvolvedor responsável
                <select name="d_assigned_to"><?= options($devs, $_POST['d_assigned_to'] ?? null) ?></select>
            </label>
        </div>
        <?php endif; ?>
    </fieldset>
    <?php
}

/** Extrai os campos d_* do POST para o formato esperado por create_defect(). */
function defect_data_from_post(): array
{
    return [
        'title'       => post('d_title'),
        'description' => post('d_description'),
        'severity'    => post('d_severity', 'medium'),
        'priority'    => post('d_priority', 'medium'),
        'assigned_to' => post_int('d_assigned_to'),
    ];
}
