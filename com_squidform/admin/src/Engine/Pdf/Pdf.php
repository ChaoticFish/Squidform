<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Pdf;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Event\Event;
use SquidForm\Component\SquidForm\Administrator\Engine\Acl;
use SquidForm\Component\SquidForm\Administrator\Engine\ApprovalFlow;
use SquidForm\Component\SquidForm\Administrator\Engine\Display;
use SquidForm\Component\SquidForm\Administrator\Engine\ESign;
use SquidForm\Component\SquidForm\Administrator\Engine\Field\SignatureField;
use SquidForm\Component\SquidForm\Administrator\Engine\Ref;

\defined('_JEXEC') or die;

/**
 * PDFs of submissions (0.11.0): a staff copy (from the submission's page), a
 * copy for the sender (after sending, and attached to a confirmation email)
 * and the signed record of an e-signature, with its audit trail.
 *
 * The sender's copy leaves out hidden fields and "Hide what's typed"
 * answers, as emails do; IP addresses are never in any PDF.
 */
final class Pdf
{
    private static ?PdfRendererInterface $addon = null;

    private static bool $looked = false;

    /** The add-on renderer if one is installed (onSquidformPdfRenderer), else null. */
    public static function addon(): ?PdfRendererInterface
    {
        if (!self::$looked) {
            self::$looked = true;

            try {
                PluginHelper::importPlugin('squidform');
                $ev = new Event('onSquidformPdfRenderer', ['result' => []]);
                Factory::getApplication()->getDispatcher()->dispatch('onSquidformPdfRenderer', $ev);

                foreach ((array) $ev->getArgument('result', []) as $r) {
                    if ($r instanceof PdfRendererInterface) {
                        self::$addon = $r;
                        break;
                    }
                }
            } catch (\Throwable) {
                // no add-on
            }
        }

        return self::$addon;
    }

    /** Which renderer is in use, for Check this server. */
    public static function rendererName(): string
    {
        return self::addon()?->name() ?? (new SimplePdf())->name();
    }

    public static function bytes(PdfDocument $doc): string
    {
        $doc->paper = ComponentHelper::getParams('com_squidform')->get('pdf_paper', 'letter') === 'a4' ? 'a4' : 'letter';
        $lang       = Factory::getApplication()->getLanguage()->getTag() ?: 'en-US';

        if ($addon = self::addon()) {
            try {
                return $addon->render($doc);
            } catch (\Throwable $e) {
                Log::add('SquidForm PDF add-on "' . $addon->name() . '" failed, using the built-in PDF: ' . $e->getMessage(), Log::WARNING, 'squidform');
            }
        }

        return (new SimplePdf($lang))->render($doc);
    }

    /**
     * A submission as a document.
     *
     * @param object $item      from SubmissionService::load()
     * @param string $audience  "staff" or "sender"
     */
    public static function submission(object $item, string $audience = 'staff'): PdfDocument
    {
        $site   = (string) Factory::getApplication()->get('sitename');
        $ref    = Ref::short((string) $item->uid);
        $doc    = new PdfDocument(trim((string) ($item->form_title ?? '')) . ' — ' . Text::sprintf('COM_SQUIDFORM_VIEWER_SUBMISSION_N', $ref), $site);
        $staff  = $audience === 'staff';
        $reveal = $staff && Acl::can('squidform.view', (int) ($item->form_id ?? 0));

        $doc->pair(Text::_('COM_SQUIDFORM_PDF_REFERENCE'), $ref);
        $doc->pair(Text::_('COM_SQUIDFORM_HEADING_CREATED'), HTMLHelper::_('date', $item->created, Text::_('DATE_FORMAT_LC2')));

        if ($staff) {
            $doc->pair(Text::_('COM_SQUIDFORM_HEADING_STATUS'), ApprovalFlow::statusText((string) $item->status, (string) ($item->current_step ?? ''), $item->schema ?? null));
            $doc->pair(Text::_('COM_SQUIDFORM_HEADING_USER'), $item->user_id ? (string) $item->user_name : Text::_('COM_SQUIDFORM_GUEST'));
            $doc->pair(Text::_('COM_SQUIDFORM_HEADING_VERSION'), (string) (int) ($item->version_no ?? 0));
        }

        $doc->heading(Text::_('COM_SQUIDFORM_PDF_ANSWERS'));
        self::answers($doc, $item, $staff, $reveal);

        foreach (ESign::forSubmission((int) $item->id) as $sig) {
            ESign::addToPdf($doc, $sig, $item);
        }

        return $doc;
    }

    private static function answers(PdfDocument $doc, object $item, bool $staff, bool $reveal): void
    {
        $data = (array) $item->data;

        if (!$item->schema) {
            $doc->text((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return;
        }

        foreach ($item->schema->valueFields() as $field) {
            $key = $field['key'];

            if (!\array_key_exists($key, $data) || (!$staff && self::private($field))) {
                continue;
            }

            $label = (string) ($field['label'] ?? $key);
            $value = $data[$key];

            if ($field['type'] === 'repeater') {
                $rows = array_values((array) $value);

                if (!$rows) {
                    $doc->pair($label, '—');
                    continue;
                }

                $cols = array_values(array_filter($field['fields'], static fn ($c) => $staff || !self::private($c)));
                $head = array_merge(['#'], array_map(static fn ($c) => (string) ($c['label'] ?? $c['key']), $cols));
                $body = [];

                foreach ($rows as $n => $row) {
                    $line = [(string) ($n + 1)];

                    foreach ($cols as $child) {
                        $line[] = Display::value($child, $row[$child['key']] ?? null, '—', $reveal);
                    }

                    $body[] = $line;
                }

                $doc->table($label, $head, $body);
                continue;
            }

            if ($field['type'] === 'signature' && \is_array($value) && ($value['mode'] ?? '') === 'draw') {
                $png = SignatureField::cleanPng((string) ($value['png'] ?? ''));
                $doc->pair($label, Display::value($field, $value, '—', $reveal));

                if ($png !== '') {
                    $doc->image((string) base64_decode(substr($png, 22)), Text::sprintf('COM_SQUIDFORM_PDF_SIG_ALT', $label), 220, 80);
                }

                continue;
            }

            $doc->pair($label, Display::value($field, $value, '—', $reveal));
        }
    }

    /** Left out of the sender's copy: hidden fields and "Hide what's typed" answers. */
    private static function private(array $field): bool
    {
        return Display::isPrivate($field);
    }

    /** A safe file name: "visitor-request-ca9a4d1b.pdf". */
    public static function fileName(object $item, string $suffix = ''): string
    {
        $base = preg_replace('/[^a-z0-9]+/', '-', strtolower((string) ($item->form_alias ?? 'submission'))) ?: 'submission';

        return trim($base, '-') . '-' . Ref::short((string) $item->uid) . ($suffix !== '' ? '-' . $suffix : '') . '.pdf';
    }

    /** Sends PDF bytes to the browser as a download and ends the request. */
    public static function send(string $bytes, string $name): never
    {
        $app = Factory::getApplication();
        $app->setHeader('Content-Type', 'application/pdf', true);
        $app->setHeader('Content-Disposition', 'attachment; filename="' . str_replace('"', '', $name) . '"', true);
        $app->setHeader('X-Content-Type-Options', 'nosniff', true);
        $app->setHeader('Cache-Control', 'no-store, private', true);
        $app->setHeader('Content-Length', (string) \strlen($bytes), true);
        $app->sendHeaders();
        echo $bytes;
        $app->close();
    }
}
