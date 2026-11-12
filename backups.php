<?php
require __DIR__ . '/includes/bootstrap.php';
$me = require_role('admin');

if (is_post()) {
    csrf_check();
    try {
        if (post('action') === 'create') {
            $b = backup_create('manual');
            audit('backup_criado', 'backup', null, $b['file'] . ' (' . round($b['size'] / 1024) . ' KB)');
            flash('success', 'Backup criado: ' . $b['file']);
        } elseif (post('action') === 'delete') {
            $f = basename(post('file'));
            if (preg_match(BACKUP_NAME_RE, $f) && is_file(BACKUP_DIR . $f)) {
                @unlink(BACKUP_DIR . $f);
                audit('backup_excluido', 'backup', null, $f);
                flash('success', 'Backup excluído.');
            }
        }
    } catch (Throwable $e) {
        flash('error', 'Não foi possível criar o backup: ' . $e->getMessage());
    }
    redirect('backups.php');
}

backup_prepare_dir();
$list = backup_list();
$last = get_setting('last_backup_at', '');
$writable = is_dir(BACKUP_DIR) && is_writable(BACKUP_DIR);

$pageTitle = 'Backups';
$active = 'backups.php';
include __DIR__ . '/includes/header.php';
?>
<?php if (!$writable): ?>
    <div class="alert alert-error">A pasta de backups (<code>backups/</code>) não é gravável pelo servidor. Libere a permissão de escrita para os backups funcionarem.</div>
<?php endif; ?>
<div class="card">
    <div class="card-head">
        <h2>Backups do banco de dados</h2>
        <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="create">
            <button class="btn" type="submit"><?= icon('download', 16) ?> Fazer backup agora</button></form>
    </div>
    <dl class="detail-grid rows" style="margin-bottom:14px">
        <dt>Backup automático</dt><dd>1 por dia, no primeiro acesso ao painel do dia. Mantém os últimos <?= BACKUP_AUTO_KEEP ?> automáticos.</dd>
        <dt>Último backup</dt><dd><?= $last ? fmt_dt($last) : '<span class="muted">nenhum ainda</span>' ?></dd>
        <dt>Antes de apagar de vez</dt><dd>Excluir definitivamente da Lixeira faz um backup antes (tipo <em>limpeza</em>).</dd>
        <dt>Como restaurar</dt><dd>No phpMyAdmin: crie um banco vazio e use <strong>Importar</strong> com o arquivo (.sql ou .sql.gz). Não importe sobre o banco em uso.</dd>
        <dt>Fotos</dt><dd>As fotos não entram no arquivo do banco: ficam na pasta <code>uploads/</code> (copie-a junto se quiser backup completo).</dd>
    </dl>
    <div class="table-wrap"><table>
        <thead><tr><th>Arquivo</th><th>Tipo</th><th>Tamanho</th><th>Criado em</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($list as $b): ?>
            <tr>
                <td><code><?= e($b['file']) ?></code></td>
                <td><?= badge(['auto' => 'Automático', 'manual' => 'Manual', 'limpeza' => 'Antes de limpeza'][$b['kind']], $b['kind'] === 'auto' ? 'blue' : ($b['kind'] === 'manual' ? 'green' : 'amber')) ?></td>
                <td><?= $b['size'] >= 1048576 ? round($b['size'] / 1048576, 1) . ' MB' : max(1, round($b['size'] / 1024)) . ' KB' ?></td>
                <td><?= fmt_dt(date('Y-m-d H:i:s', $b['time'])) ?></td>
                <td class="actions">
                    <a class="btn btn-sm btn-ghost" href="backup_download.php?file=<?= urlencode($b['file']) ?>"><?= icon('download', 14) ?> Baixar</a>
                    <form method="post" class="inline-form" data-confirm="O arquivo de backup será excluído do servidor." data-confirm-title="Excluir backup?" data-confirm-ok="Excluir" data-confirm-type="danger">
                        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="file" value="<?= e($b['file']) ?>">
                        <button class="btn btn-sm btn-danger" type="submit"><?= icon('trash', 14) ?></button></form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$list): ?><tr><td colspan="5" class="empty">Nenhum backup ainda. Clique em “Fazer backup agora”.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
