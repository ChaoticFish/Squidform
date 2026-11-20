<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Factory;

\defined('_JEXEC') or die;

/**
 * Per-visitor progress through a form, kept in the Joomla session:
 * current page, values entered so far, errors and one-time messages.
 *
 * For servers behind a load balancer use Joomla's database session handler
 * (Global Configuration → System → Session Handler) so every node sees it.
 */
final class State
{
    private const PREFIX = 'squidform.state.';

    /** @return array<string, mixed> */
    public static function fresh(int $versionId): array
    {
        return [
            'version' => $versionId,
            'run'     => SubmissionStore::uuid(),
            'page'    => 0,
            'data'    => [],
            'errors'  => [],
            'message' => null,
            'debug'   => null,
        ];
    }

    /** @return array<string, mixed> */
    public static function get(string $alias): array
    {
        $s = Factory::getApplication()->getSession()->get(self::PREFIX . $alias);

        return \is_array($s) ? $s : self::fresh(0);
    }

    /**
     * A published change to the form restarts anyone part-way through it, so
     * values never land against fields that no longer exist.
     *
     * @param   array<string, mixed>  $state
     *
     * @return  array<string, mixed>
     */
    public static function forVersion(array $state, int $versionId): array
    {
        if ((int) ($state['version'] ?? 0) !== $versionId) {
            $fresh            = self::fresh($versionId);
            $fresh['message'] = $state['message'] ?? null;

            return $fresh;
        }

        return $state;
    }

    /** @param array<string, mixed> $state */
    public static function put(string $alias, array $state): void
    {
        Factory::getApplication()->getSession()->set(self::PREFIX . $alias, $state);
    }

    public static function clear(string $alias): void
    {
        Factory::getApplication()->getSession()->remove(self::PREFIX . $alias);
    }
}
