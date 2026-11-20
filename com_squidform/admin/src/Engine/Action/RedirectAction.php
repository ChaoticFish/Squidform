<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine\Action;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Uri\Uri;
use SquidForm\Component\SquidForm\Administrator\Engine\ActionResult;
use SquidForm\Component\SquidForm\Administrator\Engine\Context;
use SquidForm\Component\SquidForm\Administrator\Engine\Placeholders;
use SquidForm\Component\SquidForm\Administrator\Engine\Url;

\defined('_JEXEC') or die;

/**
 * Redirect: sends the person to another page and ends the run.
 *
 * config:
 *   url   relative ("index.php?Itemid=12", "/thanks") or absolute on this site.
 *         Placeholders allowed. Values a visitor controls ({data:...},
 *         {var:...}, {action:...}, {user.field:...}) are URL-encoded so they
 *         cannot change the address or add parameters; site values such as
 *         {site:url} are inserted as-is.
 *
 * Only this site's addresses (same scheme, host and port) are allowed unless
 * "Allow external redirects" is on in the SquidForm options.
 */
final class RedirectAction implements ActionInterface
{
    public function run(array $config, Context $ctx): ActionResult
    {
        $ph  = Placeholders::forContext($ctx);
        $raw = (string) ($config['url'] ?? '');

        $url = trim((string) preg_replace_callback(
            '/\{[a-z]+(?:\.[a-z]+)?:[^{}]*\}/',
            static function (array $m) use ($ph) {
                $value = $ph->replace($m[0], 'text');

                return Placeholders::readsInput($m[0]) ? rawurlencode($value) : $value;
            },
            $raw
        ));

        if ($url === '') {
            return ActionResult::fail('Redirect URL is empty.');
        }

        if (preg_match('#^(javascript|data|vbscript|file):#i', (string) preg_replace('/[\s\x00-\x1f]+/', '', $url))) {
            return ActionResult::fail('Unsafe redirect URL.');
        }

        $internal = Url::isInternal($url);

        if (!$internal && !ComponentHelper::getParams('com_squidform')->get('allow_external_redirects', 0)) {
            return ActionResult::fail('External redirects are turned off in SquidForm Options.');
        }

        if ($internal && !preg_match('#^(https?:)?//#i', $url)) {
            // "/path" is relative to the host; "index.php?..." is relative to the Joomla root.
            $url = str_starts_with($url, '/')
                ? Uri::getInstance()->toString(['scheme', 'host', 'port']) . $url
                : Uri::root() . $url;
        }

        $ctx->redirect = $url;
        $ctx->stop     = true;

        return ActionResult::success($url);
    }
}
