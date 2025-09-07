<?php

use Lotgd\Translator;
//translator ready
//addnews ready
//mail ready

define("ALLOW_ANONYMOUS", true);
define("OVERRIDE_FORCED_NAV", true);
define("IS_INSTALLER", true);

use Lotgd\DataCache;
use Lotgd\Http;
use Lotgd\PageParts;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Nav;
use Lotgd\Settings;

//PHP 8.3 or higher is required for this version
//MySQL 5.0.3 and the mysqli extension are required for this version
$requirements_met = true;
$php_met = true;
$mysql_met = true;

if (version_compare(PHP_VERSION, '8.3.0') < 0) {
        $requirements_met = false;
        $php_met = false;
} elseif (!extension_loaded('mysqli')) {
        $requirements_met = false;
        $mysql_met = false;
} elseif (function_exists('mysqli_get_client_version') && mysqli_get_client_version() < 50003) {
        $requirements_met = false;
        $mysql_met = false;
}

if (!$requirements_met) {
    //we have NO output object possibly :( hence no nice formatting
    echo "<h1>Requirements not sufficient<br/><br/>";
    if (!$php_met) {
        echo sprintf("You need PHP 8.3 or higher to install this version. Please upgrade from your existing PHP version %s.<br/>", PHP_VERSION);
    }
    if (!$mysql_met && extension_loaded('mysqli') === false) {
        echo "The mysqli extension is missing. You need to enable the mysqli extension to install this version.<br/>";
    }
    if (!$mysql_met && function_exists('mysqli_get_client_info')) {
        echo sprintf("You need MySQL 5.0 or higher to install this version. Your current MySQL client version is %s.<br/>", mysqli_get_client_info());
    }
    exit(1);
}

if (!file_exists("dbconnect.php")) {
       define("DB_NODB", true);
}
chdir(__DIR__);

require_once __DIR__ . "/common.php";
if (file_exists("dbconnect.php")) {
    require_once __DIR__ . "/dbconnect.php";
}

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
    $session['dbinfo'] = array("DB_HOST" => "","DB_USER" => "","DB_PASS" => "","DB_NAME" => "");
}
if (
    file_exists("dbconnect.php") && (
    $stage == 3 ||
    $stage == 4 ||
    $stage == 5
    )
) {
        output("`%This stage was completed during a previous installation.");
        output("`2If you wish to perform stages 4 through 6 again, please delete the file named \"dbconnect.php\" from your site.`n`n");
        $stage = 6;
}
if ($stage > $session['stagecompleted']) {
    $session['stagecompleted'] = $stage;
}

Header::pageHeader("LoGD Installer - %s", $stages[$stage]);
$installer = new \Lotgd\Installer\Installer();
$installer->runStage($stage);


if (!$noinstallnavs) {
    if ($session['user']['loggedin']) {
        addnav("Back to the game", $session['user']['restorepage']);
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
