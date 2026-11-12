<?php
require __DIR__ . '/includes/bootstrap.php';
require_role('admin');

$rows = q('SELECT * FROM audit_log ORDER BY id DESC LIMIT 300')->fetchAll();

$pageTitle = 'Auditoria';
$active = 'audit.php';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
    <div class="card-head"><h2>Registro de atividades <small class="muted">(últimas <?= count($rows) ?>)</small></h2></div>
    <p class="muted" style="margin-top:0">Quem fez o quê e quando: acessos, usuários, projetos/suítes/casos, lixeira, atribuições, decisões em chamados, configurações e backups.</p>
    <div class="table-wrap"><table>
        <thead><tr><th>Data</th><th>Usuário</th><th>Ação</th><th>Item</th><th>Detalhes</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td style="white-space:nowrap"><?= fmt_dt($r['created_at']) ?></td>
                <td><?= e($r['user_name'] ?: '—') ?></td>
                <td><?= badge(AUDIT_ACTIONS[$r['action']] ?? $r['action'], str_contains($r['action'], 'falhou') || str_contains($r['action'], 'excluir') || str_contains($r['action'], 'lixeira') ? 'red' : 'gray') ?></td>
                <td><?= $r['entity'] ? e($r['entity']) . ($r['entity_id'] ? ' #' . (int)$r['entity_id'] : '') : '—' ?></td>
                <td><?= e($r['details'] ?: '—') ?></td>
                <td class="muted"><?= e($r['ip'] ?: '—') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="empty">Nenhuma atividade registrada ainda.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
