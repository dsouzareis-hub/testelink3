<?php
/**
 * Conexão PDO (singleton). Todas as consultas do sistema usam prepared statements.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            exit('<h1>Erro de conexão</h1><p>Não foi possível conectar ao banco de dados. '
                . 'Verifique <code>config/config.php</code> e se o <code>database.sql</code> foi importado.</p>');
        }
    }
    return $pdo;
}

/** Atalho: executa uma consulta preparada e devolve o statement. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
