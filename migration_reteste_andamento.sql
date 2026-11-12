-- Reteste vai direto para "Em Andamento" no Kanban do tester (sem mudança de estrutura, só ajuste de dados).
-- Execute uma única vez no banco testlink3. Não apaga dados.
USE testlink3;

UPDATE assignments a
   SET a.kanban_status = 'doing', a.done_at = NULL
 WHERE a.kanban_status = 'waiting'
   AND EXISTS (SELECT 1 FROM defects d
                WHERE d.case_id = a.case_id AND d.status = 'retest' AND d.retest_tester_id = a.tester_id);
