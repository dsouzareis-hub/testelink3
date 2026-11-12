<?php
/**
 * Atribuição de casos a testers feita no próprio formulário do caso.
 * (A tela "Atribuições" continua existindo para atribuir vários casos de uma vez.)
 */

/**
 * Deixa o caso atribuído exatamente aos testers marcados (entre os testers ATIVOS).
 * - Quem foi marcado e ainda não tinha o caso: é atribuído e recebe um aviso (precisa executar).
 * - Quem foi desmarcado: é removido SOMENTE se ainda não executou o caso; quem já executou continua atribuído
 *   (remover esconderia o histórico dele do quadro).
 * Retorna ['added' => [nomes], 'removed' => [nomes], 'kept' => [nomes que não puderam ser removidos]].
 */
function sync_case_testers(int $caseId, array $testerIds, int $adminId): array
{
    $active = users_by_role('tester');                                   // id => nome (só ativos)
    $want = array_values(array_unique(array_filter(array_map('intval', $testerIds), fn($i) => isset($active[$i]))));
    $current = array_map('intval', q('SELECT tester_id FROM assignments WHERE case_id = ?', [$caseId])->fetchAll(PDO::FETCH_COLUMN));
    $code = (string)q('SELECT code FROM test_cases WHERE id = ?', [$caseId])->fetchColumn();
    $res = ['added' => [], 'removed' => [], 'kept' => []];

    foreach ($want as $t) {
        if (in_array($t, $current, true)) { continue; }
        q('INSERT IGNORE INTO assignments (case_id, tester_id, assigned_by) VALUES (?,?,?)', [$caseId, $t, $adminId]);
        notify($t, 'Caso ' . $code . ' atribuído a você para execução.', 'case_view.php?id=' . $caseId, 'assign:' . $caseId);
        $res['added'][] = $active[$t];
    }
    foreach ($current as $t) {
        if (!isset($active[$t]) || in_array($t, $want, true)) { continue; }   // inativos não são gerenciados aqui
        if (q('SELECT 1 FROM executions WHERE case_id = ? AND tester_id = ? LIMIT 1', [$caseId, $t])->fetchColumn()) {
            $res['kept'][] = $active[$t];
            continue;
        }
        q('DELETE FROM assignments WHERE case_id = ? AND tester_id = ?', [$caseId, $t]);
        notify_resolve('assign:' . $caseId, $t);
        $res['removed'][] = $active[$t];
    }
    if ($res['added'] || $res['removed']) {
        audit($res['added'] ? 'atribuicao' : 'atribuicao_removida', 'case', $caseId,
            $code . ($res['added'] ? ' → ' . implode(', ', $res['added']) : '') . ($res['removed'] ? ' (removido: ' . implode(', ', $res['removed']) . ')' : ''));
    }
    return $res;
}
