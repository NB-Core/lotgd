<?php

declare(strict_types=1);

/**
 * Handles the multi step installation of Legend of the Green Dragon.
 *
 * Each public stage method represents a step in the installer wizard.
 */

namespace Lotgd\Installer;

use Lotgd\MySQL\Database;
use Lotgd\Output;
use Lotgd\Http;
use Lotgd\Nav;
use Lotgd\Translator;
use Lotgd\Redirect;
use Lotgd\Settings;
use Lotgd\Modules\Installer as ModuleInstaller;
use Lotgd\Doctrine\Bootstrap;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager;
use Doctrine\Migrations\Version\Version;
use Doctrine\Migrations\Version\ExecutionResult;
use Symfony\Component\Console\Input\ArrayInput;

class Installer
{
    /**
     * Counter for unique HTML tip identifiers used by the tip() helper.
     *
     * @var int
    */
    private int $tipid = 0;
    private Output $output;

    public function __construct()
    {
        $this->output = Output::getInstance();
    }

    /**
     * Dynamically call one of the stage methods by number.
     */
    public function runStage(int $stage): void
    {
        if ($stage === 6 && ! $this->verifyInstallDirectoryWritable()) {
            return;
        }

        $method = 'stage' . $stage;
        if (method_exists($this, $method)) {
            $this->$method();
        } else {
            $this->stageDefault();
        }
    }

    // region Stage Methods

    /**
     * Stage 0 - Display welcome text and verify upgrade credentials if needed.
     */
    public function stage0(): void
    {
        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;
        $this->output->output("`@`c`bWelcome to Legend of the Green Dragon`b`c`0");
        $this->output->output("`2This is the installer script for Legend of the Green Dragon, by Eric Stevens & JT Traub.`n");
        $this->output->output("`nIn order to install and use Legend of the Green Dragon (LoGD), you must agree to the license under which it is deployed.`n");
        $this->output->output("`n`&This game is a small project into which we have invested a tremendous amount of personal effort, and we provide this to you absolutely free of charge.`2");
        $this->output->output("Please understand that if you modify our copyright, or otherwise violate the license, you are not only breaking international copyright law (which includes penalties which are defined in whichever country you live), but you're also defeating the spirit of open source, and ruining any good faith which we have demonstrated by providing our blood, sweat, and tears to you free of charge.  You should also know that by breaking the license even one time, it is within our rights to require you to permanently cease running LoGD forever.`n");
        $this->output->output("`nPlease note that in order to use the installer, you must have cookies enabled in your browser.`n");

        // Check sys_get_temp_dir()
        $dir = sys_get_temp_dir();
        if (empty($dir) || !is_writable($dir)) {
            $this->output->output("`\$Important note: Your PHP setup is not configured correctly.`n");
            $this->output->output("`2The temporary directory (`#sys_get_temp_dir()`2) is either empty or not writable (Path given:'%s').`n", $dir);
            $this->output->output("`2Please make sure that your PHP configuration has a valid and writable temporary directory set.`n");
            $this->output->output("`2For more information, see e.g. <a href='https://www.php.net/manual/en/function.sys-get-temp-dir.php' target='_blank'>PHP sys_get_temp_dir documentation</a> or <a href='https://www.php.net/manual/en/ini.core.php#ini.upload-tmp-dir' target='_blank'>upload_tmp_dir configuration</a>.`n", true);
            $this->output->output("`2`nWithout a valid temporary directory writeable, we cannot move on. Check also your webserver settings if this is accessible.`n");
            $this->output->output("`ni.e. with apache2 you need to have it in openbasedir: 'php_admin_value open_basedir /var/www /tmp' (and then there is a private temp for you under there.");
            $this->output->output("`\$The installation may not be able to continue until this problem is resolved.`n");
        }

        if (getenv("MYSQL_HOST")) {
                                    $this->output->output("`n`\$This seems to be a Docker setup, which means the database will be provided by the environment variables. You can change them if you want, but most likely you won't be able to connect to the database.`2`n");
                                    $this->output->output("Also, you need to take care of other hosting issues, like SSL, possibly Let's encrypt and other things.");
                                    $this->output->output("`n`nNote that the entire html folder is volume-linked, so the database connection file and more will be stored there, too.");
        }
        if (defined("DB_CHOSEN") && DB_CHOSEN) {
                                    $sql = "SELECT count(*) AS c FROM " . Database::prefix("accounts") . " WHERE superuser & " . SU_MEGAUSER;
                                    $result = Database::query($sql);
                                    $row = Database::fetchAssoc($result);
            if ($row['c'] == 0) {
                $needsauthentication = false;
            }
            if (!empty(Http::post("username"))) {
                //if you have login troubles and wiped your own password, here in the installer you can debug-output it
                //$this->output->debug(md5(md5(stripslashes(Http::post("password")))), true);
                $sql = "SELECT * FROM " . Database::prefix("accounts") . " WHERE login='" . Http::post("username") . "' AND password='" . md5(md5(stripslashes(Http::post("password")))) . "' AND superuser & " . SU_MEGAUSER;
                $result = Database::query($sql);
                if (Database::numRows($result) > 0) {
                    $row = Database::fetchAssoc($result);
                    //$this->output->debug($row['password'], true);
                    //$this->output->debug(Http::post('password'), true);
                    // Okay, we have a username with megauser, now we need to do
                    // some hackery with the password.
                    $needsauthentication = true;
                    $p = stripslashes(Http::post("password"));
                    $p1 = md5($p);
                    $p2 = md5($p1);
                    $this->output->debug($p2, true);

                    if ($this->getSetting("installer_version", "-1") == "-1") {
                        $this->output->debug("HERE I AM", true);
                        // Okay, they are upgrading from 0.9.7  they will have
                        // either a non-encrypted password, or an encrypted singly
                        // password.
                        if (
                            strlen($row['password']) == 32 &&
                            $row['password'] == $p1
                        ) {
                            $needsauthentication = false;
                        } elseif ($row['password'] == $p) {
                            $needsauthentication = false;
                        }
                    } elseif ($row['password'] == $p2) {
                        $needsauthentication = false;
                    }
                    if ($needsauthentication === false) {
                        Redirect::redirect("installer.php?stage=1");
                    }
                    $this->output->output("`\$That username / password was not found, or is not an account with sufficient privileges to perform the upgrade.`n");
                } else {
                    $needsauthentication = true;
                    $this->output->output("`\$That username / password was not found, or is not an account with sufficient privileges to perform the upgrade.`n");
                }
            } else {
                $sql = "SELECT count(*) AS c FROM " . Database::prefix("accounts") . " WHERE superuser & " . SU_MEGAUSER;
                $result = Database::query($sql);
                $row = Database::fetchAssoc($result);
                if ($row['c'] > 0) {
                    $needsauthentication = true;
                } else {
                    $needsauthentication = false;
                }
            }
        } else {
                                    $needsauthentication = false;
        }
        //if a user with appropriate privs is already logged in, let's let them past.
        if ($session['user']['superuser'] & SU_MEGAUSER) {
            $needsauthentication = false;
        }
        if ($needsauthentication) {
            $session['stagecompleted'] = -1;
            $this->output->rawOutput("<form action='installer.php?stage=0' method='POST'>");
            $this->output->output("`%In order to upgrade this LoGD installation, you will need to provide the username and password of a superuser account with the MEGAUSER privilege`n");
            $this->output->output("`^Username: `0");
            $this->output->rawOutput("<input name='username'><br>");
            $this->output->output("`^Password: `0");
            $this->output->rawOutput("<input type='password' name='password'><br>");
            $submit = Translator::translateInline("Submit");
            $this->output->rawOutput("<input type='submit' value='$submit' class='button'>");
            $this->output->rawOutput("</form>");
        } else {
            $this->output->output("`nPlease continue on to the next page, \"License Agreement.\"");
        }
    }

    /**
     * Stage 1 - Show the license agreement that must be accepted.
     */
    public function stage1(): void
    {
        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;
        $this->output->output("`@`c`bLicense Agreement`b`c`0");
        $this->output->output("`2Before continuing, you must read and understand the following license agreement.`0`n`n");
        $this->output->output("The license may be referenced at <a target='_blank' href='http://creativecommons.org/licenses/by-nc-sa/2.0/legalcode'>the Creative Commons site</a>.", true);

        // Check LICENSE.txt file for integrity
        $licenseFile = __DIR__ . '/../../LICENSE.txt';
        if (!file_exists($licenseFile)) {
            $this->output->output("`\$The license file (LICENSE.txt) could not be found.`n");
            $this->output->output("`2Please make sure that the LICENSE.txt file is located in the root directory of your installation.`n");
            $this->output->output("`2Without this file, the installation cannot continue.`n");
            $stage = 1; // Stay on this stage
            $session['stagecompleted'] = $stage - 1;
            return;
        }
        $license = file_get_contents($licenseFile);
        $license = preg_replace("/[^\na-zA-Z0-9!?.,;:'\"\/\\()@ -\]\[]/", "", $license);
        $licensemd5s = array(
            'e281e13a86d4418a166d2ddfcd1e8032' => true, //old for DP
            'bc9f6fb23e352600d6c1c948298cbd82' => true, //new for +nb
            '072d59d3dc6722cb8557575953cd0b34' => true, //new for +nb with no line breaks
        );
        if (isset($licensemd5s[md5($license)])) {
            // Reload it so we get the right line breaks, etc.
            $license = htmlentities($license, ENT_COMPAT, $this->getSetting("charset", "UTF-8"));
            $license = nl2br($license);
            $this->output->output("`n`n`b`@Plain Text:`b`n`7");
            $this->output->rawOutput($license);
        } else {
            $this->output->output("`^The license file (LICENSE.txt) has been modified.  Please obtain a new copy of the game's code, this file has been tampered with.");
            $this->output->output("Expected MD5 in (" . implode(", ", array_keys($licensemd5s)) . "), but got " . md5($license));
            $stage = -1;
            $session['stagecompleted'] = -1;
        }
    }

    /**
     * Stage 10 - Create or verify the initial superuser account.
     */
    public function stage10(): void
    {
        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;
        $this->output->output("`@`c`bSuperuser Accounts`b`c");
        $this->output->debug($logd_version, true);
        $sql = "SELECT login, password FROM " . Database::prefix("accounts") . " WHERE superuser & " . SU_MEGAUSER;
        $result = Database::query($sql);
        if (Database::numRows($result) == 0) {
            if (Http::post("name") > "") {
                $showform = false;
                if (Http::post("pass1") != Http::post("pass2")) {
                    $this->output->output("`\$Oops, your passwords don't match.`2`n");
                    $showform = true;
                } elseif (strlen(Http::post("pass1")) < 6) {
                    $this->output->output("`\$Whoa, that's a short password, you really should make it longer. At least 6 letters.`2`n");
                    $showform = true;
                } else {
                    // Give the superuser a decent set of privs so they can
                    // do everything needed without having to first go into
                    // the user editor and give themselves privs.
                    $su = SU_MEGAUSER | SU_EDIT_MOUNTS | SU_EDIT_CREATURES |
                    SU_EDIT_PETITIONS | SU_EDIT_COMMENTS | SU_EDIT_DONATIONS |
                    SU_EDIT_USERS | SU_EDIT_CONFIG | SU_INFINITE_DAYS |
                    SU_EDIT_EQUIPMENT | SU_EDIT_PAYLOG | SU_DEVELOPER |
                    SU_POST_MOTD | SU_MODERATE_CLANS | SU_EDIT_RIDDLES |
                    SU_MANAGE_MODULES | SU_AUDIT_MODERATION | SU_RAW_SQL |
                    SU_VIEW_SOURCE | SU_NEVER_EXPIRE;
                    $name = Http::post("name");
                    $pass = md5(md5(stripslashes(Http::post("pass1"))));
                    $sql = "DELETE FROM " . Database::prefix("accounts") . " WHERE login='$name'";
                    Database::query($sql);
                    $sql = "INSERT INTO " . Database::prefix("accounts") . " (login,password,superuser,name,playername,ctitle,title,regdate,badguy,companions, allowednavs, restorepage, bufflist, dragonpoints, prefs, donationconfig,specialinc,specialmisc,emailaddress,replaceemail,emailvalidation,hauntedby,bio) VALUES('$name','$pass',$su,'`%Admin `&$name`0','`%Admin `&$name`0','`%Admin','', NOW(),'','','','village.php','','','','','','','','','')";
                    $result = Database::query($sql);
                    if (Database::affectedRows($result) == 0) {
                        print_r($sql);
                        die("Failed to create Admin account. Your first check should be to make sure that MYSQL (if that is your type) is not in strict mode.");
                    }
                    $this->output->output("`^Your superuser account has been created as `%Admin `&$name`^!");
                    $this->saveSetting("installer_version", $logd_version);
                }
            } else {
                $showform = true;
                $this->saveSetting("installer_version", $logd_version);
            }
            if ($showform) {
                $this->output->rawOutput("<form action='installer.php?stage=$stage' method='POST'>");
                $this->output->output("Enter a name for your superuser account:");
                $postedName = Http::post('name');
                $this->output->rawOutput("<input name='name' value=\"" . htmlentities((string) $postedName, ENT_COMPAT, $this->getSetting('charset', 'UTF-8')) . "\">");
                $this->output->output("`nEnter a password: ");
                $this->output->rawOutput("<input name='pass1' type='password'>");
                $this->output->output("`nConfirm your password: ");
                $this->output->rawOutput("<input name='pass2' type='password'>");
                $submit = Translator::translateInline("Create");
                $this->output->rawOutput("<br><input type='submit' value='$submit' class='button'>");
                $this->output->rawOutput("</form>");
            }
        } else {
            $this->output->output("`#You already have a superuser account set up on this server.");
            $this->saveSetting("installer_version", $logd_version);
        }
    }

    /**
     * Stage 11 - Final completion message and clean up actions.
     */
    public function stage11(): void
    {
        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;
        $this->output->output("`@`c`bAll Done!`b`c");
        $this->output->output("Your install of Legend of the Green Dragon has been completed!`n");
        $this->output->output("`nRemember us when you have hundreds of users on your server, enjoying the game.");
        $this->output->output("Eric, JT, and a lot of others put a lot of work into this world, so please don't disrespect that by violating the license.`n`n");
        $this->output->output("For further information see the <a href='https://github.com/NB-Core/lotgd' target='_blank'>project README</a> on GitHub. Issues and discussions can be filed there.`n", true);
        if ($session['user']['loggedin']) {
            Nav::add("Continue", $session['user']['restorepage']);
        } else {
            Nav::add("Login Screen", "./");
        }
        $this->saveSetting("installer_version", $logd_version);
        $file = __DIR__ . '/../../installer.php';

        // Check if the button has been pressed to delete the installer file
        if (array_key_exists('delete_installer', $_POST) && $_POST['delete_installer'] == '1') {
            if (file_exists($file)) {
                try {
                    if (unlink($file)) {
                        $this->output->output("`\$Installer file installer.php removed.`n");
                    } else {
                        $this->output->output("`\$Unable to delete installer.php. Please remove it manually.`n");
                    }
                } catch (Throwable $e) {
                    $this->output->output("`\$Error deleting installer.php: " . $e->getMessage() . "`n");
                }
            }
        } else {
            if (file_exists($file)) {
                $this->output->output("`\$The installer.php file still exists. For security reasons, you should delete it now.`n");
                $this->output->rawOutput("
                    <form method='POST' action='installer.php?stage=11'>
                        <input type='hidden' name='delete_installer' value='1'>
                        <input type='submit' class='button' value='" . htmlentities(Translator::translateInline("Delete installer.php now"), ENT_COMPAT, $this->getSetting("charset", "UTF-8")) . "'>
                    </form>
                ");
            }
        }
        $this->checkDbconnectPermissions();
        $noinstallnavs = true;
    }

    /**
     * Stage 2 - Confirm acceptance of the license agreement.
     */
    public function stage2(): void
    {
        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;
        $this->output->output("`#By continuing with this installation, you indicate your agreement with the terms of the license found on the previous page (License Agreement).");
    }

    /**
     * Stage 3 - Gather database connection information from the user.
     */
    public function stage3(): void
    {
        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;
        $this->output->rawOutput("<form action='installer.php?stage=4' method='POST'>");
        $this->output->output("`@`c`bDatabase Connection Information`b`c`2");
        $this->output->output("In order to run Legend of the Green Dragon, your server must have access to a MySQL database.");
        $this->output->output("If you are not sure if you meet this need, talk to server's Internet Service Provider (ISP), and make sure they offer MySQL databases.");
        $this->output->output("If you are running on your own machine or a server under your control, you can download and install MySQL from <a href='http://www.mysql.com/' target='_blank'>the MySQL website</a> for free.`n", true);
        if (file_exists("dbconnect.php")) {
            $this->output->output("There appears to already be a database setup file (dbconnect.php) in your site root, you can proceed to the next step.");
        } else {
            if (getenv("MYSQL_HOST")) {
                // Docker Setup
                $session['dbinfo']['DB_HOST'] = getenv("MYSQL_HOST");
                $session['dbinfo']['DB_USER'] = getenv("MYSQL_USER");
                $session['dbinfo']['DB_PASS'] = getenv("MYSQL_PASSWORD");
                $session['dbinfo']['DB_NAME'] = getenv("MYSQL_DATABASE");
                $session['dbinfo']['DB_USEDATACACHE'] = (bool)getenv("MYSQL_USEDATACACHE");
                $session['dbinfo']['DB_DATACACHEPATH'] = getenv("MYSQL_DATACACHEPATH");
                $this->output->output("`n`\$This seems to be a Docker setup, so I will use the environment variables to connect to the database. You can change them if you want, but most likely you won't be able to connect to the database.`2`n");
            }
            $this->output->output("`nIt looks like this is a new install of Legend of the Green Dragon.");
            $this->output->output("First, thanks for installing LoGD!");
            $this->output->output("In order to connect to the database server, I'll need the following information.");
            $this->output->output("`iIf you are unsure of the answer to any of these questions, please check with your server's ISP, or read the documentation on MySQL`i`n");

            $this->output->output("`nWhat is the address of your database server?`n");
            $this->output->rawOutput("<input name='DB_HOST' value=\"" . htmlentities((string)($session['dbinfo']['DB_HOST'] ?? ''), ENT_COMPAT, $this->getSetting("charset", "UTF-8")) . "\">");
            $this->tip("If you are running LoGD from the same server as your database, use 'localhost' here.  Otherwise, you will have to find out what the address is of your database server.  Your server's ISP might be able to provide this information.");

            $this->output->output("`nWhat is the username you use to connect to the database server?`n");
            $this->output->rawOutput("<input name='DB_USER' value=\"" . htmlentities((string)($session['dbinfo']['DB_USER'] ?? ''), ENT_COMPAT, $this->getSetting("charset", "UTF-8")) . "\">");
            $this->tip("This username does not have to be the same one you use to connect to the database server for administrative reasons.  However, in order to use this installer, and to install some of the modules, the account you provide here must have the ability to create, modify, and drop tables.  If you want the installer to create a new database for LoGD, the account will also have to have the ability to create databases.  Finally, to run the game, this account must at a minimum be able to select, insert, update, and delete records, and be able to lock tables.  If you're uncertain, grant the account the following privileges: SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, and ALTER.");

            $this->output->output("`nWhat is the password for this username?`n");
            $this->output->rawOutput("<input name='DB_PASS' value=\"" . htmlentities((string)($session['dbinfo']['DB_PASS'] ?? ''), ENT_COMPAT, $this->getSetting("charset", "UTF-8")) . "\">");
            $this->tip("The password is necessary here in order for the game to successfully connect to the database server.  This information is not shared with anyone, it is simply used to configure the game.");

            $this->output->output("`nWhat is the name of the database you wish to install LoGD in?`n");
            $this->output->rawOutput("<input name='DB_NAME' value=\"" . htmlentities((string)($session['dbinfo']['DB_NAME'] ?? ''), ENT_COMPAT, $this->getSetting("charset", "UTF-8")) . "\">");
            $this->tip("Database servers such as MySQL can control many different databases.  This is very useful if you have many different programs each needing their own database.  Each database has a unique name.  Provide the name you wish to use for LoGD in this field.");

            $this->output->output("`nDo you want to use datacaching (high load optimization)?`n");
            $this->output->rawOutput("<select name='DB_USEDATACACHE'>");
            $this->output->rawOutput("<option value=\"1\" " . ($session['dbinfo']['DB_USEDATACACHE'] ? 'selected=\"selected\"' : '') . ">" . Translator::translateInline("Yes") . "</option>");
            $this->output->rawOutput("<option value=\"0\" " . (!$session['dbinfo']['DB_USEDATACACHE'] ? 'selected=\"selected\"' : '') . ">" . Translator::translateInline("No") . "</option>");
            $this->output->rawOutput("</select>");
            $this->tip("Do you want to use a datacache for the sql queries? Many internal queries produce the same results and can be cached. This feature is *highly* recommended to use as the MySQL server is usually high frequented. When using in an environment where Safe Mode is enabled; this needs to be a path that has the same UID as the web server runs.");

            $this->output->output("`nIf yes, what is the path to the datacache directory?`n");
            $this->output->rawOutput("<input name='DB_DATACACHEPATH' value=\"" . htmlentities((string)($session['dbinfo']['DB_DATACACHEPATH'] ?? ''), ENT_COMPAT, $this->getSetting("charset", "UTF-8")) . "\">");
            $this->tip("If you have chosen to use the datacache function, you have to enter a path here to where temporary files may be stored. Verify that you have the proper permission (777) set to this folder, else you will have lots of errors. Do NOT end with a slash / ... just enter the dir");

            /*
                $yes = Translator::translateInline("Yes");
                $no = Translator::translateInline("No");
                $this->output->output("`nShould I attempt to create this database if it does not exist?`n");
                $this->output->rawOutput("<select name='DB_CREATE'><option value='1'>$yes</option><option value='0'>$no</option></select>");
                $this->tip("If this database doesn't exist, I'll try to create it for you if you like.");
            */
            $submit = "Test this connection information.";
            $this->output->outputNotl("`n`n<input type='submit' value='$submit' class='button'>", true);
        }
        $this->output->rawOutput("</form>");
    }

    /**
     * Stage 4 - Test the provided database connection settings.
     */
    public function stage4(): void
    {
        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;
        if (Http::postIsset("DB_HOST")) {
            $session['dbinfo']['DB_HOST'] = Http::post("DB_HOST");
            $session['dbinfo']['DB_USER'] = Http::post("DB_USER");
            $session['dbinfo']['DB_PASS'] = Http::post("DB_PASS");
            $session['dbinfo']['DB_NAME'] = Http::post("DB_NAME");
            $session['dbinfo']['DB_USEDATACACHE'] = (bool)Http::post("DB_USEDATACACHE");
            $session['dbinfo']['DB_DATACACHEPATH'] = Http::post("DB_DATACACHEPATH");
        }
        $this->output->output("`@`c`bTesting the Database Connection`b`c`2");
        $this->output->output("Trying to establish a connection with the database:`n");
        ob_start();
        $connected = Database::connect($session['dbinfo']['DB_HOST'], $session['dbinfo']['DB_USER'], $session['dbinfo']['DB_PASS']);
        $error = ob_get_contents();
        ob_end_clean();
        if (!$connected) {
            $this->output->output("`\$Blast!  I wasn't able to connect to the database server with the information you provided!");
            $this->output->output("`2This means that either the database server address, database username, or database password you provided were wrong, or else the database server isn't running.");
            $this->output->output("The specific error the database returned was:");
            $this->output->rawOutput("<blockquote>" . $error . "</blockquote>");
            $this->output->output("If you believe you provided the correct information, make sure that the database server is running (check documentation for how to determine this).`n`n");
            $this->output->output("Otherwise, you should return to the previous step, \"Database Info\" and double-check that the information provided there is accurate.");
            $session['stagecompleted'] = 3;
        } else {
            $this->output->output("`^Yahoo, I was able to connect to the database server!`n`n");
            $this->output->output("`2This means that the database server address, database username, and database password you provided were probably accurate, and that your database server is running and accepting connections.`n");
            $this->output->output("`nI'm now going to attempt to connect to the LoGD database you provided.`n");
            $link = Database::getInstance();
            define("LINK", $link);
            if (Http::get("op") == "trycreate") {
                $this->createDb($link, $session['dbinfo']['DB_NAME']);
            }
            $dbName = $session['dbinfo']['DB_NAME'];
            $sql = "SHOW DATABASES LIKE '" . addslashes($dbName) . "';";
            $dbExistsResult = Database::query($sql);
            $dbExists = Database::numRows($dbExistsResult) > 0;

            if (!$dbExists) {
                $this->output->output("`n`^It looks like the database `%{$session['dbinfo']['DB_NAME']}`^ does not exist yet.`n");
                $this->output->output("`2If you would like me to create it for you, please click the button below.`nIf you do not want me to create it, please return to the previous step and provide a different database name.`n");
                $this->output->output("`nTo create the database, <a href='installer.php?stage=4&op=trycreate'>click here</a>.`n", true);
            } elseif (!Database::selectDb($session['dbinfo']['DB_NAME'])) {
                $this->output->output("`\$Rats!  I was not able to connect to the database.");
                $error = Database::error();
                if ($error == "Unknown database '{$session['dbinfo']['DB_NAME']}'") {
                    $this->output->output("`2It looks like the database for LoGD hasn't been created yet.");
                    $this->output->output("I can attempt to create it for you if you like, but in order for that to work, the account you provided has to have permissions to create a new database.");
                    $this->output->output("If you're not sure what this means, it's safe to try to create this database, but you should double check that you've typed the name correctly by returning to the previous stage before you try it.`n");
                               $this->output->output("`nTo try to create the database, <a href='installer.php?stage=4&op=trycreate'>click here</a>.`n", true);
                } else {
                    $this->output->output("`2This is probably because the username and password you provided doesn't have permission to connect to the database.`n");
                }
                $this->output->output("`nThe exact error returned from the database server was:");
                $this->output->rawOutput("<blockquote>$error</blockquote>");
                $session['stagecompleted'] = 3;
            } else {
                $this->output->output("`n`^Excellent, I was able to connect to the database!`n");
                define("DB_INSTALLER_STAGE4", true);
                $this->output->output("`n`@Tests`2`n");
                $this->output->output("I'm now going to run a series of tests to determine what the permissions of this account are.`n");
                $issues = array();
                $this->output->output("`n`^Test: `#Creating a table`n");
                //try to destroy the table if it's already here.
                $sql = "DROP TABLE IF EXISTS logd_environment_test";
                Database::query($sql, false);
                $sql = "CREATE TABLE logd_environment_test (a int(11) unsigned not null)";
                Database::query($sql);
                if ($error = Database::error()) {
                    $this->output->output("`2Result: `\$Fail`n");
                    $this->output->rawOutput("<blockquote>$error</blockquote>");
                    array_push($issues, "`^Warning:`2 The installer will not be able to create the tables necessary to install LoGD.  If these tables already exist, or you have created them manually, then you can ignore this.  Also, many modules rely on being able to create tables, so you will not be able to use these modules.");
                } else {
                    $this->output->output("`2Result: `@Pass`n");
                }
                    $this->output->output("`n`^Test: `#Modifying a table`n");
                $sql = "ALTER TABLE logd_environment_test CHANGE a b varchar(50) not null";
                Database::query($sql);
                if ($error = Database::error()) {
                    $this->output->output("`2Result: `\$Fail`n");
                    $this->output->rawOutput("<blockquote>$error</blockquote>");
                    array_push($issues, "`^Warning:`2 The installer will not be able to modify existing tables (if any) to line up with new configurations.  Also, many modules rely on table modification permissions, so you will not be able to use these modules.");
                } else {
                    $this->output->output("`2Result: `@Pass`n");
                }
                    $this->output->output("`n`^Test: `#Creating an index`n");
                $sql = "ALTER TABLE logd_environment_test ADD INDEX(b)";
                Database::query($sql);
                if ($error = Database::error()) {
                    $this->output->output("`2Result: `\$Fail`n");
                    $this->output->rawOutput("<blockquote>$error</blockquote>");
                    array_push($issues, "`^Warning:`2 The installer will not be able to create indices on your tables.  Indices are extremely important for an active server, but can be done without on a small server.");
                } else {
                    $this->output->output("`2Result: `@Pass`n");
                }
                    $this->output->output("`n`^Test: `#Inserting a row`n");
                $sql = "INSERT INTO logd_environment_test (b) VALUES ('testing')";
                Database::query($sql);
                if ($error = Database::error()) {
                    $this->output->output("`2Result: `\$Fail`n");
                    $this->output->rawOutput("<blockquote>$error</blockquote>");
                    array_push($issues, "`\$Critical:`2 The game will not be able to function with out the ability to insert rows.");
                    $session['stagecompleted'] = 3;
                } else {
                    $this->output->output("`2Result: `@Pass`n");
                }
                    $this->output->output("`n`^Test: `#Selecting a row`n");
                $sql = "SELECT * FROM logd_environment_test";
                Database::query($sql);
                if ($error = Database::error()) {
                    $this->output->output("`2Result: `\$Fail`n");
                    $this->output->rawOutput("<blockquote>$error</blockquote>");
                    array_push($issues, "`\$Critical:`2 The game will not be able to function with out the ability to select rows.");
                    $session['stagecompleted'] = 3;
                } else {
                    $this->output->output("`2Result: `@Pass`n");
                }
                    $this->output->output("`n`^Test: `#Updating a row`n");
                $sql = "UPDATE logd_environment_test SET b='MightyE'";
                Database::query($sql);
                if ($error = Database::error()) {
                    $this->output->output("`2Result: `\$Fail`n");
                    $this->output->rawOutput("<blockquote>$error</blockquote>");
                    array_push($issues, "`\$Critical:`2 The game will not be able to function with out the ability to update rows.");
                    $session['stagecompleted'] = 3;
                } else {
                    $this->output->output("`2Result: `@Pass`n");
                }
                    $this->output->output("`n`^Test: `#Deleting a row`n");
                $sql = "DELETE FROM logd_environment_test";
                Database::query($sql);
                if ($error = Database::error()) {
                    $this->output->output("`2Result: `\$Fail`n");
                    $this->output->rawOutput("<blockquote>$error</blockquote>");
                    array_push($issues, "`\$Critical:`2 The game database will grow very large with out the ability to delete rows.");
                    $session['stagecompleted'] = 3;
                } else {
                    $this->output->output("`2Result: `@Pass`n");
                }
                    $this->output->output("`n`^Test: `#Locking a table`n");
                $sql = "LOCK TABLES logd_environment_test WRITE";
                Database::query($sql);
                if ($error = Database::error()) {
                    $this->output->output("`2Result: `\$Fail`n");
                    $this->output->rawOutput("<blockquote>$error</blockquote>");
                    array_push($issues, "`\$Critical:`2 The game will not run correctly without the ability to lock tables.");
                    $session['stagecompleted'] = 3;
                } else {
                    $this->output->output("`2Result: `@Pass`n");
                }
                    $this->output->output("`n`^Test: `#Unlocking a table`n");
                $sql = "UNLOCK TABLES";
                Database::query($sql);
                if ($error = Database::error()) {
                    $this->output->output("`2Result: `\$Fail`n");
                    $this->output->rawOutput("<blockquote>$error</blockquote>");
                    array_push($issues, "`\$Critical:`2 The game will not run correctly without the ability to unlock tables.");
                    $session['stagecompleted'] = 3;
                } else {
                    $this->output->output("`2Result: `@Pass`n");
                }
                    $this->output->output("`n`^Test: `#Deleting a table`n");
                $sql = "DROP TABLE logd_environment_test";
                Database::query($sql);
                if ($error = Database::error()) {
                    $this->output->output("`2Result: `\$Fail`n");
                    $this->output->rawOutput("<blockquote>$error</blockquote>");
                    array_push($issues, "`^Warning:`2 The installer will not be able to delete old tables (if any).  Also, many modules need to be able to delete the tables they put in place when they are uninstalled.  Although the game will function, you may end up with a lot of old data sitting around.");
                } else {
                    $this->output->output("`2Result: `@Pass`n");
                }
                        $this->output->output("`n`^Test: `#Checking datacache`n");
                if (!$session['dbinfo']['DB_USEDATACACHE']) {
                        $this->output->output("-----skipping, not selected-----`n");
                } else {
                        $datacache = $session['dbinfo']['DB_DATACACHEPATH'];
                    if (!is_dir($datacache)) {
                                $this->output->output("`2Result: `\$Fail`n");
                                array_push($issues, "`^The datacache path '" . htmlentities($datacache, ENT_COMPAT, $this->getSetting('charset', 'UTF-8')) . "' does not exist or is not a directory.`n");
                                $session['stagecompleted'] = 3;
                    } elseif (!is_writable($datacache)) {
                                    $this->output->output("`2Result: `\$Fail`n");
                                    array_push($issues, "`^The datacache path '" . htmlentities($datacache, ENT_COMPAT, $this->getSetting('charset', 'UTF-8')) . "' is not writable.`n");
                                    $session['stagecompleted'] = 3;
                    } else {
                        error_clear_last();
                        $fp = fopen($datacache . "/dummy.php", "w+");
                        if ($fp) {
                                $dummyContent = "<?php //test ?>";
                                error_clear_last();
                            if (fwrite($fp, $dummyContent) !== false) {
                                    $this->output->output("`2Result: `@Pass`n");
                            } else {
                                    $this->output->output("`2Result: `\$Fail`n");
                                    $err = error_get_last();
                                if ($err) {
                                    if (
                                                    !\Lotgd\Installer\InstallerLogger::log(sprintf(
                                                        "Error: %s in %s on line %d",
                                                        $err['message'],
                                                        $err['file'],
                                                        $err['line']
                                                    ))
                                    ) {
                                        $this->output->output("`^Could not write install log (`2%s`^)`n", \Lotgd\Installer\InstallerLogger::getLogFilePath());
                                    }
                                                    $this->output->rawOutput("<blockquote>" . htmlentities($err['message'], ENT_COMPAT, $this->getSetting('charset', 'UTF-8')) . "</blockquote>");
                                }
                                    array_push($issues, "`^I was not able to write to your datacache directory!`n");
                                    $session['stagecompleted'] = 3;
                            }
                                fclose($fp);
                                $dummyFile = $datacache . "/dummy.php";
                            if (file_exists($dummyFile)) {
                                if (!unlink($dummyFile)) {
                                    $err = error_get_last();
                                    if ($err) {
                                        if (
                                            !\Lotgd\Installer\InstallerLogger::log(sprintf(
                                                "Error: %s in %s on line %d",
                                                $err['message'],
                                                $err['file'],
                                                $err['line']
                                            ))
                                        ) {
                                            $this->output->output("`^Could not write install log (`2%s`^)`n", \Lotgd\Installer\InstallerLogger::getLogFilePath());
                                        }
                                        $this->output->rawOutput("<blockquote>" . htmlentities($err['message'], ENT_COMPAT, $this->getSetting('charset', 'UTF-8')) . "</blockquote>");
                                    } else {
                                        $this->output->rawOutput("<blockquote>`^Failed to delete the dummy file.`</blockquote>");
                                    }
                                }
                            }
                        } else {
                                    $this->output->output("`2Result: `\$Fail`n");
                                    $err = error_get_last();
                            if ($err) {
                                if (
                                    !\Lotgd\Installer\InstallerLogger::log(sprintf(
                                        "Error: %s in %s on line %d",
                                        $err['message'],
                                        $err['file'],
                                        $err['line']
                                    ))
                                ) {
                                        $this->output->output("`^Could not write install log (`2%s`^)`n", \Lotgd\Installer\InstallerLogger::getLogFilePath());
                                }
                                $this->output->rawOutput("<blockquote>" . htmlentities($err['message'], ENT_COMPAT, $this->getSetting('charset', 'UTF-8')) . "</blockquote>");
                            }
                                    array_push($issues, "`^I was not able to write to your datacache directory! Check your permissions there!`n");
                                    $session['stagecompleted'] = 3;
                        }
                    }
                }
                $this->output->output("`n`^Overall results:`2`n");
                if (count($issues) == 0) {
                    $this->output->output("You've passed all the tests, you're ready for the next stage.");
                } else {
                    $this->output->rawOutput("<ul>");
                    $this->output->output("<li>" . join("</li>\n<li>", $issues) . "</li>", true);
                    $this->output->rawOutput("</ul>");
                    $this->output->output("Even if all of the above issues are merely warnings, you will probably periodically see database errors as a result of them.");
                    $this->output->output("It would be a good idea to resolve these permissions issues before attempting to run this game.");
                    $this->output->output("For you technical folk, the specific permissions suggested are: SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER and LOCK TABLES.");
                    $this->output->output("I'm sorry, this is not something I can do for you.");
                }
            }
        }
    }

    /**
     * Stage 5 - Detect existing tables and gather table prefix.
     */
    public function stage5(): void
    {
        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;
        if (!empty(Http::post("DB_PREFIX"))) {
            $session['dbinfo']['DB_PREFIX'] = Http::post("DB_PREFIX");
            if (substr($session['dbinfo']['DB_PREFIX'], -1) != "_") {
                $session['dbinfo']['DB_PREFIX'] .= "_";
            }
        } else {
            $session['dbinfo']['DB_PREFIX'] = "";
        }
        $descriptors = $this->descriptors($session['dbinfo']['DB_PREFIX']);
        $unique = 0;
        $game = 0;
        $missing = 0;
        $conflict = array();

        $link = Database::connect($session['dbinfo']['DB_HOST'], $session['dbinfo']['DB_USER'], $session['dbinfo']['DB_PASS']);
        Database::selectDb($session['dbinfo']['DB_NAME']);
        $sql = "SHOW TABLES";
        $result = Database::query($sql);
        //the conflicts seems not to work - we should check this.
        while ($row = Database::fetchAssoc($result)) {
            foreach ($row as $key => $val) {
                if (isset($descriptors[$val])) {
                    $game++;
                    array_push($conflict, $val);
                } else {
                    $unique++;
                }
            }
        }


        $missing = count($descriptors) - $game;
        if ($missing * 10 < $game) {
            //looks like an upgrade
            $upgrade = true;
        } else {
            $upgrade = false;
        }
        if (Http::get("type") == "install") {
            $upgrade = false;
        }
        if (Http::get("type") == "upgrade") {
            $upgrade = true;
        }
        $session['dbinfo']['upgrade'] = $upgrade;
        if ($upgrade) {
            $this->output->output("`@This looks like a game upgrade.");
               $this->output->output("`^If this is not an upgrade from a previous version of LoGD, <a href='installer.php?stage=5&type=install'>click here</a>.", true);
            $this->output->output("`2Otherwise, continue on to the next step.");
        } else {
            //looks like a clean install
            $upgrade = false;
            $this->output->output("`@This looks like a fresh install.");
               $this->output->output("`2If this is not a fresh install, but rather an upgrade from a previous version of LoGD, chances are that you installed LoGD with a table prefix.  If that's the case, enter the prefix below.  If you are still getting this message, it's possible that I'm just spooked by how few tables are common to the current version, and in which case, I can try an upgrade if you <a href='installer.php?stage=5&type=upgrade'>click here</a>.`n", true);
            if (count($conflict) > 0) {
                $this->output->output("`n`n`\$There are table conflicts.`2");
                $this->output->output("If you continue with an install, the following tables will be overwritten with the game's tables.  If the listed tables belong to LoGD, they will be upgraded, otherwise all existing data in those tables will be destroyed.  Once this is done, this cannot be undone unless you have a backup!`n");
                $this->output->output("`nThese tables conflict: `^" . join(", ", $conflict) . "`2`n");
                if (Http::get("op") == "confirm_overwrite") {
                    $session['sure i want to overwrite the tables'] = true;
                }
                if (!$session['sure i want to overwrite the tables']) {
                    $session['stagecompleted'] = 4;
                               $this->output->output("`nIf you are sure that you wish to overwrite these tables, <a href='installer.php?stage=5&op=confirm_overwrite'>click here</a>.`n", true);
                }
            }
            $this->output->output("`nYou can avoid table conflicts with other applications in the same database by providing a table name prefix.");
            $this->output->output("This prefix will get put on the name of every table in the database.");
        }

        //Display rights - I won't parse them, sue me for laziness, and this should work nicely to explain any errors
        $sql = "SHOW GRANTS FOR CURRENT_USER()";
        $result = Database::query($sql);
        $this->output->output("`2These are the rights for your mysql user, `\$make sure you have the 'LOCK TABLES' privileges OR a \"GRANT ALL PRIVLEGES\" on the tables.`2`n`n");
        $this->output->output("If you do not know what this means, ask your hosting provider that supplied you with the database credentials.`n`n");
        $this->output->rawOutput("<table cellspacing='1' cellpadding='2' border='0' bgcolor='#999999'>");
        $i = 0;
        while ($row = Database::fetchAssoc($result)) {
            if ($i == 0) {
                $this->output->rawOutput("<tr class='trhead'>");
                $keys = array_keys($row);
                foreach ($keys as $value) {
                    $this->output->rawOutput("<td>$value</td>");
                }
                $this->output->rawOutput("</tr>");
            }
            $this->output->rawOutput("<tr class='" . ($i % 2 == 0 ? "trlight" : "trdark") . "'>");
            foreach ($keys as $value) {
                $this->output->rawOutput("<td valign='top'>{$row[$value]}</td>");
            }
            $this->output->rawOutput("</tr>");
            $i++;
        }
        $this->output->rawOutput("</table>");

        //done

        $this->output->rawOutput("<form action='installer.php?stage=5' method='POST'>");
        $this->output->output("`nTo provide a table prefix, enter it here.");
        $this->output->output("If you don't know what this means, you should either leave it blank, or enter an intuitive value such as \"logd\".`n");
        $this->output->rawOutput("<input name='DB_PREFIX' value=\"" . htmlentities($session['dbinfo']['DB_PREFIX'], ENT_COMPAT, $this->getSetting("charset", "UTF-8")) . "\"><br>");
        $submit = Translator::translateInline("Submit your prefix.");
        $this->output->rawOutput("<input type='submit' value='$submit' class='button'>");
        $this->output->rawOutput("</form>");
        if (count($conflict) == 0) {
            $this->output->output("`^It looks like you can probably safely skip this step if you don't know what it means.");
        }
        $this->output->output("`n`n`@Once you have submitted your prefix, you will be returned to this page to select the next step.");
        $this->output->output("If you don't need a prefix, just select the next step now.");
    }

    /**
     * Stage 6 - Create the dbconnect.php configuration file.
     */
    public function stage6(): void
    {
        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;
        if (file_exists("dbconnect.php")) {
            $success = true;
            $initial = false;
        } else {
            $initial = true;
            $this->output->output("`@`c`bWriting your dbconnect.php file`b`c");
            $this->output->output("`2I'm attempting to write a file named 'dbconnect.php' to your site root.");
            $this->output->output("This file tells LoGD how to connect to the database, and is necessary to continue installation.`n");
            $dbconnect =
                "<?php\n"
                . "//This file automatically created by installer.php on " . date("M d, Y h:i a") . "\n"
                . "return [\n"
                . "    'DB_HOST' => '{$session['dbinfo']['DB_HOST']}',\n"
                . "    'DB_USER' => '{$session['dbinfo']['DB_USER']}',\n"
                . "    'DB_PASS' => '{$session['dbinfo']['DB_PASS']}',\n"
                . "    'DB_NAME' => '{$session['dbinfo']['DB_NAME']}',\n"
                . "    'DB_PREFIX' => '{$session['dbinfo']['DB_PREFIX']}',\n"
                . "    'DB_USEDATACACHE' => " . ((int)$session['dbinfo']['DB_USEDATACACHE']) . ",\n"
                . "    'DB_DATACACHEPATH' => '{$session['dbinfo']['DB_DATACACHEPATH']}',\n"
                . "];\n";
                $failure = false;
                $dir = dirname('dbconnect.php');
            if (is_writable($dir)) {
                    error_clear_last();
                    $fp = fopen('dbconnect.php', 'w+');
                if ($fp) {
                        error_clear_last();
                    if (fwrite($fp, $dbconnect) !== false) {
                        $this->output->output("`n`@Success!`2  I was able to write your dbconnect.php file, you can continue on to the next step.");
                    } else {
                            $failure = true;
                            $err = error_get_last();
                        if ($err) {
                            if (!\Lotgd\Installer\InstallerLogger::log(sprintf("Error: %s in %s on line %d", $err['message'], $err['file'], $err['line']))) {
                                $this->output->output("`^Could not write install log (`2%s`^)`n", \Lotgd\Installer\InstallerLogger::getLogFilePath());
                            }
                            $this->output->output("`n`\$Failed to write to dbconnect.php:`2 %s in %s on line %d", $err['message'], $err['file'], $err['line']);
                        }
                    }
                        fclose($fp);
                } else {
                        $failure = true;
                        $err = error_get_last();
                    if ($err) {
                        if (!\Lotgd\Installer\InstallerLogger::log($err['message'])) {
                            $this->output->output("`^Could not write install log (`2%s`^)`n", \Lotgd\Installer\InstallerLogger::getLogFilePath());
                        }
                            $this->output->output("`n`\$Failed to create dbconnect.php:`2 %s", $err['message']);
                    }
                }
            } else {
                    $failure = true;
                    $this->output->output("`n`\$Directory not writable:`2 %s", $dir);
            }
            if ($failure) {
                    $this->output->output("`n`\$Unfortunately, I was not able to write your dbconnect.php file.");
                    $this->output->output("`2You will have to create this file yourself, and upload it to your web server.");
                $this->output->output("The contents of this file should be as follows:`3");
                $this->output->rawOutput("<blockquote><pre>" . htmlentities($dbconnect, ENT_COMPAT, $this->getSetting("charset", "UTF-8")) . "</pre></blockquote>");
                $this->output->output("`2Create a new file, past the entire contents from above into it (everything from and including `3<?php`2 up to and including `3?>`2 ).");
                $this->output->output("When you have that done, save the file as 'dbconnect.php' and upload this to the location you have LoGD at.");
                $this->output->output("You can refresh this page to see if you were successful.");
            } else {
                $success = true;
            }
        }
        if ($success && !$initial) {
            $version = $this->getSetting("installer_version", "-1");
            $sub = substr($version, 0, 5);
            $sub = (int)str_replace(".", "", $sub);
            if ($sub < 110) {
                        $assignments = [];
                        $fp = fopen('dbconnect.php', 'r+');
                if ($fp) {
                    while (!feof($fp)) {
                        $buffer = fgets($fp, 4096);
                        if (strpos($buffer, '$DB') !== false && preg_match('/\$(DB_[A-Z_]+)\s*=\s*([^;]*);/', $buffer, $matches)) {
                            $assignments[$matches[1]] = trim($matches[2], " \t\"'");
                        }
                    }
                    fclose($fp);
                }
                $dbconnect =
                    "<?php\n"
                    . "//This file automatically created by installer.php on " . date("M d, Y h:i a") . "\n"
                    . "return [\n"
                    . "    'DB_HOST' => '" . ($assignments['DB_HOST'] ?? '') . "',\n"
                    . "    'DB_USER' => '" . ($assignments['DB_USER'] ?? '') . "',\n"
                    . "    'DB_PASS' => '" . ($assignments['DB_PASS'] ?? '') . "',\n"
                    . "    'DB_NAME' => '" . ($assignments['DB_NAME'] ?? '') . "',\n"
                    . "    'DB_PREFIX' => '" . ($assignments['DB_PREFIX'] ?? '') . "',\n"
                    . "    'DB_USEDATACACHE' => " . ((int)($assignments['DB_USEDATACACHE'] ?? 0)) . ",\n"
                    . "    'DB_DATACACHEPATH' => " . var_export($assignments['DB_DATACACHEPATH'] ?? '', true) . ",\n"
                    . "];\n";
                // Check if the file is writeable for us. If yes, we will change the file and notice the admin
                // if not, they have to change the file themselves...
                        $failure = false;
                        $dir = dirname('dbconnect.php');
                if (is_writable($dir)) {
                        $fp = fopen('dbconnect.php', 'w+');
                    if ($fp) {
                        if (fwrite($fp, $dbconnect) !== false) {
                            $this->output->output("`n`@Success!`2  I was able to write your dbconnect.php file.");
                        } else {
                            $failure = true;
                            $err = error_get_last();
                            if ($err) {
                                if (!\Lotgd\Installer\InstallerLogger::log($err['message'])) {
                                    $this->output->output("`^Could not write install log (`2%s`^)`n", \Lotgd\Installer\InstallerLogger::getLogFilePath());
                                }
                                    $this->output->output("`n`\$Failed to write to dbconnect.php:`2 %s", $err['message']);
                            }
                        }
                                fclose($fp);
                    } else {
                            $failure = true;
                            $err = error_get_last();
                        if ($err) {
                            if (!\Lotgd\Installer\InstallerLogger::log($err['message'])) {
                                $this->output->output("`^Could not write install log (`2%s`^)`n", \Lotgd\Installer\InstallerLogger::getLogFilePath());
                            }
                                    $this->output->output("`n`\$Failed to create dbconnect.php:`2 %s", $err['message']);
                        }
                    }
                } else {
                        $failure = true;
                        $this->output->output("`n`\$Directory not writable:`2 %s", $dir);
                }
                if ($failure) {
                    $this->output->output("`2With this new version the settings for datacaching had to be moved to `idbconnect.php`i.");
                    $this->output->output("Due to your system settings and privleges for this file, I was not able to perform the changes by myself.");
                    $this->output->output("This part involves you: We have to ask you to replace the content of your existing `idbconnect.php`i with the following code:`n`n`&");
                    $this->output->rawOutput("<blockquote><pre>" . htmlentities($dbconnect, ENT_COMPAT, $this->getSetting("charset", "UTF-8")) . "</pre></blockquote>");
                    $this->output->output("`2This will let you use your existing datacaching settings.`n`n");
                    $this->output->output("If you have done this, you are ready for the next step.");
                } else {
                    $this->output->output("`n`^You are ready for the next step.");
                }
            } else {
                $this->output->output("`n`^You are ready for the next step.");
            }
        } elseif (!$success) {
                $session['stagecompleted'] = 5;
        }
        $this->checkDbconnectPermissions();
    }

    /**
     * Stage 7 - Choose between new installation or upgrade.
     */
    public function stage7(): void
    {
        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;
        require __DIR__ . '/../data/installer_sqlstatements.php';
        if (Http::post("type") > "") {
            if (Http::post("type") == "install") {
                $session['fromversion'] = "-1";
                $session['dbinfo']['upgrade'] = false;
            } else {
                $session['fromversion'] = Http::post("version");
                $session['dbinfo']['upgrade'] = true;
            }
        }

        if (!isset($session['fromversion']) || $session['fromversion'] == "") {
                $this->output->output("`@`c`bConfirmation`b`c");
                $this->output->output("`2Please confirm the following:`0`n");
               $this->output->rawOutput("<form action='installer.php?stage=7' method='POST'>");
                $this->output->rawOutput("<table border='0' cellpadding='0' cellspacing='0'><tr><td valign='top'>");
                $this->output->output("`2I should:`0");
                $this->output->rawOutput("</td><td>");
                $version = $this->getSetting("installer_version", "-1");
                // Determine if this is an upgrade based on db version and code version
            if ($version != "-1" && $version != $logd_version) {
                    $session['dbinfo']['upgrade'] = true;
            } else {
                    $session['dbinfo']['upgrade'] = false;
            }
            if ($version != "-1") {
                    $this->output->output("`n`2Detected database version: `^%s`2.`n", $version);
                if ($session['dbinfo']['upgrade']) {
                        $this->output->output("`2Code version: `^%s`2. The installer will upgrade your database.`n", $logd_version);
                } else {
                        $this->output->output("`2Code version matches the database version.`n");
                }
            } else {
                if (file_exists('dbconnect.php') && (time() - filemtime('dbconnect.php') < 300)) {
                        $this->output->output("`n`2A new dbconnect.php file was detected; assuming fresh installation.`n");
                }
            }
            $this->output->rawOutput("<input type='radio' value='upgrade' name='type'" . ($session['dbinfo']['upgrade'] ? " checked" : "") . ">");
            $this->output->output(" `2Perform an upgrade" . ($session['dbinfo']['upgrade'] ? " from" : "") . " ");
            if ($version == "-1") {
                $version = "0.9.7";
            }
            if ($session['dbinfo']['upgrade']) {
                if (!isset($sql_upgrade_statements)) {
                    require __DIR__ . '/../data/installer_sqlstatements.php';
                }
                reset($sql_upgrade_statements);
                $this->output->rawOutput("<select name='version'>");
                foreach ($sql_upgrade_statements as $key => $val) {
                    if ($key != "-1") {
                        $this->output->rawOutput("<option value='$key'" . ($version == $key ? " selected" : "") . ">$key</option>");
                    }
                }
                $this->output->rawOutput("</select>");
            }
            $this->output->rawOutput("<br><input type='radio' value='install' name='type'" . ($session['dbinfo']['upgrade'] ? "" : " checked") . ">");
            $this->output->output(" `2Perform a clean install.");
            $this->output->rawOutput("</td></tr></table>");
            $submit = Translator::translateInline("Submit");
            $this->output->rawOutput("<input type='submit' value='$submit' class='button'>");
            $this->output->rawOutput("</form>");
            $session['stagecompleted'] = $stage - 1;
        } else {
            $session['stagecompleted'] = $stage;
        // Header, because we do not want to save the user(!)
            header("Location: installer.php?stage=" . ($stage + 1));
        }
    }

    /**
     * Stage 8 - Select which modules to install and activate.
     */
    public function stage8(): void
    {
        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;
        $recommended_modules = is_array($recommended_modules) ? $recommended_modules : [];
        if (array_key_exists('modulesok', $_POST)) {
            $session['moduleoperations'] = $_POST['modules'];
            $session['stagecompleted'] = $stage;
        // Header, because we do not want to save the user(!)
            header("Location: installer.php?stage=" . ($stage + 1));
        } elseif (array_key_exists('moduleoperations', $session) && is_array($session['moduleoperations'])) {
            $session['stagecompleted'] = $stage;
        } else {
            $session['stagecompleted'] = $stage - 1;
        }

        $this->output->output("`@`c`bManage Modules`b`c");
        $this->output->output("Legend of the Green Dragon supports an extensive module system.");
        $this->output->output("Modules are small self-contained files that perform a specific function or event within the game.");
        $this->output->output("For the most part, modules are independant of each other, meaning that one module can be installed, uninstalled, activated, and deactivated without negative impact on the rest of the game.");
        $this->output->output("Not all modules are ideal for all sites, for example, there's a module called 'Multiple Cities,' which is intended only for large sites with many users online at the same time.");
        $this->output->output("`n`n`^If you are not familiar with Legend of the Green Dragon, and how the game is played, it is probably wisest to choose the default set of modules to be installed.");
        $this->output->output("`n`n`@There is an extensive community of users who write modules for LoGD at <a href='http://dragonprime.net/'>http://dragonprime.net/</a>.", true);
        $phpram = ini_get("memory_limit");
        if ($this->returnBytes($phpram) < 62582912 && $phpram != -1 && !$session['overridememorylimit'] && !$session['dbinfo']['upgrade']) {// 62 MBytes
                                                                        // enter this ONLY if it's not an upgrade and if the limit is really too low
            $this->output->output("`n`n`\$Warning: Your PHP memory limit is set to a very low level.");
            $this->output->output("Smaller servers should not be affected by this during normal gameplay but for this installation step you should assign at least 12 Megabytes of RAM for your PHP process.");
            $this->output->output("For now we will skip this step, but before installing any module, make sure to increase you memory limit.");
            $this->output->output("`nYou can proceed at your own risk. Be aware that a blank screen indicates you *must* increase the memory limit.");
            $this->output->output("`n`nTo override click again on \"Set Up Modules\".");
            $session['stagecompleted'] = "8";
            $session['overridememorylimit'] = true;
            $session['skipmodules'] = true;
        } else {
            if (isset($session['overridememorylimit']) && $session['overridememorylimit']) {
                $this->output->output("`4`n`nYou have been warned... you are now working on your own risk.`n`n");
                $session['skipmodules'] = false;
            }
            $submit = Translator::translateInline("Save Module Settings");
            $install = Translator::translateInline("Select Recommended Modules");
            $reset = Translator::translateInline("Reset Values");
            $all_modules = array();

            //check if we have no table there right now (fresh install)
            if (isset($session['dbinfo']) && $session['dbinfo']['upgrade']) {
                $sql = "SELECT * FROM " . Database::prefix("modules") . " ORDER BY category,active DESC,formalname";
                $result = @Database::query($sql);
                if ($result !== false) {
                    while ($row = Database::fetchAssoc($result)) {
                        if (!array_key_exists($row['category'], $all_modules)) {
                            $all_modules[$row['category']] = array();
                        }
                        $row['installed'] = true;
                        $all_modules[$row['category']][$row['modulename']] = $row;
                    }
                }
                $install_status = get_module_install_status(true);
            } else {
                $install_status = get_module_install_status(false);
            }
            $uninstalled = $install_status['uninstalledmodules'];
            reset($uninstalled);
            $invalidmodule = array(
                    "version" => "",
                    "author" => "",
                    "category" => "Invalid Modules",
                    "download" => "",
                    "description" => "",
                    "invalid" => true,
                    );
            $this->output->output("`n`nChecking possible uninstalled modules (%d) - in case of errors check the modules themselves for errors...", count($uninstalled));
            foreach ($uninstalled as $key => $modulename) {
                $row = array();
                //test if the file is a valid module or a lib file/whatever that got in, maybe even malcode that does not have module form
                $file = file_get_contents("modules/$modulename.php");
                if (
                    strpos($file, $modulename . "_getmoduleinfo") === false ||
                        //strpos($file,$shortname."_dohook")===false ||
                        //do_hook is not a necessity
                        strpos($file, $modulename . "_install") === false ||
                        strpos($file, $modulename . "_uninstall") === false
                ) {
                    //here the files has neither do_hook nor getinfo, which means it won't execute as a module here --> block it + notify the admin who is the manage modules section
                    $moduleinfo = array_merge($invalidmodule, array("name" => $modulename . ".php " . appoencode(Translator::translateInline("(`\$Invalid Module! Contact Author or check file!`0)"))));
                } else {
                    $moduleinfo = get_module_info($modulename, false);
                }
                //end of testing
                $row['installed'] = false;
                $row['active'] = false;
                $row['category'] = $moduleinfo['category'];
                $row['modulename'] = $modulename;
                $row['formalname'] = $moduleinfo['name'];
                $row['description'] = $moduleinfo['description'];
                $row['moduleauthor'] = $moduleinfo['author'];
                $row['invalid'] = (isset($moduleinfo['invalid'])) ? $moduleinfo['invalid'] : false;
                if (!array_key_exists($row['category'], $all_modules)) {
                    $all_modules[$row['category']] = array();
                }
                $all_modules[$row['category']][$row['modulename']] = $row;
            }
            $this->output->output("`n... done.)", count($uninstalled));
            $this->output->outputNotl("`0");
                $this->output->rawOutput("<form action='installer.php?stage=" . $stage . "' method='POST'>");
            $this->output->rawOutput("<input type='submit' name='modulesok' value='$submit' class='button'>");
            $this->output->rawOutput("<input type='button' onClick='chooseRecommendedModules();' class='button' value='$install'>");
            $this->output->rawOutput("<input type='reset' value='$reset' class='button'><br>");
            $this->output->rawOutput("<table cellpadding='1' cellspacing='1'>");
            ksort($all_modules);
            reset($all_modules);
            $x = 0;
            foreach ($all_modules as $categoryName => $categoryItems) {
                $this->output->rawOutput("<tr class='trhead'><td colspan='6'>" . Translator::tl($categoryName) . "</td></tr>");
                $this->output->rawOutput("<tr class='trhead'><td>" . Translator::tl("Uninstalled") . "</td><td>" . Translator::tl("Installed") . "</td><td>" . Translator::tl("Activated") . "</td><td>" . Translator::tl("Recommended") . "</td><td>" . Translator::tl("Module Name") . "</td><td>" . Translator::tl("Author") . "</td></tr>");
                foreach ($categoryItems as $modulename => $moduleinfo) {
                    $x++;
                    //if we specified things in a previous hit on this page, let's update the modules array here as we go along.
                    $moduleinfo['realactive'] = $moduleinfo['active'];
                    $moduleinfo['realinstalled'] = $moduleinfo['installed'];
                    if (array_key_exists('moduleoperations', $session) && is_array($session['moduleoperations']) && array_key_exists($modulename, $session['moduleoperations'])) {
                        $ops = explode(",", $session['moduleoperations'][$modulename]);
                        reset($ops);
                        foreach ($ops as $op) {
                            switch ($op) {
                                case "uninstall":
                                    $moduleinfo['installed'] = false;
                                    $moduleinfo['active'] = false;
                                    break;
                                case "install":
                                    $moduleinfo['installed'] = true;
                                    $moduleinfo['active'] = false;
                                    break;
                                case "activate":
                                    $moduleinfo['installed'] = true;
                                    $moduleinfo['active'] = true;
                                    break;
                                case "deactivate":
                                    $moduleinfo['installed'] = true;
                                    $moduleinfo['active'] = false;
                                    break;
                                case "donothing":
                                    break;
                            }
                        }
                    }
                    $this->output->rawOutput("<tr class='" . ($x % 2 ? "trlight" : "trdark") . "'>");
                    if ($moduleinfo['realactive']) {
                        $uninstallop = "uninstall";
                        $installop = "deactivate";
                        $activateop = "donothing";
                    } elseif ($moduleinfo['realinstalled']) {
                        $uninstallop = "uninstall";
                        $installop = "donothing";
                        $activateop = "activate";
                    } else {
                        $uninstallop = "donothing";
                        $installop = "install";
                        $activateop = "install,activate";
                    }
                    $uninstallcheck = false;
                    $installcheck = false;
                    $activatecheck = false;
                    if ($moduleinfo['active']) {
                        $activatecheck = true;
                    } elseif ($moduleinfo['installed']) {
                        //echo "<font color='red'>$modulename is installed but not active.</font><br>";
                        $installcheck = true;
                    } else {
                        //echo "$modulename is uninstalled.<br>";
                        $uninstallcheck = true;
                    }
                    if (isset($moduleinfo['invalid']) && $moduleinfo['invalid'] == true) {
                        $this->output->rawOutput("<td><input type='radio' name='modules[$modulename]' id='uninstall-$modulename' value='$uninstallop' checked disabled></td>");
                        $this->output->rawOutput("<td><input type='radio' name='modules[$modulename]' id='install-$modulename' value='$installop' disabled></td>");
                        $this->output->rawOutput("<td><input type='radio' name='modules[$modulename]' id='activate-$modulename' value='$activateop' disabled></td>");
                    } else {
                        $this->output->rawOutput("<td><input type='radio' name='modules[$modulename]' id='uninstall-$modulename' value='$uninstallop'" . ($uninstallcheck ? " checked" : "") . "></td>");
                        $this->output->rawOutput("<td><input type='radio' name='modules[$modulename]' id='install-$modulename' value='$installop'" . ($installcheck ? " checked" : "") . "></td>");
                        $this->output->rawOutput("<td><input type='radio' name='modules[$modulename]' id='activate-$modulename' value='$activateop'" . ($activatecheck ? " checked" : "") . "></td>");
                    }
                    $this->output->outputNotl("<td>" . (in_array($modulename, $recommended_modules) ? Translator::tl("`^Yes`0") : Translator::tl("`\$No`0")) . "</td>", true);
                    require_once("lib/sanitize.php");
                    $this->output->rawOutput("<td><span title=\"" .
                            (isset($moduleinfo['description']) &&
                             $moduleinfo['description'] ?
                             $moduleinfo['description'] :
                             sanitize($moduleinfo['formalname'])) . "\">");
                    $this->output->outputNotl("`@");
                    if (isset($moduleinfo['invalid']) && $moduleinfo['invalid'] == true) {
                        $this->output->rawOutput($moduleinfo['formalname']);
                    } else {
                        $this->output->output($moduleinfo['formalname']);
                    }
                    $this->output->outputNotl(" [`%$modulename`@]`0");
                    $this->output->rawOutput("</span></td><td>");
                    $this->output->outputNotl("`#{$moduleinfo['moduleauthor']}`0", true);
                    $this->output->rawOutput("</td>");
                    $this->output->rawOutput("</tr>");
                }
            }
            $this->output->rawOutput("</table>");
            $this->output->rawOutput("<br><input type='submit' name='modulesok' value='$submit' class='button'>");
            $this->output->rawOutput("<input type='button' onClick='chooseRecommendedModules();' class='button' value='$install' class='button'>");
            $this->output->rawOutput("<input type='reset' value='$reset' class='button'>");
            $this->output->rawOutput("</form>");
            $this->output->rawOutput("<script language='JavaScript'>
            function chooseRecommendedModules(){
            var thisItem;
            var selectedCount = 0;
            ");
            foreach ($recommended_modules as $key => $val) {
                $this->output->rawOutput("thisItem = document.getElementById('activate-$val'); ");
                $this->output->rawOutput("if (thisItem && !thisItem.checked) { selectedCount++; thisItem.checked=true; }\n");
            }
            $this->output->rawOutput("
													alert('I selected '+selectedCount+' modules that I recommend, but which were not already selected.');
													}");
            if (!$session['dbinfo']['upgrade']) {
                $this->output->rawOutput("
        				chooseRecommendedModules();");
            }
            $this->output->rawOutput("
        			</script>");
        }
    }

    /**
     * Stage 9 - Build or upgrade the game tables.
     */
    public function stage9(): void
    {
        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE, $DB_PREFIX;
        $session['skipmodules'] = $session['skipmodules'] ?? false;
        $this->output->output("`@`c`bRunning Database Migrations`b`c");
        $this->output->output("`2The installer now uses Doctrine migrations to set up the database schema.`n");
        try {
            $this->runMigrations();
            $this->output->output("`@Migrations executed successfully.`n");
        } catch (\Throwable $e) {
            $this->output->output("`\$Migration error:`n" . $e->getMessage());
            return;
        }

        require __DIR__ . '/../data/installer_sqlstatements.php';
        $fromVersion = $session['fromversion'] ?? '-1';
        foreach ($sql_upgrade_statements as $version => $statements) {
            $version = (string) $version;
            if (!($session['dbinfo']['upgrade'] ?? false) || version_compare($version, $fromVersion, '>')) {
                foreach ($statements as $sql) {
                    Database::query($sql);
                }
            }
        }
        /*
           $this->output->output("`n`2Now I'll install the recommended modules.");
           $this->output->output("Please note that these modules will be installed, but not activated.");
           $this->output->output("Once installation is complete, you should use the Module Manager found in the superuser grotto to activate those modules you wish to use.");
           reset($recommended_modules);
           $this->output->rawOutput("<div style='width: 100%; height: 150px; max-height: 150px; overflow: auto;'>");
           while (list($key,$modulename)=each($recommended_modules)){
           $this->output->output("`3Installing `#$modulename`$`n");
           ModuleInstaller::install($modulename, false);
           }
           $this->output->rawOutput("</div>");
         */
        if (!($session['skipmodules'] ?? false)) {
            $this->output->output("`n`2Now I'll install and configure your modules.");
            reset($session['moduleoperations']);
            $this->output->rawOutput("<div style='width: 100%; height: 150px; max-height: 150px; overflow: auto;'>");
            foreach ($session['moduleoperations'] as $modulename => $val) {
                $ops = explode(",", $val);
                reset($ops);
                foreach ($ops as $op) {
                    switch ($op) {
                        case "uninstall":
                            $this->output->output("`3Uninstalling `#$modulename`3: ");
                            if (ModuleInstaller::uninstall($modulename)) {
                                $this->output->output("`@OK!`0`n");
                            } else {
                                $this->output->output("`\$Failed!`0`n");
                            }
                            break;
                        case "install":
                            $this->output->output("`3Installing `#$modulename`3: ");
                            if (ModuleInstaller::install($modulename)) {
                                $this->output->output("`@OK!`0`n");
                            } else {
                                $this->output->output("`\$Failed!`0`n");
                            }
                            ModuleInstaller::install($modulename);
                            break;
                        case "activate":
                            $this->output->output("`3Activating `#$modulename`3: ");
                            if (ModuleInstaller::activate($modulename)) {
                                $this->output->output("`@OK!`0`n");
                            } else {
                                $this->output->output("`\$Failed!`0`n");
                            }
                            break;
                        case "deactivate":
                            $this->output->output("`3Deactivating `#$modulename`3: ");
                            if (ModuleInstaller::deactivate($modulename)) {
                                $this->output->output("`@OK!`0`n");
                            } else {
                                $this->output->output("`\$Failed!`0`n");
                            }
                            break;
                        case "donothing":
                            break;
                    }
                }
                $session['moduleoperations'][$modulename] = "donothing";
            }
            $this->output->rawOutput("</div>");
        }
        $this->output->output("`n`n`^You're ready for the next step.");
    }

    /**
     * Fallback stage handler when an unknown stage is requested.
     */
    public function stageDefault(): void
    {
        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;
        $this->output->output("`\$Requested installer step not found.`n");
        $this->output->output("`2Restarting at stage 1...`n");
        Redirect::redirect("installer.php?stage=1");
    }

    // endregion

    // region Helper Methods

    /**
     * Attempt to create a new database and connect to it.
     *
     * Displays success or failure messages to the user.
     */
    private function createDb($connection, string $dbname): void
    {
        $this->output->output("`n`2Attempting to create your database...`n");
        $sql = "CREATE DATABASE `$dbname`";
        if ($connection->query($sql) === true) {
            $this->output->output("`@Success!`2  I was able to create the database and connect to it!`n");
        } else {
            if ($connection instanceof \mysqli && property_exists($connection, 'error')) {
                $error = $connection->error;
            } else {
                $error = $connection->error();
            }
            $this->output->output("`\$It seems I was not successful.`2 ");
            $this->output->output("The error returned by the database server was:");
            $this->output->rawOutput("<blockquote>$error</blockquote>");
        }
    }

    /**
     * Render a mouse over tip containing the supplied messages.
     *
     * Accepts the same parameters as $this->output->output().
     */
    private function tip(...$args): void
    {
        $tip = Translator::translateInline("Tip");
        $this->output->outputNotl("<div style='cursor: pointer; cursor: hand; display: inline;' onMouseOver=\"tip{$this->tipid}.style.visibility='visible'; tip{$this->tipid}.style.display='inline';\" onMouseOut=\"tip{$this->tipid}.style.visibility='hidden'; tip{$this->tipid}.style.display='none';\">`i[ `b{$tip}`b ]`i", true);
        $this->output->rawOutput("<div class='debug' id='tip{$this->tipid}' style='position: absolute; width: 200px; max-width: 200px; float: right;'>");
        call_user_func_array('output', $args);
        $this->output->rawOutput("</div></div>");
        $this->output->rawOutput("<script language='JavaScript'>var tip{$this->tipid} = document.getElementById('tip{$this->tipid}'); tip{$this->tipid}.style.visibility='hidden'; tip{$this->tipid}.style.display='none';</script>");
        $this->tipid++;
    }

    /**
     * Retrieve table descriptors and optionally prefix table names.
     *
     * @param string $prefix Table name prefix
     */
    private function descriptors(string $prefix = ''): array
    {
        require_once(__DIR__ . '/../data/tables.php');
        $array = get_all_tables();
        $out = [];
        foreach ($array as $key => $val) {
            $out[$prefix . $key] = $val;
        }

        return $out;
    }

    /**
     * Check permissions on dbconnect.php and warn if it is world writable.
     */
    private function checkDbconnectPermissions(): void
    {
        $file = __DIR__ . '/../../dbconnect.php';
        if (file_exists($file)) {
            $perms = @fileperms($file);
            if ($perms !== false && ($perms & 0o002)) {
                $this->output->output("`^Warning:`2 dbconnect.php is world-writable and may allow unauthorized changes.");
                $this->output->output("`2Use `#chmod 640 dbconnect.php`2 or `#chmod 600 dbconnect.php`2 to secure this file.`n");
            }
        }
    }

    /**
     * Verify that the install directory is writable before creating files.
     */
    private function verifyInstallDirectoryWritable(): bool
    {
        $dir = dirname(__DIR__, 2);
        if (! is_writable($dir)) {
            $this->output->output("`\$Installation directory not writable:`2 %s", $dir);
            $this->output->output("`2Please adjust permissions, e.g., run `#chmod 775 %s`2. See <a href='https://www.php.net/manual/en/function.chmod.php' target='_blank'>permission docs</a>.", $dir, true);
            return false;
        }

        return true;
    }

    private function getSetting(string $name, $default = '')
    {
        return Settings::getInstance()->getSetting($name, $default);
    }

    private function saveSetting(string $name, $value): void
    {
        Settings::getInstance()->saveSetting($name, $value);
    }

    /**
     * Execute Doctrine migrations defined in the configured migrations directory.
     */
    private function runMigrations(): void
    {
        global $session, $DB_PREFIX;

        $db        = require dirname(__DIR__, 2) . '/dbconnect.php';
        $DB_PREFIX = $db['DB_PREFIX'] ?? '';
        InstallerLogger::log('DB_PREFIX set to ' . $DB_PREFIX);

        $config = require dirname(__DIR__, 2) . '/src/Lotgd/Config/migrations.php';

        $em = Bootstrap::getEntityManager();

        $dependencyFactory = DependencyFactory::fromEntityManager(
            new ConfigurationArray(['migrations_paths' => $config['migrations_paths']]),
            new ExistingEntityManager($em)
        );

        $storage = $dependencyFactory->getMetadataStorage();
        $storage->ensureInitialized();

        $executed = $storage->getExecutedMigrations();
        $map = [
            '0.9' => '20250724000000',
            '0.9.1' => '20250724000001',
            '0.9.7' => '20250724000002',
            '0.9.8-prerelease.1' => '20250724000003',
            '0.9.8-prerelease.6' => '20250724000004',
            '0.9.8-prerelease.11' => '20250724000005',
            '0.9.8-prerelease.12' => '20250724000006',
            '0.9.8-prerelease.14a' => '20250724000007',
            '1.1.0 Dragonprime Edition' => '20250724000008',
            '1.1.1 Dragonprime Edition' => '20250724000009',
            '1.1.1.0 Dragonprime Edition +nb' => '20250724000010',
            '1.1.1.1 Dragonprime Edition +nb' => '20250724000011',
            '1.2.6 +nb Edition' => '20250724000013',
            '1.2.7 +nb Edition' => '20250724000014',
        ];

        $from = $session['fromversion'] ?? '-1';
        if ($from !== '-1') {
            // Expose the source version so migrations can load legacy SQL when upgrading
            $_ENV['LOTGD_BASE_VERSION'] = $from;

            foreach ($map as $ver => $id) {
                if (version_compare($from, $ver, '<')) {
                    $v = new Version($id);
                    if (! $executed->hasMigration($v)) {
                        $storage->complete(new ExecutionResult($v));
                    }
                }
            }
        }

        $aliasResolver = $dependencyFactory->getVersionAliasResolver();
        $latestVersion = $aliasResolver->resolveVersionAlias('latest');
        $plan = $dependencyFactory->getMigrationPlanCalculator()->getPlanUntilVersion($latestVersion);
        $factory = $dependencyFactory->getConsoleInputMigratorConfigurationFactory();
        $migratorConfig = $factory->getMigratorConfiguration(new ArrayInput([]));
        try {
            $dependencyFactory->getMigrator()->migrate($plan, $migratorConfig);
        } catch (\Throwable $e) {
            InstallerLogger::log('Migration error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Convert a PHP ini memory value (like '8M') into an integer of bytes.
     */
    private function returnBytes($val): int
    {
        $val  = trim($val);
        $last = strtolower($val[strlen($val) - 1]);
        $numericPart = (int) substr($val, 0, -1);
        switch ($last) {
            // The 'G' modifier is available since PHP 5.1.0
            case 'g':
                $numericPart *= 1024;
                // no break
            case 'm':
                $numericPart *= 1024;
                // no break
            case 'k':
                $numericPart *= 1024;
        }
        return $numericPart;
    }

    // endregion
}
