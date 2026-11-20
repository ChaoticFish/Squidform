<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * What the visual builder can offer on this site (0.7.0): field and action
 * types (plugins' types too), user groups, custom user fields for "Fill from
 * signed-in user", option files, allow-listed tables and their columns,
 * captcha plugins and the starter forms. Read once when the edit screen opens.
 */
final class BuilderCatalog
{
    /** @return array<string, mixed> */
    public static function build(int $formId = 0): array
    {
        return [
            // E-signatures (0.11.0): may this editor add or change one here, and what it is (and isn't).
            'esign'       => ['allowed' => ESign::mayChange($formId), 'disclaimer' => ESign::disclaimer(), 'statement' => \Joomla\CMS\Language\Text::_('COM_SQUIDFORM_ESIGN_STATEMENT_DEFAULT')],
            'fieldTypes'  => array_values(array_diff(array_keys(FieldRegistry::all()), ['section_end'])),
            'actionTypes' => array_keys(ActionRegistry::all()),
            'groups'      => self::groups(),
            'userFields'  => self::userFields(),
            'optionFiles' => self::optionFiles(),
            'optionsFolder' => trim((string) ComponentHelper::getParams('com_squidform')->get('options_folder', 'images/squidform/options'), '/'),
            'tables'      => self::tables(),
            'htmlTags'    => Html::TAGS,
            'htmlAttributes' => Html::ATTRIBUTES,
            'countries'   => Places::COUNTRIES,
            'dial'        => Places::DIAL,
            'uploadTypes' => Uploads::settings()['types'],
            'uploadMaxMb' => Uploads::settings()['max_mb'],
            'scanMode'    => Scanner::config()['mode'],
            'captchas'    => self::captchas(),
            'starters'    => self::starters(),
            'handlers'    => Handlers::catalog(),
            'handlerFolder' => Handlers::folder(),
            // 1.0.0: files in the handler folder that didn't load, and why (also in Check this server).
            'handlerProblems' => (object) Handlers::problems(),
            'siteRoot'    => rtrim(\Joomla\CMS\Uri\Uri::root(true), '/') . '/',
            // 0.13.1: how add-on field types and actions show in the builder.
            'addonFields'  => self::addonInfo(FieldRegistry::all()),
            'addonActions' => self::addonInfo(ActionRegistry::all()),
            // 0.13.2: address formats from add-ons (onSquidformAddressFormats).
            'addressFormats' => array_map(static fn ($f) => $f['name'], AddressFormats::all()),
            'defaultLook' => Looks::siteDefault(),
            // 0.15.0: session data (Options → Data Sources → Session data forms may read) and what's in the admin's own session.
            'sessionKeys'  => SessionData::allowed(),
            'sessionPaths' => SessionData::paths(),
            // 0.15.0: how many test submissions this form has (Settings → Test mode).
            'testCount' => $formId ? (int) SubmissionStore::db()->setQuery('SELECT COUNT(*) FROM ' . SubmissionStore::db()->quoteName('#__squidform_submissions') . ' WHERE form_id = ' . (int) $formId . ' AND is_test = 1')->loadResult() : 0,
            'canPurge'  => $formId && Acl::can('squidform.delete.hard', $formId),
            // 0.15.0: languages: what the site offers, and what the form's words are written in.
            'languages' => array_map(static fn ($t) => ['tag' => $t, 'name' => Lang::packs()[$t][0], 'own' => Lang::packs()[$t][1], 'joke' => Lang::packs()[$t][3]], Lang::site()),
            'langBase'  => Lang::base(),
            'langText'  => ['settings' => Lang::SETTINGS, 'field' => Lang::FIELD_TEXT, 'lists' => Lang::FIELD_LISTS],
            // 1.0.3: forms that share fields as choices, and add-ons' choice lists.
            'sharedForms'   => self::sharedForms($formId),
            'optionSources' => array_values(array_map(static fn ($n, $s) => ['name' => $n, 'title' => $s['title']], array_keys(Addons::optionSources()), Addons::optionSources())),
            // 0.14.0: add-on placeholders, for the Placeholders tab.
            'addonPlaceholders' => array_values(array_map(static fn ($ns, $p) => ['ns' => $ns, 'title' => $p['title'], 'items' => (object) $p['items']], array_keys(Placeholders::addons()), Placeholders::addons())),
        ];
    }

    /**
     * Forms whose Settings → Share as choices lists some fields (1.0.3).
     *
     * @return list<array{alias: string, title: string, fields: list<array{key: string, label: string}>}>
     */
    private static function sharedForms(int $formId): array
    {
        $db   = SubmissionStore::db();
        $rows = $db->setQuery(
            $db->createQuery()->select(['f.id', 'f.title', 'f.alias', 'v.schema_json'])
                ->from($db->quoteName('#__squidform_forms', 'f'))
                ->join('INNER', $db->quoteName('#__squidform_form_versions', 'v'), 'v.id = f.current_version_id')
                ->where('f.state IN (0, 1)')
                ->where($db->quoteName('v.schema_json') . ' LIKE ' . $db->quote('%shared_fields%'))
                ->order('f.title')
        )->loadObjectList();
        $out = [];

        foreach ($rows as $r) {
            try {
                $schema = Schema::fromJson((string) $r->schema_json);
            } catch (\Throwable) {
                continue;
            }

            $fields = [];

            foreach ((array) $schema->setting('shared_fields', []) as $k) {
                $f = $schema->field((string) $k);

                if ($f !== null) {
                    $fields[] = ['key' => (string) $k, 'label' => (string) ($f['label'] ?? $k)];
                }
            }

            if ($fields) {
                $out[] = ['alias' => (string) $r->alias, 'title' => (string) $r->title . ((int) $r->id === $formId ? ' (this form)' : ''), 'fields' => $fields];
            }
        }

        return $out;
    }

    /** @return array<string, array{label: string, icon: string, group: string, defaults: array<string, mixed>}> */
    private static function addonInfo(array $types): array
    {
        $out = [];

        foreach ($types as $name => $impl) {
            if (!$impl instanceof BuilderInfoInterface) {
                continue;
            }

            try {
                $i = $impl->builderInfo();
            } catch (\Throwable) {
                continue;
            }

            $out[(string) $name] = [
                'label'    => mb_substr((string) ($i['label'] ?? $name), 0, 80),
                'icon'     => mb_substr((string) ($i['icon'] ?? '•'), 0, 4),
                'group'    => mb_substr((string) ($i['group'] ?? 'Add-ons'), 0, 40),
                'defaults' => \is_array($i['defaults'] ?? null) ? $i['defaults'] : [],
            ];
        }

        return $out;
    }

    /** @return list<array{id: int, title: string, level: int}> */
    private static function groups(): array
    {
        $db   = Factory::getContainer()->get(DatabaseInterface::class);
        $rows = $db->setQuery(
            'SELECT a.id, a.title, COUNT(DISTINCT b.id) AS level FROM ' . $db->quoteName('#__usergroups', 'a')
            . ' LEFT JOIN ' . $db->quoteName('#__usergroups', 'b') . ' ON a.lft > b.lft AND a.rgt < b.rgt'
            . ' GROUP BY a.id, a.title, a.lft ORDER BY a.lft'
        )->loadObjectList();

        return array_map(static fn ($r) => ['id' => (int) $r->id, 'title' => (string) $r->title, 'level' => (int) $r->level], $rows ?: []);
    }

    /**
     * Joomla custom fields for users: {user.field:NAME}, conditions and (for
     * List, Radio and Checkboxes fields) choice lists. Only the field's own
     * choices go to the builder, never anyone's answer.
     *
     * @return list<array{name: string, title: string, type: string, options: list<array{value: string, label: string}>}>
     */
    private static function userFields(): array
    {
        return array_values(array_map(static fn ($d) => ['name' => $d->name, 'title' => $d->title, 'type' => $d->type, 'options' => $d->options], UserFacts::definitions()));
    }

    /** @return string[] paths relative to the options folder */
    private static function optionFiles(): array
    {
        $folder = trim((string) ComponentHelper::getParams('com_squidform')->get('options_folder', 'images/squidform/options'), '/');
        $base   = realpath(JPATH_ROOT . '/' . $folder);

        if ($base === false) {
            return [];
        }

        $out = [];
        $it  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $f) {
            if ($f->isFile() && \in_array(strtolower($f->getExtension()), ['json', 'csv', 'txt'], true) && \count($out) < 500) {
                $out[] = str_replace('\\', '/', substr($f->getPathname(), \strlen($base) + 1));
            }
        }

        sort($out);

        return $out;
    }

    /** Tables on the read allow-list, with their columns. @return list<array{name: string, columns: string[]}> */
    private static function tables(): array
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $names = preg_split('/[\s,]+/', (string) ComponentHelper::getParams('com_squidform')->get('read_tables', ''), -1, PREG_SPLIT_NO_EMPTY);
        $out   = [];

        foreach (\array_slice($names, 0, 100) as $n) {
            try {
                $name = Options::allowedTable($n, 'read');
                $cols = array_keys($db->getTableColumns($name));
            } catch (\Throwable) {
                continue;
            }

            $out[] = ['name' => $name, 'columns' => $cols];
        }

        return $out;
    }

    /** @return list<array{element: string, name: string}> */
    private static function captchas(): array
    {
        $out = [];

        foreach (PluginHelper::getPlugin('captcha') as $p) {
            $out[] = ['element' => (string) $p->name, 'name' => (string) $p->name];
        }

        return $out;
    }

    /**
     * Starter forms for a new form: the bundled examples.
     *
     * @return list<array{id: string, json: string}>
     */
    private static function starters(): array
    {
        $dir = JPATH_ADMINISTRATOR . '/components/com_squidform/examples';
        $out = [];

        foreach (['blank', 'contact', 'starter', 'approval', 'event', 'order', 'volunteer', 'agreement', 'report', 'survey', 'timeoff', 'membership', 'showcase'] as $id) {
            $file = $dir . '/' . $id . '.json';

            if (is_file($file)) {
                $out[] = ['id' => $id, 'json' => (string) file_get_contents($file)];
            }
        }

        // 1.0.3: starter forms an add-on offers (onSquidformStarters), after the built-in ones.
        foreach (Addons::starters() as $s) {
            $out[] = $s;
        }

        return $out;
    }
}
