<?php

/**
 * @package     SquidForm
 * @subpackage  com_squidform
 * @license     GNU General Public License version 2 or later
 */

namespace SquidForm\Component\SquidForm\Administrator\Engine;

\defined('_JEXEC') or die;

/**
 * The optional scanner add-on (0.10.1, plg_squidform_scannertools): the
 * "ClamAV program" scan mode, which runs clamdscan or clamscan, and the
 * EICAR test file for Check this server. They're kept out of the main
 * package so it holds no test-virus string and runs no programs, which
 * keeps it clear of the upload and malware filters many organisations use.
 */
interface ScannerAddonInterface
{
    /** Whether PHP may run programs here (the add-on checks). */
    public function canRun(): bool;

    /** ClamAV programs found in the usual places. @return list<string> */
    public function findPrograms(): array;

    /**
     * Scan one file with a ClamAV program.
     *
     * @return array{status: string, detail: string}  Scanner::CLEAN, INFECTED or ERROR
     */
    public function scanFile(string $path, string $program): array;

    /** The EICAR test file's bytes. */
    public function testBytes(): string;
}
