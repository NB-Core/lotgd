<?php

declare(strict_types=1);

use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Translator;
use Lotgd\Nav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Http;
use Lotgd\Installer\InstallerLogger;


use Lotgd\Output;

require_once __DIR__ . '/common.php';

$output = Output::getInstance();

SuAccess::check(SU_EDIT_CONFIG);

Translator::getInstance()->setSchema('logviewer');

Header::pageHeader('Log Viewer');
Nav::add('Navigation');
SuperuserNav::render();

$logDir = __DIR__ . '/logs';
$param = Http::get('file');
$requested = $param !== false ? basename($param) : '';

// Label => absolute path. Building the map first keeps the basename() allow-list
// intact while letting logs that live outside logs/ appear here too.
$files = [];
if (is_dir($logDir)) {
    foreach (scandir($logDir) ?: [] as $file) {
        if (is_file($logDir . '/' . $file) && substr($file, -4) === '.log') {
            $files[$file] = $logDir . '/' . $file;
        }
    }
}

// The installer writes to errors/install.log, or to LOTGD_STATE_PATH when the
// deployment sets one, so it never showed up in this list even though it is the
// log an operator most wants after a failed install or upgrade. The class check
// keeps this working on installs that delete install/ after setup.
if (class_exists(InstallerLogger::class)) {
    $installLog = InstallerLogger::getLogFilePath();
    if (is_file($installLog) && !array_key_exists(basename($installLog), $files)) {
        $files[basename($installLog)] = $installLog;
    }
}

ksort($files);

Nav::add('Logs');
foreach (array_keys($files) as $file) {
    Nav::add($file, "logviewer.php?file=" . rawurlencode($file));
}

if ($requested !== '' && array_key_exists($requested, $files)) {
    $content = (string) file_get_contents($files[$requested]);
    $output->rawOutput('<pre>');
    $output->rawOutput(htmlentities($content));
    $output->rawOutput('</pre>');
} else {
    $output->output('Select a log file from the navigation to view its contents.');
}

Footer::pageFooter();
