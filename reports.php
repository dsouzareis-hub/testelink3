<?php
require __DIR__ . '/includes/bootstrap.php';
require_role('admin', 'viewer');

$pageTitle = 'Relatórios';
$active = 'reports.php';
$backUrl = 'dashboard.php';
include __DIR__ . '/includes/header.php';
?>
<div class="link-cards">
    <a class="link-card" href="report_execution.php"><strong>Execução por projeto</strong>Último resultado de cada caso, com tester e data.</a>
    <a class="link-card" href="report_coverage.php"><strong>Cobertura de testes</strong>Casos executados vs. total, por projeto e suíte.</a>
    <a class="link-card" href="report_defects.php"><strong>Defeitos abertos</strong>Defeitos pendentes por severidade e responsável.</a>
    <a class="link-card" href="cases.php"><strong>Ver Tudo de um caso</strong>Escolha um caso e clique em “Ver Tudo” para ver todas as execuções, evidências e defeitos — com Imprimir e Baixar PDF.</a>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
