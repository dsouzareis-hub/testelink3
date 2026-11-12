-- Proteção contra perda (lixeira + auditoria), notificações e resultado por passo.
-- Execute uma única vez no banco testlink3. Só cria tabelas novas; não altera nem apaga dados.
USE testlink3;

CREATE TABLE IF NOT EXISTS execution_steps (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    execution_id INT UNSIGNED NOT NULL,
    step_no      INT UNSIGNED NOT NULL,
    step_text    TEXT NOT NULL,
    failed       TINYINT(1) NOT NULL DEFAULT 0,
    KEY idx_step_exec (execution_id),
    FOREIGN KEY (execution_id) REFERENCES executions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    message    VARCHAR(255) NOT NULL,
    link       VARCHAR(255) NULL,
    ref        VARCHAR(40) NULL,
    read_at    DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_notif_user (user_id, read_at),
    KEY idx_notif_ref (ref),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_log (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NULL,
    user_name  VARCHAR(160) NULL,
    action     VARCHAR(40) NOT NULL,
    entity     VARCHAR(40) NULL,
    entity_id  INT UNSIGNED NULL,
    details    VARCHAR(500) NULL,
    ip         VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_created (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS trash (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('project','suite','case') NOT NULL,
    entity_id   INT UNSIGNED NOT NULL,
    title       VARCHAR(255) NOT NULL,
    summary     VARCHAR(255) NULL,
    payload     LONGTEXT NOT NULL,
    files       TEXT NULL,
    deleted_by  INT UNSIGNED NULL,
    deleted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
