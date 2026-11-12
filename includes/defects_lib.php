<?php
/**
 * Regras de negócio dos defeitos (chamados).
 *
 * Fluxo:
 *  1. Tester reprova uma execução → o sistema abre o chamado em "triage" (Aguardando triagem), sem desenvolvedor.
 *  2. O ADMIN encaminha: escolhe apenas urgência e desenvolvedor → status "open".
 *  3. O desenvolvedor trabalha (Aberto / Em andamento) e conclui → status "fixed": ele perde o acesso e o chamado volta ao Admin.
 *  4. Reteste do tester: aprovado → "closed"; reprovado → "reopened" (volta ao Admin para reencaminhar).
 */

/** Status em que o chamado está nas mãos do desenvolvedor (visível e editável por ele). */
const DEV_ACTIVE_STATUS = ['open', 'fixing'];

/** Chamado ainda não resolvido para este caso (evita duplicar chamados). */
function unresolved_defect_for_case(int $caseId): ?array
{
    return q("SELECT id, status FROM defects WHERE case_id = ? AND status IN ('triage','open','fixing','reopened') ORDER BY id DESC LIMIT 1",
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

/**
 * Encaminhamento feito pelo Admin: somente urgência + desenvolvedor.
 * Lança InvalidArgumentException com mensagem amigável em caso de erro.
 */
function forward_defect(int $defectId, int $devId, string $urgency, int $adminId): void
{
    $d = q('SELECT status FROM defects WHERE id = ?', [$defectId])->fetch();
    if (!$d || $d['status'] === 'closed') {
        throw new InvalidArgumentException('Chamado não encontrado ou já fechado.');
    }
    if (!isset(LEVELS[$urgency])) {
        throw new InvalidArgumentException('Selecione a urgência.');
    }
    $dev = q("SELECT name FROM users WHERE id = ? AND role = 'developer' AND active = 1 AND status = 'approved'", [$devId])->fetchColumn();
    if (!$dev) {
        throw new InvalidArgumentException('Selecione um desenvolvedor válido.');
    }
    q("UPDATE defects SET assigned_to = ?, priority = ?, status = 'open' WHERE id = ?", [$devId, $urgency, $defectId]);
    add_defect_comment($defectId, $adminId, 'Encaminhado ao desenvolvedor ' . $dev . ' — urgência ' . LEVELS[$urgency] . '.',
        $d['status'], 'open');
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
        // Concluído pelo dev: o caso volta ao Kanban do tester como "Aguardando" para o reteste (novo ciclo)
        if ($new === 'fixed') {
            q("UPDATE assignments SET kanban_status = 'waiting', cycle_start = NOW()
               WHERE case_id = (SELECT case_id FROM defects WHERE id = ?)", [$defectId]);
        }
    }
    return add_defect_comment($defectId, $userId, $comment, $old !== $new ? $old : null, $old !== $new ? $new : null);
}

/** O usuário pode ver este defeito? */
function can_view_defect(array $user, array $defect): bool
{
    switch ($user['role']) {
        case 'admin':
        case 'viewer':
            return true;
        case 'developer':
            // O desenvolvedor só enxerga enquanto o chamado está com ele; ao concluir, perde o acesso
            return (int)$defect['assigned_to'] === (int)$user['id'] && in_array($defect['status'], DEV_ACTIVE_STATUS, true);
        case 'tester':
            return (int)$defect['reported_by'] === (int)$user['id'] || tester_has_case($user['id'], (int)$defect['case_id']);
    }
    return false;
}
