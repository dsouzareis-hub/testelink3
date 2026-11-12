<?php
require __DIR__ . '/includes/bootstrap.php';
require_role('admin');

if (is_post()) {
    csrf_check();
    $name = post('system_name') ?: APP_NAME;
    $maxMb = max(1, min(20, post_int('max_upload_mb')));
    $set = ['system_name' => mb_substr($name, 0, 80), 'max_upload_mb' => (string)$maxMb];

    // Logo da empresa (usado no cabeçalho dos relatórios em PDF)
    if (isset($_POST['remove_logo']) && ($old = logo_path())) {
        @unlink($old);
        $set['company_logo'] = '';
    }
    if (!empty($_FILES['logo']['name']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $f = $_FILES['logo'];
        $mime = $f['error'] === UPLOAD_ERR_OK ? (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) : '';
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            flash('error', 'Falha no envio do logo.');
        } elseif ($f['size'] > 2 * 1024 * 1024) {
            flash('error', 'O logo deve ter no máximo 2 MB.');
        } elseif (!isset(ALLOWED_IMAGE_TYPES[$mime]) || @getimagesize($f['tmp_name']) === false) {
            flash('error', 'Logo inválido: use JPG, PNG ou WEBP.');
        } else {
            if (!is_dir(LOGO_DIR)) { mkdir(LOGO_DIR, 0755, true); }
            if ($old = logo_path()) { @unlink($old); }
            $stored = 'logo_' . bin2hex(random_bytes(6)) . '.' . ALLOWED_IMAGE_TYPES[$mime];
            if (move_uploaded_file($f['tmp_name'], LOGO_DIR . $stored)) {
                $set['company_logo'] = $stored;
            } else {
                flash('error', 'Não foi possível gravar o logo.');
            }
        }
    }
    foreach ($set as $k => $v) {
        q('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
    }
    flash('success', 'Configurações salvas.');
    redirect('settings.php');
}

$pageTitle = 'Configurações';
$active = 'settings.php';
include __DIR__ . '/includes/header.php';

$uploadLimit = ini_get('upload_max_filesize');
?>
<div class="card" style="max-width:560px">
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="grid">
            <label class="field">Nome do sistema / empresa
                <input type="text" name="system_name" maxlength="80" value="<?= e(get_setting('system_name', APP_NAME)) ?>">
                <small>Aparece no cabeçalho dos relatórios em PDF.</small>
            </label>
            <div class="field">Logo da empresa (relatórios em PDF)
                <?php if (logo_path()): ?>
                    <div class="logo-preview"><img src="file.php?logo=1" alt="Logo atual"></div>
                    <label class="check"><input type="checkbox" name="remove_logo" value="1"> Remover logo atual (volta ao ícone padrão)</label>
                <?php else: ?>
                    <small>Nenhum logo enviado — os PDFs usam o ícone padrão do sistema.</small>
                <?php endif; ?>
                <input type="file" name="logo" accept="image/jpeg,image/png,image/webp">
                <small>JPG, PNG ou WEBP até 2 MB. Para melhor resultado no PDF, prefira PNG ou JPG com fundo claro.</small>
            </div>
            <label class="field">Tamanho máximo por evidência (MB)
                <input type="number" name="max_upload_mb" min="1" max="20" value="<?= e(get_setting('max_upload_mb', '5')) ?>">
                <small>Limite do PHP neste servidor: <code>upload_max_filesize = <?= e($uploadLimit) ?></code>. O valor efetivo é o menor entre os dois.</small>
            </label>
        </div>
        <div class="form-actions"><button class="btn" type="submit">Salvar</button></div>
    </form>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
