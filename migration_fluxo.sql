-- Migração do novo fluxo (triagem pelo Admin, fotos do Dev). Execute uma única vez no banco testlink3.
USE testlink3;

ALTER TABLE defects
    MODIFY status ENUM('triage','open','fixing','fixed','reopened','closed') NOT NULL DEFAULT 'triage',
    MODIFY assigned_to INT UNSIGNED NULL;

CREATE TABLE IF NOT EXISTS defect_attachments (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    defect_id     INT UNSIGNED NOT NULL,
    comment_id    INT UNSIGNED NULL,
    user_id       INT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name   VARCHAR(80) NOT NULL,
    mime          VARCHAR(40) NOT NULL,
    size          INT UNSIGNED NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (defect_id)  REFERENCES defects(id)         ON DELETE CASCADE,
    FOREIGN KEY (comment_id) REFERENCES defect_comments(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)    REFERENCES users(id)           ON DELETE CASCADE
) ENGINE=InnoDB;
