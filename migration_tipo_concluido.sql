-- Tipo da execução (Manual/Automatizado) e data de conclusão do cartão (Concluído some à meia-noite).
-- Execute uma única vez no banco testlink3. Não apaga dados.
USE testlink3;

ALTER TABLE executions
    ADD COLUMN exec_type ENUM('manual','automated') NOT NULL DEFAULT 'manual' AFTER result;

ALTER TABLE assignments
    ADD COLUMN done_at DATETIME NULL;

-- Cartões já concluídos: usa a data da última execução do tester no caso (os de dias anteriores saem do quadro e ficam no Histórico)
UPDATE assignments a
   SET a.done_at = COALESCE((SELECT MAX(e.executed_at) FROM executions e WHERE e.case_id = a.case_id AND e.tester_id = a.tester_id), a.cycle_start)
 WHERE a.kanban_status = 'done';
