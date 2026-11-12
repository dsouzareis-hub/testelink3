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
 *
 * MODO EXPRESS (opcional, por caso; só o admin que criou o caso liga, e exige Tester e Desenvolvedor responsáveis).
 * O chamado de um caso Express tramita direto, sem o admin no meio, e o admin continua vendo e podendo intervir:
 *   reprovou → direto ao dev responsável (open) · dev conclui → direto ao tester responsável (retest)
 *   · reteste reprova → direto ao dev (open) · reteste aprova → fecha (regra de encerramento de sempre).
 * Cada passo automático é gravado no histórico, com quem o provocou e o texto "Fluxo Express (automático)".
 * Se faltar um responsável válido na hora, o chamado cai no fluxo convencional (admin decide).
 */

/** Status em que o chamado está nas mãos do desenvolvedor (visível e editável por ele). */
const DEV_ACTIVE_STATUS = ['open', 'fixing'];

/** Passos marcados como falhos numa execução (Reprovado): lista de [step_no, step_text]. */
function execution_failed_steps(int $executionId): array
{
    return q('SELECT step_no, step_text FROM execution_steps WHERE execution_id = ? AND failed = 1 ORDER BY step_no', [$executionId])->fetchAll();
}

/** HTML da lista de passos com falha (vazio se não houver). */
function failed_steps_html(array $steps): string
{
    if (!$steps) {
        return '';
    }
    $html = '<ul class="step-failed-list">';
    foreach ($steps as $s) {
        $html .= '<li>Passo ' . (int)$s['step_no'] . ' — ' . e($s['step_text']) . '</li>';
    }
    return $html . '</ul>';
}

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

    // Caso Express (com os dois responsáveis válidos): o chamado já nasce marcado como Express
    $express = case_express_config((int)$ex['case_id']) ? 1 : 0;
    q('INSERT INTO defects (execution_id, case_id, project_id, title, description, severity, priority, status, reported_by, assigned_to, express)
       VALUES (?,?,?,?,?,?,?,?,?,NULL,?)', [
        $executionId, $ex['case_id'], $ex['project_id'], mb_substr('[' . $ex['code'] . '] ' . $ex['title'], 0, 200),
        $ex['notes'] ?: 'Execução reprovada (sem observações).', $ex['priority'], 'medium', 'triage', $testerId, $express,
    ]);
    $id = (int)db()->lastInsertId();
    add_defect_comment($id, $testerId, $express
        ? 'Execução reprovada em caso Express.'
        : 'Execução reprovada. Aguardando o Administrador encaminhar ao desenvolvedor.', null, 'triage');
    if ($express && express_to_dev($id, $testerId, 'Reprovado pelo tester:')) {
        return $id;                                           // seguiu direto ao desenvolvedor: o admin não precisa agir
    }
    // Fluxo convencional (ou Express sem responsável válido): quem precisa agir é o administrador
    notify_admins('Chamado #' . $id . ' (' . $ex['code'] . ') aguardando a sua decisão — reprovado no teste.',
        'defect_view.php?id=' . $id, 'decide:' . $id, $testerId);
    return $id;
}

/* ============================== MODO EXPRESS ============================== */

/**
 * Configuração Express do caso: [tester_id, dev_id, priority] se o caso é Express E os dois responsáveis estão
 * ativos e com o perfil certo; senão null (o fluxo convencional assume).
 */
function case_express_config(int $caseId): ?array
{
    $c = q('SELECT express, responsible_tester_id t, responsible_dev_id d, priority FROM test_cases WHERE id = ?', [$caseId])->fetch();
    if (!$c || !(int)$c['express'] || !$c['t'] || !$c['d']) {
        return null;
    }
    $okT = q("SELECT 1 FROM users WHERE id = ? AND role = 'tester' AND active = 1 AND status = 'approved'", [$c['t']])->fetchColumn();
    $okD = q("SELECT 1 FROM users WHERE id = ? AND role = 'developer' AND active = 1 AND status = 'approved'", [$c['d']])->fetchColumn();
    return ($okT && $okD) ? ['tester_id' => (int)$c['t'], 'dev_id' => (int)$c['d'], 'priority' => $c['priority']] : null;
}

/** Este chamado tramita em Express agora? (marcado como Express na abertura E o caso ainda tem responsáveis válidos) */
function defect_express_active(int $defectId): bool
{
    $d = q('SELECT case_id, express FROM defects WHERE id = ?', [$defectId])->fetch();
    return $d && (int)$d['express'] === 1 && case_express_config((int)$d['case_id']) !== null;
}

/** Express: encaminha direto ao desenvolvedor responsável (status "open"). */
function express_to_dev(int $defectId, int $actorId, string $reason): bool
{
    $d = q('SELECT d.status, d.case_id, tc.code FROM defects d JOIN test_cases tc ON tc.id = d.case_id WHERE d.id = ?', [$defectId])->fetch();
    $cfg = $d ? case_express_config((int)$d['case_id']) : null;
    if (!$cfg || $d['status'] === 'closed') {
        return false;
    }
    $devName = (string)q('SELECT name FROM users WHERE id = ?', [$cfg['dev_id']])->fetchColumn();
    q("UPDATE defects SET assigned_to = ?, retest_tester_id = NULL, priority = ?, status = 'open' WHERE id = ?", [$cfg['dev_id'], $cfg['priority'], $defectId]);
    add_defect_comment($defectId, $actorId, 'Fluxo Express (automático): ' . $reason . ' encaminhado direto ao desenvolvedor ' . $devName
        . ' — urgência ' . LEVELS[$cfg['priority']] . '.', $d['status'], 'open');
    notify_resolve('decide:' . $defectId);
    notify_resolve('retest:' . $defectId);
    notify($cfg['dev_id'], 'Chamado #' . $defectId . ' (' . $d['code'] . ') encaminhado a você (Express) — urgência ' . LEVELS[$cfg['priority']] . '.',
        'defect_view.php?id=' . $defectId, 'dev:' . $defectId);
    audit('chamado_express', 'defect', $defectId, 'Direto ao desenvolvedor ' . $devName . ' — ' . $reason, $actorId);
    return true;
}

/** Express: envia direto ao tester responsável para reteste (status "retest") e põe o caso no Kanban dele. */
function express_to_tester(int $defectId, int $actorId, string $reason): bool
{
    $d = q('SELECT d.status, d.case_id, tc.code FROM defects d JOIN test_cases tc ON tc.id = d.case_id WHERE d.id = ?', [$defectId])->fetch();
    $cfg = $d ? case_express_config((int)$d['case_id']) : null;
    if (!$cfg || $d['status'] === 'closed') {
        return false;
    }
    $tName = (string)q('SELECT name FROM users WHERE id = ?', [$cfg['tester_id']])->fetchColumn();
    q("UPDATE defects SET retest_tester_id = ?, status = 'retest' WHERE id = ?", [$cfg['tester_id'], $defectId]);
    // O tester responsável precisa ter o caso atribuído; o cartão entra direto em Em Andamento (novo ciclo)
    q('INSERT IGNORE INTO assignments (case_id, tester_id, assigned_by) VALUES (?,?,NULL)', [$d['case_id'], $cfg['tester_id']]);
    q("UPDATE assignments SET kanban_status = 'doing', cycle_start = NOW(), done_at = NULL WHERE case_id = ? AND tester_id = ?", [$d['case_id'], $cfg['tester_id']]);
    add_defect_comment($defectId, $actorId, 'Fluxo Express (automático): ' . $reason . ' enviado direto ao tester ' . $tName . ' para reteste.', $d['status'], 'retest');
    notify_resolve('dev:' . $defectId);
    notify_resolve('decide:' . $defectId);
    notify($cfg['tester_id'], 'Reteste solicitado (Express): caso ' . $d['code'] . ' (chamado #' . $defectId . ').',
        'case_view.php?id=' . (int)$d['case_id'], 'retest:' . $defectId);
    audit('chamado_express', 'defect', $defectId, 'Direto ao tester ' . $tName . ' para reteste — ' . $reason, $actorId);
    return true;
}

/**
 * Chamado fechado: o cartão de reteste que ficou na fila do tester sem nenhuma atividade (ex.: o admin fechou por fora)
 * deixa de ser pendência e vai para Concluído. Quem já executou algo mantém o cartão como está.
 */
function release_retest_card(int $defectId): void
{
    $d = q('SELECT case_id, retest_tester_id FROM defects WHERE id = ?', [$defectId])->fetch();
    if (!$d || !$d['retest_tester_id']) {
        return;
    }
    q("UPDATE assignments a SET a.kanban_status = 'done', a.done_at = NOW()
       WHERE a.case_id = ? AND a.tester_id = ? AND a.kanban_status IN ('waiting','doing')
         AND NOT EXISTS (SELECT 1 FROM executions e WHERE e.case_id = a.case_id AND e.tester_id = a.tester_id AND e.executed_at >= a.cycle_start)",
        [$d['case_id'], $d['retest_tester_id']]);
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
 * - tester: chamado vai ao tester como Reteste (status "retest") e o cartão dele entra direto em "Em Andamento" (novo ciclo).
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
    $code = (string)q('SELECT code FROM test_cases WHERE id = ?', [$d['case_id']])->fetchColumn();

    if ($target === 'developer') {
        $dev = q("SELECT name FROM users WHERE id = ? AND role = 'developer' AND active = 1 AND status = 'approved'", [$targetUserId])->fetchColumn();
        if (!$dev) {
            throw new InvalidArgumentException('Selecione um desenvolvedor válido.');
        }
        q("UPDATE defects SET assigned_to = ?, retest_tester_id = NULL, priority = ?, status = 'open' WHERE id = ?", [$targetUserId, $urgency, $defectId]);
        add_defect_comment($defectId, $adminId, 'Decisão do Administrador: encaminhado ao desenvolvedor ' . $dev . ' — urgência ' . LEVELS[$urgency] . '.',
            $d['status'], 'open');
        // A decisão foi tomada (baixa o aviso dos admins) e agora quem precisa agir é o desenvolvedor
        notify_resolve('decide:' . $defectId);
        notify_resolve('retest:' . $defectId);
        notify($targetUserId, 'Chamado #' . $defectId . ' (' . $code . ') encaminhado a você — urgência ' . LEVELS[$urgency] . '.',
            'defect_view.php?id=' . $defectId, 'dev:' . $defectId);
        audit('chamado_decisao', 'defect', $defectId, 'Desenvolvedor: ' . $dev . ' — urgência ' . LEVELS[$urgency]);
        return;
    }

    if ($target === 'tester') {
        $testers = case_testers((int)$d['case_id']);
        if (!isset($testers[$targetUserId])) {
            throw new InvalidArgumentException('Selecione um tester atribuído a este caso.');
        }
        q("UPDATE defects SET retest_tester_id = ?, priority = ?, status = 'retest' WHERE id = ?", [$targetUserId, $urgency, $defectId]);
        // O caso entra no Kanban do tester direto em "Em Andamento" (Reteste), iniciando um novo ciclo de execução
        q("UPDATE assignments SET kanban_status = 'doing', cycle_start = NOW(), done_at = NULL WHERE case_id = ? AND tester_id = ?", [$d['case_id'], $targetUserId]);
        add_defect_comment($defectId, $adminId, 'Decisão do Administrador: enviado ao tester ' . $testers[$targetUserId] . ' para reteste — urgência ' . LEVELS[$urgency] . '.',
            $d['status'], 'retest');
        notify_resolve('decide:' . $defectId);
        notify_resolve('dev:' . $defectId);
        notify($targetUserId, 'Reteste solicitado: caso ' . $code . ' (chamado #' . $defectId . ') — urgência ' . LEVELS[$urgency] . '.',
            'case_view.php?id=' . (int)$d['case_id'], 'retest:' . $defectId);
        audit('chamado_decisao', 'defect', $defectId, 'Reteste com ' . $testers[$targetUserId] . ' — urgência ' . LEVELS[$urgency]);
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
        defect_status_notifications($defectId, $new, $userId);
    }
    // Primeiro grava a ação de quem a fez; só depois o passo automático do Express (histórico na ordem certa)
    $recordId = add_defect_comment($defectId, $userId, $comment, $old !== $new ? $old : null, $old !== $new ? $new : null);
    if ($old !== $new && defect_express_active($defectId)) {
        if ($new === 'fixed') {
            express_to_tester($defectId, $userId, 'Correção concluída pelo desenvolvedor:');
        } elseif ($new === 'reopened') {
            express_to_dev($defectId, $userId, 'Reteste reprovado:');
        }
    }
    return $recordId;
}

/**
 * Avisos gerados por uma mudança de status. Só avisa quem precisa agir (nunca quem acabou de agir):
 *  - concluído pelo dev / reaberto no reteste → o ADMIN precisa decidir;
 *  - fechado → ninguém mais precisa agir: dá baixa em todos os avisos do chamado.
 */
function defect_status_notifications(int $defectId, string $new, int $actorId): void
{
    $code = (string)q('SELECT tc.code FROM defects d JOIN test_cases tc ON tc.id = d.case_id WHERE d.id = ?', [$defectId])->fetchColumn();
    $link = 'defect_view.php?id=' . $defectId;
    // Em Express o chamado segue direto (o próximo responsável é avisado pelo passo automático): o admin não precisa agir
    $express = defect_express_active($defectId);
    switch ($new) {
        case 'fixed':
            notify_resolve('dev:' . $defectId);
            if (!$express) {
                notify_admins('Chamado #' . $defectId . ' (' . $code . ') concluído pelo desenvolvedor — aguardando a sua decisão.', $link, 'decide:' . $defectId, $actorId);
            }
            break;
        case 'reopened':
            notify_resolve('retest:' . $defectId);
            if (!$express) {
                notify_admins('Chamado #' . $defectId . ' (' . $code . ') reaberto no reteste — aguardando a sua decisão.', $link, 'decide:' . $defectId, $actorId);
            }
            break;
        case 'closed':
            foreach (['decide:', 'dev:', 'retest:'] as $p) { notify_resolve($p . $defectId); }
            release_retest_card($defectId);          // não deixa o reteste "pendurado" na fila do tester
            break;
    }
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
