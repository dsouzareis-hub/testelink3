-- Migração para quem já instalou versões anteriores (sem Kanban). Execute uma única vez no banco testlink3.
USE testlink3;
ALTER TABLE assignments
    ADD COLUMN kanban_status ENUM('waiting','doing','done') NOT NULL DEFAULT 'waiting',
    ADD COLUMN cycle_start DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;
