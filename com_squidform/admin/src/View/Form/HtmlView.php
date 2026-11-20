<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\View\Form;

use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ContentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Router\Route;
use SquidForm\Component\SquidForm\Administrator\Engine\ApprovalFlow;
use SquidForm\Component\SquidForm\Administrator\Engine\BuilderCatalog;
use SquidForm\Component\SquidForm\Administrator\Engine\Drafts;
use SquidForm\Component\SquidForm\Administrator\Engine\ApprovalMail;
use SquidForm\Component\SquidForm\Administrator\Engine\Schema;

\defined('_JEXEC') or die;

class HtmlView extends BaseHtmlView
{
    protected $form;
    protected $item;

    /** Unpublished changes (0.7.0): {at, by, base_version_no} or null. */
    protected ?object $draft = null;

    /** Where a refused save found the first problem: ['line' => …, 'column' => …], or null. */
    protected ?array $errorAt = null;

    public function display($tpl = null): void
    {
        $model      = $this->getModel();
        $this->form = $model->getForm();
        $this->item = $model->getItem();

        // Shown once, after a refused save.
        $app           = Factory::getApplication();
        $at            = $app->getUserState('com_squidform.edit.form.error_at');
        $this->errorAt = \is_array($at) && (int) ($at['line'] ?? 0) > 0 ? $at : null;
        $app->setUserState('com_squidform.edit.form.error_at', null);

        $this->builderOptions();

        // A form with approvals, but no "Awaiting my approval" menu item:
        // approvers who can't use the admin would have no list to work from.
        try {
            $json = (string) ($this->item->schema_json ?? '');

            if ($json !== '' && ApprovalFlow::of(Schema::fromJson($json)) !== null && !ApprovalMail::hasApprovalsMenuItem()) {
                Factory::getApplication()->enqueueMessage(Text::_('COM_SQUIDFORM_WARN_NO_APPROVALS_MENU'), 'warning');
            }
        } catch (\Throwable) {
            // A definition that doesn't parse is reported when it's saved.
        }

        $this->addToolbar();

        parent::display($tpl);
    }

    /** Everything the visual builder needs, as script options (0.7.0). */
    private function builderOptions(): void
    {
        $id    = (int) ($this->item->id ?? 0);
        $draft = Drafts::get($id);

        if ($draft) {
            $this->draft = (object) [
                'at'              => HTMLHelper::_('date', $draft->modified, Text::_('DATE_FORMAT_LC2')),
                'by'              => (string) ($draft->modified_by_name ?: '?'),
                'base_version_no' => (int) $draft->base_version_no,
            ];
        }

        // Keys of the live version: changing one of these leaves old answers behind.
        $liveKeys = [];

        if ($id > 0 && !empty($this->item->schema_json)) {
            try {
                foreach (Schema::fromJson((string) $this->item->schema_json)->pages() as $p) {
                    foreach ($p['fields'] ?? [] as $f) {
                        $liveKeys[] = (string) $f['key'];

                        foreach ($f['fields'] ?? [] as $c) {
                            $liveKeys[] = (string) $c['key'];
                        }
                    }
                }
            } catch (\Throwable) {
                $liveKeys = [];
            }
        }

        $this->getDocument()->addScriptOptions('com_squidform.builder', [
            'id'          => $id,
            'alias'       => (string) ($this->item->alias ?? ''),
            'isNew'       => $id === 0,
            'liveVersion' => (int) ($this->item->version_no ?? 0),
            'draft'       => $this->draft ? ['at' => $this->draft->at, 'by' => $this->draft->by, 'base_version' => $this->draft->base_version_no] : null,
            'liveKeys'    => $liveKeys,
            'errorAt'     => $this->errorAt,
            'urls'        => ['draft' => Route::_('index.php?option=com_squidform&task=form.draft&format=json', false)],
            'catalog'     => BuilderCatalog::build((int) ($this->item->id ?? 0)),
            'formLinks'   => $this->formLinks((string) ($this->item->alias ?? '')),
        ]);
    }

    /**
     * Where the form is on the site, for the link helper (0.10.2): its menu
     * items, then the direct address.
     *
     * @return list<array{label: string, url: string}>
     */
    private function formLinks(string $alias): array
    {
        if ($alias === '') {
            return [];
        }

        $out = [];

        try {
            $db   = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            $link = 'index.php?option=com_squidform&view=form&form=' . $alias;
            $rows = $db->setQuery(
                $db->createQuery()->select(['id', 'title'])->from($db->quoteName('#__menu'))
                    ->where($db->quoteName('client_id') . ' = 0')->where($db->quoteName('published') . ' = 1')
                    ->where($db->quoteName('link') . ' = :link')->bind(':link', $link)->order('id')
            )->loadObjectList() ?: [];

            foreach ($rows as $r) {
                $out[] = ['label' => Text::sprintf('COM_SQUIDFORM_LINK_MENU_ITEM', (string) $r->title),
                    'url' => Route::link('site', 'index.php?Itemid=' . (int) $r->id, false, Route::TLS_IGNORE, true)];
            }
        } catch (\Throwable) {
            // the direct address still works
        }

        $out[] = ['label' => Text::_('COM_SQUIDFORM_LINK_DIRECT'), 'url' => \Joomla\CMS\Uri\Uri::root() . 'index.php?option=com_squidform&view=form&form=' . rawurlencode($alias)];

        return $out;
    }

    protected function addToolbar(): void
    {
        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        $isNew   = empty($this->item->id);
        $canDo   = ContentHelper::getActions('com_squidform');
        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_($isNew ? 'COM_SQUIDFORM_FORM_NEW' : 'COM_SQUIDFORM_FORM_EDIT'), 'pencil-alt');

        if ($canDo->get('core.edit') || $canDo->get('core.create')) {
            $toolbar->apply('form.apply', 'COM_SQUIDFORM_TOOLBAR_PUBLISH');
            $saveGroup = $toolbar->dropdownButton('save-group');
            $child     = $saveGroup->configure(function ($childBar) use ($canDo, $isNew) {
                $childBar->save('form.save', 'COM_SQUIDFORM_TOOLBAR_PUBLISH_CLOSE');

                if ($canDo->get('core.create')) {
                    $childBar->save2new('form.save2new');

                    if (!$isNew) {
                        $childBar->save2copy('form.save2copy');
                    }
                }
            });
        }

        // A form imported from ChronoForms: its import report, in a new tab (0.12.0).
        if (!$isNew && \SquidForm\Component\SquidForm\Administrator\Engine\Import\ChronoImport::recordFor((int) $this->item->id)) {
            $toolbar->linkButton('chronoreport')
                ->text('COM_SQUIDFORM_CF_REPORT_BUTTON')
                ->url(Route::_('index.php?option=com_squidform&view=chronoforms&layout=report&form_id=' . (int) $this->item->id, false))
                ->target('_blank')
                ->icon('icon-list');
        }

        $toolbar->cancel('form.cancel', $isNew ? 'JTOOLBAR_CANCEL' : 'JTOOLBAR_CLOSE');
    }
}
