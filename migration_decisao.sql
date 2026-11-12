-- Migração da "Tomada de Decisão" (Admin envia ao Desenvolvedor OU ao Tester para reteste).
-- Execute uma única vez no banco testlink3. Não apaga dados.
USE testlink3;

ALTER TABLE defects
    MODIFY status ENUM('triage','open','fixing','fixed','retest','reopened','closed') NOT NULL DEFAULT 'triage',
    ADD COLUMN retest_tester_id INT UNSIGNED NULL AFTER assigned_to,
    ADD CONSTRAINT fk_defect_retest_tester FOREIGN KEY (retest_tester_id) REFERENCES users(id) ON DELETE SET NULL;
