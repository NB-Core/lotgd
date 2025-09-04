<?php

declare(strict_types=1);

use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Translator;

require_once 'common.php';
require_once 'lib/http.php';

SuAccess::check(SU_EDIT_CONFIG);

Translator::getInstance()->setSchema('logviewer');

page_header('Log Viewer');
addnav('Navigation');
SuperuserNav::render();

$logDir = __DIR__ . '/logs';
$param = httpget('file');
$requested = $param !== false ? basename($param) : '';
$files = [];
if (is_dir($logDir)) {
    $files = array_values(array_filter(scandir($logDir), static function ($file) use ($logDir) {
        return is_file($logDir . '/' . $file) && substr($file, -4) === '.log';
    }));
}

addnav('Logs');
foreach ($files as $file) {
    addnav($file, "logviewer.php?file=" . rawurlencode($file));
}

if ($requested && in_array($requested, $files, true)) {
    $content = file_get_contents($logDir . '/' . $requested);
    rawoutput('<pre>');
    rawoutput(htmlentities($content));
    rawoutput('</pre>');
} else {
    output('Select a log file from the navigation to view its contents.');
}

page_footer();
