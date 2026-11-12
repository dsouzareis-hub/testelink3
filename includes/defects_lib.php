<?php
/**
 * Regras de negócio dos defeitos (chamados).
 *
 * Fluxo:
 *  1. Tester reprova uma execução → o sistema abre o chamado em "triage" (Aguardando triagem), sem desenvolvedor.
 *  2. TOMADA DE DECISÃO (Admin): define a urgência e escolhe UM destino:
 *       - Desenvolvedor → status "open"; ou
 *       - Tester → status "retest": o caso volta ao Kanban do tester como Reteste (já possível desde a triagem).
 *  3. O desenvolvedor trabalha (Aberto / Em andamento) e conclui → status "fixed": ele perde o acesso e o chamado
 *     volta ao Admin, que toma uma nova decisão (de novo ao dev, ou ao tester para reteste).
 *  4. Reteste do tester: aprovado → "closed"; reprovado → "reopened" (volta ao Admin para nova decisão).
 */

/** Status em que o chamado está nas mãos do desenvolvedor (visível e editável por ele). */
const DEV_ACTIVE_STATUS = ['open', 'fixing'];

/** Chamado ainda não resolvido para este caso (evita duplicar chamados). */
function unresolved_defect_for_case(int $caseId): ?array
{
    return q("SELECT id, status FROM defects WHERE case_id = ? AND status IN ('triage','open','fixing','fixed','retest','reopened') ORDER BY id DESC LIMIT 1",
        [$caseId])->fetch() ?: null;
}

/**
 * Abre automaticamente o chamado de uma execução reprovada, aguardando triagem do Admin.
 * Se já existir chamado em aberto para o caso, apenas registra a nova reprovação no histórico dele.
 * Retorna o id do chamado.
 */
function create_triage_defect(int $executionId, int $testerId): int
{
    $ex = q('SELECT ex.id, ex.case_id, ex.result, ex.notes, tc.code, tc.title, tc.project_id, tc.priority
             FROM executions ex JOIN test_cases tc ON tc.id = ex.case_id WHERE ex.id = ?', [$executionId])->fetch();
    if (!$ex || $ex['result'] !== 'failed') {
        throw new InvalidArgumentException('Somente execuções reprovadas geram chamado.');
    }

    if ($existing = unresolved_defect_for_case((int)$ex['case_id'])) {
        add_defect_comment((int)$existing['id'], $testerId,
            'Nova execução reprovada (#' . $executionId . '): ' . ($ex['notes'] ?: 'sem observações'), null, null);
        return (int)$existing['id'];
    }

    q('INSERT INTO defects (execution_id, case_id, project_id, title, description, severity, priority, status, reported_by, assigned_to)
       VALUES (?,?,?,?,?,?,?,?,?,NULL)', [
        $executionId, $ex['case_id'], $ex['project_id'], mb_substr('[' . $ex['code'] . '] ' . $ex['title'], 0, 200),
        $ex['notes'] ?: 'Execução reprovada (sem observações).', $ex['priority'], 'medium', 'triage', $testerId,
    ]);
    $id = (int)db()->lastInsertId();
    add_defect_comment($id, $testerId, 'Execução reprovada. Aguardando o Administrador encaminhar ao desenvolvedor.', null, 'triage');
    return $id;
}

/** Testers ativos atribuídos ao caso (id => nome): são os que podem receber o reteste. */
function case_testers(int $caseId): array
{
    return q("SELECT u.id, u.name FROM assignments a JOIN users u ON u.id = a.tester_id
              WHERE a.case_id = ? AND u.role = 'tester' AND u.active = 1 AND u.status = 'approved' ORDER BY u.name", [$caseId])
        ->fetchAll(PDO::FETCH_KEY_PAIR);
}

/**
 * Tomada de decisão do Admin: urgência + UM destino ('developer' ou 'tester').
 * - developer: chamado vai ao desenvolvedor escolhido (status "open").
 * - tester: chamado vai ao tester como Reteste (status "retest") e o cartão dele volta a "Aguardando" (novo ciclo).
 * Lança InvalidArgumentException com mensagem amigável em caso de erro.
 */
function decide_defect(int $defectId, string $target, int $targetUserId, string $urgency, int $adminId): void
{
    $d = q('SELECT status, case_id FROM defects WHERE id = ?', [$defectId])->fetch();
    if (!$d || $d['status'] === 'closed') {
        throw new InvalidArgumentException('Chamado não encontrado ou já fechado.');
    }
    if (!isset(LEVELS[$urgency])) {
        throw new InvalidArgumentException('Selecione a urgência.');
    }

    if ($target === 'developer') {
        $dev = q("SELECT name FROM users WHERE id = ? AND role = 'developer' AND active = 1 AND status = 'approved'", [$targetUserId])->fetchColumn();
        if (!$dev) {
            throw new InvalidArgumentException('Selecione um desenvolvedor válido.');
        }
        q("UPDATE defects SET assigned_to = ?, retest_tester_id = NULL, priority = ?, status = 'open' WHERE id = ?", [$targetUserId, $urgency, $defectId]);
        add_defect_comment($defectId, $adminId, 'Decisão do Administrador: encaminhado ao desenvolvedor ' . $dev . ' — urgência ' . LEVELS[$urgency] . '.',
            $d['status'], 'open');
        return;
    }

    if ($target === 'tester') {
        $testers = case_testers((int)$d['case_id']);
        if (!isset($testers[$targetUserId])) {
            throw new InvalidArgumentException('Selecione um tester atribuído a este caso.');
        }
        q("UPDATE defects SET retest_tester_id = ?, priority = ?, status = 'retest' WHERE id = ?", [$targetUserId, $urgency, $defectId]);
        // O caso volta ao Kanban do tester como "Aguardando" (Reteste), iniciando um novo ciclo de execução
        q("UPDATE assignments SET kanban_status = 'waiting', cycle_start = NOW(), done_at = NULL WHERE case_id = ? AND tester_id = ?", [$d['case_id'], $targetUserId]);
        add_defect_comment($defectId, $adminId, 'Decisão do Administrador: enviado ao tester ' . $testers[$targetUserId] . ' para reteste — urgência ' . LEVELS[$urgency] . '.',
            $d['status'], 'retest');
        return;
    }

    throw new InvalidArgumentException('Escolha o destino: Desenvolvedor ou Tester.');
}

/** Registra comentário e/ou mudança de status no histórico. Retorna o id do registro. */
function add_defect_comment(int $defectId, int $userId, ?string $comment, ?string $old, ?string $new): int
{
    q('INSERT INTO defect_comments (defect_id, user_id, comment, old_status, new_status) VALUES (?,?,?,?,?)',
        [$defectId, $userId, $comment, $old, $new]);
    return (int)db()->lastInsertId();
}

/** Altera o status e registra no histórico. Retorna o id do registro do histórico (ou 0). */
function set_defect_status(int $defectId, string $new, int $userId, ?string $comment = null): int
{
    $old = q('SELECT status FROM defects WHERE id = ?', [$defectId])->fetchColumn();
    if ($old === false || !isset(DEFECT_STATUS[$new])) {
        return 0;
    }
    if ($old !== $new) {
        q('UPDATE defects SET status = ? WHERE id = ?', [$new, $defectId]);
    }
    return add_defect_comment($defectId, $userId, $comment, $old !== $new ? $old : null, $old !== $new ? $new : null);
}

/** Este desenvolvedor já concluiu este chamado em algum momento? (o chamado fica na coluna Concluído dele, só leitura) */
function dev_concluded(int $devId, int $defectId): bool
{
    return (bool)q("SELECT 1 FROM defect_comments WHERE defect_id = ? AND user_id = ? AND new_status = 'fixed' LIMIT 1", [$defectId, $devId])->fetchColumn();
}

/** O chamado está com este desenvolvedor para trabalho (aberto/em andamento)? Só então ele pode agir. */
function dev_can_act(array $user, array $defect): bool
{
    return $user['role'] === 'developer' && (int)$defect['assigned_to'] === (int)$user['id']
        && in_array($defect['status'], DEV_ACTIVE_STATUS, true);
}

/** O usuário pode ver este defeito? */
function can_view_defect(array $user, array $defect): bool
{
    switch ($user['role']) {
        case 'admin':
        case 'viewer':
            return true;
        case 'developer':
            // Vê o que está com ele (para agir) e o que ele já concluiu (somente leitura)
            return dev_can_act($user, $defect) || dev_concluded((int)$user['id'], (int)$defect['id']);
        case 'tester':
            return (int)$defect['reported_by'] === (int)$user['id'] || tester_has_case($user['id'], (int)$defect['case_id']);
    }
    return false;
}
