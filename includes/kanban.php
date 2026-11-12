<?php
/**
 * Regras do Kanban "Meus Testes".
 * Colunas: waiting (Em aberto) → doing (Em Andamento) → done (Concluído).
 * Concluído mostra apenas o que foi concluído no dia (assignments.done_at); depois da meia-noite o cartão sai do quadro e fica só no Histórico.
 * Toda alteração de status (arrastar e soltar ou requisição direta) passa por kanban_move(),
 * que é onde a regra de conclusão é aplicada — não existe outro caminho que grave o status.
 */

const KANBAN_COLUMNS = ['waiting' => 'Em aberto', 'doing' => 'Em Andamento', 'done' => 'Concluído'];

/** Rótulos de criticidade do Kanban (masculino, como pedido). */
const KANBAN_LEVELS = ['critical' => 'Crítico', 'high' => 'Alto', 'medium' => 'Médio', 'low' => 'Baixo'];

/** Colunas do Kanban do desenvolvedor (status do chamado). Sem "Reaberto": isso é decisão do Admin/reteste. */
const DEV_COLUMNS = ['open' => 'Aberto', 'fixing' => 'Em Andamento', 'fixed' => 'Concluído'];

/**
 * Move um chamado no Kanban do desenvolvedor.
 * Aberto ⇄ Em Andamento é livre. Concluído devolve o chamado ao Admin e o cartão PERMANECE na coluna Concluído,
 * porém travado: o dev não pode mais mover, comentar nem alterar nada.
 * Retorna ['ok', 'message', 'status', 'locked'?, 'removed'?].
 */
function dev_move(int $defectId, string $to, int $devId): array
{
    $d = q("SELECT id, status FROM defects WHERE id = ? AND assigned_to = ? AND status IN ('open','fixing')", [$defectId, $devId])->fetch();
    if (!$d) {
        if (dev_concluded($devId, $defectId)) {
            return ['ok' => false, 'message' => 'Este chamado já foi concluído: fica na coluna Concluído, somente leitura.', 'status' => 'fixed', 'locked' => true];
        }
        return ['ok' => false, 'message' => 'Chamado não encontrado. Recarregue a página.', 'status' => '', 'removed' => true];
    }
    if (!isset(DEV_COLUMNS[$to])) {
        return ['ok' => false, 'message' => 'Status inválido.', 'status' => $d['status']];
    }
    if ($to === $d['status']) {
        return ['ok' => true, 'message' => '', 'status' => $to];
    }
    if ($to === 'fixed') {
        set_defect_status($defectId, 'fixed', $devId, 'Concluído pelo desenvolvedor; chamado devolvido ao Administrador.');
        return ['ok' => true, 'message' => 'Chamado #' . $defectId . ' concluído e devolvido ao Administrador. Ele fica em Concluído, somente leitura.', 'status' => 'fixed', 'locked' => true];
    }
    set_defect_status($defectId, $to, $devId);
    return ['ok' => true, 'message' => 'Movido para ' . DEV_COLUMNS[$to] . '.', 'status' => $to];
}

/**
 * O que falta para concluir? Considera a última execução deste tester, neste caso, dentro do ciclo atual.
 * - Assentamento: observações da execução OU algum comentário adicionado depois.
 * - Foto de evidência: obrigatória, exceto quando o resultado é Aprovado (Aprovado + comentário já conclui).
 * Retorna lista de pendências (vazia = pode concluir).
 */
function kanban_missing(array $assignment): array
{
    $ex = q('SELECT ex.id, ex.notes, ex.result,
                    (SELECT COUNT(*) FROM evidences WHERE execution_id = ex.id) evs,
                    (SELECT COUNT(*) FROM execution_comments WHERE execution_id = ex.id AND comment <> \'\') cmts
             FROM executions ex WHERE ex.case_id = ? AND ex.tester_id = ? AND ex.executed_at >= ?
             ORDER BY ex.id DESC LIMIT 1',
        [$assignment['case_id'], $assignment['tester_id'], $assignment['cycle_start']])->fetch();

    if (!$ex) {
        return ['a execução do teste (resultado, assentamento e foto)'];
    }
    $missing = [];
    if (trim((string)$ex['notes']) === '' && (int)$ex['cmts'] === 0) {
        $missing[] = 'o assentamento (observações/relatório da execução)';
    }
    if ($ex['result'] !== 'passed' && (int)$ex['evs'] < 1) {
        $missing[] = 'ao menos uma foto de evidência';
    }
    return $missing;
}

/**
 * Chamado depois de registrar execução, comentário ou foto: ajusta o cartão do tester automaticamente.
 * - Aprovado com assentamento → vai para Concluído e permanece lá.
 * - Qualquer outra situação (reprovado, bloqueado, aprovado sem comentário) → Em Andamento.
 * Retorna uma mensagem para o usuário (ou string vazia).
 */
function kanban_after_execution(int $caseId, int $testerId, string $result): string
{
    $a = q('SELECT * FROM assignments WHERE case_id = ? AND tester_id = ?', [$caseId, $testerId])->fetch();
    if (!$a) {
        return '';
    }
    if ($result === 'passed') {
        $missing = kanban_missing($a);
        if (!$missing) {
            // done_at = agora: o cartão aparece em Concluído até a meia-noite
            q("UPDATE assignments SET kanban_status = 'done', done_at = NOW() WHERE id = ?", [$a['id']]);
            return 'Aprovado: o cartão foi movido para Concluído.';
        }
        if ($a['kanban_status'] !== 'doing') {
            q("UPDATE assignments SET kanban_status = 'doing', done_at = NULL WHERE id = ?", [$a['id']]);
        }
        return 'Aprovado registrado, mas o cartão ficou em Em Andamento: falta registrar ' . implode(' e ', $missing)
            . '. Adicione um comentário na execução para concluí-lo.';
    }
    if ($a['kanban_status'] !== 'doing') {
        q("UPDATE assignments SET kanban_status = 'doing', done_at = NULL WHERE id = ?", [$a['id']]);
    }
    return '';
}

/**
 * Já houve atividade neste cartão no ciclo atual? (execução registrada — o que inclui resultado, tipo,
 * assentamento, comentários e fotos). Com atividade, o cartão não pode voltar para "Em aberto".
 */
function kanban_has_activity(array $assignment): bool
{
    return (bool)q('SELECT 1 FROM executions WHERE case_id = ? AND tester_id = ? AND executed_at >= ? LIMIT 1',
        [$assignment['case_id'], $assignment['tester_id'], $assignment['cycle_start']])->fetchColumn();
}

/**
 * Move o cartão. Retorna ['ok' => bool, 'message' => string, 'status' => status atual].
 * Em aberto → Em Andamento é livre; voltar para Em aberto só sem atividade registrada;
 * para Concluído exige assentamento (+ foto, exceto Aprovado).
 */
function kanban_move(int $assignmentId, string $to, int $testerId): array
{
    $a = q('SELECT a.*, tc.status case_status FROM assignments a JOIN test_cases tc ON tc.id = a.case_id
            WHERE a.id = ? AND a.tester_id = ?', [$assignmentId, $testerId])->fetch();
    if (!$a) {
        return ['ok' => false, 'message' => 'Cartão não encontrado.', 'status' => 'waiting'];
    }
    if (!isset(KANBAN_COLUMNS[$to])) {
        return ['ok' => false, 'message' => 'Status inválido.', 'status' => $a['kanban_status']];
    }
    if ($a['kanban_status'] === $to) {
        return ['ok' => true, 'message' => '', 'status' => $to];
    }
    if ($to !== 'waiting' && $a['case_status'] !== 'active') {
        return ['ok' => false, 'message' => 'Este caso de teste está inativo.', 'status' => $a['kanban_status']];
    }
    // Depois que algo foi registrado (execução, comentário, foto), o cartão não volta para "Em aberto"
    if ($to === 'waiting' && kanban_has_activity($a)) {
        return [
            'ok' => false,
            'message' => 'Não é possível voltar para Em aberto: este teste já tem atividade registrada (execução, comentário ou foto).',
            'status' => $a['kanban_status'],
        ];
    }
    if ($to === 'done') {
        $missing = kanban_missing($a);
        if ($missing) {
            return [
                'ok' => false,
                'message' => 'Não é possível concluir: falta registrar ' . implode(' e ', $missing)
                    . '. Abra o teste (botão Executar) e preencha antes de concluir.',
                'status' => $a['kanban_status'],
            ];
        }
    }
    // done_at marca quando foi concluído (Concluído só exibe o que foi concluído no dia); limpa ao sair de Concluído
    q("UPDATE assignments SET kanban_status = ?, done_at = IF(? = 'done', NOW(), NULL) WHERE id = ?", [$to, $to, $assignmentId]);
    return ['ok' => true, 'message' => 'Movido para ' . KANBAN_COLUMNS[$to] . '.', 'status' => $to];
}
