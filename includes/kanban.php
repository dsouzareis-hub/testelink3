<?php
/**
 * Regras do Kanban "Meus Testes".
 * Colunas: waiting (Aguardando) → doing (Em Andamento) → done (Concluído).
 * Toda alteração de status (arrastar e soltar, seletor "Mover para", POST direto) passa por kanban_move(),
 * que é onde a regra de conclusão é aplicada — não existe outro caminho que grave o status.
 */

const KANBAN_COLUMNS = ['waiting' => 'Aguardando', 'doing' => 'Em Andamento', 'done' => 'Concluído'];

/** Rótulos de criticidade do Kanban (masculino, como pedido). */
const KANBAN_LEVELS = ['critical' => 'Crítico', 'high' => 'Alto', 'medium' => 'Médio', 'low' => 'Baixo'];

/**
 * O que falta para concluir? Considera a última execução deste tester, neste caso, dentro do ciclo atual.
 * Exige: assentamento preenchido E ao menos uma foto de evidência.
 * Retorna lista de pendências (vazia = pode concluir).
 */
function kanban_missing(array $assignment): array
{
    $ex = q('SELECT ex.id, ex.notes, (SELECT COUNT(*) FROM evidences WHERE execution_id = ex.id) evs
             FROM executions ex WHERE ex.case_id = ? AND ex.tester_id = ? AND ex.executed_at >= ?
             ORDER BY ex.id DESC LIMIT 1',
        [$assignment['case_id'], $assignment['tester_id'], $assignment['cycle_start']])->fetch();

    if (!$ex) {
        return ['a execução do teste (resultado, assentamento e foto)'];
    }
    $missing = [];
    if (trim((string)$ex['notes']) === '') {
        $missing[] = 'o assentamento (observações/relatório da execução)';
    }
    if ((int)$ex['evs'] < 1) {
        $missing[] = 'ao menos uma foto de evidência';
    }
    return $missing;
}

/**
 * Move o cartão. Retorna ['ok' => bool, 'message' => string, 'status' => status atual].
 * Aguardando → Em Andamento é livre; para Concluído exige assentamento + foto.
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
    q('UPDATE assignments SET kanban_status = ? WHERE id = ?', [$to, $assignmentId]);
    return ['ok' => true, 'message' => 'Movido para ' . KANBAN_COLUMNS[$to] . '.', 'status' => $to];
}
