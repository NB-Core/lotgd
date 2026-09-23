<?php

declare(strict_types=1);

use Lotgd\Translator;
use Lotgd\DataCache;
use Lotgd\Http;
use Lotgd\PageParts;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Nav;
use Lotgd\Settings;

//translator ready
//addnews ready
//mail ready

use Lotgd\Output;

define("ALLOW_ANONYMOUS", true);
define("OVERRIDE_FORCED_NAV", true);
define("IS_INSTALLER", true);

// Checked before common.php: without these the game cannot even report
// what is wrong. See Lotgd\Installer\Requirements for why it is loaded by
// hand rather than through Composer.
require_once __DIR__ . '/install/lib/Requirements.php';
$unmetRequirements = \Lotgd\Installer\Requirements::unmet();
if (function_exists('mysqli_get_client_version') && mysqli_get_client_version() < 50003) {
    $unmetRequirements[] = sprintf(
        'MySQL client library 5.0.3 or higher is required; this server has %s.',
        function_exists('mysqli_get_client_info') ? mysqli_get_client_info() : (string) mysqli_get_client_version()
    );
}

if ($unmetRequirements !== []) {
    //we have NO output object possibly :( hence no nice formatting
    echo '<h1>Requirements not met</h1><ul>';
    foreach ($unmetRequirements as $message) {
        echo '<li>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    echo '</ul>';
    exit(1);
}

chdir(__DIR__);

$dbConfig = [];
if (!file_exists("dbconnect.php")) {
    define("DB_NODB", true);
} else {
    $configFromFile = require __DIR__ . "/dbconnect.php";
    if (is_array($configFromFile)) {
        $dbConfig = $configFromFile;
    }
}

require_once __DIR__ . "/common.php";

$output = Output::getInstance();

// Load settings only when a database connection is available
$settings = null;
if (!defined('DB_NODB') || !DB_NODB) {
    try {
        $settings = new Settings();
    } catch (\Throwable $th) {
        $settings = null;
    }
}

$noinstallnavs = false;

DataCache::getInstance()->invalidatedatacache("gamesettings");
$DB_USEDATACACHE = 0;
//make sure we do not use the caching during this, else we might need to run  through the installer multiple times. AND we now need to reset the game settings, as these were due to faulty code not cached before.

Translator::getInstance()->setSchema("installer");

$stages = array(
    "1. Introduction",
    "2. License Agreement",
    "3. I Agree",
    "4. Database Info",
    "5. Test Database",
    "6. Examine Database",
    "7. Write dbconnect file",
    "8. Install Type",
    "9. Set Up Modules",
    "10. Build Tables",
    "11. Admin Accounts",
    "12. Done!",
);

// Get the recommended modules
require_once 'install/data/recommended_modules.php';

$DB_USEDATACACHE = 0; //Necessary


if ((int)Http::get("stage") > 0) {
    $stage = (int)Http::get("stage");
} else {
    $stage = 0;
}
if (!isset($session['stagecompleted'])) {
    $session['stagecompleted'] = -1;
}
if ($stage > $session['stagecompleted'] + 1) {
    $stage = $session['stagecompleted'];
}
if (!isset($session['dbinfo'])) {
    $session['dbinfo'] = array("DB_HOST" => "","DB_USER" => "","DB_PASS" => "","DB_NAME" => "","DB_PREFIX" => "");
}
if (
    file_exists("dbconnect.php") && (
    $stage == 3 ||
    $stage == 4
    )
) {
        $output->output("`%This stage was completed during a previous installation.");
        $output->output("`2If you wish to perform stages 3 and 4 again, please delete the file named \"dbconnect.php\" from your site.`n`n");
        $stage = 5;
}
if ($stage >= 5 && $dbConfig) {
    $keys = ['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME', 'DB_PREFIX'];
    foreach ($keys as $key) {
        if (array_key_exists($key, $dbConfig)) {
            $value = $dbConfig[$key];
            if (!is_string($value)) {
                $value = (string) $value;
            }
            $session['dbinfo'][$key] = $value;
        }
    }
    $_SESSION['dbinfo'] = $session['dbinfo'];
}
if ($stage > $session['stagecompleted']) {
    $session['stagecompleted'] = $stage;
}

Header::pageHeader("LoGD Installer - %s", $stages[$stage]);
$installer = new \Lotgd\Installer\Installer();
$installer->runStage($stage);


if (!$noinstallnavs) {
    if ($session['user']['loggedin']) {
        Nav::add("Back to the game", $session['user']['restorepage']);
    }
    Nav::add("Install Stages");

    for ($x = 0; $x <= min(count($stages) - 1, $session['stagecompleted'] + 1); $x++) {
        if ($x == $stage) {
            $stages[$x] = "`^{$stages[$x]} <----";
        }
               Nav::add($stages[$x], "installer.php?stage=$x");
    }
}
Footer::pageFooter(false);
