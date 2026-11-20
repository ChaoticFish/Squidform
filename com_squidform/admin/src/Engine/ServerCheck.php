<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Uri\Uri;

\defined('_JEXEC') or die;

/**
 * "Check this server" (0.9.0): what uploads need, checked on this server and
 * said in plain words, for site admins who don't know their server.
 *
 * Each check: ['label', 'status' (ok | warn | bad | info), 'value', 'help'].
 */
final class ServerCheck
{
    /** @return list<array{label: string, status: string, value: string, help: string}> */
    public static function run(): array
    {
        $out = [];
        $cfg = Uploads::settings();

        // PHP
        $on    = (bool) \ini_get('file_uploads');
        $out[] = ['label' => 'PHP accepts uploads', 'status' => $on ? 'ok' : 'bad', 'value' => $on ? 'Yes' : 'No',
            'help' => $on ? '' : 'file_uploads is off in php.ini. Ask your host to turn it on, or set file_uploads = On.'];

        $limit = Uploads::phpLimit();
        $want  = $cfg['max_mb'] * 1048576;
        $out[] = ['label' => 'Largest upload PHP allows', 'status' => $limit >= $want ? 'ok' : 'warn',
            'value' => Uploads::size($limit) . ' (upload_max_filesize ' . \ini_get('upload_max_filesize') . ', post_max_size ' . \ini_get('post_max_size') . ')',
            'help' => $limit >= $want ? '' : 'The Options allow ' . $cfg['max_mb'] . ' MB, but PHP stops at ' . Uploads::size($limit) . '. Raise upload_max_filesize and post_max_size in php.ini (post_max_size a little larger), or lower the Options.'];

        $out[] = ['label' => 'Files at once', 'status' => 'info', 'value' => (string) \ini_get('max_file_uploads'), 'help' => 'max_file_uploads in php.ini: the most files one page can send.'];

        $fi    = \function_exists('finfo_open');
        $out[] = ['label' => 'Checking what a file really is (fileinfo)', 'status' => $fi ? 'ok' : 'bad', 'value' => $fi ? 'Available' : 'Missing',
            'help' => $fi ? '' : 'PHP\'s fileinfo extension is off, so SquidForm can\'t check files and refuses them all. Ask your host to enable "fileinfo".'];

        // Folder
        $dir    = $cfg['folder'];
        $exists = is_dir($dir);

        if (!$exists) {
            try {
                Uploads::ensureFolder($dir);
                $exists = true;
            } catch (\Throwable) {
                // reported below
            }
        }

        $writable = $exists && is_writable($dir);
        $out[]    = ['label' => 'Upload folder', 'status' => $writable ? 'ok' : 'bad', 'value' => $dir,
            'help' => $writable ? '' : ($exists ? 'The folder is there but the web server can\'t write to it. Give the web server\'s user write access (for example chown www-data).' : 'The folder doesn\'t exist and can\'t be created. Create it, writable by the web server, or choose another in the Options.')];

        // Where to put it (0.9.1): beside the site, which most hosts allow.
        $site    = rtrim(str_replace('\\', '/', realpath(JPATH_ROOT) ?: JPATH_ROOT), '/');
        $suggest = \dirname($site) . '/squidform-files';
        $out[]   = ['label' => 'Where this site is', 'status' => 'info', 'value' => $site,
            'help' => 'A folder outside the site could be ' . $suggest . ' (beside it, so the web server can’t hand files out). Put the full path, starting with /, in Options → Uploads → Upload folder; SquidForm creates the folder if it can. A path that doesn’t start with / is inside the site, here: ' . $site . '/…'];

        $inside = Uploads::insideWebRoot($dir);
        $out[]  = ['label' => 'Folder is outside the web site', 'status' => $inside ? 'warn' : 'ok', 'value' => $inside ? 'No, it\'s inside the site\'s folder' : 'Yes',
            'help' => $inside ? 'Safest is a folder the web server can\'t serve at all, such as /var/lib/squidform-files. Inside the site, SquidForm puts deny-all .htaccess and web.config files in it (see the next check).' : ''];

        if ($inside && $writable) {
            $out[] = self::probe($dir);
        }

        // Scanner
        $sc    = Scanner::config();
        $out[] = self::scanner($sc);
        $found = Scanner::detect();
        $list  = [];

        foreach ($found['sockets'] as $s) {
            $list[] = $s['address'] . ' → ' . $s['answer'];
        }

        if ($found['tcp'] !== null) {
            $list[] = 'tcp://127.0.0.1:3310 → ' . $found['tcp'];
        }

        foreach ($found['commands'] as $c) {
            $list[] = $c . ($found['canRun'] ? '' : ' (but PHP can\'t run programs here)');
        }

        // The scanner add-on (0.10.1): the ClamAV program mode and the EICAR test.
        $out[] = ['label' => 'Scanner add-on', 'status' => 'info', 'value' => $found['addon'] ? 'Installed' : 'Not installed',
            'help' => $found['addon']
                ? 'Adds the "ClamAV program" mode and the EICAR test below.'
                : 'Optional (plg_squidform_scannertools): adds the "ClamAV program" mode (clamdscan or clamscan) and the EICAR test. The ClamAV daemon works without it. It\'s kept separate because a test-virus string and running programs are what many organisations\' upload filters block.'];

        $out[] = ['label' => 'ClamAV found on this server', 'status' => $list ? 'ok' : 'info', 'value' => $list ? implode("\n", $list) : 'Nothing found',
            'help' => $list
                ? 'Copy an address answering PONG into Options → Uploads → Daemon address (with "ClamAV daemon"), or a program path into Scanner program (with "ClamAV program").'
                : 'No ClamAV daemon or program in the usual places. If ClamAV is installed somewhere else, put its socket or program in the Options. To install it: Debian/Ubuntu "sudo apt install clamav-daemon"; RHEL/Rocky/Alma "sudo dnf install clamd clamav-update" (then enable clamd@scan); Windows: ClamAV for Windows, whose clamd listens on tcp://127.0.0.1:3310. On shared hosting ClamAV usually isn\'t available: leave scanning off, keep the file types narrow, and files are still never served directly. On a closed network, update its signatures from a local mirror or by copying them in.'];

        // Tables that can be read (0.10.2): what's listed, and what to be careful with.
        $out[] = self::readTables();

        // PHP handlers (0.10.0); the built-in starters (1.0.2).
        $on    = Handlers::starters();
        $off   = array_diff(array_keys(\SquidForm\Component\SquidForm\Administrator\Engine\Handler\Starter\Starter::ALL), $on);
        $out[] = ['label' => 'Starter handlers', 'status' => $on ? 'ok' : 'info', 'value' => $on ? implode(', ', $on) : 'None switched on',
            'help' => 'Built into SquidForm; tick the ones forms may use in Options → Developers → Starter handlers.' . ($off ? ' Not ticked: ' . implode(', ', $off) . '.' : '')
                . (\in_array('WriteFile', $on, true) && \SquidForm\Component\SquidForm\Administrator\Engine\Handler\Starter\WriteFile::folder() === null
                    ? ' Write a file is ticked but has no usable folder: set "Folder Write a file may use" to a full path outside the website that the web server can write to.' : '')];

        $hdir  = Handlers::folder();
        $mine  = array_values(array_diff(array_keys(Handlers::all()), $on));
        $out[] = ['label' => 'Your own PHP handlers', 'status' => 'info',
            'value' => is_dir($hdir) ? ($mine ? implode(', ', $mine) : 'None') : 'No folder',
            'help' => (is_dir($hdir)
                ? 'Folder: ' . $hdir . '. A developer adds a handler as a file there (TicketNumber-example.txt shows how). Forms run them with "Run PHP handler".'
                : 'The folder ' . $hdir . ' doesn\'t exist. It\'s made when SquidForm is installed or updated, or make it yourself (Options → Developers sets where it is).')];

        foreach (Handlers::problems() as $file => $why) {
            $out[] = ['label' => 'Handler ' . $file, 'status' => 'bad', 'value' => 'Not loaded', 'help' => $why];
        }

        return $out;
    }

    /** The read allow-list, with a word on tables that hold more than visitors should see. */
    private static function readTables(): array
    {
        $db    = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $names = preg_split('/[\s,]+/', (string) \Joomla\CMS\Component\ComponentHelper::getParams('com_squidform')->get('read_tables', ''), -1, PREG_SPLIT_NO_EMPTY);
        $lines = [];
        $warn  = false;

        foreach (\array_slice($names, 0, 100) as $n) {
            try {
                $name = Options::allowedTable($n, 'read');
                $cols = array_map('strtolower', array_keys($db->getTableColumns($name)));
                $risk = array_values(array_intersect(['state', 'published', 'access'], $cols));
                $lines[] = $name . ($risk ? '  (has ' . implode(', ', $risk) . ': forms should use them, or use a view)' : '');
                $warn = $warn || $risk;
            } catch (\Throwable $e) {
                $lines[] = $n . '  (not used: ' . $e->getMessage() . ')';
                $warn = true;
            }
        }

        $prefix = $db->getPrefix();

        return ['label' => 'Tables forms can read', 'status' => $warn ? 'warn' : 'info', 'value' => $lines ? implode("\n", $lines) : 'None (the default)',
            'help' => 'Options → Data Sources. Whatever a form reads, its visitors can see. Usually safe: #__categories and #__tags, and your own tables. '
                . 'A table with state/published or access columns also holds unpublished or members-only rows, and Read Data doesn\'t apply access levels: add rules for those columns, or allow-list a database view instead. '
                . 'For example, only published, public articles: CREATE VIEW ' . $prefix . 'sf_public_articles AS SELECT id, title, catid FROM ' . $prefix . 'content WHERE state = 1 AND access = 1; '
                . 'then add #__sf_public_articles.'];
    }

    /** Can the web server hand out a file from the folder? */
    private static function probe(string $dir): array
    {
        $label = 'Folder can\'t be read from the web';
        $root  = rtrim(str_replace('\\', '/', realpath(JPATH_ROOT) ?: JPATH_ROOT), '/');
        $real  = rtrim(str_replace('\\', '/', realpath($dir) ?: $dir), '/');
        $name  = 'sf-probe-' . bin2hex(random_bytes(6)) . '.txt';
        $mark  = 'squidform-probe-' . bin2hex(random_bytes(6));

        if (@file_put_contents($real . '/' . $name, $mark) === false) {
            return ['label' => $label, 'status' => 'warn', 'value' => 'Couldn\'t test', 'help' => 'The test file couldn\'t be written.'];
        }

        $url = rtrim(Uri::root(), '/') . substr($real, \strlen($root)) . '/' . $name;

        try {
            $res  = HttpFactory::getHttp([], ['curl', 'stream'])->get($url, [], 4);
            $open = $res->getStatusCode() === 200 && str_contains((string) $res->getBody(), $mark);

            return ['label' => $label, 'status' => $open ? 'bad' : 'ok', 'value' => $open ? 'No: the test file could be downloaded directly' : 'Yes (HTTP ' . $res->getStatusCode() . ')',
                'help' => $open ? 'This web server doesn\'t follow the deny-all files (nginx ignores .htaccess; some Apache sites don\'t allow it). Move the folder outside the site (best), or block it in the server\'s own settings, for example in nginx: location ^~ /' . trim(substr($real, \strlen($root)), '/') . '/ { deny all; }' : ''];
        } catch (\Throwable $e) {
            return ['label' => $label, 'status' => 'warn', 'value' => 'Couldn\'t test', 'help' => 'The server couldn\'t reach its own address (' . $url . '): ' . $e->getMessage() . '. Open that address in a browser: it should not show the file.'];
        } finally {
            @unlink($real . '/' . $name);
        }
    }

    private static function scanner(array $sc): array
    {
        $label = 'Virus scanning';

        if ($sc['mode'] === 'none') {
            return ['label' => $label, 'status' => 'warn', 'value' => 'Off', 'help' => 'Uploads aren\'t scanned. If ClamAV is found below, turn it on in Options → Uploads.'];
        }

        try {
            if ($sc['mode'] === 'clamd') {
                $v = Scanner::version($sc['address']);

                return ['label' => $label, 'status' => 'ok', 'value' => 'ClamAV daemon at ' . $sc['address'] . ': ' . $v, 'help' => 'Press "Test with the EICAR file" to check it finds a test virus.'];
            }

            if (Scanner::addon() === null) {
                return ['label' => $label, 'status' => 'bad', 'value' => 'ClamAV program ' . ($sc['command'] ?: '(not set)'), 'help' => 'The "ClamAV program" mode needs the SquidForm scanner add-on (plg_squidform_scannertools), which isn\'t installed or is switched off. Install it, or use the ClamAV daemon instead.'];
            }

            if (!Scanner::canRun()) {
                return ['label' => $label, 'status' => 'bad', 'value' => 'ClamAV program ' . $sc['command'], 'help' => 'PHP isn\'t allowed to run programs here. Use the ClamAV daemon instead, or ask your host.'];
            }

            $ok = $sc['command'] !== '' && is_file($sc['command']) && is_executable($sc['command']);

            return ['label' => $label, 'status' => $ok ? 'ok' : 'bad', 'value' => 'ClamAV program ' . ($sc['command'] ?: '(not set)'), 'help' => $ok ? 'Press "Test with the EICAR file" to check it finds a test virus.' : 'That program isn\'t there or can\'t be run.'];
        } catch (\Throwable $e) {
            return ['label' => $label, 'status' => 'bad', 'value' => 'ClamAV daemon at ' . ($sc['address'] ?: '(not set)'), 'help' => 'No answer: ' . $e->getMessage() . '. Is clamd running, and may the web server\'s user use its socket?'];
        }
    }
}
