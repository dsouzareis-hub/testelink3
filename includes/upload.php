<?php
/**
 * Upload seguro de imagens (somente jpg/png/webp): evidências de execução, fotos de defeitos e logo.
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
 * Valida e grava as imagens de um campo <input type="file" multiple>.
 * Para cada arquivo salvo chama $onSaved(nomeOriginal, nomeGravado, mime, tamanho).
 * Retorna [quantidade_salva, [mensagens de erro]].
 */
function process_image_uploads(string $field, callable $onSaved): array
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
            $errors[] = 'Limite de ' . MAX_FILES_PER_EXECUTION . ' imagens por envio atingido.';
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

        $onSaved(mb_substr($label, 0, 255), $stored, $mime, (int)$f['size'][$i]);
        $saved++;
    }
    return [$saved, $errors];
}

/** Evidências da execução (campo "evidences[]"). */
function save_evidences(int $executionId, string $field = 'evidences'): array
{
    return process_image_uploads($field, function ($name, $stored, $mime, $size) use ($executionId) {
        q('INSERT INTO evidences (execution_id, original_name, stored_name, mime, size) VALUES (?,?,?,?,?)',
            [$executionId, $name, $stored, $mime, $size]);
    });
}

/** Fotos anexadas a um comentário de defeito (campo "photos[]"). */
function save_defect_images(int $defectId, ?int $commentId, int $userId, string $field = 'photos'): array
{
    return process_image_uploads($field, function ($name, $stored, $mime, $size) use ($defectId, $commentId, $userId) {
        q('INSERT INTO defect_attachments (defect_id, comment_id, user_id, original_name, stored_name, mime, size) VALUES (?,?,?,?,?,?,?)',
            [$defectId, $commentId, $userId, $name, $stored, $mime, $size]);
    });
}

/** Evidências de uma execução. */
function execution_evidences(int $executionId): array
{
    return q('SELECT id, original_name FROM evidences WHERE execution_id = ? ORDER BY id', [$executionId])->fetchAll();
}

/** Miniaturas clicáveis; $route = 'id' (evidência da execução) ou 'att' (foto de defeito). */
function image_gallery(array $items, string $route = 'id'): string
{
    if (!$items) {
        return '<p class="muted">Nenhuma imagem anexada.</p>';
    }
    $html = '<div class="gallery">';
    foreach ($items as $it) {
        $url = 'file.php?' . $route . '=' . (int)$it['id'];
        $html .= '<a href="' . $url . '" target="_blank" rel="noopener" class="thumb" title="' . e($it['original_name']) . '">'
            . '<img src="' . $url . '" alt="' . e($it['original_name']) . '" loading="lazy"></a>';
    }
    return $html . '</div>';
}

/** Galeria de evidências da execução (compatível com o uso anterior). */
function evidence_gallery(array $evidences): string
{
    return image_gallery($evidences, 'id');
}
