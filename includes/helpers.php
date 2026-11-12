<?php
/**
 * Funções auxiliares: escape, CSRF, flash, rótulos, badges, ícones e menu.
 */

/* ---------- Segurança / entrada ---------- */

/** Escapa texto para HTML (proteção contra XSS). */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

/** Lê um campo de texto do POST já "trimado". */
function post(string $key, string $default = ''): string
{
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : $default;
}

function post_int(string $key): int
{
    return (int)($_POST[$key] ?? 0);
}

function get_int(string $key): int
{
    return (int)($_GET[$key] ?? 0);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** Valida o token CSRF em requisições POST; aborta se inválido. */
function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419);
        exit('Sessão expirada ou requisição inválida (CSRF). Volte e tente novamente.');
    }
}

/* ---------- Mensagens flash ---------- */

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- Configurações ---------- */

function get_setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        try {
            $cache = db()->query('SELECT k, v FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    return $cache[$key] ?? $default;
}

/* ---------- Rótulos ---------- */

const ROLES = [
    'admin'     => 'Administrador',
    'tester'    => 'Tester',
    'developer' => 'Desenvolvedor',
    'viewer'    => 'Usuário (leitura)',
];
const RESULTS = ['passed' => 'Aprovado', 'failed' => 'Reprovado', 'blocked' => 'Bloqueado'];
/** Tipo da execução: feita à mão ou por automação. */
const EXEC_TYPES = ['manual' => 'Manual', 'automated' => 'Automatizado'];
const LEVELS = ['low' => 'Baixa', 'medium' => 'Média', 'high' => 'Alta', 'critical' => 'Crítica'];
const DEFECT_STATUS = [
    'triage' => 'Aguardando triagem', 'open' => 'Aberto', 'fixing' => 'Em andamento',
    'fixed' => 'Concluído pelo Dev', 'retest' => 'Reteste (Tester)', 'reopened' => 'Reaberto', 'closed' => 'Fechado',
];
/** Status considerados "defeito em aberto" (tudo que ainda não foi fechado) nos relatórios e dashboard. */
const OPEN_DEFECT_STATUS = ['triage', 'open', 'fixing', 'fixed', 'retest', 'reopened'];
/** Status em que o chamado está com o Admin, aguardando a tomada de decisão. */
const DECISION_DEFECT_STATUS = ['triage', 'fixed', 'reopened'];

function badge(string $text, string $class): string
{
    return '<span class="badge badge-' . e($class) . '">' . e($text) . '</span>';
}

function result_badge(?string $r): string
{
    if (!$r) {
        return badge('Não executado', 'gray');
    }
    $map = ['passed' => 'green', 'failed' => 'red', 'blocked' => 'amber'];
    return badge(RESULTS[$r] ?? $r, $map[$r] ?? 'gray');
}

function level_badge(string $l): string
{
    $map = ['low' => 'gray', 'medium' => 'blue', 'high' => 'amber', 'critical' => 'red'];
    return badge(LEVELS[$l] ?? $l, $map[$l] ?? 'gray');
}

function defect_badge(string $s): string
{
    $map = ['triage' => 'amber', 'open' => 'red', 'fixing' => 'blue', 'fixed' => 'teal', 'retest' => 'purple', 'reopened' => 'amber', 'closed' => 'gray'];
    return badge(DEFECT_STATUS[$s] ?? $s, $map[$s] ?? 'gray');
}

function fmt_dt(?string $d): string
{
    return $d ? date('d/m/Y H:i', strtotime($d)) : '—';
}

function fmt_date(?string $d): string
{
    return $d ? date('d/m/Y', strtotime($d)) : '—';
}

/** Retorna <option>s a partir de um array chave=>rótulo. */
function options(array $items, $selected = null, bool $blank = false, string $blankLabel = 'Todos'): string
{
    $html = $blank ? '<option value="">' . e($blankLabel) . '</option>' : '';
    foreach ($items as $k => $label) {
        $sel = ((string)$k === (string)$selected) ? ' selected' : '';
        $html .= '<option value="' . e($k) . '"' . $sel . '>' . e($label) . '</option>';
    }
    return $html;
}

/** Lista id=>nome de projetos (para selects). */
function project_list(): array
{
    return q('SELECT id, name FROM projects ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Lista id=>nome de usuários ativos de um perfil. */
function users_by_role(string $role): array
{
    return q('SELECT id, name FROM users WHERE role = ? AND active = 1 ORDER BY name', [$role])
        ->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Percentual seguro (evita divisão por zero). */
function pct(int $part, int $total): int
{
    return $total > 0 ? (int)round($part * 100 / $total) : 0;
}

/* ---------- Ícones SVG inline ---------- */

function icon(string $name, int $size = 18): string
{
    static $paths = [
        'home'     => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
        'folder'   => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
        'layers'   => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
        'clipboard'=> '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/>',
        'users'    => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'play'     => '<polygon points="5 3 19 12 5 21 5 3"/>',
        'bug'      => '<path d="M12 20c-3.3 0-6-2.7-6-6v-3a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v3c0 3.3-2.7 6-6 6z"/><path d="M9 7V6a3 3 0 1 1 6 0v1M12 20v-9M6 13H2M22 13h-4M3 5c0 2 1.6 3.7 3.5 4M21 5c0 2-1.6 3.7-3.5 4M3 21c0-2.1 1.7-3.9 3.8-4M21 21c0-2.1-1.7-3.9-3.8-4"/>',
        'chart'    => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
        'settings' => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
        'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'user'     => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'printer'  => '<polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
        'plus'     => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'edit'     => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'trash'    => '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/>',
        'check'    => '<polyline points="20 6 9 17 4 12"/>',
        'menu'     => '<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'send'     => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
        'eye'      => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        'upload'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'arrow-left' => '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
        'clock'    => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    ];
    $p = $paths[$name] ?? '';
    return '<svg class="icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" '
        . 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . $p . '</svg>';
}

/* ---------- Menu lateral por perfil ---------- */

function nav_items(string $role): array
{
    $dash = ['dashboard.php', 'Dashboard', 'home'];
    $defects = ['defects.php', 'Defeitos', 'bug'];
    $reports = ['reports.php', 'Relatórios', 'chart'];
    $profile = ['profile.php', 'Meu perfil', 'user'];

    switch ($role) {
        case 'admin':
            return [
                $dash,
                ['projects.php', 'Projetos', 'folder'],
                ['suites.php', 'Suítes', 'layers'],
                ['cases.php', 'Casos de Teste', 'clipboard'],
                ['assignments.php', 'Atribuições', 'send'],
                $defects,
                $reports,
                ['users.php', 'Usuários', 'users'],
                ['settings.php', 'Configurações', 'settings'],
                $profile,
            ];
        case 'tester':
            return [$dash, ['my_tests.php', 'Meus Testes', 'play'], ['history.php', 'Histórico', 'clock'], $defects, $profile];
        case 'developer':
            return [$dash, ['dev_board.php', 'Meus Chamados', 'bug'], ['defects.php?all=1', 'Histórico', 'clock'], $profile];
        default: // viewer
            return [$dash, ['cases.php', 'Casos de Teste', 'clipboard'], ['history.php', 'Execuções', 'clock'], $defects, $reports, $profile];
    }
}

/**
 * Botão "Voltar" (seta). Volta à página anterior pelo histórico do navegador (assets/js/app.js);
 * sem JavaScript, ou quando a página anterior não serve (ex.: formulário recém-enviado), vai para $fallback.
 */
function back_button(string $fallback, string $extraClass = ''): string
{
    return '<a href="' . e($fallback) . '" class="btn btn-ghost btn-back ' . e($extraClass) . '" data-back title="Voltar à página anterior">'
        . icon('arrow-left', 16) . ' <span>Voltar</span></a>';
}

/** Cabeçalho padrão dos relatórios (botões de voltar / baixar PDF / imprimir / visão limpa). */
function report_toolbar(string $title, string $pdfType = ''): void
{
    global $backUrl;
    $clean = !empty($_GET['clean']);
    $qs = $_GET;
    unset($qs['clean']);
    $pdfQs = http_build_query(['type' => $pdfType] + $qs);
    if (!$clean) {
        $qs['clean'] = 1;
    }
    $toggle = basename($_SERVER['SCRIPT_NAME']) . '?' . http_build_query($qs);
    echo '<div class="report-actions no-print">';
    // Na visão limpa a barra superior some; o Voltar passa a ficar aqui
    if ($clean) {
        echo back_button($backUrl ?: 'reports.php');
    }
    if ($pdfType !== '') {
        echo '<a class="btn" href="report_pdf.php?' . e($pdfQs) . '">' . icon('download', 16) . ' Baixar PDF</a>';
    }
    echo '<button type="button" class="btn btn-ghost" data-print>' . icon('printer', 16) . ' Imprimir</button>';
    echo '<a class="btn btn-ghost" href="' . e($toggle) . '">' . ($clean ? 'Sair da visão limpa' : 'Visão limpa') . '</a>';
    echo '</div>';
    echo '<div class="print-only report-head"><strong>' . e(get_setting('system_name', APP_NAME)) . '</strong> — '
        . e($title) . '<br><small>Gerado em ' . date('d/m/Y H:i') . ' por ' . e(current_user()['name'] ?? '') . '</small></div>';
}
