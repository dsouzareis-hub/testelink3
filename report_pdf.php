<?php
/**
 * Gera os relatórios em PDF (download). Parâmetro ?type= execution | coverage | defects | case
 * Usa os mesmos filtros (GET) das telas de relatório.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/pdf.php';
$user = require_role('admin', 'viewer', 'tester');

$type = $_GET['type'] ?? '';
if ($type !== 'case' && $user['role'] === 'tester') { forbidden(); }

/* ---------- Cores e células ---------- */
const C_BLUE = [37, 99, 235];
const C_GREEN = [22, 163, 74];
const C_RED = [220, 38, 38];
const C_AMBER = [217, 119, 6];
const C_GRAY = [156, 163, 175];
const C_TEAL = [15, 118, 110];

function cell_result(?string $r): array
{
    $map = [
        'passed'  => [[22, 101, 52], [220, 252, 231]],
        'failed'  => [[153, 27, 27], [254, 226, 226]],
        'blocked' => [[146, 64, 14], [254, 243, 199]],
    ];
    [$fg, $bg] = $map[$r] ?? [[75, 85, 99], [229, 231, 235]];
    return ['t' => $r ? RESULTS[$r] : 'Não executado', 'fg' => $fg, 'bg' => $bg, 'b' => true];
}

function cell_level(string $l): array
{
    $map = ['low' => [[75, 85, 99], [229, 231, 235]], 'medium' => [[30, 64, 175], [219, 234, 254]],
            'high' => [[146, 64, 14], [254, 243, 199]], 'critical' => [[153, 27, 27], [254, 226, 226]]];
    [$fg, $bg] = $map[$l];
    return ['t' => LEVELS[$l], 'fg' => $fg, 'bg' => $bg, 'b' => true];
}

function cell_defect(string $s): array
{
    $map = ['retest' => [[109, 40, 217], [237, 233, 254]], 'triage' => [[146, 64, 14], [254, 243, 199]], 'open' => [[153, 27, 27], [254, 226, 226]], 'fixing' => [[30, 64, 175], [219, 234, 254]],
            'fixed' => [[15, 118, 110], [204, 251, 241]], 'reopened' => [[146, 64, 14], [254, 243, 199]],
            'closed' => [[75, 85, 99], [229, 231, 235]]];
    [$fg, $bg] = $map[$s];
    return ['t' => DEFECT_STATUS[$s], 'fg' => $fg, 'bg' => $bg, 'b' => true];
}

/** Abre o documento com a identidade visual (logo da empresa nas configurações ou o favicon). */
function new_pdf(string $title): SimplePdf
{
    $logo = pdf_image_info(logo_path() ?? __DIR__ . '/assets/img/favicon.png');
    return new SimplePdf([
        'system' => get_setting('system_name', APP_NAME),
        'title'  => $title,
        'user'   => current_user()['name'],
    ], $logo);
}

function filter_line(SimplePdf $pdf, array $parts): void
{
    $parts = array_filter($parts);
    $pdf->paragraph('Filtros: ' . ($parts ? implode('  ·  ', $parts) : 'nenhum (todos os registros)'), 8.5, [107, 114, 128]);
    $pdf->space(8);
}

$projects = project_list();
$fProject = get_int('project');
$file = 'testlink_' . $type . '_' . date('Ymd_His') . '.pdf';

/* =============== Execução por projeto =============== */
if ($type === 'execution') {
    $fFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '';
    $fTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '';
    $where = ["tc.status = 'active'"];
    $params = [];
    if ($fProject) { $where[] = 'tc.project_id = ?'; $params[] = $fProject; }
    if ($fFrom) { $where[] = 'ex.executed_at >= ?'; $params[] = $fFrom . ' 00:00:00'; }
    if ($fTo) { $where[] = 'ex.executed_at <= ?'; $params[] = $fTo . ' 23:59:59'; }

    $rows = q('SELECT tc.code, tc.title, p.name project, s.name suite, ex.result, ex.exec_type, ex.executed_at, u.name tester,
                      (SELECT COUNT(*) FROM defects d WHERE d.case_id = tc.id AND d.status IN (\'triage\',\'open\',\'fixing\',\'fixed\',\'retest\',\'reopened\')) open_defects
               FROM test_cases tc JOIN projects p ON p.id = tc.project_id JOIN suites s ON s.id = tc.suite_id
               LEFT JOIN executions ex ON ex.id = (SELECT MAX(id) FROM executions WHERE case_id = tc.id)
               LEFT JOIN users u ON u.id = ex.tester_id
               WHERE ' . implode(' AND ', $where) . ' ORDER BY p.name, s.name, tc.code', $params)->fetchAll();

    $sum = ['passed' => 0, 'failed' => 0, 'blocked' => 0, 'none' => 0];
    foreach ($rows as $r) { $sum[$r['result'] ?? 'none']++; }

    $pdf = new_pdf('Relatório de Execução de Testes');
    filter_line($pdf, [
        $fProject ? 'Projeto: ' . ($projects[$fProject] ?? '') : '',
        $fFrom ? 'De ' . fmt_date($fFrom) : '', $fTo ? 'Até ' . fmt_date($fTo) : '',
    ]);
    $pdf->stats([
        [count($rows), 'Casos de teste', C_BLUE],
        [$sum['passed'] . ' (' . pct($sum['passed'], count($rows)) . '%)', 'Aprovados', C_GREEN],
        [$sum['failed'] . ' (' . pct($sum['failed'], count($rows)) . '%)', 'Reprovados', C_RED],
        [$sum['blocked'], 'Bloqueados', C_AMBER],
        [$sum['none'], 'Não executados', C_GRAY],
    ]);
    $pdf->heading('Resultado por caso de teste');
    $table = [];
    foreach ($rows as $r) {
        $table[] = [['t' => $r['code'], 'b' => true], $r['title'], $r['project'] . ' / ' . $r['suite'], cell_result($r['result']),
                    $r['result'] ? (EXEC_TYPES[$r['exec_type']] ?? '') : '—',
                    $r['tester'] ?? '—', $r['executed_at'] ? fmt_dt($r['executed_at']) : '—', (string)(int)$r['open_defects']];
    }
    $pdf->table([
        ['l' => 'Código', 'w' => 9], ['l' => 'Título', 'w' => 21], ['l' => 'Projeto / Suíte', 'w' => 17], ['l' => 'Resultado', 'w' => 12, 'a' => 'C'],
        ['l' => 'Tipo', 'w' => 10], ['l' => 'Tester', 'w' => 11], ['l' => 'Data', 'w' => 14], ['l' => 'Def.', 'w' => 5, 'a' => 'C'],
    ], $table, 7.5);
    $pdf->download($file);
}

/* =============== Cobertura =============== */
if ($type === 'coverage') {
    $where = $fProject ? 'AND s.project_id = ?' : '';
    $rows = q("SELECT p.name project, s.name suite, COUNT(tc.id) total,
                      COALESCE(SUM(ex.id IS NOT NULL), 0) executed,
                      COALESCE(SUM(ex.result = 'passed'), 0) passed,
                      COALESCE(SUM(ex.result = 'failed'), 0) failed,
                      COALESCE(SUM(ex.result = 'blocked'), 0) blocked
               FROM suites s JOIN projects p ON p.id = s.project_id
               LEFT JOIN test_cases tc ON tc.suite_id = s.id AND tc.status = 'active'
               LEFT JOIN executions ex ON ex.id = (SELECT MAX(id) FROM executions WHERE case_id = tc.id)
               WHERE 1=1 $where GROUP BY s.id, p.name, s.name ORDER BY p.name, s.name", $fProject ? [$fProject] : [])->fetchAll();
    $gaps = q("SELECT tc.code, tc.title, p.name project, s.name suite, tc.priority
               FROM test_cases tc JOIN projects p ON p.id = tc.project_id JOIN suites s ON s.id = tc.suite_id
               WHERE tc.status = 'active' AND NOT EXISTS (SELECT 1 FROM executions WHERE case_id = tc.id) "
               . ($fProject ? 'AND tc.project_id = ?' : '') . ' ORDER BY p.name, s.name, tc.code', $fProject ? [$fProject] : [])->fetchAll();

    $t = ['total' => 0, 'executed' => 0, 'passed' => 0];
    foreach ($rows as $r) { $t['total'] += $r['total']; $t['executed'] += $r['executed']; $t['passed'] += $r['passed']; }

    $pdf = new_pdf('Relatório de Cobertura de Testes');
    filter_line($pdf, [$fProject ? 'Projeto: ' . ($projects[$fProject] ?? '') : '']);
    $pdf->stats([
        [pct($t['executed'], $t['total']) . '%', 'Cobertura geral', C_BLUE],
        [$t['executed'] . '/' . $t['total'], 'Casos executados', C_GRAY],
        [pct($t['passed'], $t['executed']) . '%', 'Taxa de aprovação', C_GREEN],
    ]);
    $pdf->heading('Cobertura por suíte');
    $table = [];
    foreach ($rows as $r) {
        $c = pct((int)$r['executed'], (int)$r['total']);
        $table[] = [$r['project'], $r['suite'], (string)$r['total'], (string)$r['executed'], (string)$r['passed'],
                    (string)$r['failed'], (string)$r['blocked'],
                    ['t' => $c . '%', 'b' => true, 'fg' => $c === 100 ? [22, 101, 52] : [31, 41, 55], 'bg' => $c === 100 ? [220, 252, 231] : null]];
    }
    $pdf->table([
        ['l' => 'Projeto', 'w' => 18], ['l' => 'Suíte', 'w' => 20], ['l' => 'Casos', 'w' => 8, 'a' => 'C'], ['l' => 'Exec.', 'w' => 8, 'a' => 'C'],
        ['l' => 'Aprov.', 'w' => 8, 'a' => 'C'], ['l' => 'Reprov.', 'w' => 8, 'a' => 'C'], ['l' => 'Bloq.', 'w' => 8, 'a' => 'C'], ['l' => 'Cobertura', 'w' => 10, 'a' => 'C'],
    ], $table, 8);
    $pdf->heading('Casos sem execução (' . count($gaps) . ')');
    $table = [];
    foreach ($gaps as $g) {
        $table[] = [['t' => $g['code'], 'b' => true], $g['title'], $g['project'] . ' / ' . $g['suite'], cell_level($g['priority'])];
    }
    $pdf->table([['l' => 'Código', 'w' => 10], ['l' => 'Título', 'w' => 38], ['l' => 'Projeto / Suíte', 'w' => 28], ['l' => 'Prioridade', 'w' => 12, 'a' => 'C']], $table, 8);
    $pdf->download($file);
}

/* =============== Defeitos =============== */
if ($type === 'defects') {
    $fSeverity = $_GET['severity'] ?? '';
    $includeAll = !empty($_GET['all']);
    $where = [];
    $params = [];
    if (!$includeAll) { $where[] = "d.status IN ('triage','open','fixing','fixed','retest','reopened')"; }
    if ($fProject) { $where[] = 'd.project_id = ?'; $params[] = $fProject; }
    if (isset(LEVELS[$fSeverity])) { $where[] = 'd.severity = ?'; $params[] = $fSeverity; }
    $rows = q('SELECT d.id, d.title, d.severity, d.priority, d.status, d.created_at, tc.code, p.name project, dev.name developer
               FROM defects d JOIN test_cases tc ON tc.id = d.case_id JOIN projects p ON p.id = d.project_id
               LEFT JOIN users dev ON dev.id = d.assigned_to ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . '
               ORDER BY FIELD(d.severity, \'critical\',\'high\',\'medium\',\'low\'), d.created_at', $params)->fetchAll();
    $bySev = array_fill_keys(array_keys(LEVELS), 0);
    foreach ($rows as $r) { $bySev[$r['severity']]++; }

    $pdf = new_pdf($includeAll ? 'Relatório de Defeitos' : 'Relatório de Defeitos Abertos');
    filter_line($pdf, [
        $fProject ? 'Projeto: ' . ($projects[$fProject] ?? '') : '',
        isset(LEVELS[$fSeverity]) ? 'Severidade: ' . LEVELS[$fSeverity] : '',
        $includeAll ? 'Todos os status' : 'Somente em aberto (todos, exceto fechados)',
    ]);
    $pdf->stats([
        [count($rows), 'Defeitos', C_BLUE], [$bySev['critical'], 'Críticos', C_RED], [$bySev['high'], 'Altos', C_AMBER],
        [$bySev['medium'], 'Médios', [37, 99, 235]], [$bySev['low'], 'Baixos', C_GRAY],
    ]);
    $pdf->heading('Defeitos');
    $table = [];
    foreach ($rows as $d) {
        $table[] = [['t' => '#' . $d['id'], 'b' => true], $d['title'], $d['code'], $d['project'], cell_level($d['severity']),
                    cell_defect($d['status']), $d['developer'], fmt_date($d['created_at'])];
    }
    $pdf->table([
        ['l' => 'Nº', 'w' => 5], ['l' => 'Título', 'w' => 24], ['l' => 'Caso', 'w' => 8], ['l' => 'Projeto', 'w' => 13], ['l' => 'Severid.', 'w' => 10, 'a' => 'C'],
        ['l' => 'Status', 'w' => 11, 'a' => 'C'], ['l' => 'Responsável', 'w' => 12], ['l' => 'Aberto em', 'w' => 10],
    ], $table, 7.5);
    $pdf->download($file);
}

/* =============== Caso individual (com evidências) =============== */
if ($type === 'case') {
    $id = get_int('id');
    $case = q('SELECT tc.*, p.name project, s.name suite FROM test_cases tc
               JOIN projects p ON p.id = tc.project_id JOIN suites s ON s.id = tc.suite_id WHERE tc.id = ?', [$id])->fetch();
    if (!$case) { not_found(); }
    if ($user['role'] === 'tester' && !tester_has_case((int)$user['id'], $id)) { forbidden(); }

    $execs = q('SELECT ex.*, u.name tester FROM executions ex JOIN users u ON u.id = ex.tester_id WHERE ex.case_id = ? ORDER BY ex.id DESC', [$id])->fetchAll();
    $defects = q('SELECT d.id, d.title, d.status, d.severity, d.execution_id, dev.name developer FROM defects d
                  LEFT JOIN users dev ON dev.id = d.assigned_to WHERE d.case_id = ? ORDER BY d.id', [$id])->fetchAll();

    $pdf = new_pdf('Relatório do Caso de Teste ' . $case['code']);
    $pdf->heading($case['code'] . ' — ' . $case['title']);
    $pdf->kv('Projeto / Suíte', $case['project'] . ' / ' . $case['suite']);
    $pdf->kv('Prioridade', LEVELS[$case['priority']]);
    $pdf->kv('Versão do caso', 'v' . $case['version'] . ($case['status'] === 'active' ? ' (ativo)' : ' (inativo)'));
    $pdf->kv('Pré-condições', (string)$case['preconditions']);
    $steps = array_values(array_filter(array_map('trim', preg_split('/\R/', $case['steps'])), 'strlen'));
    $pdf->kv('Passos', implode("\n", array_map(fn($s, $i) => ($i + 1) . '. ' . $s, $steps, array_keys($steps))));
    $pdf->kv('Resultado esperado', $case['expected_result']);

    $last = $execs[0]['result'] ?? null;
    $pdf->space(4);
    $pdf->stats([
        [count($execs), 'Execuções', C_BLUE],
        [$last ? RESULTS[$last] : '—', 'Último resultado', $last === 'passed' ? C_GREEN : ($last === 'failed' ? C_RED : ($last === 'blocked' ? C_AMBER : C_GRAY))],
        [count($defects), 'Defeitos vinculados', C_RED],
    ]);

    $pdf->heading('Execuções e evidências');
    if (!$execs) { $pdf->paragraph('Este caso ainda não foi executado.', 9.5, [107, 114, 128]); }
    foreach ($execs as $x) {
        $pdf->ensure(70);
        $pdf->table([['l' => 'Execução #' . $x['id'], 'w' => 30], ['l' => 'Tester', 'w' => 22], ['l' => 'Data', 'w' => 22], ['l' => 'Resultado', 'w' => 16, 'a' => 'C']],
            [[['t' => 'Versão do caso: v' . $x['case_version'] . ' · ' . (EXEC_TYPES[$x['exec_type']] ?? $x['exec_type'])], $x['tester'], fmt_dt($x['executed_at']), cell_result($x['result'])]], 8.5);
        $pdf->space(-4);
        $pdf->kv('Assentamento', (string)$x['notes'], 80);
        if ($failedSteps = execution_failed_steps((int)$x['id'])) {
            $pdf->kv('Passo(s) com falha', implode("\n", array_map(fn($s) => 'Passo ' . $s['step_no'] . ' — ' . $s['step_text'], $failedSteps)), 80);
        }
        $items = [];
        foreach (execution_evidences((int)$x['id']) as $ev) {
            $row = q('SELECT stored_name FROM evidences WHERE id = ?', [$ev['id']])->fetchColumn();
            $info = $row ? pdf_image_info(UPLOAD_DIR . basename($row)) : null;
            $items[] = ['img' => $info ? $pdf->addImage($info) : null, 'caption' => $ev['original_name']];
        }
        if ($items) { $pdf->gallery($items); }
        $pdf->space(6);
    }

    if ($defects) {
        $pdf->heading('Defeitos vinculados');
        $table = [];
        foreach ($defects as $d) {
            $table[] = [['t' => '#' . $d['id'], 'b' => true], $d['title'], cell_level($d['severity']), cell_defect($d['status']), $d['developer'], '#' . $d['execution_id']];
        }
        $pdf->table([['l' => 'Nº', 'w' => 6], ['l' => 'Título', 'w' => 34], ['l' => 'Severid.', 'w' => 12, 'a' => 'C'], ['l' => 'Status', 'w' => 12, 'a' => 'C'],
                     ['l' => 'Responsável', 'w' => 16], ['l' => 'Execução', 'w' => 10, 'a' => 'C']], $table, 8);
    }
    $pdf->download('testlink_caso_' . preg_replace('/[^A-Za-z0-9_-]/', '', $case['code']) . '_' . date('Ymd') . '.pdf');
}

http_response_code(400);
exit('Tipo de relatório inválido.');
