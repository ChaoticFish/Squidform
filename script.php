<?php

/**
 * @package     SquidForm
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Filesystem\Folder;

/**
 * Package install script:
 *  - refuses to install on Joomla older than 5.4, PHP older than 8.2, or a
 *    database other than MySQL/MariaDB (and PHP < 8.3 on Joomla 6)
 *  - enables the shortcode plugin
 *  - copies example option files to images/squidform/options (never overwrites)
 */
return new class () implements InstallerScriptInterface {
    private const MIN_JOOMLA = '5.4.0';
    private const MIN_PHP    = '8.2.0';
    private const MIN_PHP_J6 = '8.3.0';

    /** Package version before this update ('' on a fresh install). */
    private string $oldVersion = '';

    public function install(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function update(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function uninstall(InstallerAdapter $adapter): bool
    {
        Factory::getApplication()->enqueueMessage(
            'SquidForm was removed, but all of its data was kept: the #__squidform_* tables and every form\'s _sfd_ / _sfa_ tables are still in the database. '
            . 'Installing SquidForm again picks them up. To remove the data for good, drop those tables (for example in phpMyAdmin).',
            'notice'
        );

        return true;
    }

    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type === 'uninstall') {
            return true;
        }

        $app = Factory::getApplication();

        if (version_compare(JVERSION, self::MIN_JOOMLA, '<')) {
            $app->enqueueMessage('SquidForm needs Joomla ' . self::MIN_JOOMLA . ' or later (this site: ' . JVERSION . ').', 'error');

            return false;
        }

        $minPhp = version_compare(JVERSION, '6.0.0', '>=') ? self::MIN_PHP_J6 : self::MIN_PHP;

        if (version_compare(PHP_VERSION, $minPhp, '<')) {
            $app->enqueueMessage('SquidForm needs PHP ' . $minPhp . ' or later on this Joomla version (this server: ' . PHP_VERSION . ').', 'error');

            return false;
        }

        $db     = Factory::getContainer()->get(DatabaseInterface::class);
        $server = $db->getServerType();

        if ($type === 'update') {
            try {
                $cache = (string) $db->setQuery(
                    $db->createQuery()->select('manifest_cache')->from($db->quoteName('#__extensions'))
                        ->where($db->quoteName('type') . ' = ' . $db->quote('package'))
                        ->where($db->quoteName('element') . ' = ' . $db->quote('pkg_squidform'))
                )->loadResult();
                $this->oldVersion = (string) (json_decode($cache, true)['version'] ?? '');
            } catch (\Throwable) {
                $this->oldVersion = '';
            }
        }

        if ($server !== 'mysql') {
            $app->enqueueMessage('SquidForm supports MySQL and MariaDB only (this site uses ' . $server . ').', 'error');

            return false;
        }

        return true;
    }

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type === 'uninstall') {
            return true;
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        // Safety net: Joomla treats a component as an *update* (and skips the
        // install SQL) if its folders already exist, e.g. after a half-finished
        // uninstall or a copied site. Make sure every table exists; the SQL only
        // uses CREATE TABLE IF NOT EXISTS, so running it again is harmless.
        // 1.0.2: run every time, so a table added in a later version (such as
        // #__squidform_counters) exists even when Joomla skipped its update SQL.
        $have    = array_map('strtolower', $db->getTableList());
        $missing = !\in_array(strtolower($db->getPrefix() . 'squidform_forms'), $have, true);
        $file    = JPATH_ADMINISTRATOR . '/components/com_squidform/sql/install.mysql.utf8.sql';

        if (is_file($file)) {
            foreach ($db->splitSql((string) file_get_contents($file)) as $query) {
                if (trim($query) !== '' && preg_match('/^\s*(--[^\n]*\n\s*)*CREATE TABLE IF NOT EXISTS/i', $query)) {
                    $db->setQuery($query)->execute();
                }
            }

            if ($missing) {
                Factory::getApplication()->enqueueMessage('SquidForm: database tables were missing and have been created.', 'notice');
            }
        }

        // Tables kept from an older version (uninstall keeps the data) don't get
        // new columns from CREATE TABLE IF NOT EXISTS; add what's missing (0.9.0).
        $this->repairColumns($db);

        // The PHP handler folder (0.10.0), outside the component so updates
        // never touch it, with a deny-all and the example. Nothing in it runs
        // until someone renames the example or adds a handler of their own.
        $this->handlerFolder();

        // Per-form permissions (0.3.0): every form needs an asset row under the
        // component's asset. Forms created before 0.3.0 get one with no rules
        // of their own, so they keep inheriting the component settings.
        try {
            $this->createFormAssets($db);
        } catch (\Throwable $e) {
            Factory::getApplication()->enqueueMessage('SquidForm: could not create per-form permission records: ' . $e->getMessage(), 'warning');
        }

        // 0.6.1: Registered may view and edit their own submissions by default
        // (set on the component, so every form inherits it). Done once: on a
        // fresh install, or an update from before 0.6.1.
        if ($type === 'install' || ($this->oldVersion !== '' && version_compare($this->oldVersion, '0.6.1', '<'))) {
            try {
                $this->defaultOwnRules($db);
            } catch (\Throwable $e) {
                Factory::getApplication()->enqueueMessage('SquidForm: could not set the default View Own / Edit Own permissions: ' . $e->getMessage(), 'warning');
            }
        }

        // 0.4.0: move answers into each form's own tables (once).
        try {
            $this->migrateTo040($db);
        } catch (\Throwable $e) {
            Factory::getApplication()->enqueueMessage('SquidForm: moving answers into per-form tables did not finish: ' . $e->getMessage()
                . ' Nothing was removed; the old columns are kept. Run the update again after fixing the cause.', 'error');
        }

        // Enable the shortcode plugin on first install.
        if ($type === 'install') {
            $q = $db->createQuery()
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('squidform'));
            $db->setQuery($q)->execute();
        }

        // Example option files for the starter form (kept if they already exist).
        $src  = JPATH_ADMINISTRATOR . '/components/com_squidform/examples/options';
        $dest = JPATH_ROOT . '/images/squidform/options';

        if (is_dir($src)) {
            if (!is_dir($dest)) {
                Folder::create($dest);
            }

            foreach (['sites.json', 'buildings.csv', 'README.txt'] as $f) {
                if (!is_file($dest . '/' . $f) && is_file($src . '/' . $f)) {
                    copy($src . '/' . $f, $dest . '/' . $f);
                }
            }
        }

        Factory::getApplication()->enqueueMessage(
            $type === 'update'
                ? 'SquidForm updated. Your forms and submissions are unchanged.'
                : 'SquidForm installed. Go to Components → SquidForm → Forms to create your first form.',
            'message'
        );

        return true;
    }
    /**
     * Give Joomla's Registered group (id 2) View Own and Edit Own on the
     * component, unless the site already has a setting for that group.
     * Public gets nothing. Each form inherits it and can override it.
     */
    /**
     * Columns added after a table was first created. Each is added only when
     * it's missing, so this is safe on every install and update.
     */
    private function handlerFolder(): void
    {
        try {
            $params = \Joomla\CMS\Component\ComponentHelper::getParams('com_squidform');
            $folder = trim((string) $params->get('handler_folder', 'administrator/squidform-handlers')) ?: 'administrator/squidform-handlers';
            $dir    = preg_match('#^(/|[A-Za-z]:[\\\\/])#', $folder) ? $folder : JPATH_ROOT . '/' . ltrim($folder, '/');

            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                return;
            }

            $files = [
                '.htaccess'  => "# SquidForm handlers: never served directly.\nRequire all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
                'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization>"
                    . "<security><authorization><add accessType=\"Deny\" users=\"*\" /></authorization></security></system.webServer></configuration>\n",
                'index.html' => "<!DOCTYPE html><title></title>\n",
            ];

            foreach ($files as $name => $body) {
                if (!is_file($dir . '/' . $name)) {
                    @file_put_contents($dir . '/' . $name, $body);
                }
            }

            // The example for writing your own (TicketNumber). It's .txt, so nothing
            // runs until someone renames it to .php.
            foreach (glob(JPATH_ADMINISTRATOR . '/components/com_squidform/examples/handlers/*-example.txt') ?: [] as $example) {
                @copy($example, $dir . '/' . basename($example));
            }

            // 1.0.2: the starter handlers are built in (Options → Developers), so the
            // .txt copies 0.15.0–1.0.1 put here are removed, but only when they're
            // exactly as shipped. Anything someone changed or renamed stays.
            $shipped = [
                '03b9e1bf1f0e229e402ea58767909690f7c9bebfe3fcec09a45f153ae40949fe', '19b90427d9b5b9b337c853616614b2be0126dd39440e5973a72fce1eb4057855',
                '349744bb9d8cb0732920fde542ffc78ff754626ab27acf6f1aa206d636c9c35b', '3b7c5bcf73a4c59a040df01119eba7c9d8022be3a3e1fb988509749e4c22ac0d',
                '8e076c0ff0182de8c02bd98e4b12bc7954a2dd58ced2e49b26f05bc0edc13968', 'd19402d1ae446ee45a0934ea6a1d89c4e7d2a79b9eee66bbcaf4275157c64064',
                'e74a03883b7dc6b29129e1e036efa656eb856e738e779e80ac113df3db76d33a', 'f25bb4399d8a649eb692a575ecb80703b4d9350c9ee34e2edf6e22340cedafb9',
            ];

            foreach (['BlockedList', 'LookUpPerson', 'NextNumber', 'TakeTurns', 'TidyText', 'WorkingDays', 'WriteFile'] as $old) {
                $f = $dir . '/' . $old . '-example.txt';

                if (is_file($f) && \in_array(hash_file('sha256', $f), $shipped, true)) {
                    @unlink($f);
                }
            }
        } catch (\Throwable) {
            // Check this server says what's missing.
        }
    }

    private function repairColumns(DatabaseInterface $db): void
    {
        $add = [
            '#__squidform_submission_files' => [
                'uid'        => "char(32) NOT NULL DEFAULT '' AFTER `id`",
                'form_id'    => 'int unsigned NOT NULL DEFAULT 0 AFTER `uid`',
                'scan'       => "varchar(16) NOT NULL DEFAULT 'not_scanned' AFTER `path`",
                'owner'      => "char(64) NOT NULL DEFAULT '' AFTER `scan`",
                'created_by' => 'int unsigned NOT NULL DEFAULT 0 AFTER `created`',
            ],
            // 0.15.0: added only here (0.15.0.sql leaves them to this, so a rerun can't fail).
            '#__squidform_submissions' => [
                'is_test'  => 'tinyint NOT NULL DEFAULT 0 AFTER `archived`',
                'language' => "varchar(16) NOT NULL DEFAULT '' AFTER `is_test`",
            ],
        ];

        foreach ($add as $table => $cols) {
            try {
                $have = array_map('strtolower', array_keys($db->getTableColumns($table, false)));
            } catch (\Throwable) {
                continue;
            }

            $did = false;

            foreach ($cols as $name => $ddl) {
                if (!\in_array($name, $have, true)) {
                    $db->setQuery('ALTER TABLE ' . $db->quoteName($table) . ' ADD COLUMN ' . $db->quoteName($name) . ' ' . $ddl)->execute();
                    $did = true;
                }
            }

            if ($table === '#__squidform_submission_files' && $did) {
                $db->setQuery('ALTER TABLE ' . $db->quoteName($table) . ' MODIFY ' . $db->quoteName('submission_id') . ' int unsigned NOT NULL DEFAULT 0')->execute();
                $db->setQuery('UPDATE ' . $db->quoteName($table) . " SET `uid` = MD5(CONCAT('sf', `id`, `sha256`)) WHERE `uid` = ''")->execute();
                $keys = array_map(static fn ($k) => $k->Key_name, $db->setQuery('SHOW INDEX FROM ' . $db->quoteName($table))->loadObjectList());

                if (!\in_array('idx_uid', $keys, true)) {
                    $db->setQuery('ALTER TABLE ' . $db->quoteName($table) . ' ADD UNIQUE KEY `idx_uid` (`uid`)')->execute();
                }

                if (!\in_array('idx_pending', $keys, true)) {
                    $db->setQuery('ALTER TABLE ' . $db->quoteName($table) . ' ADD KEY `idx_pending` (`submission_id`, `created`)')->execute();
                }
            }
        }
    }

    private function defaultOwnRules(DatabaseInterface $db): void
    {
        $registered = (int) $db->setQuery(
            $db->createQuery()->select('id')->from($db->quoteName('#__usergroups'))->where('id = 2')
        )->loadResult();
        $asset = $db->setQuery(
            $db->createQuery()->select(['id', 'rules'])->from($db->quoteName('#__assets'))->where($db->quoteName('name') . ' = ' . $db->quote('com_squidform'))
        )->loadObject();

        if ($registered !== 2 || !$asset) {
            return;
        }

        $rules   = json_decode((string) $asset->rules, true);
        $rules   = \is_array($rules) ? $rules : [];
        $changed = [];

        foreach (['squidform.view.own', 'squidform.edit.own'] as $action) {
            $rules[$action] = \is_array($rules[$action] ?? null) ? $rules[$action] : [];

            if (!\array_key_exists('2', $rules[$action]) && !\array_key_exists(2, $rules[$action])) {
                $rules[$action]['2'] = 1;
                $changed[] = $action;
            }
        }

        if ($changed === []) {
            return;
        }

        $q = $db->createQuery()->update($db->quoteName('#__assets'))
            ->set($db->quoteName('rules') . ' = ' . $db->quote(json_encode($rules)))
            ->where('id = ' . (int) $asset->id);
        $db->setQuery($q)->execute();

        Factory::getApplication()->enqueueMessage(
            'SquidForm: Registered users may now view and edit their own submissions on every form (Options → Permissions). A form can change this on its own Permissions tab.',
            'message'
        );
    }

    private function createFormAssets(DatabaseInterface $db): void
    {
        $parent = (int) $db->setQuery(
            $db->createQuery()->select('id')->from($db->quoteName('#__assets'))->where($db->quoteName('name') . ' = ' . $db->quote('com_squidform'))
        )->loadResult();

        if (!$parent) {
            return;
        }

        // Forms with no asset, or with a misplaced one (a very early build could
        // create "#__squidform_forms.<id>" under the root asset).
        $forms = $db->setQuery(
            $db->createQuery()->select(['f.id', 'f.title', 'f.asset_id', $db->quoteName('a.name', 'asset_name'), $db->quoteName('a.parent_id', 'asset_parent')])
                ->from($db->quoteName('#__squidform_forms', 'f'))
                ->join('LEFT', $db->quoteName('#__assets', 'a'), 'a.id = f.asset_id')
        )->loadObjectList();

        foreach ($forms as $f) {
            $asset = new \Joomla\CMS\Table\Asset($db);
            $name  = 'com_squidform.form.' . (int) $f->id;

            if ((int) $f->asset_id > 0 && $f->asset_name === $name && (int) $f->asset_parent === $parent) {
                continue; // already right
            }

            if ((int) $f->asset_id > 0 && $f->asset_name !== null && $f->asset_name !== $name && $asset->load((int) $f->asset_id)) {
                // Repair the misplaced row in place (keeps any rules on it).
                $asset->setLocation($parent, 'last-child');
                $asset->name  = $name;
                $asset->title = (string) $f->title;

                if (!$asset->check() || !$asset->store()) {
                    continue;
                }
            } elseif ($asset->loadByName($name)) {
                // Right name, wrong place (for example after a reinstall): move it under SquidForm.
                if ((int) $asset->parent_id !== $parent) {
                    $asset->setLocation($parent, 'last-child');

                    if (!$asset->check() || !$asset->store()) {
                        continue;
                    }
                }
            } else {
                $asset->reset();
                $asset->id = null;
                $asset->setLocation($parent, 'last-child');
                $asset->name  = $name;
                $asset->title = (string) $f->title;
                $asset->rules = '{}';

                if (!$asset->check() || !$asset->store()) {
                    continue;
                }
            }

            $db->setQuery(
                $db->createQuery()->update($db->quoteName('#__squidform_forms'))
                    ->set('asset_id = ' . (int) $asset->id)->where('id = ' . (int) $f->id)
            )->execute();
        }
    }

    /**
     * 0.4.0 storage change. Answers move from #__squidform_submissions.data_json
     * (and the derived #__squidform_submission_values) into each form's own
     * tables, and edit history moves from the shared audit into each form's
     * __history table. The old column and table are dropped only after every
     * submission has been copied and counted; otherwise they are kept.
     */
    private function migrateTo040(DatabaseInterface $db): void
    {
        $cols = $db->getTableColumns('#__squidform_submissions', false);

        if (!isset($cols['data_json'])) {
            return; // new install, or already migrated
        }

        \JLoader::registerNamespace('SquidForm\\Component\\SquidForm\\Administrator', JPATH_ADMINISTRATOR . '/components/com_squidform/src');

        $FormData = \SquidForm\Component\SquidForm\Administrator\Engine\FormData::class;
        $Schema   = \SquidForm\Component\SquidForm\Administrator\Engine\Schema::class;
        $app      = Factory::getApplication();

        // 0. Submissions whose form was deleted before 0.4.0 (they could be, then)
        //    but whose definition versions still exist: give them an archived
        //    "Recovered form" so their answers get tables and can be reviewed,
        //    restored or permanently deleted like any other archived form.
        $orphans = $db->setQuery(
            'SELECT DISTINCT s.form_id FROM ' . $db->quoteName('#__squidform_submissions') . ' s'
            . ' LEFT JOIN ' . $db->quoteName('#__squidform_forms') . ' f ON f.id = s.form_id'
            . ' WHERE f.id IS NULL'
        )->loadColumn();

        foreach ($orphans as $oid) {
            $oid    = (int) $oid;
            $latest = (int) $db->setQuery(
                'SELECT id FROM ' . $db->quoteName('#__squidform_form_versions') . ' WHERE form_id = ' . $oid . ' ORDER BY version_no DESC LIMIT 1'
            )->loadResult();

            if (!$latest) {
                continue; // no definition left: those answers stay in the kept old column
            }

            $now = Factory::getDate()->toSql();
            $db->setQuery(
                'INSERT INTO ' . $db->quoteName('#__squidform_forms')
                . ' (id, asset_id, title, alias, description, state, access, current_version_id, created, created_by, modified, modified_by)'
                . ' VALUES (' . $oid . ', 0, ' . $db->quote('Recovered form #' . $oid) . ', ' . $db->quote('recovered-form-' . $oid) . ', '
                . $db->quote('Created by the 0.4.0 update for submissions whose form had been deleted.') . ', 2, 1, ' . $latest . ', '
                . $db->quote($now) . ', 0, ' . $db->quote($now) . ', 0)'
            )->execute();
        }

        if ($orphans) {
            $this->createFormAssets($db);
        }

        // 1. Tables and columns for every version of every form (oldest first).
        $forms    = $db->setQuery($db->createQuery()->select(['id', 'alias', 'state', 'current_version_id'])->from($db->quoteName('#__squidform_forms')))->loadObjectList('id');
        $versions = [];
        $live     = [];

        foreach ($db->setQuery(
            $db->createQuery()->select(['id', 'form_id', 'schema_json'])->from($db->quoteName('#__squidform_form_versions'))->order('form_id, version_no')
        )->loadObjectList() as $v) {
            try {
                $schema = $Schema::fromJson((string) $v->schema_json);
            } catch (\Throwable) {
                continue;
            }

            $versions[(int) $v->id] = $schema;

            if (isset($forms[$v->form_id])) {
                $FormData::forForm((int) $v->form_id)->ensure($schema, (string) $forms[$v->form_id]->alias);

                if ((int) $forms[$v->form_id]->current_version_id === (int) $v->id) {
                    $live[(int) $v->form_id] = $schema;
                }
            }
        }

        foreach ($live as $formId => $schema) {
            $FormData::forForm($formId)->markRetired($schema);
        }

        // 2. Copy every submission's answers.
        $lastId   = 0;
        $copied   = 0;
        $unmapped = 0;

        do {
            $rows = $db->setQuery(
                $db->createQuery()->select(['id', 'form_id', 'version_id', 'data_json'])->from($db->quoteName('#__squidform_submissions'))
                    ->where('id > ' . $lastId)->order('id'),
                0,
                500
            )->loadObjectList();

            foreach ($rows as $r) {
                $lastId = (int) $r->id;
                $schema = $versions[(int) $r->version_id] ?? ($live[(int) $r->form_id] ?? null);
                $data   = json_decode((string) $r->data_json, true);

                if (!$schema || !isset($forms[$r->form_id]) || !\is_array($data)) {
                    $unmapped++;
                    continue;
                }

                $known = [];

                foreach ($schema->valueFields() as $f) {
                    $known[$f['key']] = true;
                }

                if (array_diff_key($data, $known)) {
                    $unmapped++;
                }

                $FormData::forForm((int) $r->form_id)->write((int) $r->id, $schema, $data);
                $copied++;
            }
        } while ($rows);

        // 3. Edit history (old → new values) moves into each form's own table.
        foreach ($db->setQuery(
            $db->createQuery()->select('*')->from($db->quoteName('#__squidform_audit'))->where('action = ' . $db->quote('edit'))->order('id')
        )->loadObjectList() as $a) {
            $fd = $FormData::forForm((int) $a->form_id);

            if ($fd->historyTable()) {
                $fd->historyAdd((int) $a->submission_id, (int) $a->user_id, 'edit', json_decode((string) $a->diff_json, true), (string) $a->created);
                $db->setQuery($db->createQuery()->delete($db->quoteName('#__squidform_audit'))->where('id = ' . (int) $a->id))->execute();
            }
        }

        // 4. Trashed forms (before 0.4.0) are archived forms now, and so are the
        //    recovered ones: their tables are renamed sfd_ → sfa_.
        foreach ($forms as $formId => $f) {
            if ((int) $f->state === -2 || (int) $f->state === 2) {
                $FormData::forForm((int) $formId)->rename(true);
                $db->setQuery($db->createQuery()->update($db->quoteName('#__squidform_forms'))->set('state = 2')->where('id = ' . (int) $formId))->execute();
            }
        }

        // 5. Count, then drop the old copies only when everything arrived.
        $ok = $unmapped === 0;

        foreach ($forms as $formId => $f) {
            $fd   = $FormData::forForm((int) $formId);
            $want = (int) $db->setQuery($db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__squidform_submissions'))->where('form_id = ' . (int) $formId))->loadResult();
            $have = $fd->mainTable() ? (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName($fd->mainTable()))->loadResult() : 0;

            if ($want !== $have) {
                $ok = false;
            }
        }

        if (!$ok) {
            // Keep the old copy under a new name (so this runs only once and a later
            // update never copies stale answers over edited ones), and let new
            // submissions be saved without it.
            $db->setQuery('ALTER TABLE ' . $db->quoteName('#__squidform_submissions') . ' CHANGE ' . $db->quoteName('data_json') . ' '
                . $db->quoteName('data_json_pre040') . ' longtext NULL')->execute();
            $app->enqueueMessage('SquidForm: ' . $copied . ' submissions were copied into per-form tables, but ' . $unmapped
                . ' could not be matched to a form definition (or the counts differ). The old answers were kept in the data_json_pre040 column and the submission_values table so nothing is lost.', 'warning');

            return;
        }

        $db->setQuery('ALTER TABLE ' . $db->quoteName('#__squidform_submissions') . ' DROP COLUMN ' . $db->quoteName('data_json'))->execute();
        $db->setQuery('DROP TABLE IF EXISTS ' . $db->quoteName('#__squidform_submission_values'))->execute();

        $app->enqueueMessage('SquidForm: ' . $copied . ' submissions moved into per-form tables (' . $FormData::prefix() . 'sfd_…).', 'message');
    }
};
