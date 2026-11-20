<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Pdf;

\defined('_JEXEC') or die;

/**
 * What goes in a PDF, independent of how it's drawn (0.11.0). Plain text
 * only (UTF-8); a renderer decides fonts and layout.
 *
 * Blocks, in order:
 *   ['type' => 'heading', 'text' => …]
 *   ['type' => 'text', 'text' => …, 'small' => bool]
 *   ['type' => 'pair', 'label' => …, 'value' => …]          a label and its answer
 *   ['type' => 'table', 'caption' => …, 'head' => [..], 'rows' => [[..], ..]]
 *   ['type' => 'image', 'bytes' => PNG or JPEG bytes, 'alt' => …, 'max_w' => pt, 'max_h' => pt]
 *   ['type' => 'rule']
 */
final class PdfDocument
{
    /** @var list<array<string, mixed>> */
    public array $blocks = [];

    public string $paper = 'letter';

    public function __construct(
        public string $title,
        public string $footer = '',
        public string $author = '',
    ) {
    }

    public function heading(string $text): self
    {
        $this->blocks[] = ['type' => 'heading', 'text' => $text];

        return $this;
    }

    public function text(string $text, bool $small = false): self
    {
        $this->blocks[] = ['type' => 'text', 'text' => $text, 'small' => $small];

        return $this;
    }

    public function pair(string $label, string $value): self
    {
        $this->blocks[] = ['type' => 'pair', 'label' => $label, 'value' => $value];

        return $this;
    }

    /**
     * @param list<string>        $head
     * @param list<list<string>>  $rows
     */
    public function table(string $caption, array $head, array $rows): self
    {
        $this->blocks[] = ['type' => 'table', 'caption' => $caption, 'head' => $head, 'rows' => $rows];

        return $this;
    }

    public function image(string $bytes, string $alt, float $maxW = 220, float $maxH = 80): self
    {
        $this->blocks[] = ['type' => 'image', 'bytes' => $bytes, 'alt' => $alt, 'max_w' => $maxW, 'max_h' => $maxH];

        return $this;
    }

    public function rule(): self
    {
        $this->blocks[] = ['type' => 'rule'];

        return $this;
    }

    /** All the words in the document, for tests and for checking nothing was lost. */
    public function plainText(): string
    {
        $out = [$this->title];

        foreach ($this->blocks as $b) {
            $out[] = match ($b['type']) {
                'heading', 'text' => $b['text'],
                'pair'            => $b['label'] . ': ' . $b['value'],
                'table'           => $b['caption'] . "\n" . implode(' | ', $b['head']) . "\n" . implode("\n", array_map(static fn ($r) => implode(' | ', $r), $b['rows'])),
                'image'           => '[' . $b['alt'] . ']',
                default           => '',
            };
        }

        return implode("\n", $out) . "\n" . $this->footer;
    }
}
