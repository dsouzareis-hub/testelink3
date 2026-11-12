<?php
/**
 * Backup do banco de dados em PHP puro (não depende do mysqldump).
 * - Manual: botão em Backups. Automático: no máximo 1 por dia, disparado no primeiro acesso ao painel do dia.
 * - Antes de apagar definitivamente itens da lixeira também é feito um backup ("limpeza").
 * - Gera .sql.gz (ou .sql se não houver zlib). As fotos ficam em uploads/ (copie a pasta junto, se quiser).
 * - Restaurar: importe o arquivo em um banco vazio (phpMyAdmin → Importar) — nunca sobre o banco em uso.
 */

define('BACKUP_DIR', dirname(__DIR__) . '/backups/');
const BACKUP_AUTO_KEEP = 14;                    // quantos backups automáticos manter
// O nome leva um sufixo aleatório (8 hex) para não ser adivinhável mesmo se o servidor ignorar o .htaccess
const BACKUP_NAME_RE = '/^backup_\d{8}_\d{6}_(auto|manual|limpeza)(?:_[a-f0-9]{8})?\.sql(\.gz)?$/';

function backup_prepare_dir(): void
{
    if (!is_dir(BACKUP_DIR)) {
        @mkdir(BACKUP_DIR, 0755, true);
    }
    // Pasta nunca acessível direto pelo navegador
    $ht = BACKUP_DIR . '.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    $idx = BACKUP_DIR . 'index.html';
    if (!is_file($idx)) { @file_put_contents($idx, ''); }
}

/** Cria um backup. $kind: manual | auto | limpeza. Retorna ['file' => nome, 'size' => bytes]. */
function backup_create(string $kind = 'manual'): array
{
    backup_prepare_dir();
    $kind = in_array($kind, ['manual', 'auto', 'limpeza'], true) ? $kind : 'manual';
    $gz = function_exists('gzopen');
    $name = 'backup_' . date('Ymd_His') . '_' . $kind . '_' . bin2hex(random_bytes(4)) . '.sql' . ($gz ? '.gz' : '');
    $path = BACKUP_DIR . $name;

    $h = $gz ? @gzopen($path, 'wb6') : @fopen($path, 'wb');
    if (!$h) {
        throw new RuntimeException('Não foi possível gravar na pasta de backups.');
    }
    $write = fn(string $s) => $gz ? gzwrite($h, $s) : fwrite($h, $s);

    $pdo = db();
    $write("-- TestLink 3.0 — backup de " . date('d/m/Y H:i:s') . " (" . $kind . ")\n-- Banco: " . DB_NAME . "\n");
    $write("-- Restaure em um banco VAZIO (ex.: phpMyAdmin > Importar).\n");
    $write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
        $write("-- ----------------------------\n-- Tabela `$table`\n-- ----------------------------\n");
        $write(preg_replace('/^CREATE TABLE/', 'CREATE TABLE IF NOT EXISTS', $create) . ";\n");

        $st = $pdo->query('SELECT * FROM `' . $table . '`');
        $cols = null;
        $batch = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $cols ??= '`' . implode('`,`', array_keys($row)) . '`';
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($row));
            $batch[] = '(' . implode(',', $vals) . ')';
            if (count($batch) >= 100) {
                $write("INSERT INTO `$table` ($cols) VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        if ($batch) {
            $write("INSERT INTO `$table` ($cols) VALUES\n" . implode(",\n", $batch) . ";\n");
        }
        $write("\n");
    }
    $write("SET FOREIGN_KEY_CHECKS = 1;\n");
    $gz ? gzclose($h) : fclose($h);

    q("INSERT INTO settings (k, v) VALUES ('last_backup_at', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [date('Y-m-d H:i:s')]);
    if ($kind === 'auto') {
        backup_rotate();
    }
    return ['file' => $name, 'size' => (int)filesize($path)];
}

/** Mantém só os últimos BACKUP_AUTO_KEEP backups automáticos. */
function backup_rotate(): void
{
    $auto = array_values(array_filter(backup_list(), fn($b) => $b['kind'] === 'auto'));
    foreach (array_slice($auto, BACKUP_AUTO_KEEP) as $old) {
        @unlink(BACKUP_DIR . $old['file']);
    }
}

/** Backups existentes, do mais novo para o mais antigo. */
function backup_list(): array
{
    $out = [];
    foreach (glob(BACKUP_DIR . 'backup_*') ?: [] as $f) {
        $n = basename($f);
        if (preg_match(BACKUP_NAME_RE, $n, $m)) {
            $out[] = ['file' => $n, 'kind' => $m[1], 'size' => (int)filesize($f), 'time' => (int)filemtime($f)];
        }
    }
    usort($out, fn($a, $b) => $b['file'] <=> $a['file']);
    return $out;
}

/** Faz o backup automático se o último tem mais de 24 h. Nunca interrompe a página. */
function backup_maybe_auto(): void
{
    try {
        $last = get_setting('last_backup_at', '');
        if ($last === '' || strtotime($last) < time() - 86400) {
            backup_create('auto');
        }
    } catch (Throwable $e) {
        // sem permissão de escrita etc.: o painel avisa o admin na tela de Backups
    }
}
