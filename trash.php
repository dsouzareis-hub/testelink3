<?php
require __DIR__ . '/includes/bootstrap.php';
$me = require_role('admin');

if (is_post()) {
    csrf_check();
    $action = post('action');
    $id = post_int('id');
    try {
        if ($action === 'restore' && $id) {
            flash('success', trash_restore($id));
        } elseif ($action === 'purge' && $id) {
            backup_create('limpeza');                       // rede de segurança antes de apagar de vez
            $ok = trash_purge($id);
            flash($ok ? 'success' : 'error', $ok ? 'Item excluído definitivamente (um backup foi feito antes).' : 'Item não encontrado.');
        } elseif ($action === 'empty') {
            backup_create('limpeza');
            $n = 0;
            foreach (q('SELECT id FROM trash')->fetchAll(PDO::FETCH_COLUMN) as $tid) { $n += trash_purge((int)$tid) ? 1 : 0; }
            audit('lixeira_esvaziar', 'trash', null, "$n item(ns) excluído(s) definitivamente");
            flash('success', "Lixeira esvaziada ($n item(ns)). Um backup foi feito antes.");
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    } catch (Throwable $e) {
        flash('error', 'Não foi possível concluir a operação.');
    }
    redirect('trash.php');
}

$rows = q('SELECT t.*, u.name deleted_by_name FROM trash t LEFT JOIN users u ON u.id = t.deleted_by ORDER BY t.id DESC')->fetchAll();

$pageTitle = 'Lixeira';
$active = 'trash.php';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
    <div class="card-head">
        <h2>Lixeira <small class="muted">(<?= count($rows) ?>)</small></h2>
        <?php if ($rows): ?>
        <form method="post" class="inline-form" data-confirm="Todos os itens da lixeira serão excluídos DEFINITIVAMENTE, com as fotos. Um backup do banco é feito antes." data-confirm-title="Esvaziar a lixeira?" data-confirm-ok="Esvaziar" data-confirm-type="danger">
            <?= csrf_field() ?><input type="hidden" name="action" value="empty">
            <button class="btn btn-danger btn-sm" type="submit"><?= icon('trash', 14) ?> Esvaziar lixeira</button></form>
        <?php endif; ?>
    </div>
    <p class="muted" style="margin-top:0">Projetos, suítes e casos excluídos ficam aqui com tudo que dependia deles (execuções, passos, comentários, fotos, defeitos) e podem ser <strong>restaurados</strong> com os mesmos números.</p>
    <div class="table-wrap"><table>
        <thead><tr><th>Tipo</th><th>Item</th><th>Continha</th><th>Excluído por</th><th>Em</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= badge(TRASH_LABELS[$r['entity_type']], $r['entity_type'] === 'project' ? 'purple' : ($r['entity_type'] === 'suite' ? 'blue' : 'gray')) ?></td>
                <td><strong><?= e($r['title']) ?></strong> <small class="muted">#<?= (int)$r['entity_id'] ?></small></td>
                <td><?= e($r['summary']) ?></td>
                <td><?= e($r['deleted_by_name'] ?: '—') ?></td>
                <td><?= fmt_dt($r['deleted_at']) ?></td>
                <td class="actions">
                    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn-sm btn-success" type="submit"><?= icon('check', 14) ?> Restaurar</button></form>
                    <form method="post" class="inline-form" data-confirm="Este item e as fotos dele serão excluídos DEFINITIVAMENTE. Um backup do banco é feito antes." data-confirm-title="Excluir definitivamente?" data-confirm-ok="Excluir de vez" data-confirm-type="danger">
                        <?= csrf_field() ?><input type="hidden" name="action" value="purge"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn-sm btn-danger" type="submit"><?= icon('trash', 14) ?></button></form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="empty">A lixeira está vazia.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
