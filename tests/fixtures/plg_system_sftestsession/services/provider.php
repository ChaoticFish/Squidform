<?php
\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use SquidForm\Plugin\System\SfTestSession\Extension\SfTestSession;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(PluginInterface::class, $container->lazy(SfTestSession::class, function () {
            $p = new SfTestSession((array) PluginHelper::getPlugin('system', 'sftestsession'));
            $p->setApplication(Factory::getApplication());
            return $p;
        }));
    }
};
