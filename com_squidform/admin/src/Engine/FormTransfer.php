<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Copying forms (0.9.1): duplicate on this site, or export to a JSON file
 * and import it on another.
 *
 * What travels is the form itself: title, description and its live
 * definition (fields, logic, approvals, viewer, settings). Never
 * submissions, files or permissions, which belong to each site. A copy or
 * an import is always a new form, unpublished, with an alias of its own;
 * nothing that exists is overwritten.
 */
final class FormTransfer
{
    public const FORMAT = 'squidform-forms';

    private static function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    /** @return list<object{id: int, title: string, alias: string, description: ?string, schema_json: string}> */
    private static function rows(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if (!$ids) {
            return [];
        }

        $db = self::db();
        $q  = $db->createQuery()
            ->select(['f.id', 'f.title', 'f.alias', 'f.description', 'v.schema_json'])
            ->from($db->quoteName('#__squidform_forms', 'f'))
            ->join('INNER', $db->quoteName('#__squidform_form_versions', 'v'), 'v.id = f.current_version_id')
            ->whereIn('f.id', $ids)
            ->order('f.title');

        return $db->setQuery($q)->loadObjectList();
    }

    /** The export file's content. */
    public static function export(array $ids): array
    {
        $out = [];

        foreach (self::rows($ids) as $r) {
            $out[] = [
                'title'       => (string) $r->title,
                'alias'       => (string) $r->alias,
                'description' => (string) ($r->description ?? ''),
                'definition'  => json_decode((string) $r->schema_json, true),
            ];
        }

        return [
            'format'   => self::FORMAT,
            'version'  => 1,
            'squidform' => self::squidformVersion(),
            'exported' => gmdate('Y-m-d\TH:i:s\Z'),
            'site'     => (string) Factory::getApplication()->get('sitename'),
            'forms'    => $out,
        ];
    }

    private static function squidformVersion(): string
    {
        $file = JPATH_ADMINISTRATOR . '/components/com_squidform/squidform.xml';
        $xml  = is_file($file) ? @simplexml_load_file($file) : false;

        return $xml ? (string) $xml->version : '';
    }

    /**
     * Copies of these forms on this site.
     *
     * @return list<array{title: string, alias: string, ok: bool, message: string}>
     */
    public static function duplicate(array $ids): array
    {
        $report = [];

        foreach (self::rows($ids) as $r) {
            $report[] = self::create((string) $r->title . ' (copy)', (string) $r->alias . '-copy', (string) ($r->description ?? ''), (string) $r->schema_json, 'Copy of ' . $r->alias);
        }

        return $report;
    }

    /**
     * Forms from an export file.
     *
     * @return list<array{title: string, alias: string, ok: bool, message: string, warnings?: list<string>}>
     */
    public static function import(string $json): array
    {
        $data = json_decode($json, true);

        if (!\is_array($data) || ($data['format'] ?? '') !== self::FORMAT || !\is_array($data['forms'] ?? null)) {
            throw new \RuntimeException('This isn’t a SquidForm forms file (exported from the Forms list).');
        }

        $report = [];

        foreach ($data['forms'] as $f) {
            if (!\is_array($f) || !\is_array($f['definition'] ?? null)) {
                $report[] = ['title' => (string) ($f['title'] ?? '?'), 'alias' => '', 'ok' => false, 'message' => 'no definition in the file'];
                continue;
            }

            $def  = json_encode($f['definition'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $line = self::create(mb_substr((string) ($f['title'] ?? 'Imported form'), 0, 255), (string) ($f['alias'] ?? ''), (string) ($f['description'] ?? ''), (string) $def,
                'Imported' . (!empty($data['site']) ? ' from ' . mb_substr((string) $data['site'], 0, 100) : ''));

            if ($line['ok']) {
                $line['warnings'] = self::missing($f['definition']);
            }

            $report[] = $line;
        }

        return $report;
    }

    /** A new, unpublished form. */
    public static function create(string $title, string $alias, string $description, string $json, string $note): array
    {
        $line = ['title' => $title, 'alias' => '', 'ok' => false, 'message' => ''];

        try {
            Schema::fromJson($json);
        } catch (SchemaException $e) {
            $line['message'] = implode(' ', \array_slice($e->getErrors(), 0, 3));

            return $line;
        } catch (\Throwable $e) {
            $line['message'] = $e->getMessage();

            return $line;
        }

        /** @var \SquidForm\Component\SquidForm\Administrator\Model\FormModel $model */
        $model = Factory::getApplication()->bootComponent('com_squidform')->getMVCFactory()
            ->createModel('Form', 'Administrator', ['ignore_request' => true]);
        $ok    = $model->save([
            'id'          => 0,
            'title'       => $title,
            'alias'       => $alias,
            'description' => $description,
            'state'       => 0,
            'access'      => 1,
            'schema_json' => $json,
            'version_note' => $note,
        ]);

        if (!$ok) {
            $line['message'] = (string) $model->getError();

            return $line;
        }

        $id = (int) $model->getState('form.id');
        $line['ok']    = true;
        $line['id']    = $id;
        $line['alias'] = (string) self::db()->setQuery(
            self::db()->createQuery()->select('alias')->from('#__squidform_forms')->where('id = :id')->bind(':id', $id, ParameterType::INTEGER)
        )->loadResult();

        return $line;
    }

    /**
     * What an imported form uses that isn't on this site: tables not on the
     * read allow-list, option files not in the folder.
     *
     * @return list<string>
     */
    public static function missing(array $def): array
    {
        $out  = [];
        $walk = static function (array $fields) use (&$walk, &$out): void {
            foreach ($fields as $f) {
                if (!\is_array($f)) {
                    continue;
                }

                $label = (string) ($f['label'] ?? $f['key'] ?? '?');

                if (isset($f['options']['table']['name'])) {
                    try {
                        Options::allowedTable((string) $f['options']['table']['name'], 'read');
                    } catch (\Throwable) {
                        $out[] = $label . ': the table ' . $f['options']['table']['name'] . ' isn’t on this site’s read allow-list (Options → Data Sources).';
                    }
                }

                if (isset($f['options']['file']['path'])) {
                    try {
                        Options::optionsFile((string) $f['options']['file']['path']);
                    } catch (\Throwable) {
                        $out[] = $label . ': the option file ' . $f['options']['file']['path'] . ' isn’t in this site’s option files folder.';
                    }
                }

                if (\is_array($f['fields'] ?? null)) {
                    $walk($f['fields']);
                }
            }
        };

        foreach ((array) ($def['pages'] ?? []) as $p) {
            $walk((array) ($p['fields'] ?? []));
        }

        return $out;
    }
}
