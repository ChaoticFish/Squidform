<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Uploaded files (0.9.0).
 *
 * The site admin decides, in Options → Uploads: the folder, the file types,
 * the largest size, whether files are stored under random names, and the
 * virus scanner. A field can only narrow those (fewer types, a smaller size).
 *
 * A file is checked when it arrives: size, an allowed extension, and that
 * its content really is that kind of file (PHP's fileinfo). Then it is
 * scanned, when a scanner is set. It is stored as <folder>/<form id>/<yyyy-mm>/<name>
 * and recorded in #__squidform_submission_files as "pending" (submission 0,
 * tied to the visitor's session) until the form is saved. Pending files left
 * behind are removed after a day.
 *
 * Files are never served from the folder: the folder gets deny-all
 * .htaccess and web.config files, and downloads go through SquidForm, which
 * checks who may see the submission and always sends them as attachments.
 */
final class Uploads
{
    /** Extensions and the content types fileinfo may report for them. */
    public const TYPES = [
        'pdf'  => ['application/pdf'],
        'jpg'  => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'gif' => ['image/gif'], 'webp' => ['image/webp'],
        'heic' => ['image/heic', 'image/heif'], 'tif' => ['image/tiff'], 'tiff' => ['image/tiff'],
        'txt'  => ['text/plain'], 'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'doc'  => ['application/msword', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/CDFV2'],
        'xls'  => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/CDFV2'],
        'ppt'  => ['application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/CDFV2'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
        'odt'  => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'ods'  => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
        'odp'  => ['application/vnd.oasis.opendocument.presentation', 'application/zip'],
        'rtf'  => ['text/rtf', 'application/rtf'],
        'zip'  => ['application/zip'],
        'mp3'  => ['audio/mpeg'], 'mp4' => ['video/mp4'], 'mov' => ['video/quicktime'],
    ];

    public const DEFAULT_TYPES = 'pdf,jpg,jpeg,png,gif,webp,txt,csv,doc,docx,xls,xlsx,ppt,pptx,odt,ods';

    /** Never accepted, whatever the Options say: things a web server or browser could run. */
    public const NEVER = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'phps', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash',
        'asp', 'aspx', 'jsp', 'exe', 'dll', 'com', 'bat', 'cmd', 'msi', 'scr', 'vbs', 'js', 'mjs', 'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz',
        'xml', 'htaccess', 'ini', 'jar', 'swf', 'hta', 'ps1', 'lnk'];

    /** @return array{folder: string, types: list<string>, max_mb: int, random: bool, scan_fail: string} */
    public static function settings(): array
    {
        $p     = ComponentHelper::getParams('com_squidform');
        $types = array_values(array_unique(array_filter(array_map(
            static fn ($t) => strtolower(trim($t, " .\t\n")),
            explode(',', (string) $p->get('upload_types', self::DEFAULT_TYPES))
        ), static fn ($t) => $t !== '' && preg_match('/^[a-z0-9]{1,10}$/', $t) && !\in_array($t, self::NEVER, true))));

        return [
            'folder'    => self::folder((string) $p->get('upload_folder', 'squidform-files')),
            'types'     => $types,
            'max_mb'    => max(1, min(2048, (int) $p->get('upload_max_mb', 10))),
            'random'    => (int) $p->get('upload_random_names', 1) === 1,
            'scan_fail' => $p->get('scan_fail', 'reject') === 'accept' ? 'accept' : 'reject',
        ];
    }

    /** The folder as an absolute path: absolute as given, else under the site root. */
    public static function folder(string $setting): string
    {
        $s = trim(str_replace('\\', '/', $setting));
        $s = $s === '' ? 'squidform-files' : $s;

        if ($s[0] === '/' || preg_match('#^[A-Za-z]:/#', $s)) {
            return rtrim($s, '/');
        }

        return rtrim(str_replace('\\', '/', JPATH_ROOT), '/') . '/' . trim(str_replace('..', '', $s), '/');
    }

    /** Is the folder inside the web site's own folder (so a web server could serve it)? */
    public static function insideWebRoot(string $folder): bool
    {
        $root = rtrim(str_replace('\\', '/', realpath(JPATH_ROOT) ?: JPATH_ROOT), '/') . '/';
        $real = rtrim(str_replace('\\', '/', realpath($folder) ?: $folder), '/') . '/';

        return str_starts_with($real, $root);
    }

    /** The largest upload PHP itself allows, in bytes. */
    public static function phpLimit(): int
    {
        $b = static function (string $v): int {
            $v = trim($v);
            $n = (int) $v;

            return match (strtolower(substr($v, -1))) {
                'g' => $n * 1073741824,
                'm' => $n * 1048576,
                'k' => $n * 1024,
                default => $n,
            };
        };

        $u = $b((string) \ini_get('upload_max_filesize'));
        $p = $b((string) \ini_get('post_max_size'));

        return $p > 0 ? min($u, $p) : $u;
    }

    /** Make the folder (and its deny-all files) if it isn't there. */
    public static function ensureFolder(string $folder): void
    {
        if (!is_dir($folder) && !@mkdir($folder, 0750, true) && !is_dir($folder)) {
            throw new \RuntimeException('can’t create the upload folder');
        }

        $deny = [
            '.htaccess'  => "# SquidForm: never serve these files directly.\nRequire all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization>"
                . "<security><authorization><add accessType=\"Deny\" users=\"*\" /></authorization></security></system.webServer></configuration>\n",
            'index.html' => "<!DOCTYPE html><title></title>\n",
        ];

        foreach ($deny as $name => $body) {
            if (!is_file($folder . '/' . $name)) {
                @file_put_contents($folder . '/' . $name, $body);
            }
        }
    }

    /**
     * The types a field accepts: its own "accept" list within the Options.
     *
     * @return list<string>
     */
    public static function typesFor(array $field): array
    {
        $all = self::settings()['types'];
        $own = \is_array($field['accept'] ?? null) ? array_map(static fn ($t) => strtolower(ltrim((string) $t, '.')), $field['accept']) : [];

        return $own ? array_values(array_intersect($all, $own)) : $all;
    }

    public static function maxBytesFor(array $field): int
    {
        $mb = self::settings()['max_mb'];

        if (isset($field['max_mb']) && (int) $field['max_mb'] > 0) {
            $mb = min($mb, (int) $field['max_mb']);
        }

        return min($mb * 1048576, self::phpLimit() ?: PHP_INT_MAX);
    }

    /** The visitor's session, hashed: ties pending files to whoever sent them. */
    public static function owner(): string
    {
        return hash('sha256', 'squidform-files|' . Factory::getApplication()->getSession()->getId());
    }

    /**
     * Take files from one upload field on one page.
     *
     * @param  list<array{name?: string, tmp_name?: string, error?: int, size?: int}> $files  from the request
     * @param  list<string> $keep  uids of files already uploaded that stay
     * @param  list<string> $known uids this visitor uploaded earlier for this field (from the form state)
     *
     * @return array{uids: list<string>, errors: list<string>}
     */
    public static function take(int $formId, array $field, array $files, array $keep, array $known): array
    {
        $label  = (string) ($field['label'] ?? $field['key']);
        $uids   = array_values(array_intersect($keep, $known));
        $errors = [];
        $max    = max(1, min(20, (int) ($field['max_files'] ?? 1)));
        $types  = self::typesFor($field);
        $bytes  = self::maxBytesFor($field);
        $cfg    = self::settings();

        foreach ($files as $f) {
            $err = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);

            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $name = self::cleanName((string) ($f['name'] ?? 'file'));

            if (\count($uids) >= $max) {
                $errors[] = Text::sprintf('COM_SQUIDFORM_ERR_FILE_COUNT', $label, $max);
                break;
            }

            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || (int) ($f['size'] ?? 0) > $bytes) {
                $errors[] = Text::sprintf('COM_SQUIDFORM_ERR_FILE_SIZE', $name, self::size($bytes));
                continue;
            }

            if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($f['tmp_name'] ?? ''))) {
                $errors[] = Text::sprintf('COM_SQUIDFORM_ERR_FILE_FAILED', $name);
                Log::add('SquidForm upload failed with PHP code ' . $err, Log::WARNING, 'com_squidform');
                continue;
            }

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if ($ext === '' || !\in_array($ext, $types, true)) {
                $errors[] = Text::sprintf('COM_SQUIDFORM_ERR_FILE_TYPE', $name, implode(', ', $types));
                continue;
            }

            $tmp  = (string) $f['tmp_name'];
            $mime = self::sniff($tmp);

            if (!self::mimeFits($ext, $mime)) {
                $errors[] = Text::sprintf('COM_SQUIDFORM_ERR_FILE_CONTENT', $name, strtoupper($ext));
                Log::add('SquidForm refused ' . $name . ': content looks like ' . $mime, Log::WARNING, 'com_squidform');
                continue;
            }

            // The virus check, before anything is kept.
            $scan = ['status' => 'not_scanned', 'detail' => ''];

            if (Scanner::config()['mode'] !== 'none') {
                $scan = Scanner::scanFile($tmp);

                if ($scan['status'] === Scanner::INFECTED) {
                    $errors[] = Text::sprintf('COM_SQUIDFORM_ERR_FILE_VIRUS', $name);
                    Audit::log(null, $formId, (int) (Factory::getApplication()->getIdentity()?->id ?? 0), 'upload_infected', ['file' => $name, 'found' => $scan['detail']]);
                    @unlink($tmp);
                    continue;
                }

                if ($scan['status'] === Scanner::ERROR) {
                    Log::add('SquidForm couldn’t scan ' . $name . ': ' . $scan['detail'], Log::WARNING, 'com_squidform');

                    if ($cfg['scan_fail'] !== 'accept') {
                        $errors[] = Text::sprintf('COM_SQUIDFORM_ERR_FILE_NOSCAN', $name);
                        continue;
                    }

                    $scan['status'] = 'not_scanned';
                }
            }

            try {
                $uids[] = self::store($formId, (string) $field['key'], $tmp, $name, $mime, $scan, $cfg);
            } catch (\Throwable $e) {
                $errors[] = Text::sprintf('COM_SQUIDFORM_ERR_FILE_FAILED', $name);
                Log::add('SquidForm couldn’t store an upload: ' . $e->getMessage(), Log::ERROR, 'com_squidform');
            }
        }

        // Files the visitor took off the list go now.
        $dropped = array_diff($known, $uids);

        if ($dropped) {
            self::removePending(array_values($dropped));
        }

        self::sweep();

        return ['uids' => array_values($uids), 'errors' => $errors];
    }

    /** Name for people: no path, no control characters, at most 200 characters. */
    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace('/[\x00-\x1f\x7f"<>:|?*]+/u', '', $name), " .");

        return mb_substr($name !== '' ? $name : 'file', -200);
    }

    public static function sniff(string $path): string
    {
        if (!\function_exists('finfo_open')) {
            return 'application/octet-stream';
        }

        $fi   = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $fi ? (string) finfo_file($fi, $path) : '';

        return $mime !== '' ? $mime : 'application/octet-stream';
    }

    /** Does the content match the extension? Types SquidForm doesn't know only need to not be something runnable. */
    public static function mimeFits(string $ext, string $mime): bool
    {
        if (isset(self::TYPES[$ext])) {
            return \in_array($mime, self::TYPES[$ext], true) || ($mime === 'text/plain' && \in_array($ext, ['csv', 'txt'], true));
        }

        return !preg_match('#^(text/(html|x-|javascript)|application/(x-(php|sh|executable|dosexec|msdownload|sharedlib)|javascript|xhtml)|image/svg)#', $mime);
    }

    /** @return string the file's uid */
    private static function store(int $formId, string $key, string $tmp, string $name, string $mime, array $scan, array $cfg): string
    {
        self::ensureFolder($cfg['folder']);
        $uid  = bin2hex(random_bytes(16));
        $sub  = $formId . '/' . gmdate('Y-m');
        $dir  = $cfg['folder'] . '/' . $sub;

        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('can’t create ' . $sub);
        }

        // Random name (default): nothing about the file is in its name on disk.
        $stored = $cfg['random'] ? $uid : self::diskName($dir, $name);
        $target = $dir . '/' . $stored;
        if (!@move_uploaded_file($tmp, $target)) {
            throw new \RuntimeException('can’t move the file into ' . $sub);
        }

        @chmod($target, 0640);
        $db  = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $row = (object) [
            'uid'           => $uid,
            'form_id'       => $formId,
            'submission_id' => 0,
            'field_key'     => $key,
            'stored_name'   => $stored,
            'original_name' => $name,
            'mime'          => mb_substr($mime, 0, 127),
            'size'          => (int) filesize($target),
            'sha256'        => (string) hash_file('sha256', $target),
            'path'          => $sub . '/' . $stored,
            'scan'          => $scan['status'],
            'owner'         => self::owner(),
            'created'       => Factory::getDate()->toSql(),
            'created_by'    => (int) (Factory::getApplication()->getIdentity()?->id ?? 0),
        ];
        $db->insertObject('#__squidform_submission_files', $row);

        return $uid;
    }

    /** Original name made safe and unique in its folder (when random names are off). */
    private static function diskName(string $dir, string $name): string
    {
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($name, PATHINFO_FILENAME)) ?: 'file';
        $base = substr(trim($base, '.-'), 0, 80) ?: 'file';
        $try  = $base . '.' . $ext;

        for ($n = 2; is_file($dir . '/' . $try); $n++) {
            $try = $base . '-' . $n . '.' . $ext;
        }

        return $try;
    }

    /** @return array<string, object> uid => row */
    public static function rows(array $uids): array
    {
        $uids = array_values(array_filter($uids, static fn ($u) => \is_string($u) && preg_match('/^[0-9a-f]{32}$/', $u)));

        if (!$uids) {
            return [];
        }

        $db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $q  = $db->createQuery()->select('*')->from($db->quoteName('#__squidform_submission_files'))->whereIn($db->quoteName('uid'), $uids, ParameterType::STRING);
        $out = [];

        foreach ($db->setQuery($q)->loadObjectList() as $r) {
            $out[$r->uid] = $r;
        }

        return $out;
    }

    public static function byUid(string $uid): ?object
    {
        return self::rows([$uid])[$uid] ?? null;
    }

    /** The pending files of this visitor, kept when a submission is saved. */
    public static function attach(int $submissionId, array $uids): void
    {
        $uids = array_values(array_filter($uids, static fn ($u) => \is_string($u) && preg_match('/^[0-9a-f]{32}$/', $u)));

        if (!$uids) {
            return;
        }

        $db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $q  = $db->createQuery()->update($db->quoteName('#__squidform_submission_files'))
            ->set($db->quoteName('submission_id') . ' = ' . (int) $submissionId)
            ->set($db->quoteName('owner') . " = ''")
            ->where($db->quoteName('submission_id') . ' = 0')
            ->whereIn($db->quoteName('uid'), $uids, ParameterType::STRING);
        $db->setQuery($q)->execute();
    }

    public static function pathOf(object $row): string
    {
        return self::settings()['folder'] . '/' . str_replace('..', '', (string) $row->path);
    }

    /** Remove pending files (from this visitor) by uid. */
    public static function removePending(array $uids): void
    {
        $owner = self::owner();
        $rows  = array_filter(self::rows($uids), static fn ($r) => (int) $r->submission_id === 0 && hash_equals((string) $r->owner, $owner));
        self::remove($rows);
    }

    /** Delete the files of these submissions, from disk and the table. */
    public static function deleteForSubmissions(array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if (!$ids) {
            return;
        }

        $db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        self::remove($db->setQuery($db->createQuery()->select('*')->from($db->quoteName('#__squidform_submission_files'))->whereIn($db->quoteName('submission_id'), $ids))->loadObjectList());
    }

    /** Every file of a form, pending ones too (when the form is deleted for good). */
    public static function deleteForForm(int $formId): void
    {
        $db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        self::remove($db->setQuery($db->createQuery()->select('*')->from($db->quoteName('#__squidform_submission_files'))->where($db->quoteName('form_id') . ' = ' . (int) $formId))->loadObjectList());
    }

    /** Remove pending files held by a saved draft (0.11.0) when the draft goes. */
    public static function removeOwned(array $uids, string $owner): void
    {
        self::remove(array_filter(self::rows($uids), static fn ($r) => (int) $r->submission_id === 0 && hash_equals((string) $r->owner, $owner)));
    }

    /** Pending files nobody saved within a day (files of saved drafts stay with their draft). */
    public static function sweep(): void
    {
        $db  = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $old = Factory::getDate('-1 day')->toSql();
        $q   = $db->createQuery()->select('*')->from($db->quoteName('#__squidform_submission_files'))
            ->where($db->quoteName('submission_id') . ' = 0')->where($db->quoteName('created') . ' < :old')
            ->where($db->quoteName('owner') . ' NOT LIKE ' . $db->quote('resume:%'))->bind(':old', $old)->setLimit(200);
        self::remove($db->setQuery($q)->loadObjectList());
    }

    private static function remove(iterable $rows): void
    {
        $db  = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $ids = [];

        foreach ($rows as $r) {
            $p = self::pathOf($r);

            if (is_file($p)) {
                @unlink($p);
            }

            $ids[] = (int) $r->id;
        }

        if ($ids) {
            $db->setQuery($db->createQuery()->delete($db->quoteName('#__squidform_submission_files'))->whereIn($db->quoteName('id'), $ids))->execute();
        }
    }

    /**
     * Links to the files on the site (0.9.1, for emails): the site's own
     * download, which still checks the reader may see the submission.
     *
     * @return list<array{name: string, size: string, url: string}>
     */
    public static function links(array $uids): array
    {
        $rows = self::rows($uids);
        $root = rtrim(\Joomla\CMS\Uri\Uri::root(), '/');
        $out  = [];

        foreach ($uids as $u) {
            if (isset($rows[$u])) {
                $out[] = ['name' => (string) $rows[$u]->original_name, 'size' => self::size((int) $rows[$u]->size),
                    'url' => $root . '/index.php?option=com_squidform&task=file.download&uid=' . $u];
            }
        }

        return $out;
    }

    public static function size(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
    }
}
