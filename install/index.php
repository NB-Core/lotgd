<?php

//translator ready
//addnews ready
//mail ready

define("ALLOW_ANONYMOUS",true);
define("OVERRIDE_FORCED_NAV",true);
define("IS_INSTALLER",true);


//PHP 7.4 or higher is required for this version
//MySQL 5.0.3 and the mysqli extension are required for this version
$requirements_met=true;
$php_met=true;
$mysql_met=true;

if (version_compare(PHP_VERSION, '7.4.0') < 0) {
        $requirements_met=false;
        $php_met=false;
} elseif (!extension_loaded('mysqli')) {
        $requirements_met=false;
        $mysql_met=false;
} elseif (function_exists('mysqli_get_client_version') && mysqli_get_client_version() < 50003) {
        $requirements_met=false;
        $mysql_met=false;
}

if (!$requirements_met) {
	//we have NO output object possibly :( hence no nice formatting
    echo "<h1>Requirements not sufficient<br/><br/>";
    if (!$php_met) echo sprintf("You need PHP 7.4 or higher to install this version. Please upgrade from your existing PHP version %s.<br/>",PHP_VERSION);
    if (!$mysql_met && extension_loaded('mysqli') === false) {
        echo "The mysqli extension is missing. You need to enable the mysqli extension to install this version.<br/>";
    }
    if (!$mysql_met && function_exists('mysqli_get_client_info')) echo sprintf("You need MySQL 5.0 or higher to install this version. Your current MySQL client version is %s.<br/>",mysqli_get_client_info());
	exit(1);
}

if (!file_exists("dbconnect.php")){
       define("DB_NODB",true);
}
chdir(__DIR__ . '/..');

require_once("common.php");
if (file_exists("dbconnect.php")){
       require_once("dbconnect.php");
}

$noinstallnavs=false;

invalidatedatacache("gamesettings");
$DB_USEDATACACHE = 0;
//make sure we do not use the caching during this, else we might need to run  through the installer multiple times. AND we now need to reset the game settings, as these were due to faulty code not cached before.

tlschema("installer");

$stages=array(
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

$recommended_modules = array(
	"abigail",
	"breakin",
	"calendar",
	"cedrikspotions",
//	"cities", //I don't think this is good for most people.
	"collapse",
	"crazyaudrey",
	"crying",
	"dag",
	"darkhorse",
	"distress",
	"dragonattack",
	"drinks",
	"drunkard",
	"expbar",
	"fairy",
	"findgem",
	"findgold",
	"foilwench",
	"forestturn",
	"game_dice",
	"game_stones",
	"gardenparty",
	"ghosttown",
	"glowingstream",
	"goldmine",
	"grassyfield",
	"haberdasher",
	"healthbar",
	"innchat",
	"kitchen",
	"klutz",
	"lottery",
	"lovers",
	"newbieisland",
	"oldman",
	"outhouse",
	"peerpressure",
	"petra",
	"racedwarf",
	"raceelf",
	"racehuman",
	"racetroll",
	"riddles",
	"salesman",
	"sethsong",
	"smith",
	"soulgem",
	"spa",
	"specialtydarkarts",
	"specialtymysticpower",
	"specialtythiefskills",
	"statue",
	"stocks",
	"stonehenge",
	"strategyhut",
	"thieves",
	"tutor",
	"tynan",
	"waterfall",
);

$DB_USEDATACACHE=0; //Necessary


if ((int)httpget("stage")>0)
	$stage = (int)httpget("stage");
else
	$stage = 0;
if (!isset($session['stagecompleted'])) $session['stagecompleted']=-1;
if ($stage > $session['stagecompleted']+1) $stage = $session['stagecompleted'];
if (!isset($session['dbinfo'])) $session['dbinfo']=array("DB_HOST"=>"","DB_USER"=>"","DB_PASS"=>"","DB_NAME"=>"");
if (file_exists("dbconnect.php") && (
	$stage==3 ||
	$stage==4 ||
	$stage==5
	)){
		output("`%This stage was completed during a previous installation.");
		output("`2If you wish to perform stages 4 through 6 again, please delete the file named \"dbconnect.php\" from your site.`n`n");
		$stage=6;
	}
if ($stage > $session['stagecompleted']) $session['stagecompleted'] = $stage;

page_header("LoGD Installer &#151; %s",$stages[$stage]);
$installer = new \Lotgd\Installer\Installer();
$installer->runStage($stage);


if (!$noinstallnavs){
	if ($session['user']['loggedin']) addnav("Back to the game",$session['user']['restorepage']);
	addnav("Install Stages");

	for ($x=0; $x<=min(count($stages)-1,$session['stagecompleted']+1); $x++){
		if ($x == $stage) $stages[$x]="`^{$stages[$x]} <----";
               addnav($stages[$x],"install/index.php?stage=$x");
	}
}
page_footer(false);

?>
