<?php
/**
 * Gerador de PDF minimalista (sem bibliotecas externas).
 *
 * - Página A4, fonte Helvetica (padrão do PDF, sem embutir fonte), cabeçalho com logo e rodapé "Página X de Y".
 * - Blocos: títulos, pares rótulo/valor, cartões de resumo, tabelas com quebra de página e galeria de imagens.
 * - Imagens: JPEG e PNG (8 bits, sem entrelaçamento, com ou sem transparência) são lidos diretamente;
 *   outros formatos (ex.: WEBP) só entram se a extensão GD do PHP estiver ativa.
 */

/** Lê uma imagem e devolve os dados necessários para embuti-la no PDF, ou null se não for possível. */
function pdf_image_info(string $path): ?array
{
    $d = @file_get_contents($path);
    if ($d === false || strlen($d) < 24) {
        return null;
    }
    if (strncmp($d, "\xFF\xD8", 2) === 0) {
        return pdf_parse_jpeg($d) ?? pdf_image_via_gd($d);
    }
    if (strncmp($d, "\x89PNG\r\n\x1a\n", 8) === 0) {
        return pdf_parse_png($d) ?? pdf_image_via_gd($d);
    }
    return pdf_image_via_gd($d);
}

function pdf_parse_jpeg(string $d): ?array
{
    $i = 2;
    $len = strlen($d);
    while ($i + 9 < $len) {
        if ($d[$i] !== "\xFF") { $i++; continue; }
        $m = ord($d[$i + 1]);
        if ($m === 0xFF) { $i++; continue; }
        if ($m === 0xD8 || $m === 0x01 || ($m >= 0xD0 && $m <= 0xD7)) { $i += 2; continue; }
        if ($m === 0xC0 || $m === 0xC1 || $m === 0xC2) {
            $bpc = ord($d[$i + 4]);
            $h = unpack('n', substr($d, $i + 5, 2))[1];
            $w = unpack('n', substr($d, $i + 7, 2))[1];
            $nc = ord($d[$i + 9]);
            $cs = [1 => 'DeviceGray', 3 => 'DeviceRGB'][$nc] ?? null;
            return $cs ? ['w' => $w, 'h' => $h, 'cs' => '/' . $cs, 'bpc' => $bpc, 'filter' => 'DCTDecode', 'data' => $d] : null;
        }
        $i += 2 + unpack('n', substr($d, $i + 2, 2))[1];
    }
    return null;
}

function pdf_parse_png(string $d): ?array
{
    $pos = 8;
    $len = strlen($d);
    $idat = '';
    $pal = '';
    $w = $h = $bpc = $ct = $interlace = null;
    while ($pos + 8 <= $len) {
        $n = unpack('N', substr($d, $pos, 4))[1];
        $type = substr($d, $pos + 4, 4);
        $body = substr($d, $pos + 8, $n);
        if ($type === 'IHDR') {
            $w = unpack('N', substr($body, 0, 4))[1];
            $h = unpack('N', substr($body, 4, 4))[1];
            $bpc = ord($body[8]);
            $ct = ord($body[9]);
            $interlace = ord($body[12]);
        } elseif ($type === 'PLTE') {
            $pal = $body;
        } elseif ($type === 'IDAT') {
            $idat .= $body;
        } elseif ($type === 'IEND') {
            break;
        }
        $pos += 12 + $n;
    }
    if (!$w || $bpc !== 8 || $interlace !== 0 || $idat === '' || $w * $h > 12000000) {
        return null;
    }
    $parms = fn(int $colors, int $cols) => "<</Predictor 15 /Colors $colors /BitsPerComponent 8 /Columns $cols>>";

    if ($ct === 0 || $ct === 2 || $ct === 3) {
        if ($ct === 3 && $pal === '') {
            return null;
        }
        $cs = $ct === 0 ? '/DeviceGray' : ($ct === 2 ? '/DeviceRGB'
            : '[/Indexed /DeviceRGB ' . (intdiv(strlen($pal), 3) - 1) . ' <' . bin2hex($pal) . '>]');
        return ['w' => $w, 'h' => $h, 'cs' => $cs, 'bpc' => 8, 'filter' => 'FlateDecode',
            'parms' => $parms($ct === 2 ? 3 : 1, $w), 'data' => $idat];
    }
    if (($ct !== 4 && $ct !== 6) || !function_exists('gzuncompress')) {
        return null;
    }
    // Com canal alfa: separa cor e transparência (os filtros PNG agem por canal, então valem nos fluxos separados)
    $raw = @gzuncompress($idat);
    if ($raw === false) {
        return null;
    }
    $bpp = $ct === 6 ? 4 : 2;
    $stride = 1 + $w * $bpp;
    $color = $alpha = '';
    for ($y = 0; $y < $h; $y++) {
        $line = substr($raw, $y * $stride, $stride);
        $f = $line[0];
        $line = substr($line, 1);
        if ($ct === 6) {
            $color .= $f . preg_replace('/(.{3})./s', '$1', $line);
            $alpha .= $f . preg_replace('/.{3}(.)/s', '$1', $line);
        } else {
            $color .= $f . preg_replace('/(.)./s', '$1', $line);
            $alpha .= $f . preg_replace('/.(.)/s', '$1', $line);
        }
    }
    return ['w' => $w, 'h' => $h, 'cs' => $ct === 6 ? '/DeviceRGB' : '/DeviceGray', 'bpc' => 8, 'filter' => 'FlateDecode',
        'parms' => $parms($ct === 6 ? 3 : 1, $w), 'data' => gzcompress($color),
        'smask' => ['data' => gzcompress($alpha), 'parms' => $parms(1, $w)]];
}

/** Converte qualquer formato suportado pelo GD (ex.: WEBP) para JPEG sobre fundo branco. */
function pdf_image_via_gd(string $raw): ?array
{
    if (!function_exists('imagecreatefromstring')) {
        return null;
    }
    $im = @imagecreatefromstring($raw);
    if (!$im) {
        return null;
    }
    $w = imagesx($im);
    $h = imagesy($im);
    $dst = imagecreatetruecolor($w, $h);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopy($dst, $im, 0, 0, 0, 0, $w, $h);
    ob_start();
    imagejpeg($dst, null, 85);
    $jpeg = ob_get_clean();
    return pdf_parse_jpeg($jpeg);
}

/**
 * Fonte TrueType (glyf) para embutir no PDF como Type0/Identity-H, permitindo qualquer caractere Unicode
 * que a fonte possua (acentos, aspas tipográficas, €, etc.).
 */
final class TtfFont
{
    public string $data;
    public int $upem = 1000;
    public array $bbox = [0, 0, 1000, 1000];
    public int $asc = 800;
    public int $desc = -200;
    public int $capH = 700;
    /** gid => codepoint dos glifos efetivamente usados (para /W e ToUnicode) */
    public array $used = [];
    private array $adv = [];
    private int $nHM = 1;
    private int $cmapOff = 0;
    private array $gidCache = [];
    /** tag => [offset, length] */
    private array $tbl = [];

    private function __construct() {}

    private function u32(int $o): int { return unpack('N', substr($this->data, $o, 4))[1]; }

    /**
     * Fonte reduzida: mantém os índices de glifo originais (por isso o CIDToGIDMap Identity continua valendo),
     * esvazia o contorno de todo glifo não usado e descarta tabelas desnecessárias ao PDF (cmap, GSUB, kern...).
     */
    public function subset(): string
    {
        $loca0 = $this->tbl['loca'][0];
        $glyf0 = $this->tbl['glyf'][0];
        $n = $this->u16($this->tbl['maxp'][0] + 4);
        $long = $this->u16($this->tbl['head'][0] + 50) === 1;
        $loca = [];
        for ($i = 0; $i <= $n; $i++) {
            $loca[$i] = $long ? $this->u32($loca0 + 4 * $i) : $this->u16($loca0 + 2 * $i) * 2;
        }
        // Glifos mantidos: usados + .notdef + componentes de glifos compostos
        $keep = [0 => true];
        $queue = array_keys($this->used);
        while ($queue) {
            $g = array_pop($queue);
            $keep[$g] = true;
            $len = ($loca[$g + 1] ?? 0) - ($loca[$g] ?? 0);
            if ($len < 10) { continue; }
            $o = $glyf0 + $loca[$g];
            if ($this->s16($o) >= 0) { continue; }           // glifo simples
            $o += 10;
            do {
                $flags = $this->u16($o);
                $comp = $this->u16($o + 2);
                if (!isset($keep[$comp])) { $keep[$comp] = true; $queue[] = $comp; }
                $o += 4 + ($flags & 1 ? 4 : 2);
                if ($flags & 8) { $o += 2; } elseif ($flags & 0x40) { $o += 4; } elseif ($flags & 0x80) { $o += 8; }
            } while ($flags & 0x20);
        }
        $glyf = '';
        $newLoca = [];
        for ($g = 0; $g < $n; $g++) {
            $newLoca[] = strlen($glyf);
            $len = $loca[$g + 1] - $loca[$g];
            if (isset($keep[$g]) && $len > 0) {
                $glyf .= substr($this->data, $glyf0 + $loca[$g], $len) . str_repeat("\0", (4 - $len % 4) % 4);
            }
        }
        $newLoca[] = strlen($glyf);

        $head = substr($this->data, $this->tbl['head'][0], $this->tbl['head'][1]);
        $head = substr_replace($head, "\0\0\0\0", 8, 4);        // checkSumAdjustment
        $head = substr_replace($head, "\0\1", 50, 2);           // loca em formato longo
        $parts = ['glyf' => $glyf, 'loca' => pack('N*', ...$newLoca), 'head' => $head];
        foreach (['cvt ', 'fpgm', 'prep', 'hhea', 'hmtx', 'maxp'] as $tag) {
            if (isset($this->tbl[$tag])) { $parts[$tag] = substr($this->data, $this->tbl[$tag][0], $this->tbl[$tag][1]); }
        }
        ksort($parts);
        $cnt = count($parts);
        $pow = 1;
        while ($pow * 2 <= $cnt) { $pow *= 2; }
        $out = pack('Nnnnn', 0x00010000, $cnt, $pow * 16, (int)log($pow, 2), $cnt * 16 - $pow * 16);
        $off = 12 + 16 * $cnt;
        $body = '';
        foreach ($parts as $tag => $bin) {
            $padded = $bin . str_repeat("\0", (4 - strlen($bin) % 4) % 4);
            $sum = 0;
            foreach (unpack('N*', $padded) ?: [] as $w) { $sum = ($sum + $w) & 0xFFFFFFFF; }
            $out .= $tag . pack('NNN', $sum, $off + strlen($body), strlen($bin));
            $body .= $padded;
        }
        return $out . $body;
    }

    private function u16(int $o): int { return unpack('n', substr($this->data, $o, 2))[1]; }
    private function s16(int $o): int { $v = $this->u16($o); return $v >= 0x8000 ? $v - 0x10000 : $v; }

    public static function load(string $path): ?self
    {
        $d = @file_get_contents($path);
        if ($d === false || strlen($d) < 12 || !in_array(substr($d, 0, 4), ["\0\1\0\0", 'true'], true)) {
            return null;
        }
        $f = new self();
        $f->data = $d;
        $tables = [];
        $n = $f->u16(4);
        for ($i = 0; $i < $n; $i++) {
            $r = 12 + 16 * $i;
            $tag = substr($d, $r, 4);
            $tables[$tag] = unpack('N', substr($d, $r + 8, 4))[1];
            $f->tbl[$tag] = [$tables[$tag], unpack('N', substr($d, $r + 12, 4))[1]];
        }
        foreach (['head', 'hhea', 'hmtx', 'cmap', 'glyf'] as $need) {
            if (!isset($tables[$need])) { return null; }   // CFF/OTF não suportado
        }
        $f->upem = $f->u16($tables['head'] + 18) ?: 1000;
        $f->bbox = [$f->s16($tables['head'] + 36), $f->s16($tables['head'] + 38), $f->s16($tables['head'] + 40), $f->s16($tables['head'] + 42)];
        $f->asc = $f->s16($tables['hhea'] + 4);
        $f->desc = $f->s16($tables['hhea'] + 6);
        $f->nHM = max(1, $f->u16($tables['hhea'] + 34));
        for ($i = 0; $i < $f->nHM; $i++) {
            $f->adv[$i] = $f->u16($tables['hmtx'] + 4 * $i);
        }
        $f->capH = $f->asc;
        if (isset($tables['OS/2']) && $f->u16($tables['OS/2']) >= 2) {
            $f->capH = $f->s16($tables['OS/2'] + 88) ?: $f->asc;
        }
        // Tabela cmap: prefere Windows Unicode BMP (3,1), senão Unicode (0,x), formato 4
        $co = $tables['cmap'];
        $nt = $f->u16($co + 2);
        for ($i = 0; $i < $nt; $i++) {
            $plat = $f->u16($co + 4 + 8 * $i);
            $enc = $f->u16($co + 6 + 8 * $i);
            $off = $co + unpack('N', substr($d, $co + 8 + 8 * $i, 4))[1];
            if ((($plat === 3 && $enc === 1) || $plat === 0) && $f->u16($off) === 4) {
                $f->cmapOff = $off;
                if ($plat === 3) { break; }
            }
        }
        return $f->cmapOff ? $f : null;
    }

    /** Glifo para um codepoint (0 = ausente). */
    public function gid(int $u): int
    {
        if (isset($this->gidCache[$u])) { return $this->gidCache[$u]; }
        $gid = 0;
        if ($u <= 0xFFFF) {
            $o = $this->cmapOff;
            $x2 = $this->u16($o + 6);
            $seg = intdiv($x2, 2);
            $endO = $o + 14;
            $startO = $endO + $x2 + 2;
            $deltaO = $startO + $x2;
            $rangeO = $deltaO + $x2;
            for ($i = 0; $i < $seg; $i++) {
                if ($u <= $this->u16($endO + 2 * $i)) {
                    $start = $this->u16($startO + 2 * $i);
                    if ($u >= $start) {
                        $delta = $this->u16($deltaO + 2 * $i);
                        $ro = $this->u16($rangeO + 2 * $i);
                        if ($ro === 0) {
                            $gid = ($u + $delta) & 0xFFFF;
                        } else {
                            $g = $this->u16($rangeO + 2 * $i + $ro + 2 * ($u - $start));
                            $gid = $g === 0 ? 0 : ($g + $delta) & 0xFFFF;
                        }
                    }
                    break;
                }
            }
        }
        return $this->gidCache[$u] = $gid;
    }

    /** Largura do glifo em unidades de 1/1000 em. */
    public function advance(int $gid): float
    {
        return $this->adv[min($gid, $this->nHM - 1)] * 1000 / $this->upem;
    }

    /** Converte texto UTF-8 em [gids] registrando os glifos usados; caracteres ausentes viram "?". */
    public function glyphs(string $s): array
    {
        $out = [];
        foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $u = mb_ord($ch, 'UTF-8');
            if ($u < 32 || $u === 0xAD) { continue; }
            $g = $this->gid($u);
            if ($g === 0) { $u = 63; $g = $this->gid(63); }
            $this->used[$g] = $u;
            $out[] = $g;
        }
        return $out;
    }
}

/** Localiza fontes Unicode: primeiro em assets/fonts/, depois nas fontes do sistema. */
function pdf_load_fonts(): array
{
    $dir = dirname(__DIR__) . '/assets/fonts/';
    $candidates = [
        [$dir . 'pdf-regular.ttf', $dir . 'pdf-bold.ttf'],
        ['C:/Windows/Fonts/arial.ttf', 'C:/Windows/Fonts/arialbd.ttf'],
        ['C:/Windows/Fonts/segoeui.ttf', 'C:/Windows/Fonts/segoeuib.ttf'],
        ['/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf', '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf'],
        ['/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'],
        ['/usr/share/fonts/liberation/LiberationSans-Regular.ttf', '/usr/share/fonts/liberation/LiberationSans-Bold.ttf'],
        ['/Library/Fonts/Arial.ttf', '/Library/Fonts/Arial Bold.ttf'],
        ['/System/Library/Fonts/Supplemental/Arial.ttf', '/System/Library/Fonts/Supplemental/Arial Bold.ttf'],
    ];
    foreach ($candidates as [$reg, $bold]) {
        if (!is_file($reg) || !($r = TtfFont::load($reg))) { continue; }
        $b = is_file($bold) ? TtfFont::load($bold) : null;
        return ['F1' => $r, 'F2' => $b ?: $r];
    }
    return [];   // sem fonte Unicode: usa Helvetica + WinAnsi (acentos do português continuam funcionando)
}

final class SimplePdf
{
    public const PW = 595.28;
    public const PH = 841.89;

    private float $ml = 40;
    private float $mr = 40;
    private float $mb = 50;
    private float $top = 90;      // início da área de conteúdo (abaixo do cabeçalho)
    private array $pages = [];
    private int $cur = -1;
    public float $y = 0;
    private array $images = [];
    private ?int $logo = null;
    /** Fontes Unicode embutidas (F1 regular, F2 negrito); vazio = Helvetica/WinAnsi */
    private array $ttf = [];

    /** $meta: system, title, user */
    public function __construct(private array $meta, ?array $logoInfo = null)
    {
        $this->ttf = pdf_load_fonts();
        if ($logoInfo) {
            $this->logo = $this->addImage($logoInfo);
        }
        $this->addPage();
    }

    /* ---------- Utilidades de texto ---------- */

    private function enc(string $s): string
    {
        return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
    }

    private function esc(string $s): string
    {
        return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $s);
    }

    private function cw(int $c): int
    {
        static $t = [
            278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
            556, 556, 556, 556, 556, 556, 556, 556, 556, 556,
            278, 278, 584, 584, 584, 556, 1015,
            667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611,
            278, 278, 278, 469, 556, 333,
            556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500,
            334, 260, 334, 584,
        ];
        if ($c >= 32 && $c <= 126) {
            return $t[$c - 32];
        }
        // Letras acentuadas (Windows-1252): largura da letra-base
        if ($c >= 0xC0 && $c <= 0xC5) return 667;
        if ($c === 0xC7) return 722;
        if ($c >= 0xC8 && $c <= 0xCB) return 667;
        if ($c >= 0xCC && $c <= 0xCF) return 278;
        if ($c === 0xD1) return 722;
        if (($c >= 0xD2 && $c <= 0xD6) || $c === 0xD8) return 778;
        if ($c >= 0xD9 && $c <= 0xDC) return 722;
        if (($c >= 0xE0 && $c <= 0xE5) || ($c >= 0xE8 && $c <= 0xEB)) return 556;
        if ($c === 0xE7) return 500;
        if ($c >= 0xEC && $c <= 0xEF) return 278;
        if ($c === 0xF1 || ($c >= 0xF2 && $c <= 0xF6) || ($c >= 0xF9 && $c <= 0xFC)) return 556;
        if ($c === 0x97) return 1000;
        if ($c === 0x95) return 350;
        return 556;
    }

    public function width(string $s, float $size, bool $bold = false): float
    {
        if ($this->ttf) {
            $f = $this->ttf[$bold ? 'F2' : 'F1'];
            $w = 0;
            foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
                $u = mb_ord($ch, 'UTF-8');
                if ($u < 32 || $u === 0xAD) { continue; }
                $g = $f->gid($u) ?: $f->gid(63);
                $w += $f->advance($g);
            }
            return $w * $size / 1000;
        }
        $e = $this->enc($s);
        $w = 0;
        for ($i = 0, $n = strlen($e); $i < $n; $i++) {
            $w += $this->cw(ord($e[$i]));
        }
        return $w * $size / 1000 * ($bold ? 1.07 : 1);
    }

    /** Quebra o texto em linhas que cabem em $maxw. */
    public function wrap(string $s, float $size, bool $bold, float $maxw): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $s) as $para) {
            if (trim($para) === '') { $lines[] = ''; continue; }
            $cur = '';
            foreach (preg_split('/\s+/u', trim($para)) as $word) {
                while ($this->width($word, $size, $bold) > $maxw && mb_strlen($word) > 1) {
                    $cut = mb_strlen($word);
                    while ($cut > 1 && $this->width(mb_substr($word, 0, $cut), $size, $bold) > $maxw) { $cut--; }
                    if ($cur !== '') { $lines[] = $cur; $cur = ''; }
                    $lines[] = mb_substr($word, 0, $cut);
                    $word = mb_substr($word, $cut);
                }
                $try = $cur === '' ? $word : $cur . ' ' . $word;
                if ($cur === '' || $this->width($try, $size, $bold) <= $maxw) {
                    $cur = $try;
                } else {
                    $lines[] = $cur;
                    $cur = $word;
                }
            }
            $lines[] = $cur;
        }
        return $lines;
    }

    /* ---------- Primitivas de desenho (y medido a partir do topo) ---------- */

    private function out(string $s): void
    {
        $this->pages[$this->cur] .= $s . "\n";
    }

    private function col(array $c, bool $stroke = false): string
    {
        return sprintf('%.3F %.3F %.3F %s', $c[0] / 255, $c[1] / 255, $c[2] / 255, $stroke ? 'RG' : 'rg');
    }

    public function text(float $x, float $y, string $s, float $size = 9, bool $bold = false, array $color = [31, 41, 55]): void
    {
        $font = $bold ? 'F2' : 'F1';
        if ($this->ttf) {
            $hex = '';
            foreach ($this->ttf[$font]->glyphs($s) as $g) { $hex .= sprintf('%04X', $g); }
            $shown = '<' . $hex . '>';
        } else {
            $shown = '(' . $this->esc($this->enc($s)) . ')';
        }
        $this->out(sprintf('BT /%s %.2F Tf %s %.2F %.2F Td %s Tj ET', $font, $size, $this->col($color), $x, self::PH - $y, $shown));
    }

    public function textRight(float $xr, float $y, string $s, float $size = 9, bool $bold = false, array $color = [31, 41, 55]): void
    {
        $this->text($xr - $this->width($s, $size, $bold), $y, $s, $size, $bold, $color);
    }

    public function rect(float $x, float $y, float $w, float $h, ?array $fill = null, ?array $stroke = null, float $lw = 0.5): void
    {
        $op = '';
        if ($fill) { $op .= $this->col($fill) . ' '; }
        if ($stroke) { $op .= $this->col($stroke, true) . sprintf(' %.2F w ', $lw); }
        $op .= sprintf('%.2F %.2F %.2F %.2F re ', $x, self::PH - $y - $h, $w, $h);
        $op .= ($fill && $stroke) ? 'B' : ($fill ? 'f' : 'S');
        $this->out($op);
    }

    public function line(float $x1, float $y1, float $x2, float $y2, array $color = [226, 230, 236], float $lw = 0.6): void
    {
        $this->out($this->col($color, true) . sprintf(' %.2F w %.2F %.2F m %.2F %.2F l S', $lw, $x1, self::PH - $y1, $x2, self::PH - $y2));
    }

    public function addImage(array $info): int
    {
        $this->images[] = $info;
        return count($this->images) - 1;
    }

    public function placeImage(int $idx, float $x, float $y, float $w, float $h): void
    {
        $this->out(sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /Im%d Do Q', $w, $h, $x, self::PH - $y - $h, $idx));
    }

    /* ---------- Páginas ---------- */

    public function contentWidth(): float
    {
        return self::PW - $this->ml - $this->mr;
    }

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->cur = count($this->pages) - 1;

        // Cabeçalho: faixa azul, logo, nome do sistema, título do relatório e emissão
        $this->rect(0, 0, self::PW, 7, [37, 99, 235]);
        $x = $this->ml;
        if ($this->logo !== null) {
            $info = $this->images[$this->logo];
            $box = 40;
            $ratio = $info['w'] / max(1, $info['h']);
            $lw = $ratio >= 1 ? $box : $box * $ratio;
            $lh = $ratio >= 1 ? $box / $ratio : $box;
            $this->placeImage($this->logo, $x, 20 + ($box - $lh) / 2, $lw, $lh);
            $x += $box + 10;
        }
        $this->text($x, 38, $this->meta['system'], 16, true, [17, 24, 39]);
        $this->text($x, 56, $this->meta['title'], 10.5, false, [75, 85, 99]);
        $this->textRight(self::PW - $this->mr, 38, 'Emitido em ' . date('d/m/Y H:i'), 8, false, [107, 114, 128]);
        $this->textRight(self::PW - $this->mr, 50, 'Por ' . $this->meta['user'], 8, false, [107, 114, 128]);
        $this->line($this->ml, 72, self::PW - $this->mr, 72, [37, 99, 235], 1);
        $this->y = $this->top;
    }

    public function ensure(float $h): bool
    {
        if ($this->y + $h > self::PH - $this->mb) {
            $this->addPage();
            return true;
        }
        return false;
    }

    public function space(float $h): void
    {
        $this->y += $h;
    }

    /* ---------- Blocos ---------- */

    public function heading(string $t): void
    {
        $this->ensure(40);
        $this->y += 6;
        $this->rect($this->ml, $this->y, 3.5, 15, [37, 99, 235]);
        $this->text($this->ml + 10, $this->y + 12, $t, 12.5, true, [17, 24, 39]);
        $this->y += 26;
    }

    public function paragraph(string $s, float $size = 9.5, array $color = [55, 65, 81], bool $bold = false, float $indent = 0): void
    {
        $lh = $size * 1.4;
        foreach ($this->wrap($s, $size, $bold, $this->contentWidth() - $indent) as $line) {
            $this->ensure($lh);
            $this->text($this->ml + $indent, $this->y + $size, $line, $size, $bold, $color);
            $this->y += $lh;
        }
    }

    /** Linha "Rótulo: valor" com valor quebrando em várias linhas. */
    public function kv(string $label, string $value, float $labelW = 105): void
    {
        $size = 9.5;
        $lh = $size * 1.4;
        $lines = $this->wrap($value === '' ? '—' : $value, $size, false, $this->contentWidth() - $labelW);
        $this->ensure($lh * min(count($lines), 3));
        $this->text($this->ml, $this->y + $size, $label, 8.5, true, [107, 114, 128]);
        foreach ($lines as $line) {
            $this->ensure($lh);
            $this->text($this->ml + $labelW, $this->y + $size, $line, $size);
            $this->y += $lh;
        }
        $this->y += 3;
    }

    /** Cartões de resumo: cada item = [número, rótulo, [r,g,b]]. */
    public function stats(array $items): void
    {
        $n = count($items);
        if (!$n) { return; }
        $gap = 8;
        $w = ($this->contentWidth() - $gap * ($n - 1)) / $n;
        $h = 50;
        $this->ensure($h + 10);
        foreach ($items as $i => [$num, $label, $color]) {
            $x = $this->ml + $i * ($w + $gap);
            $this->rect($x, $this->y, $w, $h, [248, 250, 252], [226, 230, 236]);
            $this->rect($x, $this->y, 4, $h, $color);
            $this->text($x + 13, $this->y + 25, (string)$num, 19, true, [17, 24, 39]);
            $this->text($x + 13, $this->y + 40, $label, 7.5, false, [107, 114, 128]);
        }
        $this->y += $h + 12;
    }

    /**
     * Tabela com cabeçalho repetido a cada página.
     * $cols: [['l' => rótulo, 'w' => peso, 'a' => 'L'|'C'|'R'], ...]
     * $rows: lista de linhas; cada célula é string ou ['t' => texto, 'fg' => cor, 'bg' => cor, 'b' => negrito].
     */
    public function table(array $cols, array $rows, float $size = 8): void
    {
        $total = array_sum(array_column($cols, 'w'));
        $cwid = [];
        foreach ($cols as $c) { $cwid[] = $this->contentWidth() * $c['w'] / $total; }
        $pad = 4;
        $lh = $size * 1.35;

        $drawHeader = function () use ($cols, $cwid, $size, $pad) {
            $hh = $size + $pad * 2 + 2;
            $this->rect($this->ml, $this->y, $this->contentWidth(), $hh, [31, 41, 55]);
            $x = $this->ml;
            foreach ($cols as $i => $c) {
                $tx = $c['a'] ?? 'L';
                $s = mb_strtoupper($c['l']);
                $tw = $this->width($s, $size - 0.5, true);
                $px = $tx === 'R' ? $x + $cwid[$i] - $pad - $tw : ($tx === 'C' ? $x + ($cwid[$i] - $tw) / 2 : $x + $pad);
                $this->text($px, $this->y + $pad + $size, $s, $size - 0.5, true, [255, 255, 255]);
                $x += $cwid[$i];
            }
            $this->y += $hh;
        };

        $this->ensure(60);
        $drawHeader();

        if (!$rows) {
            $this->ensure(26);
            $this->text($this->ml + $pad, $this->y + 16, 'Nenhum registro encontrado.', 9, false, [107, 114, 128]);
            $this->line($this->ml, $this->y + 24, self::PW - $this->mr, $this->y + 24);
            $this->y += 30;
            return;
        }

        foreach ($rows as $ri => $row) {
            // Altura da linha = célula com mais linhas de texto
            $cells = [];
            $maxLines = 1;
            foreach ($cols as $i => $c) {
                $cell = is_array($row[$i] ?? '') ? $row[$i] : ['t' => (string)($row[$i] ?? '')];
                $cell['lines'] = $this->wrap($cell['t'] === '' ? '—' : $cell['t'], $size, !empty($cell['b']), $cwid[$i] - $pad * 2);
                $maxLines = max($maxLines, count($cell['lines']));
                $cells[] = $cell;
            }
            $rh = $maxLines * $lh + $pad * 2;
            if ($this->ensure($rh)) {
                $drawHeader();
            }
            if ($ri % 2 === 1) {
                $this->rect($this->ml, $this->y, $this->contentWidth(), $rh, [248, 250, 252]);
            }
            $x = $this->ml;
            foreach ($cols as $i => $c) {
                $cell = $cells[$i];
                if (!empty($cell['bg'])) {
                    $this->rect($x + 2, $this->y + 2, $cwid[$i] - 4, $rh - 4, $cell['bg']);
                }
                $ty = $this->y + $pad + $size;
                foreach ($cell['lines'] as $line) {
                    $tw = $this->width($line, $size, !empty($cell['b']));
                    $a = $c['a'] ?? 'L';
                    $px = $a === 'R' ? $x + $cwid[$i] - $pad - $tw : ($a === 'C' ? $x + ($cwid[$i] - $tw) / 2 : $x + $pad);
                    $this->text($px, $ty, $line, $size, !empty($cell['b']), $cell['fg'] ?? [31, 41, 55]);
                    $ty += $lh;
                }
                $x += $cwid[$i];
            }
            $this->line($this->ml, $this->y + $rh, self::PW - $this->mr, $this->y + $rh);
            $this->y += $rh;
        }
        $this->y += 8;
    }

    /** Galeria de imagens (2 por linha). $items: [['img' => índice|null, 'caption' => texto], ...] */
    public function gallery(array $items): void
    {
        $gap = 12;
        $w = ($this->contentWidth() - $gap) / 2;
        $maxH = 190;
        foreach (array_chunk($items, 2) as $row) {
            $dims = [];
            $rowH = 0;
            foreach ($row as $it) {
                if ($it['img'] === null) {
                    $dims[] = [$w, 28];
                } else {
                    $info = $this->images[$it['img']];
                    $s = min($w / $info['w'], $maxH / $info['h'], 1.6);
                    $dims[] = [$info['w'] * $s, $info['h'] * $s];
                }
                $rowH = max($rowH, end($dims)[1]);
            }
            $this->ensure($rowH + 24);
            foreach ($row as $k => $it) {
                $x = $this->ml + $k * ($w + $gap);
                if ($it['img'] === null) {
                    $this->rect($x, $this->y, $w, 28, [248, 250, 252], [226, 230, 236]);
                    $this->text($x + 6, $this->y + 17, 'Imagem não incorporada ao PDF (formato não suportado).', 7.5, false, [107, 114, 128]);
                } else {
                    $this->rect($x - 1, $this->y - 1, $dims[$k][0] + 2, $dims[$k][1] + 2, null, [203, 213, 225]);
                    $this->placeImage($it['img'], $x, $this->y, $dims[$k][0], $dims[$k][1]);
                }
                $this->text($x, $this->y + $rowH + 11, mb_strimwidth($it['caption'], 0, 70, '…'), 7.5, false, [107, 114, 128]);
            }
            $this->y += $rowH + 22;
        }
    }

    /* ---------- Saída ---------- */

    public function build(): string
    {
        $n = count($this->pages);
        for ($i = 0; $i < $n; $i++) {
            $this->cur = $i;
            $fy = self::PH - 30;
            $this->line($this->ml, $fy - 12, self::PW - $this->mr, $fy - 12);
            $this->text($this->ml, $fy, $this->meta['system'] . ' — documento gerado automaticamente', 7.5, false, [107, 114, 128]);
            $this->textRight(self::PW - $this->mr, $fy, 'Página ' . ($i + 1) . ' de ' . $n, 7.5, true, [75, 85, 99]);
        }

        $objs = [null, null,
            '<</Type/Font/Subtype/Type1/BaseFont/Helvetica/Encoding/WinAnsiEncoding>>',
            '<</Type/Font/Subtype/Type1/BaseFont/Helvetica-Bold/Encoding/WinAnsiEncoding>>'];
        $add = function (string $body) use (&$objs): int {
            $objs[] = $body;
            return count($objs);
        };
        $stream = fn(string $dict, string $data) => "<<$dict /Length " . strlen($data) . ">>\nstream\n$data\nendstream";

        // Fontes Unicode: Type0/Identity-H + CIDFontType2 + FontDescriptor + FontFile2 + ToUnicode (copiar/buscar texto)
        $done = [];
        foreach ($this->ttf as $slot => $font) {
            $oid = spl_object_id($font);
            if (!isset($done[$oid])) {
                $name = 'TestLinkSans' . (count($done) + 1);
                $sub = $font->subset();
                $file = $add($stream('/Filter/FlateDecode /Length1 ' . strlen($sub), gzcompress($sub, 6)));
                $k = 1000 / $font->upem;
                $desc = $add(sprintf('<</Type/FontDescriptor/FontName/%s/Flags 32/FontBBox[%d %d %d %d]/ItalicAngle 0/Ascent %d/Descent %d/CapHeight %d/StemV 80/FontFile2 %d 0 R>>',
                    $name, $font->bbox[0] * $k, $font->bbox[1] * $k, $font->bbox[2] * $k, $font->bbox[3] * $k, $font->asc * $k, $font->desc * $k, $font->capH * $k, $file));
                ksort($font->used);
                $w = '';
                foreach ($font->used as $gid => $u) { $w .= $gid . '[' . round($font->advance($gid)) . ']'; }
                $cid = $add("<</Type/Font/Subtype/CIDFontType2/BaseFont/$name/CIDSystemInfo<</Registry(Adobe)/Ordering(Identity)/Supplement 0>>"
                    . "/FontDescriptor $desc 0 R/DW 1000/W[$w]/CIDToGIDMap/Identity>>");
                $bf = '';
                foreach (array_chunk($font->used, 100, true) as $chunk) {
                    $bf .= count($chunk) . " beginbfchar\n";
                    foreach ($chunk as $gid => $u) { $bf .= sprintf("<%04X> <%04X>\n", $gid, $u); }
                    $bf .= "endbfchar\n";
                }
                $cmap = "/CIDInit /ProcSet findresource begin 12 dict begin begincmap\n/CIDSystemInfo<</Registry(Adobe)/Ordering(UCS)/Supplement 0>> def\n"
                    . "/CMapName/Adobe-Identity-UCS def /CMapType 2 def\n1 begincodespacerange <0000> <FFFF> endcodespacerange\n$bf"
                    . "endcmap CMapName currentdict /CMap defineresource pop end end";
                $tu = $add($stream('', $cmap));
                $done[$oid] = $add("<</Type/Font/Subtype/Type0/BaseFont/$name/Encoding/Identity-H/DescendantFonts[$cid 0 R]/ToUnicode $tu 0 R>>");
            }
            $fontIds[$slot] = $done[$oid];
        }
        $fontRes = $this->ttf ? "/F1 {$fontIds['F1']} 0 R/F2 {$fontIds['F2']} 0 R" : '/F1 3 0 R/F2 4 0 R';

        $xobj = '';
        foreach ($this->images as $i => $im) {
            $extra = '';
            if (!empty($im['smask'])) {
                $sm = $add($stream("/Type/XObject/Subtype/Image/Width {$im['w']}/Height {$im['h']}/ColorSpace/DeviceGray/BitsPerComponent 8/Filter/FlateDecode/DecodeParms {$im['smask']['parms']}", $im['smask']['data']));
                $extra = "/SMask $sm 0 R";
            }
            $parms = isset($im['parms']) ? "/DecodeParms {$im['parms']}" : '';
            $id = $add($stream("/Type/XObject/Subtype/Image/Width {$im['w']}/Height {$im['h']}/ColorSpace {$im['cs']}/BitsPerComponent {$im['bpc']}/Filter/{$im['filter']} $parms $extra", $im['data']));
            $xobj .= "/Im$i $id 0 R";
        }

        $kids = [];
        foreach ($this->pages as $content) {
            $compress = function_exists('gzcompress');
            $data = $compress ? gzcompress($content, 6) : $content;
            $c = $add($stream($compress ? '/Filter/FlateDecode' : '', $data));
            $kids[] = $add('<</Type/Page/Parent 2 0 R/MediaBox[0 0 ' . self::PW . ' ' . self::PH . ']'
                . "/Resources<</Font<<$fontRes>>/XObject<<$xobj>>>>/Contents $c 0 R>>");
        }
        $objs[0] = '<</Type/Catalog/Pages 2 0 R>>';
        $objs[1] = '<</Type/Pages/Count ' . count($kids) . '/Kids[' . implode(' ', array_map(fn($k) => "$k 0 R", $kids)) . ']>>';
        $info = $add('<</Title(' . $this->esc($this->enc($this->meta['title'])) . ')/Author(' . $this->esc($this->enc($this->meta['user']))
            . ')/Creator(' . $this->esc($this->enc($this->meta['system'])) . ')/CreationDate(D:' . date('YmdHis') . ')>>');

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $i => $body) {
            $offsets[$i + 1] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }
        return $pdf . "trailer\n<</Size " . (count($objs) + 1) . "/Root 1 0 R/Info $info 0 R>>\nstartxref\n$xref\n%%EOF";
    }

    /** Envia o PDF ao navegador como download. */
    public function download(string $filename): void
    {
        $data = $this->build();
        if (ob_get_length()) { ob_clean(); }
        header('Content-Type: application/pdf');
        // ?inline=1 abre no visualizador do navegador em vez de baixar
        header('Content-Disposition: ' . (isset($_GET['inline']) ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
        header('Content-Length: ' . strlen($data));
        header('Cache-Control: private, max-age=0, must-revalidate');
        echo $data;
        exit;
    }
}
