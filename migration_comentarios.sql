-- Comentários adicionais em execuções (Tester). Execute uma única vez no banco testlink3. Não apaga dados.
USE testlink3;

CREATE TABLE IF NOT EXISTS execution_comments (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    execution_id INT UNSIGNED NOT NULL,
    user_id      INT UNSIGNED NOT NULL,
    comment      TEXT NOT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (execution_id) REFERENCES executions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)      REFERENCES users(id)      ON DELETE CASCADE
) ENGINE=InnoDB;
