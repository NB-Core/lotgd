<?php

declare(strict_types=1);

use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Translator;
use Lotgd\Nav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Http;


require_once 'common.php';

SuAccess::check(SU_EDIT_CONFIG);

Translator::getInstance()->setSchema('logviewer');

Header::pageHeader('Log Viewer');
Nav::add('Navigation');
SuperuserNav::render();

$logDir = __DIR__ . '/logs';
$param = Http::get('file');
$requested = $param !== false ? basename($param) : '';
$files = [];
if (is_dir($logDir)) {
    $files = array_values(array_filter(scandir($logDir), static function ($file) use ($logDir) {
        return is_file($logDir . '/' . $file) && substr($file, -4) === '.log';
    }));
}

Nav::add('Logs');
foreach ($files as $file) {
    Nav::add($file, "logviewer.php?file=" . rawurlencode($file));
}

if ($requested && in_array($requested, $files, true)) {
    $content = file_get_contents($logDir . '/' . $requested);
    $output->rawOutput('<pre>');
    $output->rawOutput(htmlentities($content));
    $output->rawOutput('</pre>');
} else {
    $output->output('Select a log file from the navigation to view its contents.');
}

Footer::pageFooter();
