<?php
/** Plays a sign-on script for SquidForm's session-data tests (tests only). */
namespace SquidForm\Plugin\System\SfTestSession\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;

final class SfTestSession extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['onAfterRoute' => 'fill'];
    }

    public function fill(): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('site')) {
            return;
        }

        $user = $app->getIdentity();
        $s    = $app->getSession();

        if ($user && $user->username === 'sf_sess_user') {
            $s->set('abcdefuser', ['employeenumber' => 'E12345', 'primaryorg' => 'ORG-7', 'fineaccesscontrol' => ['ALPHA', 'BRAVO'], 'email' => 'sess@example.test', 'secretbadge' => 'S3CR3T']);
            $s->set('otherkey', ['hidden' => 'never readable']);
        } else {
            $s->clear('abcdefuser');
            $s->clear('otherkey');
        }
    }
}
