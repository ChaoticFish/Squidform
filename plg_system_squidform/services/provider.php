<?php

/**
 * @package     SquidForm
 * @subpackage  plg_system_squidform
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use SquidForm\Plugin\System\SquidForm\Extension\SquidForm;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            $container->lazy(SquidForm::class, function (Container $container) {
                $plugin = new SquidForm((array) PluginHelper::getPlugin('system', 'squidform'));
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            })
        );
    }
};
