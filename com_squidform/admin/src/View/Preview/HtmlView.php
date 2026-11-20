<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\View\Preview;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\User\User;
use SquidForm\Component\SquidForm\Administrator\Engine\Lang;
use SquidForm\Component\SquidForm\Administrator\Engine\Renderer;
use SquidForm\Component\SquidForm\Administrator\Engine\Schema;
use SquidForm\Component\SquidForm\Administrator\Engine\SchemaException;

\defined('_JEXEC') or die;

/**
 * Preview (0.7.0): what the builder posted, shown one page at a time, as a
 * signed-in person (you) or as a guest. Nothing is saved.
 */
class HtmlView extends BaseHtmlView
{
    public string $html = '';

    /** @var string[] */
    public array $errors = [];

    /** @var list<string> page titles */
    public array $pages = [];

    public int $page = 0;
    public string $as = 'user';
    public string $look = '';
    public string $title = '';

    /** 0.15.0: the language shown, and the ones the form offers */
    public string $lang = '';
    public array $langs = [];

    public function display($tpl = null): void
    {
        $app        = Factory::getApplication();
        $state      = (array) $app->getUserState('com_squidform.preview', []);
        $this->page = max(0, $app->getInput()->getInt('page'));
        $this->as   = $app->getInput()->getCmd('as') === 'guest' ? 'guest' : 'user';
        $this->title = (string) ($state['title'] ?? '');

        try {
            $schema = Schema::fromJson((string) ($state['json'] ?? ''));
        } catch (SchemaException $e) {
            $this->errors = $e->getErrors();
            parent::display($tpl);

            return;
        }

        foreach ($schema->pages() as $i => $p) {
            $this->pages[] = (string) ($p['title'] ?? '') ?: (string) ($i + 1);
        }

        $this->page = min($this->page, \count($this->pages) - 1);
        $user       = $this->as === 'guest' ? new User() : $app->getIdentity();
        $form       = ['id' => (int) ($state['id'] ?? 0), 'title' => $this->title ?: 'Preview', 'alias' => (string) ($state['alias'] ?? 'preview')];
        $this->look = \SquidForm\Component\SquidForm\Administrator\Engine\Looks::of($schema, $app->getInput()->getCmd('look', ''));
        $this->langs = Lang::forForm($schema);
        $want        = $app->getInput()->getCmd('lang', '');
        $this->lang  = \in_array($want, $this->langs, true) ? $want : $this->langs[0];
        $optionsUrl  = Route::_('index.php?option=com_squidform&task=form.previewOptions&format=json', false);
        $this->html  = (string) Lang::run($this->lang, fn () => Renderer::preview(Lang::schemaFor($schema, $this->lang), $form, $this->page, $user, $optionsUrl, $this->look, $this->lang));

        parent::display($tpl);
    }

    public function link(int $page, string $as, ?string $look = null, ?string $lang = null): string
    {
        return Route::_('index.php?option=com_squidform&view=preview&tmpl=component&page=' . $page . '&as=' . $as . '&look=' . ($look ?? $this->look) . '&lang=' . ($lang ?? $this->lang));
    }
}
