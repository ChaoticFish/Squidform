<?php
/**
 * Test helper: runs SquidForm's own code against a test site from the command line,
 * so tests can set up forms quickly. Never shipped.
 *
 *   php sf.php <site root> create <alias> <title> <definition.json> [published 0|1] [access]
 *   php sf.php <site root> delete <alias>          (purge: form, versions, submissions, tables)
 *   php sf.php <site root> params '<json>'          (merge into com_squidform Options)
 *   php sf.php <site root> eval-api <alias> '<json query>'   (Api\Submissions::find, prints JSON)
 */
const _JEXEC = 1;
$_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['REQUEST_URI'] = '/administrator/index.php'; $_SERVER['SCRIPT_NAME'] = '/administrator/index.php'; $_SERVER['REQUEST_METHOD'] = 'GET';
$site = rtrim($argv[1], '/');
define('JPATH_BASE', $site . '/administrator');
require JPATH_BASE . '/includes/defines.php';
require JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;

require_once JPATH_LIBRARIES . "/namespacemap.php";
(new \JNamespacePsr4Map())->load();

$container = Factory::getContainer();
$container->alias('session', 'session.cli')->alias('JSession', 'session.cli')
    ->alias(\Joomla\CMS\Session\Session::class, 'session.cli')
    ->alias(\Joomla\Session\Session::class, 'session.cli')
    ->alias(\Joomla\Session\SessionInterface::class, 'session.cli');
$app = $container->get(\Joomla\CMS\Application\AdministratorApplication::class);
Factory::$application = $app;
$app->loadIdentity(\Joomla\CMS\User\User::getInstance((int) Factory::getDbo()->setQuery("SELECT u.id FROM #__users u JOIN #__user_usergroup_map m ON m.user_id = u.id WHERE m.group_id = 8 ORDER BY u.id LIMIT 1")->loadResult()));
$app->loadLanguage(); $app->getLanguage()->load('com_squidform', JPATH_ADMINISTRATOR . '/components/com_squidform');
$app->bootComponent('com_squidform');
$db = Factory::getDbo();

$cmd = $argv[2] ?? '';
switch ($cmd) {
    case 'create':
        [$alias, $title, $file] = [$argv[3], $argv[4], $argv[5]];
        $line = \SquidForm\Component\SquidForm\Administrator\Engine\FormTransfer::create($title, $alias, '', (string) file_get_contents($file), 'test');
        if (!$line['ok']) { fwrite(STDERR, $line['message'] . "\n"); exit(1); }
        $pub = (int) ($argv[6] ?? 1); $acc = (int) ($argv[7] ?? 1); $id = (int) $line['id'];
        $db->setQuery("UPDATE #__squidform_forms SET state = $pub, access = $acc WHERE id = $id")->execute();
        echo json_encode(['id' => $id, 'alias' => $line['alias']]), "\n";
        break;
    case 'delete':
        // Permanent deletion, as Forms → Archive → Delete permanently does (tables, rows, files).
        foreach (array_slice($argv, 3) as $alias) {
            $id = (int) $db->setQuery('SELECT id FROM #__squidform_forms WHERE alias = ' . $db->quote($alias))->loadResult();
            if (!$id) { continue; }
            $db->setQuery("UPDATE #__squidform_forms SET state = 2 WHERE id = $id")->execute();
            $db->setQuery("UPDATE #__squidform_signatures SET retain_until = '2000-01-01' WHERE form_id = $id")->execute();
            \SquidForm\Component\SquidForm\Administrator\Engine\FormPurge::run($id);
        }
        echo "ok\n";
        break;
    case 'save':
        \defined('JPATH_COMPONENT') or \define('JPATH_COMPONENT', JPATH_ADMINISTRATOR . '/components/com_squidform');
        // A new version of an existing form's definition, as Save in the admin does (checks, versions, data tables).
        [$alias, $file] = [$argv[3], $argv[4]];
        $row = $db->setQuery('SELECT id, title, alias, state, access FROM #__squidform_forms WHERE alias = ' . $db->quote($alias))->loadAssoc();
        if (!$row) { fwrite(STDERR, "no form $alias\n"); exit(1); }
        $m = $app->bootComponent('com_squidform')->getMVCFactory()->createModel('Form', 'Administrator', ['ignore_request' => true]);
        $data = $row + ['schema_json' => (string) file_get_contents($file), 'version_note' => (string) ($argv[5] ?? '')];
        $ok = ($v = $m->validate($m->getForm($data, false), $data)) !== false && $m->save($v);
        if (!$ok) { fwrite(STDERR, strip_tags((string) $m->getError()) . "\n"); exit(1); }
        echo json_encode(['id' => (int) $row['id']]), "\n";
        break;
    case 'params':
        $p = \Joomla\CMS\Component\ComponentHelper::getParams('com_squidform');
        foreach (json_decode($argv[3], true) as $k => $v) { $p->set($k, $v); }
        $q = $db->getQuery(true)->update('#__extensions')->set('params = ' . $db->quote($p->toString()))->where("element = 'com_squidform'")->where("type = 'component'");
        $db->setQuery($q)->execute();
        echo "ok\n";
        break;
    case 'notes':
        // DefinitionCheck::notes for a definition file (1.0.4): what would go out as written.
        echo json_encode(\SquidForm\Component\SquidForm\Administrator\Engine\DefinitionCheck::notes((string) file_get_contents($argv[3]))), "\n";
        break;
    case 'eval-api':
        $api = \SquidForm\Component\SquidForm\Administrator\Api\Submissions::form($argv[3]);
        echo json_encode($api ? $api->find(json_decode($argv[4] ?? '{}', true) ?: []) : null), "\n";
        break;
    default:
        fwrite(STDERR, "unknown command\n"); exit(2);
}
