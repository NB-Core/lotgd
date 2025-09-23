<?php

use ErrorException;
use Exception;
use Lotgd\DataCache;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Output;
use Lotgd\Page\Footer;
use Lotgd\Page\Header;
use Lotgd\Settings;
use Lotgd\SuAccess;
use Lotgd\Translator;

require __DIR__ . "/common.php";

$output = Output::getInstance();
$settings = Settings::getInstance();

Translator::getInstance()->setSchema("corenews");
SuAccess::check(SU_MEGAUSER);

Header::pageHeader("Core News");

SuperuserNav::render();


$output->output("`4Latest release information will be retrieved from GitHub.`n`n");

$cacheKey = 'github_release_latest';
$release = DataCache::getInstance()->datacache($cacheKey, 86400);

if (!is_array($release)) {
    $context = stream_context_create([
        'http' => ['user_agent' => 'LotGD Core News']
    ]);
    $json = false;
    try {
        set_error_handler(function ($severity, $message, $file, $line) {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        $json = file_get_contents('https://api.github.com/repos/NB-Core/lotgd/releases/latest', false, $context);
    } catch (Exception $e) {
        error_log("Error fetching GitHub release information: " . $e->getMessage());
    } finally {
        restore_error_handler();
    }
    if ($json !== false) {
        $data = json_decode($json, true);
        if (is_array($data)) {
            $release = $data;
            DataCache::getInstance()->updatedatacache($cacheKey, $release);
        }
    }
}

if (is_array($release)) {
    $name = $release['name'] ?? ($release['tag_name'] ?? '');
    $tag = $release['tag_name'] ?? '';
    $published = isset($release['published_at']) ? substr($release['published_at'], 0, 10) : '';
    $url = $release['html_url'] ?? 'https://github.com/NB-Core/lotgd/releases';

    $charset = $settings->getSetting('charset', 'UTF-8');
    $output->output("`^Release:`0 %s`n", HTMLEntities($name, ENT_COMPAT, $charset));
    $output->output("`^Tag:`0 %s`n", HTMLEntities($tag, ENT_COMPAT, $charset));
    $output->output("`^Published:`0 %s`n", HTMLEntities($published, ENT_COMPAT, $charset));
    $output->outputNotl("`^Link:`0 <a href='" . HTMLEntities($url, ENT_COMPAT, $charset) . "' target='_blank'>" . HTMLEntities($url, ENT_COMPAT, $charset) . "</a>`n", true);
} else {
    $output->output("`4No release information available at this time.`n");
}
Footer::pageFooter();
