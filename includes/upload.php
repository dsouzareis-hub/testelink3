<?php
/**
 * Upload seguro de evidências (somente imagens jpg/png/webp).
 * - Valida o tipo real com finfo + getimagesize (não confia na extensão enviada)
 * - Grava com nome aleatório, fora do alcance direto do navegador (uploads/.htaccess)
 */

const ALLOWED_IMAGE_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

/** Pasta do logo da empresa (também protegida: uploads/.htaccess). */
define('LOGO_DIR', dirname(__DIR__) . '/uploads/logo/');

/** Caminho do logo configurado em Configurações, ou null se não houver. */
function logo_path(): ?string
{
    $name = basename(get_setting('company_logo', ''));
    return ($name !== '' && is_file(LOGO_DIR . $name)) ? LOGO_DIR . $name : null;
}

function max_upload_bytes(): int
{
    return max(1, (int)get_setting('max_upload_mb', '5')) * 1024 * 1024;
}

/**
 * Processa $_FILES['evidences'] (múltiplos arquivos) e vincula à execução.
 * Retorna [quantidade_salva, [mensagens de erro]].
 */
function save_evidences(int $executionId, string $field = 'evidences'): array
{
    $saved = 0;
    $errors = [];
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]['name'])) {
        return [0, []];
    }
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }

    $f = $_FILES[$field];
    $finfo = new finfo(FILEINFO_MIME_TYPE);

    foreach ($f['name'] as $i => $origName) {
        if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($saved >= MAX_FILES_PER_EXECUTION) {
            $errors[] = 'Limite de ' . MAX_FILES_PER_EXECUTION . ' imagens por execução atingido.';
            break;
        }
        $label = basename((string)$origName);
        if ($f['error'][$i] !== UPLOAD_ERR_OK) {
            $errors[] = "\"$label\": falha no envio.";
            continue;
        }
        if ($f['size'][$i] > max_upload_bytes()) {
            $errors[] = "\"$label\": excede o tamanho máximo de " . (max_upload_bytes() / 1048576) . ' MB.';
            continue;
        }
        $tmp = $f['tmp_name'][$i];
        if (!is_uploaded_file($tmp)) {
            continue;
        }
        $mime = $finfo->file($tmp);
        if (!isset(ALLOWED_IMAGE_TYPES[$mime]) || @getimagesize($tmp) === false) {
            $errors[] = "\"$label\": formato não permitido (use JPG, PNG ou WEBP).";
            continue;
        }

        $stored = bin2hex(random_bytes(16)) . '.' . ALLOWED_IMAGE_TYPES[$mime];
        if (!move_uploaded_file($tmp, UPLOAD_DIR . $stored)) {
            $errors[] = "\"$label\": não foi possível gravar o arquivo.";
            continue;
        }
        @chmod(UPLOAD_DIR . $stored, 0644);

        q('INSERT INTO evidences (execution_id, original_name, stored_name, mime, size) VALUES (?,?,?,?,?)', [
            $executionId, mb_substr($label, 0, 255), $stored, $mime, (int)$f['size'][$i],
        ]);
        $saved++;
    }
    return [$saved, $errors];
}

/** Remove do disco as evidências de uma lista de casos (usar ANTES de excluir os registros). */
function purge_evidence_files(array $caseIds): void
{
    if (!$caseIds) {
        return;
    }
    $in = implode(',', array_fill(0, count($caseIds), '?'));
    $rows = q("SELECT ev.stored_name FROM evidences ev JOIN executions ex ON ex.id = ev.execution_id
               WHERE ex.case_id IN ($in)", array_values($caseIds))->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $name) {
        $path = UPLOAD_DIR . basename($name);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

/** Evidências de uma execução. */
function execution_evidences(int $executionId): array
{
    return q('SELECT id, original_name FROM evidences WHERE execution_id = ? ORDER BY id', [$executionId])->fetchAll();
}

/** HTML de uma galeria de evidências (miniaturas clicáveis). */
function evidence_gallery(array $evidences): string
{
    if (!$evidences) {
        return '<p class="muted">Nenhuma evidência anexada.</p>';
    }
    $html = '<div class="gallery">';
    foreach ($evidences as $ev) {
        $url = 'file.php?id=' . (int)$ev['id'];
        $html .= '<a href="' . $url . '" target="_blank" rel="noopener" class="thumb" title="' . e($ev['original_name']) . '">'
            . '<img src="' . $url . '" alt="' . e($ev['original_name']) . '" loading="lazy"></a>';
    }
    return $html . '</div>';
}
