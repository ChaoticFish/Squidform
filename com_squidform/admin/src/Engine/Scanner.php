<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Event\Event;

\defined('_JEXEC') or die;

/**
 * Virus scanning for uploads (0.9.0), with ClamAV, the free scanner most
 * Linux servers can install. Three ways to reach it (Options → Uploads):
 *
 *   none     no scanning
 *   clamd    the ClamAV daemon over its socket: "unix:///run/clamav/clamd.ctl"
 *            or "tcp://127.0.0.1:3310" (INSTREAM, so the daemon needn't be
 *            able to read SquidForm's folder)
 *   command  run clamdscan or clamscan on the file: needs the scanner add-on
 *            (0.10.1, plg_squidform_scannertools), which also has the EICAR
 *            test. The main package runs no programs and holds no test virus.
 *
 * Nothing here goes beyond the server itself: no online lookups.
 */
final class Scanner
{
    public const CLEAN = 'clean';
    public const INFECTED = 'infected';
    public const ERROR = 'error';

    /** Where ClamAV usually puts its socket, by distribution. */
    public const SOCKETS = [
        '/run/clamav/clamd.ctl', '/var/run/clamav/clamd.ctl',             // Debian, Ubuntu
        '/run/clamd.scan/clamd.sock', '/var/run/clamd.scan/clamd.sock',   // RHEL, Rocky, Fedora
        '/run/clamav/clamd.sock', '/var/run/clamav/clamd.sock',           // Arch, Alpine, others
        '/var/lib/clamav/clamd.sock', '/tmp/clamd.socket', '/tmp/clamd.sock',
        '/usr/local/var/run/clamav/clamd.sock', '/opt/homebrew/var/run/clamav/clamd.sock',
    ];

    private static ?ScannerAddonInterface $addon = null;
    private static bool $looked = false;

    /** The scanner add-on, when it's installed and enabled. */
    public static function addon(): ?ScannerAddonInterface
    {
        if (!self::$looked) {
            self::$looked = true;
            $registry     = new \ArrayObject();

            try {
                PluginHelper::importPlugin('squidform');
                Factory::getApplication()->getDispatcher()->dispatch('onSquidformScannerAddon', new Event('onSquidformScannerAddon', ['registry' => $registry]));
            } catch (\Throwable) {
                // no add-on
            }

            self::$addon = ($registry['addon'] ?? null) instanceof ScannerAddonInterface ? $registry['addon'] : null;
        }

        return self::$addon;
    }

    /** The EICAR test file, from the add-on; null without it. */
    public static function testBytes(): ?string
    {
        return self::addon()?->testBytes();
    }

    public static function canRun(): bool
    {
        return (bool) self::addon()?->canRun();
    }

    /** @return array{mode: string, address: string, command: string} */
    public static function config(): array
    {
        $p = ComponentHelper::getParams('com_squidform');

        return [
            'mode'    => \in_array($p->get('scan_mode', 'none'), ['none', 'clamd', 'command'], true) ? (string) $p->get('scan_mode', 'none') : 'none',
            'address' => trim((string) $p->get('scan_address', '')),
            'command' => trim((string) $p->get('scan_command', '')),
        ];
    }

    /**
     * Scan bytes or a file.
     *
     * @return array{status: string, detail: string}  detail: the virus name, or what went wrong
     */
    public static function scanFile(string $path, ?array $cfg = null): array
    {
        $cfg ??= self::config();

        try {
            return match ($cfg['mode']) {
                'clamd'   => self::clamdStream((string) file_get_contents($path), $cfg['address']),
                'command' => self::program($path, $cfg['command']),
                default   => ['status' => self::ERROR, 'detail' => 'no scanner set'],
            };
        } catch (\Throwable $e) {
            return ['status' => self::ERROR, 'detail' => $e->getMessage()];
        }
    }

    public static function scanBytes(string $bytes, ?array $cfg = null): array
    {
        $cfg ??= self::config();

        try {
            if ($cfg['mode'] === 'clamd') {
                return self::clamdStream($bytes, $cfg['address']);
            }

            if ($cfg['mode'] !== 'command') {
                return ['status' => self::ERROR, 'detail' => 'no scanner set'];
            }

            $tmp = tempnam(sys_get_temp_dir(), 'sfscan');
            file_put_contents($tmp, $bytes);

            try {
                return self::program($tmp, $cfg['command']);
            } finally {
                @unlink($tmp);
            }
        } catch (\Throwable $e) {
            return ['status' => self::ERROR, 'detail' => $e->getMessage()];
        }
    }

    /** "unix:///path" or "tcp://host:port"; a bare path is a Unix socket. */
    private static function connect(string $address, int $timeout = 5)
    {
        if ($address === '') {
            throw new \RuntimeException('no daemon address set');
        }

        $target = str_starts_with($address, 'unix://') || str_starts_with($address, 'tcp://') ? $address : 'unix://' . $address;
        $sock   = @stream_socket_client($target, $errno, $errstr, $timeout);

        if (!$sock) {
            throw new \RuntimeException('can’t connect to ' . $address . ($errstr ? ': ' . $errstr : ''));
        }

        stream_set_timeout($sock, 30);

        return $sock;
    }

    /** "PONG" when the daemon answers, else throws. */
    public static function ping(string $address): string
    {
        $s = self::connect($address, 3);
        fwrite($s, "zPING\0");
        $r = trim((string) stream_get_contents($s), "\0\n ");
        fclose($s);

        return $r;
    }

    public static function version(string $address): string
    {
        $s = self::connect($address, 3);
        fwrite($s, "zVERSION\0");
        $r = trim((string) stream_get_contents($s), "\0\n ");
        fclose($s);

        return $r;
    }

    private static function clamdStream(string $bytes, string $address): array
    {
        $s = self::connect($address);
        fwrite($s, "zINSTREAM\0");

        foreach (str_split($bytes === '' ? ' ' : $bytes, 65536) as $chunk) {
            fwrite($s, pack('N', \strlen($chunk)) . $chunk);
        }

        fwrite($s, pack('N', 0));
        $reply = trim((string) stream_get_contents($s), "\0\n ");
        fclose($s);

        if (preg_match('/:\s*OK$/', $reply)) {
            return ['status' => self::CLEAN, 'detail' => ''];
        }

        if (preg_match('/:\s*(.+)\s+FOUND$/', $reply, $m)) {
            return ['status' => self::INFECTED, 'detail' => $m[1]];
        }

        return ['status' => self::ERROR, 'detail' => $reply !== '' ? $reply : 'no answer from the daemon'];
    }

    /** "ClamAV program" mode: only with the add-on. */
    private static function program(string $path, string $cmd): array
    {
        $addon = self::addon();

        if ($addon === null) {
            return ['status' => self::ERROR, 'detail' => 'the "ClamAV program" mode needs the SquidForm scanner add-on (plg_squidform_scannertools); use the ClamAV daemon, or install the add-on'];
        }

        return $addon->scanFile($path, $cmd);
    }

    /**
     * What's on this server, for "Check this server".
     *
     * @return array{sockets: list<array{address: string, answer: string}>, tcp: ?string, commands: list<string>, canRun: bool, addon: bool}
     */
    public static function detect(): array
    {
        $found = [];

        foreach (self::SOCKETS as $path) {
            if (@file_exists($path)) {
                try {
                    $found[] = ['address' => 'unix://' . $path, 'answer' => self::ping('unix://' . $path)];
                } catch (\Throwable $e) {
                    $found[] = ['address' => 'unix://' . $path, 'answer' => $e->getMessage()];
                }
            }
        }

        $tcp = null;

        try {
            $tcp = self::ping('tcp://127.0.0.1:3310');
        } catch (\Throwable) {
            // nothing listening there
        }

        $addon = self::addon();

        return ['sockets' => $found, 'tcp' => $tcp, 'commands' => $addon ? $addon->findPrograms() : [], 'canRun' => self::canRun(), 'addon' => $addon !== null];
    }
}
