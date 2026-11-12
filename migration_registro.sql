-- Migração para quem já instalou a versão anterior (sem cadastro com aprovação).
-- Execute uma única vez no banco testlink3. Usuários existentes continuam aprovados.
USE testlink3;
ALTER TABLE users
    ADD COLUMN status ENUM('approved','pending','rejected') NOT NULL DEFAULT 'approved' AFTER active;
