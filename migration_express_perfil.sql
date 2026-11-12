-- Perfil do cadastro travado + modo Express (tramitação direta tester <-> desenvolvedor).
-- Execute uma única vez no banco testlink3. Só adiciona colunas; não apaga nem altera dados existentes.
USE testlink3;

ALTER TABLE users
    ADD COLUMN self_registered TINYINT(1) NOT NULL DEFAULT 0 AFTER status;
-- Solicitações de cadastro (pendentes/recusadas) vieram da própria pessoa: o perfil dela não pode ser trocado pelo admin
UPDATE users SET self_registered = 1 WHERE status IN ('pending', 'rejected');

ALTER TABLE test_cases
    ADD COLUMN responsible_tester_id INT UNSIGNED NULL AFTER created_by,
    ADD COLUMN responsible_dev_id    INT UNSIGNED NULL AFTER responsible_tester_id,
    ADD COLUMN express               TINYINT(1) NOT NULL DEFAULT 0 AFTER responsible_dev_id,
    ADD CONSTRAINT fk_case_resp_tester FOREIGN KEY (responsible_tester_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_case_resp_dev    FOREIGN KEY (responsible_dev_id)    REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE defects
    ADD COLUMN express TINYINT(1) NOT NULL DEFAULT 0 AFTER retest_tester_id;
