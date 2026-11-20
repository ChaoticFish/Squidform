<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Pdf;

\defined('_JEXEC') or die;

/**
 * Something that turns a PdfDocument into PDF bytes (0.11.0).
 *
 * SquidForm ships SimplePdf. A plugin in the "squidform" group can offer a
 * better-looking one (for example one built on TCPDF) by answering the
 * onSquidformPdfRenderer event with an object implementing this interface:
 *
 *     public function onSquidformPdfRenderer(\Joomla\Event\Event $event): void
 *     {
 *         $event->setArgument('result', array_merge((array) $event->getArgument('result', []), [new MyRenderer()]));
 *     }
 *
 * The first renderer offered is used; if it throws, SquidForm falls back to
 * SimplePdf, so a broken add-on never stops a copy or a signed record.
 */
interface PdfRendererInterface
{
    /** A short name for logs and Check this server, e.g. "SquidForm simple PDF". */
    public function name(): string;

    /** @return string  the PDF file's bytes */
    public function render(PdfDocument $doc): string;
}
