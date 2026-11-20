<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Pdf;

\defined('_JEXEC') or die;

/**
 * SquidForm's own small PDF writer (0.11.0): no outside library, works offline.
 *
 * - Fonts: the PDF core Helvetica and Helvetica-Bold (every reader has them,
 *   nothing embedded), so text is limited to Western European characters
 *   (Windows-1252); anything else prints as "?". A renderer add-on (TCPDF,
 *   say) can do full Unicode and nicer layouts.
 * - Tagged for screen readers: the title (H1), headings (H2), paragraphs,
 *   label/answer lists (L > LI > Lbl + LBody), tables with header cells, and
 *   images with their alt text; page numbers, lines and borders are marked as
 *   decoration. Language and title are set, and readers show the title.
 * - Images: PNG (8-bit, not interlaced; transparency kept as a soft mask) and
 *   baseline JPEG. Anything else is left out and its alt text printed.
 * - Letter or A4, 54 pt margins, page breaks with table headers repeated,
 *   "Page n of N" on every page.
 */
final class SimplePdf implements PdfRendererInterface
{
    private const MARGIN = 54.0;

    /** Glyph widths (1/1000 em) for Windows-1252 codes 32–255. */
    private const W_REGULAR = '278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,761,556,0,222,556,333,1000,556,556,333,1000,667,333,1000,0,611,0,0,222,222,333,333,350,556,1000,333,1000,500,333,944,0,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,584,611,556,556,556,556,500,556,500';

    private const W_BOLD = '278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,761,556,0,278,556,500,1000,556,556,333,1000,667,333,1000,0,611,0,0,278,278,500,500,350,556,1000,333,1000,556,333,944,0,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333,400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,584,611,611,611,611,611,556,611,556';

    /** @var array<bool, list<int>> */
    private static array $widths = [];

    private float $pw = 612.0;

    private float $ph = 792.0;

    private float $y = 0.0;

    /** @var list<string> page content streams */
    private array $pages = [];

    /** @var list<int> next marked-content id per page */
    private array $mcid = [];

    /** @var list<array{type:string, parent:int, kids:list<mixed>, alt?:string, scope?:string}> structure elements; 0 is Document */
    private array $elems = [];

    /** @var list<array<string, mixed>> image XObjects */
    private array $images = [];

    public function __construct(private string $lang = 'en-US')
    {
    }

    public function name(): string
    {
        return 'SquidForm simple PDF';
    }

    public function render(PdfDocument $doc): string
    {
        [$this->pw, $this->ph] = $doc->paper === 'a4' ? [595.28, 841.89] : [612.0, 792.0];
        $this->pages  = [];
        $this->mcid   = [];
        $this->images = [];
        $this->elems  = [['type' => 'Document', 'parent' => -1, 'kids' => []]];
        $this->newPage();

        $h1 = $this->elem('H1', 0);
        $this->paragraph($doc->title, 16, true, $h1);
        $this->y -= 6;

        foreach ($doc->blocks as $b) {
            match ($b['type']) {
                'heading' => $this->headingBlock((string) $b['text']),
                'text'    => $this->textBlock((string) $b['text'], !empty($b['small'])),
                'pair'    => $this->pairBlock((string) $b['label'], (string) $b['value']),
                'table'   => $this->tableBlock((string) $b['caption'], (array) $b['head'], (array) $b['rows']),
                'image'   => $this->imageBlock((string) $b['bytes'], (string) $b['alt'], (float) $b['max_w'], (float) $b['max_h']),
                'rule'    => $this->ruleBlock(),
                default   => null,
            };
        }

        return $this->assemble($doc);
    }

    // ── Blocks ─────────────────────────────────────────────────────────────

    private ?int $list = null;

    private function endList(): void
    {
        if ($this->list !== null) {
            $this->list = null;
            $this->y -= 4;
        }
    }

    private function headingBlock(string $text): void
    {
        $this->endList();
        $this->y -= 8;
        $this->need(40);
        $this->paragraph($text, 12.5, true, $this->elem('H2', 0));
        $this->y -= 3;
    }

    private function textBlock(string $text, bool $small): void
    {
        $this->endList();
        $this->paragraph($text, $small ? 8.5 : 10, false, $this->elem('P', 0), $small ? '0.33 g' : '');
        $this->y -= 4;
    }

    /** A label and its answer, side by side; consecutive pairs form one list. */
    private function pairBlock(string $label, string $value): void
    {
        if ($this->list === null) {
            $this->list = $this->elem('L', 0);
        }

        $li     = $this->elem('LI', $this->list);
        $width  = $this->pw - 2 * self::MARGIN;
        $labelW = round($width * 0.34);
        $size   = 10;
        $lead   = $size * 1.3;
        $lLines = $this->wrap($this->enc($label), $size, true, $labelW - 10);
        $vLines = $this->wrap($this->enc($value === '' ? '—' : $value), $size, false, $width - $labelW);
        $lbl    = $this->elem('Lbl', $li);
        $body   = $this->elem('LBody', $li);
        $n      = max(\count($lLines), \count($vLines));

        // Short answers stay with their label; long ones may run onto the next page.
        $this->need(min($n, 4) * $lead + 4);

        for ($i = 0; $i < $n; $i++) {
            $this->need($lead);
            $base = $this->y - $size;

            if (isset($lLines[$i]) && $lLines[$i] !== '') {
                $this->tagged($lbl, 'Lbl', $this->textOp($lLines[$i], self::MARGIN, $base, $size, true));
            }

            if (isset($vLines[$i]) && $vLines[$i] !== '') {
                $this->tagged($body, 'LBody', $this->textOp($vLines[$i], self::MARGIN + $labelW, $base, $size, false));
            }

            $this->y -= $lead;
        }

        $this->y -= 3;
    }

    /**
     * @param list<string>        $head
     * @param list<list<string>>  $rows
     */
    private function tableBlock(string $caption, array $head, array $rows): void
    {
        $this->endList();
        $cols = max(1, \count($head));
        $w    = ($this->pw - 2 * self::MARGIN) / $cols;
        $size = 9;
        $lead = $size * 1.3;
        $pad  = 3;

        $table = $this->elem('Table', 0);

        if ($caption !== '') {
            $this->y -= 4;
            $this->need(3 * $lead + 20);
            $this->paragraph($caption, 10, true, $this->elem('Caption', $table));
        }

        $draw  = function (array $cells, bool $isHead, ?int $tr) use ($cols, $w, $size, $lead, $pad): void {
            $wrapped = [];

            for ($c = 0; $c < $cols; $c++) {
                $wrapped[$c] = $this->wrap($this->enc((string) ($cells[$c] ?? '')), $size, $isHead, $w - 2 * $pad);
            }

            $h = max(array_map('count', $wrapped)) * $lead + 2 * $pad;
            $this->need($h);
            $top = $this->y;

            if ($isHead) {
                $this->artifact(sprintf('0.92 g %.2F %.2F %.2F %.2F re f 0 g', self::MARGIN, $top - $h, $w * $cols, $h));
            }

            $this->artifact(sprintf('0.6 G 0.5 w %.2F %.2F %.2F %.2F re S 0 G', self::MARGIN, $top - $h, $w * $cols, $h));

            for ($c = 0; $c < $cols; $c++) {
                $x = self::MARGIN + $c * $w;

                if ($c > 0) {
                    $this->artifact(sprintf('0.6 G 0.5 w %.2F %.2F m %.2F %.2F l S 0 G', $x, $top, $x, $top - $h));
                }

                $cell = $tr === null ? null : $this->elem($isHead ? 'TH' : 'TD', $tr, $isHead ? ['scope' => 'Column'] : []);
                $ops  = '';

                foreach ($wrapped[$c] as $i => $line) {
                    if ($line !== '') {
                        $ops .= ($ops === '' ? '' : ' ') . $this->textOp($line, $x + $pad, $top - $pad - $size - $i * $lead + 1.5, $size, $isHead);
                    }
                }

                if ($ops !== '') {
                    $cell === null ? $this->artifact($ops) : $this->tagged($cell, $isHead ? 'TH' : 'TD', $ops);
                }
            }

            $this->y = $top - $h;
        };

        // The header row is tagged once and repeated as decoration after a page break.
        $this->need(2 * ($lead + 2 * $pad));
        $draw($head, true, $this->elem('TR', $table));

        foreach ($rows as $row) {
            $before = \count($this->pages);
            $this->need($lead + 2 * $pad);

            if (\count($this->pages) !== $before) {
                $draw($head, true, null);
            }

            $draw((array) $row, false, $this->elem('TR', $table));
        }

        $this->y -= 8;
    }

    private function imageBlock(string $bytes, string $alt, float $maxW, float $maxH): void
    {
        $this->endList();
        $img = $this->loadImage($bytes);

        if ($img === null) {
            $this->textBlock('[' . $alt . ']', true);

            return;
        }

        $scale = min($maxW / $img['w'], $maxH / $img['h'], 1.0);
        $w     = $img['w'] * $scale;
        $h     = $img['h'] * $scale;
        $this->need($h + 6);
        $this->images[] = $img;
        $name = '/Im' . \count($this->images);
        $fig  = $this->elem('Figure', 0, ['alt' => $alt]);
        $this->tagged($fig, 'Figure', sprintf('q %.2F 0 0 %.2F %.2F %.2F cm %s Do Q', $w, $h, self::MARGIN, $this->y - $h, $name));
        $this->y -= $h + 6;
    }

    private function ruleBlock(): void
    {
        $this->endList();
        $this->need(12);
        $this->y -= 5;
        $this->artifact(sprintf('0.75 G 0.75 w %.2F %.2F m %.2F %.2F l S 0 G', self::MARGIN, $this->y, $this->pw - self::MARGIN, $this->y));
        $this->y -= 7;
    }

    // ── Text ───────────────────────────────────────────────────────────────

    private function paragraph(string $text, float $size, bool $bold, int $elem, string $color = ''): void
    {
        $lead = $size * 1.3;
        $tag  = $this->elems[$elem]['type'];

        foreach ($this->wrap($this->enc($text), $size, $bold, $this->pw - 2 * self::MARGIN) as $line) {
            $this->need($lead);

            if ($line !== '') {
                $op = $this->textOp($line, self::MARGIN, $this->y - $size, $size, $bold);
                $this->tagged($elem, $tag, $color !== '' ? $color . ' ' . $op . ' 0 g' : $op);
            }

            $this->y -= $lead;
        }
    }

    private function textOp(string $line, float $x, float $y, float $size, bool $bold): string
    {
        return sprintf('BT /F%d %.2F Tf %.2F %.2F Td (%s) Tj ET', $bold ? 2 : 1, $size, $x, $y, self::esc($line));
    }

    /** UTF-8 → Windows-1252 (what the core fonts can show); the rest becomes "?". */
    private function enc(string $s): string
    {
        $s = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $s);
        $s = (string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $s);

        if (\function_exists('mb_convert_encoding')) {
            $prev = mb_substitute_character();
            mb_substitute_character(0x3F);
            $out = mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
            mb_substitute_character($prev);

            return (string) $out;
        }

        return (string) @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
    }

    private static function esc(string $s): string
    {
        return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }

    private function width(string $s, float $size, bool $bold): float
    {
        if (!isset(self::$widths[$bold])) {
            self::$widths[$bold] = array_map('intval', explode(',', $bold ? self::W_BOLD : self::W_REGULAR));
        }

        $t = 0;
        $w = self::$widths[$bold];

        foreach (unpack('C*', $s) ?: [] as $c) {
            $t += $c >= 32 ? ($w[$c - 32] ?: 556) : 0;
        }

        return $t * $size / 1000;
    }

    /** @return list<string> lines that fit $max points; words longer than a line are split. */
    private function wrap(string $s, float $size, bool $bold, float $max): array
    {
        $out = [];

        foreach (explode("\n", $s) as $para) {
            $line = '';

            foreach (preg_split('/ +/', $para) ?: [] as $word) {
                $try = $line === '' ? $word : $line . ' ' . $word;

                if ($this->width($try, $size, $bold) <= $max) {
                    $line = $try;
                    continue;
                }

                if ($line !== '') {
                    $out[] = $line;
                }

                // A single long word (an address, a link) is cut where it has to be.
                while ($this->width($word, $size, $bold) > $max && \strlen($word) > 1) {
                    $n = \strlen($word) - 1;

                    while ($n > 1 && $this->width(substr($word, 0, $n), $size, $bold) > $max) {
                        $n--;
                    }

                    $out[] = substr($word, 0, $n);
                    $word  = substr($word, $n);
                }

                $line = $word;
            }

            $out[] = $line;
        }

        return $out ?: [''];
    }

    // ── Pages and tagging ──────────────────────────────────────────────────

    private function newPage(): void
    {
        $this->pages[] = '';
        $this->mcid[]  = 0;
        $this->y       = $this->ph - self::MARGIN;
    }

    private function need(float $h): void
    {
        if ($this->y - $h < self::MARGIN + 18) {
            $this->newPage();
        }
    }

    /** @param array{alt?:string, scope?:string} $extra */
    private function elem(string $type, int $parent, array $extra = []): int
    {
        $this->elems[] = ['type' => $type, 'parent' => $parent, 'kids' => []] + $extra;
        $i             = \count($this->elems) - 1;
        $this->elems[$parent]['kids'][] = $i;

        return $i;
    }

    private function tagged(int $elem, string $tag, string $ops): void
    {
        $p  = \count($this->pages) - 1;
        $id = $this->mcid[$p]++;
        $this->pages[$p] .= '/' . $tag . ' <</MCID ' . $id . '>> BDC ' . $ops . " EMC\n";
        $this->elems[$elem]['kids'][] = ['page' => $p, 'mcid' => $id];
    }

    private function artifact(string $ops): void
    {
        $this->pages[\count($this->pages) - 1] .= '/Artifact BMC ' . $ops . " EMC\n";
    }

    // ── Images ─────────────────────────────────────────────────────────────

    /** @return array{w:int, h:int, cs:string, bpc:int, data:string, filter:string, parms?:string, smask?:string, palette?:string}|null */
    private function loadImage(string $b): ?array
    {
        if (str_starts_with($b, "\x89PNG\r\n\x1a\n")) {
            return self::png($b);
        }

        if (str_starts_with($b, "\xFF\xD8")) {
            $i = @getimagesizefromstring($b);

            if (!$i || ($i[2] ?? 0) !== IMAGETYPE_JPEG || !\in_array((int) ($i['channels'] ?? 3), [1, 3], true)) {
                return null;
            }

            return ['w' => (int) $i[0], 'h' => (int) $i[1], 'cs' => ((int) ($i['channels'] ?? 3)) === 1 ? '/DeviceGray' : '/DeviceRGB', 'bpc' => 8, 'data' => $b, 'filter' => '/DCTDecode'];
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private static function png(string $b): ?array
    {
        $pos  = 8;
        $len  = \strlen($b);
        $idat = '';
        $hdr  = null;
        $plte = '';
        $trns = '';

        while ($pos + 8 <= $len) {
            $n    = unpack('N', substr($b, $pos, 4))[1];
            $type = substr($b, $pos + 4, 4);
            $data = substr($b, $pos + 8, $n);
            $pos += 12 + $n;

            if ($type === 'IHDR') {
                $hdr = unpack('Nw/Nh/Cdepth/Ccolor/Ccomp/Cfilter/Cinterlace', $data);
            } elseif ($type === 'PLTE') {
                $plte = $data;
            } elseif ($type === 'tRNS') {
                $trns = $data;
            } elseif ($type === 'IDAT') {
                $idat .= $data;
            } elseif ($type === 'IEND') {
                break;
            }
        }

        if (!$hdr || $hdr['depth'] !== 8 || $hdr['interlace'] !== 0 || $idat === '' || $hdr['w'] < 1 || $hdr['h'] < 1 || $hdr['w'] * $hdr['h'] > 4_000_000) {
            return null;
        }

        $w     = $hdr['w'];
        $h     = $hdr['h'];
        $ct    = $hdr['color'];
        $chans = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$ct] ?? 0;

        if ($chans === 0 || ($ct === 3 && $plte === '')) {
            return null;
        }

        $cs = match ($ct) {
            0, 4    => '/DeviceGray',
            2, 6    => '/DeviceRGB',
            default => '[/Indexed /DeviceRGB ' . (int) (\strlen($plte) / 3 - 1) . ' <' . bin2hex($plte) . '>]',
        };

        // No transparency: the PNG's own compressed data goes straight in.
        if (\in_array($ct, [0, 2], true) || ($ct === 3 && $trns === '')) {
            return ['w' => $w, 'h' => $h, 'cs' => $cs, 'bpc' => 8, 'data' => $idat, 'filter' => '/FlateDecode',
                'parms' => '<</Predictor 15 /Colors ' . $chans . ' /BitsPerComponent 8 /Columns ' . $w . '>>'];
        }

        // Transparency: undo PNG's row filters, split colour from alpha.
        $raw = @gzuncompress($idat);

        if ($raw === false || \strlen($raw) < $h * ($w * $chans + 1)) {
            return null;
        }

        $stride = $w * $chans;
        $prev   = array_fill(0, $stride, 0);
        $color  = '';
        $alpha  = '';
        $pal    = $ct === 3 ? array_values(unpack('C*', $plte)) : [];
        $tr     = $ct === 3 ? array_values(unpack('C*', $trns)) : [];

        for ($y = 0, $p = 0; $y < $h; $y++) {
            $f   = \ord($raw[$p]);
            $cur = array_values(unpack('C*', substr($raw, $p + 1, $stride)));
            $p  += $stride + 1;

            for ($i = 0; $i < $stride; $i++) {
                $a = $i >= $chans ? $cur[$i - $chans] : 0;
                $u = $prev[$i];
                $c = $i >= $chans ? $prev[$i - $chans] : 0;

                $cur[$i] = ($cur[$i] + match ($f) {
                    1       => $a,
                    2       => $u,
                    3       => ($a + $u) >> 1,
                    4       => self::paeth($a, $u, $c),
                    default => 0,
                }) & 0xFF;
            }

            $prev = $cur;

            if ($ct === 3) {
                foreach ($cur as $idx) {
                    $color .= \chr($idx);
                    $alpha .= \chr($tr[$idx] ?? 255);
                }
                continue;
            }

            for ($i = 0; $i < $stride; $i += $chans) {
                $color .= $ct === 6 ? \chr($cur[$i]) . \chr($cur[$i + 1]) . \chr($cur[$i + 2]) : \chr($cur[$i]);
                $alpha .= \chr($cur[$i + $chans - 1]);
            }
        }

        return ['w' => $w, 'h' => $h, 'cs' => $cs, 'bpc' => 8, 'data' => (string) gzcompress($color), 'filter' => '/FlateDecode', 'smask' => (string) gzcompress($alpha)];
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p  = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        return $pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c);
    }

    // ── File ───────────────────────────────────────────────────────────────

    private function assemble(PdfDocument $doc): string
    {
        $total = \count($this->pages);

        // Footers are decoration, added once the page count is known.
        foreach ($this->pages as $i => $ops) {
            $label = $this->enc(($doc->footer !== '' ? $doc->footer . '   ·   ' : '') . 'Page ' . ($i + 1) . ' of ' . $total);
            $this->pages[$i] = $ops . '/Artifact BMC 0.4 g ' . $this->textOp($label, self::MARGIN, self::MARGIN - 18, 7.5, false) . " 0 g EMC\n";
        }

        $obj = [];
        $add = static function (string $body) use (&$obj): int {
            $obj[] = $body;

            return \count($obj);
        };
        $reserve = static function () use (&$obj): int {
            $obj[] = '';

            return \count($obj);
        };

        $catalog  = $reserve();
        $pagesObj = $reserve();
        $f1       = $add('<</Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding>>');
        $f2       = $add('<</Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding>>');
        $xobj     = [];

        foreach ($this->images as $n => $img) {
            $smask = '';

            if (isset($img['smask'])) {
                $sm    = $add(self::stream('<</Type /XObject /Subtype /Image /Width ' . $img['w'] . ' /Height ' . $img['h'] . ' /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode', $img['smask']));
                $smask = ' /SMask ' . $sm . ' 0 R';
            }

            $xobj[] = '/Im' . ($n + 1) . ' ' . $add(self::stream('<</Type /XObject /Subtype /Image /Width ' . $img['w'] . ' /Height ' . $img['h']
                . ' /ColorSpace ' . $img['cs'] . ' /BitsPerComponent ' . $img['bpc'] . ' /Filter ' . $img['filter']
                . (isset($img['parms']) ? ' /DecodeParms ' . $img['parms'] : '') . $smask, $img['data'])) . ' 0 R';
        }

        $res = '<</Font <</F1 ' . $f1 . ' 0 R /F2 ' . $f2 . ' 0 R>>' . ($xobj ? ' /XObject <<' . implode(' ', $xobj) . '>>' : '') . '>>';

        $pageIds = [];

        foreach ($this->pages as $i => $ops) {
            $content   = \function_exists('gzcompress') ? self::stream('<</Filter /FlateDecode', (string) gzcompress($ops)) : self::stream('<<', $ops);
            $c         = $add($content);
            $pageIds[] = $reserve();
            $obj[$pageIds[$i] - 1] = sprintf('<</Type /Page /Parent %d 0 R /MediaBox [0 0 %.2F %.2F] /Resources %s /Contents %d 0 R /StructParents %d /Tabs /S>>', $pagesObj, $this->pw, $this->ph, $res, $c, $i);
        }

        $obj[$pagesObj - 1] = '<</Type /Pages /Kids [' . implode(' ', array_map(static fn ($p) => $p . ' 0 R', $pageIds)) . '] /Count ' . $total . '>>';

        // Structure tree: element objects, then the parent tree (page → MCID → element).
        $ids = [];

        foreach (array_keys($this->elems) as $i) {
            $ids[$i] = $reserve();
        }

        $root   = $reserve();
        $parent = array_fill(0, $total, []);

        foreach ($this->elems as $i => $e) {
            $kids = [];

            foreach ($e['kids'] as $k) {
                if (\is_int($k)) {
                    $kids[] = $ids[$k] . ' 0 R';
                } else {
                    $kids[] = '<</Type /MCR /Pg ' . $pageIds[$k['page']] . ' 0 R /MCID ' . $k['mcid'] . '>>';
                    $parent[$k['page']][$k['mcid']] = $ids[$i];
                }
            }

            $obj[$ids[$i] - 1] = '<</Type /StructElem /S /' . $e['type'] . ' /P ' . ($i === 0 ? $root : $ids[$e['parent']]) . ' 0 R'
                . (isset($e['alt']) ? ' /Alt ' . self::text($e['alt']) : '')
                . (isset($e['scope']) ? ' /A <</O /Table /Scope /' . $e['scope'] . '>>' : '')
                . ' /K [' . implode(' ', $kids) . ']>>';
        }

        $nums = [];

        foreach ($parent as $p => $map) {
            ksort($map);
            $nums[] = $p . ' [' . implode(' ', array_map(static fn ($id) => $id . ' 0 R', $map)) . ']';
        }

        $ptree           = $add('<</Nums [' . implode(' ', $nums) . ']>>');
        $obj[$root - 1]  = '<</Type /StructTreeRoot /K [' . $ids[0] . ' 0 R] /ParentTree ' . $ptree . ' 0 R /ParentTreeNextKey ' . $total . '>>';
        $info            = $add('<</Title ' . self::text($doc->title) . ($doc->author !== '' ? ' /Author ' . self::text($doc->author) : '')
            . ' /Producer (SquidForm) /CreationDate (D:' . gmdate('YmdHis') . 'Z)>>');
        $obj[$catalog - 1] = '<</Type /Catalog /Pages ' . $pagesObj . ' 0 R /StructTreeRoot ' . $root . ' 0 R /MarkInfo <</Marked true>>'
            . ' /Lang ' . self::text($this->lang) . ' /ViewerPreferences <</DisplayDocTitle true>>>>';

        $out = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $off = [];

        foreach ($obj as $i => $body) {
            $off[] = \strlen($out);
            $out  .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xref = \strlen($out);
        $out .= 'xref' . "\n0 " . (\count($obj) + 1) . "\n0000000000 65535 f \n";

        foreach ($off as $o) {
            $out .= sprintf("%010d 00000 n \n", $o);
        }

        $id = md5($out);

        return $out . 'trailer' . "\n<</Size " . (\count($obj) + 1) . ' /Root ' . $catalog . ' 0 R /Info ' . $info . ' 0 R /ID [<' . $id . '> <' . $id . ">]>>\nstartxref\n" . $xref . "\n%%EOF\n";
    }

    private static function stream(string $dictStart, string $data): string
    {
        return $dictStart . ' /Length ' . \strlen($data) . ">>\nstream\n" . $data . "\nendstream";
    }

    /** A text string for metadata and alt text: UTF-16 so any language survives. */
    private static function text(string $s): string
    {
        return '<FEFF' . strtoupper(bin2hex((string) mb_convert_encoding($s, 'UTF-16BE', 'UTF-8'))) . '>';
    }
}
