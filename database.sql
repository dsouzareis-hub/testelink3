-- ============================================================
-- TestLink 3.0 - Estrutura do banco de dados (MySQL / MariaDB)
-- Importe este arquivo pelo phpMyAdmin ou via: mysql -u root -p < database.sql
-- ============================================================
-- Banco próprio (testlink3) para não colidir com uma instalação do TestLink 1.9 (banco "testlink").
-- O script não apaga tabelas: se elas já existirem, a importação falha em vez de sobrescrever dados.
CREATE DATABASE IF NOT EXISTS testlink3 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE testlink3;

-- Usuários e perfis -------------------------------------------------------
CREATE TABLE users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(120) NOT NULL,
    email         VARCHAR(160) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('admin','tester','developer','viewer') NOT NULL DEFAULT 'tester',
    active        TINYINT(1) NOT NULL DEFAULT 1,
    -- Cadastro: 'pending' = solicitou acesso e aguarda o administrador; 'rejected' = recusado
    status        ENUM('approved','pending','rejected') NOT NULL DEFAULT 'approved',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Configurações gerais (chave/valor) --------------------------------------
CREATE TABLE settings (
    k VARCHAR(60) PRIMARY KEY,
    v VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

-- Projetos e suítes --------------------------------------------------------
CREATE TABLE projects (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(150) NOT NULL,
    description TEXT NULL,
    status      ENUM('active','archived') NOT NULL DEFAULT 'active',
    created_by  INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE suites (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id  INT UNSIGNED NOT NULL,
    name        VARCHAR(150) NOT NULL,
    description TEXT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Casos de teste + histórico de versões ------------------------------------
CREATE TABLE test_cases (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id      INT UNSIGNED NOT NULL,
    suite_id        INT UNSIGNED NOT NULL,
    code            VARCHAR(40) NOT NULL,
    title           VARCHAR(200) NOT NULL,
    preconditions   TEXT NULL,
    steps           TEXT NOT NULL,
    expected_result TEXT NOT NULL,
    priority        ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    version         INT UNSIGNED NOT NULL DEFAULT 1,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_case_code (project_id, code),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (suite_id)   REFERENCES suites(id)   ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE test_case_versions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id         INT UNSIGNED NOT NULL,
    version         INT UNSIGNED NOT NULL,
    title           VARCHAR(200) NOT NULL,
    preconditions   TEXT NULL,
    steps           TEXT NOT NULL,
    expected_result TEXT NOT NULL,
    priority        VARCHAR(20) NOT NULL,
    status          VARCHAR(20) NOT NULL,
    change_note     VARCHAR(255) NULL,
    changed_by      INT UNSIGNED NULL,
    changed_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id)    REFERENCES test_cases(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES users(id)      ON DELETE SET NULL
) ENGINE=InnoDB;

-- Atribuição de CTs a testers ----------------------------------------------
CREATE TABLE assignments (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id     INT UNSIGNED NOT NULL,
    tester_id   INT UNSIGNED NOT NULL,
    assigned_by INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Kanban do tester: coluna atual do cartão e início do ciclo atual (volta a "waiting" quando um defeito é corrigido)
    kanban_status ENUM('waiting','doing','done') NOT NULL DEFAULT 'waiting',
    cycle_start   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_assignment (case_id, tester_id),
    FOREIGN KEY (case_id)     REFERENCES test_cases(id) ON DELETE CASCADE,
    FOREIGN KEY (tester_id)   REFERENCES users(id)      ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id)      ON DELETE SET NULL
) ENGINE=InnoDB;

-- Execuções e evidências ----------------------------------------------------
CREATE TABLE executions (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id      INT UNSIGNED NOT NULL,
    tester_id    INT UNSIGNED NOT NULL,
    result       ENUM('passed','failed','blocked') NOT NULL,
    notes        TEXT NULL,
    case_version INT UNSIGNED NOT NULL DEFAULT 1,
    executed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_exec_case (case_id),
    FOREIGN KEY (case_id)   REFERENCES test_cases(id) ON DELETE CASCADE,
    FOREIGN KEY (tester_id) REFERENCES users(id)      ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE evidences (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    execution_id INT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name  VARCHAR(80) NOT NULL,
    mime         VARCHAR(40) NOT NULL,
    size         INT UNSIGNED NOT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (execution_id) REFERENCES executions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Defeitos e comentários ----------------------------------------------------
CREATE TABLE defects (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    execution_id INT UNSIGNED NOT NULL,
    case_id      INT UNSIGNED NOT NULL,
    project_id   INT UNSIGNED NOT NULL,
    title        VARCHAR(200) NOT NULL,
    description  TEXT NOT NULL,
    severity     ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    priority     ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    -- triage = reprovado pelo tester, aguardando o Admin encaminhar; assigned_to só é preenchido ao encaminhar
    -- fixed = concluído pelo dev (volta ao Admin decidir); retest = Admin enviou ao tester para reteste
    status       ENUM('triage','open','fixing','fixed','retest','reopened','closed') NOT NULL DEFAULT 'triage',
    reported_by  INT UNSIGNED NOT NULL,
    assigned_to  INT UNSIGNED NULL,
    retest_tester_id INT UNSIGNED NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_defect_status (status),
    FOREIGN KEY (execution_id) REFERENCES executions(id) ON DELETE CASCADE,
    FOREIGN KEY (case_id)      REFERENCES test_cases(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id)   REFERENCES projects(id)   ON DELETE CASCADE,
    FOREIGN KEY (reported_by)  REFERENCES users(id)      ON DELETE CASCADE,
    FOREIGN KEY (assigned_to)  REFERENCES users(id)      ON DELETE CASCADE,
    FOREIGN KEY (retest_tester_id) REFERENCES users(id)  ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE defect_comments (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    defect_id  INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    comment    TEXT NULL,
    old_status VARCHAR(20) NULL,
    new_status VARCHAR(20) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (defect_id) REFERENCES defects(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB;

-- Fotos anexadas a comentários do defeito (desenvolvedor, admin, tester)
CREATE TABLE defect_attachments (
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

-- ============================================================
-- Dados iniciais
-- Todos os usuários abaixo usam a senha: Admin@123  (TROQUE após o primeiro login)
-- ============================================================
INSERT INTO users (name, email, password_hash, role) VALUES
('Administrador', 'admin@testlink.local', '$2y$10$7IfjG/59wv4wiPdNgcOEBO3iDJNTJfcIZwgFGfZmyYsEPoz1.fytu', 'admin'),
('Tester Demo',   'tester@testlink.local', '$2y$10$7IfjG/59wv4wiPdNgcOEBO3iDJNTJfcIZwgFGfZmyYsEPoz1.fytu', 'tester'),
('Dev Demo',      'dev@testlink.local',    '$2y$10$7IfjG/59wv4wiPdNgcOEBO3iDJNTJfcIZwgFGfZmyYsEPoz1.fytu', 'developer'),
('Gestor Demo',   'viewer@testlink.local', '$2y$10$7IfjG/59wv4wiPdNgcOEBO3iDJNTJfcIZwgFGfZmyYsEPoz1.fytu', 'viewer');

INSERT INTO settings (k, v) VALUES
('system_name', 'TestLink 3.0'),
('max_upload_mb', '5');

-- Projeto de demonstração
INSERT INTO projects (name, description, created_by) VALUES
('Projeto Demo', 'Projeto de exemplo para conhecer o sistema.', 1);
INSERT INTO suites (project_id, name, description) VALUES
(1, 'Autenticação', 'Casos relacionados a login e sessão.');
INSERT INTO test_cases (project_id, suite_id, code, title, preconditions, steps, expected_result, priority, created_by) VALUES
(1, 1, 'CT-001', 'Login com credenciais válidas', 'Usuário cadastrado e ativo.',
 'Acessar a tela de login\nInformar e-mail e senha válidos\nClicar em Entrar',
 'O usuário é redirecionado ao painel principal.', 'high', 1),
(1, 1, 'CT-002', 'Login com senha inválida', 'Usuário cadastrado e ativo.',
 'Acessar a tela de login\nInformar e-mail válido e senha incorreta\nClicar em Entrar',
 'Exibe mensagem de credenciais inválidas e permanece na tela de login.', 'medium', 1);
INSERT INTO test_case_versions (case_id, version, title, preconditions, steps, expected_result, priority, status, change_note, changed_by)
SELECT id, 1, title, preconditions, steps, expected_result, priority, status, 'Criação do caso', 1 FROM test_cases;
INSERT INTO assignments (case_id, tester_id, assigned_by) VALUES (1, 2, 1), (2, 2, 1);
