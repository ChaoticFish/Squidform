<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Site\Helper;

use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;
use SquidForm\Component\SquidForm\Administrator\Engine\FormRepository;
use SquidForm\Component\SquidForm\Administrator\Engine\Url;
use SquidForm\Component\SquidForm\Administrator\Engine\ViewerConfig;

\defined('_JEXEC') or die;

/**
 * Viewer contexts. A list is shown either by a "Submissions viewer" menu item
 * or by a "SquidForm Viewer" module that points at one. Either way the menu
 * item fixes the form, and detail, edit and export pages carry its Itemid; a
 * submission of another form is not shown there.
 *
 * A context is an array:
 *   itemId   the viewer menu item (detail/edit/export/task URLs)
 *   formId, form, schema
 *   params   effective list settings (module settings over the menu item's)
 *   config   ViewerConfig::resolve()
 *   ctx      '' for the menu item, 'mod<ID>' for a module
 *   key      user-state key for this list's filters, sort and page
 *   names    request names: filter, sort, dir, start, clear
 *   idp      prefix for element ids (several lists on one page)
 *   pageUrl  where the list's own filter form and sort links go
 */
final class Viewer
{
    /** @var array<string, array|false> */
    private static array $cache = [];

    /** Context of the active menu item, if it is a viewer. */
    public static function current(): ?array
    {
        if (\array_key_exists('menu', self::$cache)) {
            return self::$cache['menu'] ?: null;
        }

        self::$cache['menu'] = false;
        $item = Factory::getApplication()->getMenu()->getActive();
        $ctx  = $item ? self::forMenuItem((int) $item->id) : null;

        if ($ctx) {
            self::$cache['menu'] = $ctx;
        }

        return $ctx;
    }

    /** Context for a viewer menu item by id. */
    public static function forMenuItem(int $itemId): ?array
    {
        $item = Factory::getApplication()->getMenu('site')->getItem($itemId);

        if (!$item || ($item->query['option'] ?? '') !== 'com_squidform' || !\in_array($item->query['view'] ?? '', ['submissions', 'mine'], true)) {
            return null;
        }

        $mine   = ($item->query['view'] ?? '') === 'mine';
        $formId = $mine ? self::ownForm($item) : (int) ($item->query['form_id'] ?? 0);

        try {
            $loaded = $formId ? FormRepository::load($formId, null, false) : null;
        } catch (\Throwable) {
            $loaded = null;
        }

        // Archived forms have no viewer.
        if (!$loaded || self::formState($formId) === 2) {
            return null;
        }

        $params = $item->getParams();

        return [
            'itemId'  => (int) $item->id,
            'formId'  => $formId,
            'form'    => $loaded['form'],
            'schema'  => $loaded['schema'],
            'params'  => $params,
            'config'  => ViewerConfig::resolve($loaded['schema'], $params),
            'ctx'     => '',
            'key'     => 'com_squidform.viewer.' . (int) $item->id,
            'mine'    => $mine,
            'names'   => ['filter' => 'filter', 'sort' => 'sort', 'dir' => 'dir', 'start' => 'limitstart', 'clear' => 'clear', 'prefix' => ''],
            'idp'     => 'sfv',
            // Unescaped (1.0.4): the list escapes it when printing; with search-engine-friendly URLs
            // off, an escaped "&amp;" here broke the filter form's hidden inputs.
            'pageUrl' => Route::_('index.php?Itemid=' . (int) $item->id, false),
        ];
    }

    /**
     * Context for a SquidForm Viewer module: the module's own list settings
     * (anything left empty uses the menu item's), its own filter state, and
     * request names prefixed "sfm<ID>_" so several lists can share a page.
     */
    public static function forModule(int $moduleId, Registry $modParams, ?string $pageUrl = null): ?array
    {
        $base = self::forMenuItem((int) $modParams->get('viewer_item', 0));

        if (!$base) {
            return null;
        }

        $params = new Registry($base['params']->toArray());

        foreach (['columns', 'filters', 'sort', 'sort_dir', 'per_page', 'show_search'] as $k) {
            $v = $modParams->get($k);

            if ($v !== null && $v !== '' && $v !== [] && $v !== '[]') {
                $params->set($k, $v);
            }
        }

        $p = 'sfm' . $moduleId . '_';

        return [
            'config'  => ViewerConfig::resolve($base['schema'], $params),
            'params'  => $params,
            'ctx'     => 'mod' . $moduleId,
            'key'     => 'com_squidform.viewer.mod' . $moduleId,
            'names'   => ['filter' => $p . 'filter', 'sort' => $p . 'sort', 'dir' => $p . 'dir', 'start' => $p . 'limitstart', 'clear' => $p . 'clear', 'prefix' => $p],
            'idp'     => 'sfm' . $moduleId,
            'pageUrl' => $pageUrl ?? self::currentUrlWithout($p),
        ] + $base;
    }

    /**
     * Rebuild a context from its key ('' = the active menu item, 'mod<ID>' =
     * that module, which must be a published SquidForm Viewer pointing at the
     * active menu item).
     */
    public static function fromKey(string $ctx, ?string $pageUrl = null): ?array
    {
        $menu = self::current();

        if ($ctx === '' || !$menu) {
            return $menu;
        }

        if (!preg_match('/^mod(\d+)$/', $ctx, $m)) {
            return null;
        }

        $id  = (int) $m[1];
        $db  = Factory::getContainer()->get(DatabaseInterface::class);
        $row = $db->setQuery(
            $db->createQuery()->select(['id', 'params', 'access'])->from($db->quoteName('#__modules'))
                ->where('id = :id')->where($db->quoteName('module') . ' = ' . $db->quote('mod_squidform_viewer'))
                ->where('published = 1')->where('client_id = 0')
                ->bind(':id', $id, ParameterType::INTEGER)
        )->loadObject();

        if (!$row || !\in_array((int) $row->access, Factory::getApplication()->getIdentity()->getAuthorisedViewLevels(), true)) {
            return null;
        }

        $params = new Registry($row->params);

        if ((int) $params->get('viewer_item') !== $menu['itemId']) {
            return null;
        }

        return self::forModule($id, $params, $pageUrl);
    }

    /**
     * 0.14.0: on a "My submissions" menu item, the detail, edit and task pages
     * work for one of the signed-in person's own submissions (from "uid"), on
     * that submission's form; anything else isn't found. The usual
     * permission checks (View Own and so on) still apply on top.
     */
    private static function ownForm(object $item): int
    {
        $user = Factory::getApplication()->getIdentity();
        $id   = \SquidForm\Component\SquidForm\Administrator\Engine\Ref::idOf(Factory::getApplication()->getInput()->getString('uid', ''));

        if (!$user || $user->guest || $id <= 0) {
            return 0;
        }

        $uid  = (int) $user->id;
        $db   = Factory::getContainer()->get(DatabaseInterface::class);
        $form = (int) $db->setQuery(
            $db->createQuery()->select('form_id')->from($db->quoteName('#__squidform_submissions'))
                ->where('id = :id')->where('user_id = :u')->bind(':id', $id, ParameterType::INTEGER)->bind(':u', $uid, ParameterType::INTEGER)
        )->loadResult();
        $only = array_filter(array_map('intval', (array) $item->getParams()->get('forms', [])));

        return $form > 0 && (!$only || \in_array($form, $only, true)) ? $form : 0;
    }

    private static function formState(int $formId): int
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        return (int) $db->setQuery(
            $db->createQuery()->select('state')->from($db->quoteName('#__squidform_forms'))->where('id = ' . $formId)
        )->loadResult();
    }

    /** The current page's URL without this module's own list parameters. */
    private static function currentUrlWithout(string $prefix): string
    {
        $uri = clone Uri::getInstance();

        foreach (array_keys($uri->getQuery(true)) as $k) {
            if (str_starts_with((string) $k, $prefix)) {
                $uri->delVar($k);
            }
        }

        return $uri->toString(['path', 'query']);
    }

    /** URL on the viewer menu item (detail, edit, export, tasks). */
    public static function url(string $query, bool $xhtml = true, ?array $ctx = null): string
    {
        $c = $ctx ?? self::current();

        return Route::_('index.php?option=com_squidform&' . $query . ($c ? '&Itemid=' . $c['itemId'] : ''), $xhtml);
    }

    public static function listUrl(bool $xhtml = true): string
    {
        $c = self::current();

        return $c ? Route::_('index.php?Itemid=' . $c['itemId'], $xhtml) : Uri::root();
    }

    /** A posted return URL (module pages), if it is on this site. */
    public static function returnUrl(): ?string
    {
        $r = base64_decode(Factory::getApplication()->getInput()->getBase64('sf_return', ''), true);

        return \is_string($r) && $r !== '' && Url::isInternal($r) ? $r : null;
    }

    /** Send guests to the login page and back; refuse logged-in users. */
    public static function denied(): void
    {
        $app  = Factory::getApplication();
        $user = $app->getIdentity();

        if (!$user || $user->guest) {
            $return = base64_encode(Uri::getInstance()->toString());
            $app->enqueueMessage(\Joomla\CMS\Language\Text::_('COM_SQUIDFORM_VIEWER_LOGIN'), 'notice');
            $app->redirect(Route::_('index.php?option=com_users&view=login&return=' . $return, false));
        }

        throw new \Exception(\Joomla\CMS\Language\Text::_('COM_SQUIDFORM_VIEWER_NOT_ALLOWED'), 403);
    }
}
