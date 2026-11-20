<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Site\Controller;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;
use SquidForm\Component\SquidForm\Administrator\Engine\Processor;
use SquidForm\Component\SquidForm\Administrator\Engine\Renderer;

\defined('_JEXEC') or die;

/**
 * Front-end tasks:
 *   form.submit   POST target for every SquidForm form (menu item, module, shortcode)
 *   form.options  JSON options for cascading dropdowns
 *   form.resume   a guest's "finish later" link (0.11.0)
 *   form.copy     the sender's PDF copy of their answers, this session (0.11.0)
 */
class FormController extends BaseController
{
    public function submit(): void
    {
        Renderer::loadLanguage();

        // Raw POST is passed to the engine, which normalises only the fields the
        // form defines; nothing else from the request is used.
        $post = $this->input->post->getArray([
            'sf_form'       => 'cmd',
            'sf_page'       => 'int',
            'sf_return'     => 'base64',
            'sf_nav'        => 'cmd',
            'sf_t'          => 'string',
            'sf_hp_website' => 'string',
            'sf_captcha'    => 'raw', // the human check's answer (0.6.0); checked by Captcha
            'sf'            => 'raw',
            'sf_confirm'    => 'raw', // "Ask for it twice" email boxes (0.9.2)
            'sf_review'     => 'int', // posted from the review page (0.10.0)
            'sf_resume_email' => 'string', // where a guest's "finish later" link goes (0.11.0)
            'sf_lang'       => 'cmd', // the visitor's language (0.15.0), checked against the form's list
        ]);

        // Uploaded files (0.9.0), checked and stored by Engine\Uploads.
        $post['sf_files'] = $this->input->files->get('sf', [], 'raw');

        // More than PHP's post_max_size: PHP drops the whole request.
        if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            $this->app->enqueueMessage(Text::sprintf('COM_SQUIDFORM_ERR_POST_TOO_BIG', \SquidForm\Component\SquidForm\Administrator\Engine\Uploads::size(\SquidForm\Component\SquidForm\Administrator\Engine\Uploads::phpLimit())), 'error');
            $back = (string) ($_SERVER['HTTP_REFERER'] ?? '');
            $this->setRedirect(\Joomla\CMS\Uri\Uri::isInternal($back) ? $back : \Joomla\CMS\Uri\Uri::root());

            return;
        }

        if (!Session::checkToken('post')) {
            // Usually an expired session: go back to the form with a message.
            $this->setRedirect(Processor::tokenFailed($post));

            return;
        }

        $this->setRedirect(Processor::submit($post));
    }

    /** A guest's "finish later" link (0.11.0): fill the form back in and go to it (until the draft expires). */
    public function resume(): void
    {
        Renderer::loadLanguage();
        $row  = \SquidForm\Component\SquidForm\Administrator\Engine\Resume::byToken($this->input->getString('t', ''));
        $form = $row ? \SquidForm\Component\SquidForm\Administrator\Engine\FormRepository::load((int) $row->form_id, $this->app->getIdentity()) : null;

        if (!$row || !$form) {
            $this->app->enqueueMessage(Text::_('COM_SQUIDFORM_RESUME_LINK_BAD'), 'warning');
            $this->setRedirect(\Joomla\CMS\Uri\Uri::root());

            return;
        }

        $alias = (string) $form['form']['alias'];
        $state = \SquidForm\Component\SquidForm\Administrator\Engine\State::fresh((int) $form['version_id']);
        $state = \SquidForm\Component\SquidForm\Administrator\Engine\Resume::into($state, $row, $form['schema'], (int) $form['version_id']);

        // 0.14.0: signed in? Offer to move it into their account (shown once, above the form).
        $user = $this->app->getIdentity();

        if ($user && !$user->guest) {
            $has   = \SquidForm\Component\SquidForm\Administrator\Engine\Resume::forUser((int) $row->form_id, (int) $user->id) !== null;
            $esc   = static fn (string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
            $state['message'] = ['type' => 'info', 'text' => '<p>' . $esc(Text::_($has ? 'COM_SQUIDFORM_CLAIM_ASK_REPLACE' : 'COM_SQUIDFORM_CLAIM_ASK')) . '</p>'
                . '<form method="post" class="sf-claim" action="' . $esc(\Joomla\CMS\Router\Route::_('index.php?option=com_squidform&task=form.claim', false)) . '">'
                . '<input type="hidden" name="t" value="' . $esc($this->input->getString('t', '')) . '">'
                . ($has ? '<input type="hidden" name="replace" value="1">' : '')
                . \Joomla\CMS\HTML\HTMLHelper::_('form.token')
                . '<button type="submit" class="btn btn-primary">' . $esc(Text::_($has ? 'COM_SQUIDFORM_CLAIM_REPLACE' : 'COM_SQUIDFORM_CLAIM_MOVE')) . '</button> '
                . '<span class="small">' . $esc(Text::_('COM_SQUIDFORM_CLAIM_OR')) . '</span></form>'];
        }

        \SquidForm\Component\SquidForm\Administrator\Engine\State::put($alias, $state);

        $back = (string) $row->return_url;
        $this->setRedirect(\SquidForm\Component\SquidForm\Administrator\Engine\Url::isInternal($back) && $back !== '' ? $back . '#sf-' . $alias : \Joomla\CMS\Uri\Uri::root());
    }

    /** 0.14.0: move a guest saved form (opened from its emailed link) into the signed-in person's account. */
    public function claim(): void
    {
        Renderer::loadLanguage();
        $this->checkToken();
        $user = $this->app->getIdentity();

        if (!$user || $user->guest) {
            \SquidForm\Component\SquidForm\Site\Helper\Viewer::denied();
        }

        $resume = \SquidForm\Component\SquidForm\Administrator\Engine\Resume::class;
        $token  = $this->input->post->getString('t', '');
        $row    = $resume::byToken($token);
        $err    = $resume::claim($token, $user, $this->input->post->getInt('replace') === 1);

        if ($err !== '' || !$row) {
            $this->app->enqueueMessage(Text::_($err === 'exists' ? 'COM_SQUIDFORM_CLAIM_EXISTS' : 'COM_SQUIDFORM_RESUME_LINK_BAD'), 'warning');
            $this->setRedirect($row ? $resume::continueUrl((object) ((array) $row + ['form_alias' => $this->aliasOf((int) $row->form_id)])) : \Joomla\CMS\Uri\Uri::root());

            return;
        }

        $alias = $this->aliasOf((int) $row->form_id);
        $state = \SquidForm\Component\SquidForm\Administrator\Engine\State::get($alias);

        if (\is_array($state) && !empty($state['resumed'])) {
            $state['resumed']['guest'] = false;
            \SquidForm\Component\SquidForm\Administrator\Engine\State::put($alias, $state);
        }

        $this->app->enqueueMessage(Text::_('COM_SQUIDFORM_CLAIM_DONE'), 'success');
        $this->setRedirect($resume::continueUrl((object) ((array) $row + ['form_alias' => $alias])));
    }

    private function aliasOf(int $formId): string
    {
        $db = \SquidForm\Component\SquidForm\Administrator\Engine\SubmissionStore::db();

        return (string) $db->setQuery($db->createQuery()->select('alias')->from($db->quoteName('#__squidform_forms'))->where('id = ' . $formId))->loadResult();
    }

    /** The sender's copy (0.11.0): a PDF of what they just sent, only in the session that sent it, for an hour. */
    public function copy(): void
    {
        Renderer::loadLanguage();
        $uid  = $this->input->getString('uid', '');
        $at   = (int) $this->app->getSession()->get('squidform.copy.' . $uid, 0);
        $id   = preg_match('/^[0-9a-f-]{36}$/', $uid) && $at > time() - 3600 ? \SquidForm\Component\SquidForm\Administrator\Engine\SubmissionStore::findByUid($uid) : null;
        $item = $id ? \SquidForm\Component\SquidForm\Administrator\Engine\SubmissionService::load($id) : null;

        if (!$item) {
            $this->app->enqueueMessage(Text::_('COM_SQUIDFORM_COPY_GONE'), 'warning');
            $this->setRedirect(\Joomla\CMS\Uri\Uri::root());

            return;
        }

        $pdf = \SquidForm\Component\SquidForm\Administrator\Engine\Pdf\Pdf::class;
        $pdf::send($pdf::bytes($pdf::submission($item, 'sender')), $pdf::fileName($item));
    }

    /** Look-up suggestions (0.8.1): ?task=form.lookup&form=…&field=…&q=…&parent=… */
    public function lookup(): void
    {
        try {
            $list = Processor::lookup($this->input->getCmd('form'), $this->input->getCmd('field'), (string) $this->input->getString('q', ''), (string) $this->input->getString('parent', ''));
            $code = 200;
        } catch (\Throwable $e) {
            $list = [];
            $code = (int) $e->getCode() === 404 ? 404 : 400;
        }

        $this->app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $this->app->setHeader('Cache-Control', 'no-store', true);
        $this->app->setHeader('status', (string) $code, true);
        $this->app->sendHeaders();

        echo json_encode(['options' => $list], JSON_UNESCAPED_UNICODE);

        $this->app->close();
    }

    public function options(): void
    {
        $alias  = $this->input->getCmd('form');
        $field  = $this->input->getCmd('field');
        $parent = $this->input->getString('parent');

        try {
            $list = Processor::options($alias, $field, $parent);
            $code = 200;
        } catch (\Throwable $e) {
            $list = [];
            $code = (int) $e->getCode() === 404 ? 404 : 400;
        }

        $this->app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $this->app->setHeader('Cache-Control', 'no-store', true);
        $this->app->setHeader('status', (string) $code, true);
        $this->app->sendHeaders();

        echo json_encode(['options' => $list], JSON_UNESCAPED_UNICODE);

        $this->app->close();
    }
}
