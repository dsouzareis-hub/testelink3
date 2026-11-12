<?php
/**
 * Lixeira restaurável.
 *
 * Excluir projeto, suíte ou caso NÃO apaga mais de vez: antes de remover, o sistema guarda uma cópia completa
 * (o item e tudo que depende dele: versões, atribuições, execuções, passos, comentários, evidências, defeitos...)
 * na tabela `trash` e move as fotos para uploads/trash/. Na tela Lixeira o administrador pode restaurar
 * (com os mesmos IDs) ou excluir definitivamente.
 */

define('TRASH_DIR', dirname(__DIR__) . '/uploads/trash/');

/** Ordem de inserção na restauração (respeita as chaves estrangeiras). */
const TRASH_ORDER = ['projects', 'suites', 'test_cases', 'test_case_versions', 'assignments', 'executions', 'execution_steps',
    'execution_comments', 'evidences', 'defects', 'defect_comments', 'defect_attachments'];

/** Colunas que apontam para usuários: true = pode virar NULL se o usuário não existir mais. */
const TRASH_USER_COLS = [
    'projects' => ['created_by' => true], 'test_cases' => ['created_by' => true], 'test_case_versions' => ['changed_by' => true],
    'assignments' => ['tester_id' => false, 'assigned_by' => true], 'executions' => ['tester_id' => false],
    'execution_comments' => ['user_id' => false],
    'defects' => ['reported_by' => false, 'assigned_to' => true, 'retest_tester_id' => true],
    'defect_comments' => ['user_id' => false], 'defect_attachments' => ['user_id' => false],
];

const TRASH_LABELS = ['project' => 'Projeto', 'suite' => 'Suíte', 'case' => 'Caso de teste'];

/** Linhas de todas as tabelas que dependem dos casos informados. */
function trash_collect_cases(array $caseIds): array
{
    $t = [];
    if (!$caseIds) {
        return $t;
    }
    $in = fn(array $a) => implode(',', array_fill(0, count($a), '?'));
    $ids = array_values($caseIds);
    $t['test_cases'] = q('SELECT * FROM test_cases WHERE id IN (' . $in($ids) . ')', $ids)->fetchAll();
    $t['test_case_versions'] = q('SELECT * FROM test_case_versions WHERE case_id IN (' . $in($ids) . ')', $ids)->fetchAll();
    $t['assignments'] = q('SELECT * FROM assignments WHERE case_id IN (' . $in($ids) . ')', $ids)->fetchAll();
    $t['executions'] = q('SELECT * FROM executions WHERE case_id IN (' . $in($ids) . ')', $ids)->fetchAll();
    $exIds = array_column($t['executions'], 'id');
    foreach (['execution_steps', 'execution_comments', 'evidences'] as $tbl) {
        $t[$tbl] = $exIds ? q("SELECT * FROM $tbl WHERE execution_id IN (" . $in($exIds) . ')', $exIds)->fetchAll() : [];
    }
    $t['defects'] = q('SELECT * FROM defects WHERE case_id IN (' . $in($ids) . ')', $ids)->fetchAll();
    $dIds = array_column($t['defects'], 'id');
    foreach (['defect_comments', 'defect_attachments'] as $tbl) {
        $t[$tbl] = $dIds ? q("SELECT * FROM $tbl WHERE defect_id IN (" . $in($dIds) . ')', $dIds)->fetchAll() : [];
    }
    return $t;
}

function trash_summary(array $tables): string
{
    $labels = ['suites' => 'suíte(s)', 'test_cases' => 'caso(s)', 'executions' => 'execução(ões)', 'defects' => 'defeito(s)', 'evidences' => 'foto(s)'];
    $parts = [];
    foreach ($labels as $tbl => $lbl) {
        if (!empty($tables[$tbl])) { $parts[] = count($tables[$tbl]) . ' ' . $lbl; }
    }
    return $parts ? implode(', ', $parts) : 'sem itens dependentes';
}

function trash_files(array $tables): array
{
    $files = [];
    foreach (['evidences', 'defect_attachments'] as $tbl) {
        foreach ($tables[$tbl] ?? [] as $r) { $files[] = basename($r['stored_name']); }
    }
    return $files;
}

/** Guarda a cópia, remove do sistema e move as fotos para a lixeira. */
function trash_store(string $type, int $entityId, string $title, array $tables, string $deleteSql, array $deleteParams, ?int $userId): void
{
    $files = trash_files($tables);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO trash (entity_type, entity_id, title, summary, payload, files, deleted_by) VALUES (?,?,?,?,?,?,?)', [
            $type, $entityId, mb_substr($title, 0, 255), trash_summary($tables),
            json_encode($tables, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            $files ? json_encode($files) : null, $userId ?: null,
        ]);
        q($deleteSql, $deleteParams);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    // Só depois de gravado no banco: move as fotos (se falhar, o item continua restaurável; a foto fica no lugar)
    if (!is_dir(TRASH_DIR)) { @mkdir(TRASH_DIR, 0755, true); }
    foreach ($files as $f) {
        if (is_file(UPLOAD_DIR . $f)) { @rename(UPLOAD_DIR . $f, TRASH_DIR . $f); }
    }
}

function trash_delete_case(int $caseId, ?int $userId): bool
{
    $case = q('SELECT code, title FROM test_cases WHERE id = ?', [$caseId])->fetch();
    if (!$case) { return false; }
    $tables = trash_collect_cases([$caseId]);
    trash_store('case', $caseId, $case['code'] . ' — ' . $case['title'], $tables, 'DELETE FROM test_cases WHERE id = ?', [$caseId], $userId);
    audit('caso_lixeira', 'case', $caseId, $case['code'] . ' — ' . $case['title'] . ' (' . trash_summary($tables) . ')');
    return true;
}

function trash_delete_suite(int $suiteId, ?int $userId): bool
{
    $suite = q('SELECT * FROM suites WHERE id = ?', [$suiteId])->fetch();
    if (!$suite) { return false; }
    $caseIds = q('SELECT id FROM test_cases WHERE suite_id = ?', [$suiteId])->fetchAll(PDO::FETCH_COLUMN);
    $tables = ['suites' => [$suite]] + trash_collect_cases($caseIds);
    trash_store('suite', $suiteId, $suite['name'], $tables, 'DELETE FROM suites WHERE id = ?', [$suiteId], $userId);
    audit('suite_lixeira', 'suite', $suiteId, $suite['name'] . ' (' . trash_summary($tables) . ')');
    return true;
}

function trash_delete_project(int $projectId, ?int $userId): bool
{
    $project = q('SELECT * FROM projects WHERE id = ?', [$projectId])->fetch();
    if (!$project) { return false; }
    $suites = q('SELECT * FROM suites WHERE project_id = ?', [$projectId])->fetchAll();
    $caseIds = q('SELECT id FROM test_cases WHERE project_id = ?', [$projectId])->fetchAll(PDO::FETCH_COLUMN);
    $tables = ['projects' => [$project], 'suites' => $suites] + trash_collect_cases($caseIds);
    trash_store('project', $projectId, $project['name'], $tables, 'DELETE FROM projects WHERE id = ?', [$projectId], $userId);
    audit('projeto_lixeira', 'project', $projectId, $project['name'] . ' (' . trash_summary($tables) . ')');
    return true;
}

/**
 * Restaura um item da lixeira com os IDs originais.
 * Lança RuntimeException com mensagem amigável se não for possível (ex.: pai removido, código duplicado).
 */
function trash_restore(int $trashId): string
{
    $row = q('SELECT * FROM trash WHERE id = ?', [$trashId])->fetch();
    if (!$row) {
        throw new RuntimeException('Item da lixeira não encontrado.');
    }
    $tables = json_decode($row['payload'], true) ?: [];

    // O pai precisa existir (restaurar um caso exige a suíte e o projeto; uma suíte exige o projeto)
    if ($row['entity_type'] === 'case') {
        $c = $tables['test_cases'][0] ?? null;
        if (!$c || !q('SELECT 1 FROM suites WHERE id = ? AND project_id = ?', [$c['suite_id'], $c['project_id']])->fetchColumn()) {
            throw new RuntimeException('A suíte (ou o projeto) deste caso não existe mais. Restaure primeiro a suíte/o projeto na lixeira.');
        }
    } elseif ($row['entity_type'] === 'suite') {
        $s = $tables['suites'][0] ?? null;
        if (!$s || !q('SELECT 1 FROM projects WHERE id = ?', [$s['project_id']])->fetchColumn()) {
            throw new RuntimeException('O projeto desta suíte não existe mais. Restaure primeiro o projeto na lixeira.');
        }
    }

    $userExists = [];
    $exists = function ($id) use (&$userExists) {
        return $userExists[$id] ??= (bool)q('SELECT 1 FROM users WHERE id = ?', [$id])->fetchColumn();
    };

    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach (TRASH_ORDER as $tbl) {
            foreach ($tables[$tbl] ?? [] as $r) {
                $skip = false;
                foreach (TRASH_USER_COLS[$tbl] ?? [] as $col => $nullable) {
                    if (isset($r[$col]) && $r[$col] !== null && !$exists($r[$col])) {
                        if ($nullable) { $r[$col] = null; }
                        elseif ($tbl === 'assignments') { $skip = true; }
                        else { throw new RuntimeException('Um usuário ligado a este item não existe mais; não é possível restaurar com segurança.'); }
                    }
                }
                if ($skip) { continue; }
                $cols = array_keys($r);
                q('INSERT INTO ' . $tbl . ' (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($r));
            }
        }
        q('DELETE FROM trash WHERE id = ?', [$trashId]);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        throw new RuntimeException('Não foi possível restaurar: já existe um registro com o mesmo código/identificador. Renomeie ou remova o item conflitante e tente de novo.');
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    foreach (json_decode((string)$row['files'], true) ?: [] as $f) {
        $f = basename($f);
        if (is_file(TRASH_DIR . $f)) { @rename(TRASH_DIR . $f, UPLOAD_DIR . $f); }
    }
    audit('lixeira_restaurar', $row['entity_type'], (int)$row['entity_id'], $row['title']);
    return TRASH_LABELS[$row['entity_type']] . ' "' . $row['title'] . '" restaurado.';
}

/** Exclui um item da lixeira de vez (e as fotos dele). */
function trash_purge(int $trashId): bool
{
    $row = q('SELECT * FROM trash WHERE id = ?', [$trashId])->fetch();
    if (!$row) { return false; }
    foreach (json_decode((string)$row['files'], true) ?: [] as $f) {
        $p = TRASH_DIR . basename($f);
        if (is_file($p)) { @unlink($p); }
    }
    q('DELETE FROM trash WHERE id = ?', [$trashId]);
    audit('lixeira_excluir', $row['entity_type'], (int)$row['entity_id'], $row['title']);
    return true;
}
