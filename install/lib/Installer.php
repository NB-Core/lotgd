<?php
namespace Lotgd\Installer;

/**
 * Handles the multi step installation of Legend of the Green Dragon.
 *
 * Each public stage method represents a step in the installer wizard.
 */
class Installer
{
    /**
     * Counter for unique HTML tip identifiers used by the tip() helper.
     *
     * @var int
     */
    private int $tipid = 0;

    /**
     * Dynamically call one of the stage methods by number.
     */
    public function runStage(int $stage): void
    {
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
        output("`@`c`bWelcome to Legend of the Green Dragon`b`c`0");
        output("`2This is the installer script for Legend of the Green Dragon, by Eric Stevens & JT Traub.`n");
        output("`nIn order to install and use Legend of the Green Dragon (LoGD), you must agree to the license under which it is deployed.`n");
        output("`n`&This game is a small project into which we have invested a tremendous amount of personal effort, and we provide this to you absolutely free of charge.`2");
        output("Please understand that if you modify our copyright, or otherwise violate the license, you are not only breaking international copyright law (which includes penalties which are defined in whichever country you live), but you're also defeating the spirit of open source, and ruining any good faith which we have demonstrated by providing our blood, sweat, and tears to you free of charge.  You should also know that by breaking the license even one time, it is within our rights to require you to permanently cease running LoGD forever.`n");
        output("`nPlease note that in order to use the installer, you must have cookies enabled in your browser.`n");
        if (getenv("MYSQL_HOST")) {
        	output("`n`$This seems to be a Docker setup, which means the database will be provided by the environment variables. You can change them if you want, but most likely you won't be able to connect to the database.`2`n");
        	output("Also, you need to take care of other hosting issues, like SSL, possibly Let's encrypt and other things.");
        	output("`n`nNote that the entire html folder is volume-linked, so the database connection file and more will be stored there, too.");
        }
        if (defined("DB_CHOSEN") && DB_CHOSEN){
        	$sql = "SELECT count(*) AS c FROM ".db_prefix("accounts")." WHERE superuser & ".SU_MEGAUSER;
        	$result = db_query($sql);
        	$row = db_fetch_assoc($result);
        	if ($row['c'] == 0){
        		$needsauthentication = false;
        	}
        	if (httppost("username")>""){
        		debug(md5(md5(stripslashes(httppost("password")))), true);
        		$sql = "SELECT * FROM ".db_prefix("accounts")." WHERE login='".httppost("username")."' AND password='".md5(md5(stripslashes(httppost("password"))))."' AND superuser & ".SU_MEGAUSER;
        		$result = db_query($sql);
        		if (db_num_rows($result) > 0){
        			$row = db_fetch_assoc($result);
        			debug($row['password'], true);
        			debug(httppost('password'), true);
        			// Okay, we have a username with megauser, now we need to do
        			// some hackery with the password.
        			$needsauthentication=true;
        			$p = stripslashes(httppost("password"));
        			$p1 = md5($p);
        			$p2 = md5($p1);
        			debug($p2, true);
        
        			if (getsetting("installer_version", "-1") == "-1") {
        				debug("HERE I AM", true);
        				// Okay, they are upgrading from 0.9.7  they will have
        				// either a non-encrypted password, or an encrypted singly
        				// password.
        				if (strlen($row['password']) == 32 &&
        				$row['password'] == $p1) {
        					$needsauthentication = false;
        				} elseif ($row['password'] == $p) {
        					$needsauthentication = false;
        				}
        			} elseif ($row['password'] == $p2) {
        				$needsauthentication = false;
        			}
        			if ($needsauthentication === false) {
                                       redirect("install/index.php?stage=1");
        			}
        			output("`$That username / password was not found, or is not an account with sufficient privileges to perform the upgrade.`n");
        		}else{
        			$needsauthentication=true;
        			output("`$That username / password was not found, or is not an account with sufficient privileges to perform the upgrade.`n");
        		}
        	}else{
        		$sql = "SELECT count(*) AS c FROM ".db_prefix("accounts")." WHERE superuser & ".SU_MEGAUSER;
        		$result = db_query($sql);
        		$row = db_fetch_assoc($result);
        		if ($row['c']>0){
        			$needsauthentication=true;
        		}else{
        			$needsauthentication=false;
        		}
        	}
        }else{
        	$needsauthentication=false;
        }
        //if a user with appropriate privs is already logged in, let's let them past.
        if ($session['user']['superuser'] & SU_MEGAUSER) $needsauthentication=false;
        if ($needsauthentication){
        	$session['stagecompleted']=-1;
               rawoutput("<form action='install/index.php?stage=0' method='POST'>");
        	output("`%In order to upgrade this LoGD installation, you will need to provide the username and password of a superuser account with the MEGAUSER privilege`n");
        	output("`^Username: `0");
        	rawoutput("<input name='username'><br>");
        	output("`^Password: `0");
        	rawoutput("<input type='password' name='password'><br>");
        	$submit = translate_inline("Submit");
        	rawoutput("<input type='submit' value='$submit' class='button'>");
        	rawoutput("</form>");
        }else{
        	output("`nPlease continue on to the next page, \"License Agreement.\"");
        }
    }

    /**
     * Stage 1 - Show the license agreement that must be accepted.
     */
    public function stage1(): void
    {
        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;
        require_once("lib/pullurl.php");
        $license = join("",pullurl("http://creativecommons.org/licenses/by-nc-sa/2.0/legalcode"));
        $license = str_replace("\n","",$license);
        $license = str_replace("\r","",$license);
        $shortlicense=array();
        preg_match_all("'<body[^>]*>(.*)</body>'",$license,$shortlicense);
        $license = $shortlicense[1][0];
        output("`@`c`bLicense Agreement`b`c`0");
        output("`2Before continuing, you must read and understand the following license agreement.`0`n`n");
        if (md5($license)=="484d213db9a69e79321feafb85915ff1"){
        	rawoutput("<div style='width: 100%; height; 350px; max-height: 350px; overflow: auto; color: #FFFFFF; background-color: #000000; padding: 10px;'>");
        	rawoutput("<base href='http://creativecommons.org/licenses/by-nc-sa/2.0/legalcode'>");
        	rawoutput("<base target='_blank'>");
        	rawoutput($license);
        	rawoutput("</div>");
        	rawoutput("<base href='http://".$_SERVER["HTTP_HOST"].$_SERVER["REQUEST_URI"]."'>");
        	rawoutput("<base target='_self'>");
        }else{
        	output("`^Warning, the Creative Commons license has changed, or could not be retrieved from the Creative Commons server.");
        	output("You should check with the game authors to ensure that the below license agrees with the license under which it was released.");
        	output("The license may be referenced at <a target='_blank' href='http://creativecommons.org/licenses/by-nc-sa/2.0/legalcode'>the Creative Commons site</a>.",true);
        }
        $license = join("",file("LICENSE.txt"));
        $license = preg_replace("/[^\na-zA-Z0-9!?.,;:'\"\/\\()@ -\]\[]/","",$license);
        $licensemd5s = array(
        'e281e13a86d4418a166d2ddfcd1e8032'=>true, //old for DP
        'bc9f6fb23e352600d6c1c948298cbd82'=>true, //new for +nb
        );
        if (isset($licensemd5s[md5($license)])){
        	// Reload it so we get the right line breaks, etc.
        	//$license = file("LICENSE.txt");
        	$license = htmlentities($license, ENT_COMPAT, getsetting("charset", "ISO-8859-1"));
        	$license = nl2br($license);
        	//$license = preg_replace("/<br[^>]*>\s+<br[^>]*>/i","<p>",$license);
        	//$license = preg_replace("/<br[^>]*>/i","",$license);
        	output("`n`n`b`@Plain Text:`b`n`7");
        	rawoutput($license);
        }else{
        	output("`^The license file (LICENSE.txt) has been modified.  Please obtain a new copy of the game's code, this file has been tampered with.");
        	output("Expected MD5 in (".join(array_keys($licensemd5s),",")."), but got ".md5($license));
        	$stage=-1;
        	$session['stagecompleted']=-1;
        }
    }

    /**
     * Stage 10 - Create or verify the initial superuser account.
     */
    public function stage10(): void
    {
        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;
        output("`@`c`bSuperuser Accounts`b`c");
        debug($logd_version, true);
        $sql = "SELECT login, password FROM ".db_prefix("accounts")." WHERE superuser & ".SU_MEGAUSER;
        $result = db_query($sql);
        if (db_num_rows($result)==0){
        	if (httppost("name")>""){
        		$showform=false;
        		if (httppost("pass1")!=httppost("pass2")){
        			output("`$Oops, your passwords don't match.`2`n");
        			$showform=true;
        		}elseif (strlen(httppost("pass1"))<6){
        			output("`$Whoa, that's a short password, you really should make it longer.`2`n");
        			$showform=true;
        		}else{
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
        			$name = httppost("name");
        			$pass = md5(md5(stripslashes(httppost("pass1"))));
        			$sql = "DELETE FROM ".db_prefix("accounts")." WHERE login='$name'";
        			db_query($sql);
        			$sql = "INSERT INTO " .db_prefix("accounts") ." (login,password,superuser,name,playername,ctitle,title,regdate,badguy,companions, allowednavs, bufflist, dragonpoints, prefs, donationconfig,specialinc,specialmisc,emailaddress,replaceemail,emailvalidation,hauntedby,bio) VALUES('$name','$pass',$su,'`%Admin `&$name`0','`%Admin `&$name`0','`%Admin','', NOW(),'','','','','','','','','','','','','','')";
        			$result=db_query($sql);
        			if (db_affected_rows($result)==0) {
        				print_r($sql);
        				die("Failed to create Admin account. Your first check should be to make sure that MYSQL (if that is your type) is not in strict mode.");
        			}
        			output("`^Your superuser account has been created as `%Admin `&$name`^!");
        			savesetting("installer_version",$logd_version);
        		}
        	}else{
        		$showform=true;
        		savesetting("installer_version",$logd_version);
        	}
        	if ($showform){
                       rawoutput("<form action='install/index.php?stage=$stage' method='POST'>");
        		output("Enter a name for your superuser account:");
        		rawoutput("<input name='name' value=\"".htmlentities(httppost("name"), ENT_COMPAT, getsetting("charset", "ISO-8859-1"))."\">");
        		output("`nEnter a password: ");
        		rawoutput("<input name='pass1' type='password'>");
        		output("`nConfirm your password: ");
        		rawoutput("<input name='pass2' type='password'>");
        		$submit = translate_inline("Create");
        		rawoutput("<br><input type='submit' value='$submit' class='button'>");
        		rawoutput("</form>");
        	}
        }else{
        	output("`#You already have a superuser account set up on this server.");
        	savesetting("installer_version",$logd_version);
        }
    }

    /**
     * Stage 11 - Final completion message and clean up actions.
     */
    public function stage11(): void
    {
        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;
        output("`@`c`bAll Done!`b`c");
        output("Your install of Legend of the Green Dragon has been completed!`n");
        output("`nRemember us when you have hundreds of users on your server, enjoying the game.");
        output("Eric, JT, and a lot of others put a lot of work into this world, so please don't disrespect that by violating the license.");
        if ($session['user']['loggedin']){
        	addnav("Continue",$session['user']['restorepage']);
        }else{
        	addnav("Login Screen","./");
        }
        savesetting("installer_version",$logd_version);
        $file = __DIR__ . '/../index.php';
        	if (file_exists($file)) {
        		try {
        			if (unlink($file)) {
        				output("`2Installer file install/index.php removed.`n");
        			} else {
        				output("`$Unable to delete install/index.php. Please remove it manually.`n");
        			}
        		} catch (Throwable $e) {
        			output("`$Error deleting install/index.php: " . $e->getMessage() . "`n");
        		}
        	}
        $noinstallnavs=true;
    }

    /**
     * Stage 2 - Confirm acceptance of the license agreement.
     */
    public function stage2(): void
    {
        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;
        output("`#By continuing with this installation, you indicate your agreement with the terms of the license found on the previous page (License Agreement).");
    }

    /**
     * Stage 3 - Gather database connection information from the user.
     */
    public function stage3(): void
    {
        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;
        rawoutput("<form action='install/index.php?stage=4' method='POST'>");
        output("`@`c`bDatabase Connection Information`b`c`2");
        output("In order to run Legend of the Green Dragon, your server must have access to a MySQL database.");
        output("If you are not sure if you meet this need, talk to server's Internet Service Provider (ISP), and make sure they offer MySQL databases.");
        output("If you are running on your own machine or a server under your control, you can download and install MySQL from <a href='http://www.mysql.com/' target='_blank'>the MySQL website</a> for free.`n",true);
        if (file_exists("dbconnect.php")){
        	output("There appears to already be a database setup file (dbconnect.php) in your site root, you can proceed to the next step.");
        }else{
        	if (getenv("MYSQL_HOST")) {
        		// Docker Setup
        		$session['dbinfo']['DB_HOST']=getenv("MYSQL_HOST");
        		$session['dbinfo']['DB_USER']=getenv("MYSQL_USER");
        		$session['dbinfo']['DB_PASS']=getenv("MYSQL_PASSWORD");
        		$session['dbinfo']['DB_NAME']=getenv("MYSQL_DATABASE");
        		$session['dbinfo']['DB_USEDATACACHE']=(bool)getenv("MYSQL_USEDATACACHE");
        		$session['dbinfo']['DB_DATACACHEPATH']=getenv("MYSQL_DATACACHEPATH");
        		output("`n`$This seems to be a Docker setup, so I will use the environment variables to connect to the database. You can change them if you want, but most likely you won't be able to connect to the database.`2`n");
        	}
        	output("`nIt looks like this is a new install of Legend of the Green Dragon.");
        	output("First, thanks for installing LoGD!");
        	output("In order to connect to the database server, I'll need the following information.");
        	output("`iIf you are unsure of the answer to any of these questions, please check with your server's ISP, or read the documentation on MySQL`i`n");
        
        	output("`nWhat is the address of your database server?`n");
        	rawoutput("<input name='DB_HOST' value=\"".htmlentities($session['dbinfo']['DB_HOST'], ENT_COMPAT, getsetting("charset", "ISO-8859-1"))."\">");
        	$this->tip("If you are running LoGD from the same server as your database, use 'localhost' here.  Otherwise, you will have to find out what the address is of your database server.  Your server's ISP might be able to provide this information.");
        
        	output("`nWhat is the username you use to connect to the database server?`n");
        	rawoutput("<input name='DB_USER' value=\"".htmlentities($session['dbinfo']['DB_USER'], ENT_COMPAT, getsetting("charset", "ISO-8859-1"))."\">");
        	$this->tip("This username does not have to be the same one you use to connect to the database server for administrative reasons.  However, in order to use this installer, and to install some of the modules, the account you provide here must have the ability to create, modify, and drop tables.  If you want the installer to create a new database for LoGD, the account will also have to have the ability to create databases.  Finally, to run the game, this account must at a minimum be able to select, insert, update, and delete records, and be able to lock tables.  If you're uncertain, grant the account the following privileges: SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, and ALTER.");
        
        	output("`nWhat is the password for this username?`n");
        	rawoutput("<input name='DB_PASS' value=\"".htmlentities($session['dbinfo']['DB_PASS'], ENT_COMPAT, getsetting("charset", "ISO-8859-1"))."\">");
        	$this->tip("The password is necessary here in order for the game to successfully connect to the database server.  This information is not shared with anyone, it is simply used to configure the game.");
        
        	output("`nWhat is the name of the database you wish to install LoGD in?`n");
        	rawoutput("<input name='DB_NAME' value=\"".htmlentities($session['dbinfo']['DB_NAME'], ENT_COMPAT, getsetting("charset", "ISO-8859-1"))."\">");
        	$this->tip("Database servers such as MySQL can control many different databases.  This is very useful if you have many different programs each needing their own database.  Each database has a unique name.  Provide the name you wish to use for LoGD in this field.");
        
        	output("`nDo you want to use datacaching (high load optimization)?`n");
        	rawoutput("<select name='DB_USEDATACACHE'>");
        	rawoutput("<option value=\"1\" ".($session['dbinfo']['DB_USEDATACACHE']?'selected=\"selected\"':'').">".translate_inline("Yes")."</option>");
        	rawoutput("<option value=\"0\" ".(!$session['dbinfo']['DB_USEDATACACHE']?'selected=\"selected\"':'').">".translate_inline("No")."</option>");
        	rawoutput("</select>");
        	$this->tip("Do you want to use a datacache for the sql queries? Many internal queries produce the same results and can be cached. This feature is *highly* recommended to use as the MySQL server is usually high frequented. When using in an environment where Safe Mode is enabled; this needs to be a path that has the same UID as the web server runs.");
        
        	output("`nIf yes, what is the path to the datacache directory?`n");
        	rawoutput("<input name='DB_DATACACHEPATH' value=\"".htmlentities($session['dbinfo']['DB_DATACACHEPATH'], ENT_COMPAT, getsetting("charset", "ISO-8859-1"))."\">");
        	$this->tip("If you have chosen to use the datacache function, you have to enter a path here to where temporary files may be stored. Verify that you have the proper permission (777) set to this folder, else you will have lots of errors. Do NOT end with a slash / ... just enter the dir");
        
        	/*
        		$yes = translate_inline("Yes");
        		$no = translate_inline("No");
        		output("`nShould I attempt to create this database if it does not exist?`n");
        		rawoutput("<select name='DB_CREATE'><option value='1'>$yes</option><option value='0'>$no</option></select>");
        		$this->tip("If this database doesn't exist, I'll try to create it for you if you like.");
        	*/
        	$submit="Test this connection information.";
        	output_notl("`n`n<input type='submit' value='$submit' class='button'>",true);
        }
        rawoutput("</form>");
    }

    /**
     * Stage 4 - Test the provided database connection settings.
     */
    public function stage4(): void
    {
        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;
        require_once("lib/dbwrapper.php");
        if (httppostisset("DB_HOST")) {
        	$session['dbinfo']['DB_HOST']=httppost("DB_HOST");
        	$session['dbinfo']['DB_USER']=httppost("DB_USER");
        	$session['dbinfo']['DB_PASS']=httppost("DB_PASS");
        	$session['dbinfo']['DB_NAME']=httppost("DB_NAME");
        	$session['dbinfo']['DB_USEDATACACHE']=(bool)httppost("DB_USEDATACACHE");
        	$session['dbinfo']['DB_DATACACHEPATH']=httppost("DB_DATACACHEPATH");
        }
        output("`@`c`bTesting the Database Connection`b`c`2");
        output("Trying to establish a connection with the database:`n");
        ob_start();
        $connected = db_connect($session['dbinfo']['DB_HOST'], $session['dbinfo']['DB_USER'], $session['dbinfo']['DB_PASS']);
        $error = ob_get_contents();
        ob_end_clean();
        if (!$connected){
        	output("`$Blast!  I wasn't able to connect to the database server with the information you provided!");
        	output("`2This means that either the database server address, database username, or database password you provided were wrong, or else the database server isn't running.");
        	output("The specific error the database returned was:");
        	rawoutput("<blockquote>".$error."</blockquote>");
        	output("If you believe you provided the correct information, make sure that the database server is running (check documentation for how to determine this).");
        	output("Otherwise, you should return to the previous step, \"Database Info\" and double-check that the information provided there is accurate.");
        	$session['stagecompleted']=3;
        }else{
        	output("`^Yahoo, I was able to connect to the database server!");
        	output("`2This means that the database server address, database username, and database password you provided were probably accurate, and that your database server is running and accepting connections.`n");
        	output("`nI'm now going to attempt to connect to the LoGD database you provided.`n");
                $link = db_get_instance();
                if (httpget("op")=="trycreate"){ 
                        $this->createDb($link, $session['dbinfo']['DB_NAME']);
                }
        	if (!db_select_db($session['dbinfo']['DB_NAME'])){
        		output("`$Rats!  I was not able to connect to the database.");
        		$error = db_error();
        		if ($error=="Unknown database '{$session['dbinfo']['DB_NAME']}'"){
        			output("`2It looks like the database for LoGD hasn't been created yet.");
        			output("I can attempt to create it for you if you like, but in order for that to work, the account you provided has to have permissions to create a new database.");
        			output("If you're not sure what this means, it's safe to try to create this database, but you should double check that you've typed the name correctly by returning to the previous stage before you try it.`n");
                               output("`nTo try to create the database, <a href='install/index.php?stage=4&op=trycreate'>click here</a>.`n",true);
        		}else{
        			output("`2This is probably because the username and password you provided doesn't have permission to connect to the database.`n");
        		}
        		output("`nThe exact error returned from the database server was:");
        		rawoutput("<blockquote>$error</blockquote>");
        		$session['stagecompleted']=3;
        	}else{
        		output("`n`^Excellent, I was able to connect to the database!`n");
        		define("DB_INSTALLER_STAGE4", true);
        		output("`n`@Tests`2`n");
        		output("I'm now going to run a series of tests to determine what the permissions of this account are.`n");
        		$issues = array();
        		output("`n`^Test: `#Creating a table`n");
        		//try to destroy the table if it's already here.
        		$sql = "DROP TABLE IF EXISTS logd_environment_test";
        		db_query($sql,false);
        		$sql = "CREATE TABLE logd_environment_test (a int(11) unsigned not null)";
        		db_query($sql);
        		if ($error=db_error()){
        			output("`2Result: `$Fail`n");
        			rawoutput("<blockquote>$error</blockquote>");
        			array_push($issues,"`^Warning:`2 The installer will not be able to create the tables necessary to install LoGD.  If these tables already exist, or you have created them manually, then you can ignore this.  Also, many modules rely on being able to create tables, so you will not be able to use these modules.");
        		}else{
        			output("`2Result: `@Pass`n");
        		}
        			output("`n`^Test: `#Modifying a table`n");
        		$sql = "ALTER TABLE logd_environment_test CHANGE a b varchar(50) not null";
        		db_query($sql);
        		if ($error=db_error()){
        			output("`2Result: `$Fail`n");
        			rawoutput("<blockquote>$error</blockquote>");
        			array_push($issues,"`^Warning:`2 The installer will not be able to modify existing tables (if any) to line up with new configurations.  Also, many modules rely on table modification permissions, so you will not be able to use these modules.");
        		}else{
        			output("`2Result: `@Pass`n");
        		}
        			output("`n`^Test: `#Creating an index`n");
        		$sql = "ALTER TABLE logd_environment_test ADD INDEX(b)";
        		db_query($sql);
        		if ($error=db_error()){
        			output("`2Result: `$Fail`n");
        			rawoutput("<blockquote>$error</blockquote>");
        			array_push($issues,"`^Warning:`2 The installer will not be able to create indices on your tables.  Indices are extremely important for an active server, but can be done without on a small server.");
        		}else{
        			output("`2Result: `@Pass`n");
        		}
        			output("`n`^Test: `#Inserting a row`n");
        		$sql = "INSERT INTO logd_environment_test (b) VALUES ('testing')";
        		db_query($sql);
        		if ($error=db_error()){
        			output("`2Result: `$Fail`n");
        			rawoutput("<blockquote>$error</blockquote>");
        			array_push($issues,"`$Critical:`2 The game will not be able to function with out the ability to insert rows.");
        			$session['stagecompleted']=3;
        		}else{
        			output("`2Result: `@Pass`n");
        		}
        			output("`n`^Test: `#Selecting a row`n");
        		$sql = "SELECT * FROM logd_environment_test";
        		db_query($sql);
        		if ($error=db_error()){
        			output("`2Result: `$Fail`n");
        			rawoutput("<blockquote>$error</blockquote>");
        			array_push($issues,"`$Critical:`2 The game will not be able to function with out the ability to select rows.");
        			$session['stagecompleted']=3;
        		}else{
        			output("`2Result: `@Pass`n");
        		}
        			output("`n`^Test: `#Updating a row`n");
        		$sql = "UPDATE logd_environment_test SET b='MightyE'";
        		db_query($sql);
        		if ($error=db_error()){
        			output("`2Result: `$Fail`n");
        			rawoutput("<blockquote>$error</blockquote>");
        			array_push($issues,"`$Critical:`2 The game will not be able to function with out the ability to update rows.");
        			$session['stagecompleted']=3;
        		}else{
        			output("`2Result: `@Pass`n");
        		}
        			output("`n`^Test: `#Deleting a row`n");
        		$sql = "DELETE FROM logd_environment_test";
        		db_query($sql);
        		if ($error=db_error()){
        			output("`2Result: `$Fail`n");
        			rawoutput("<blockquote>$error</blockquote>");
        			array_push($issues,"`$Critical:`2 The game database will grow very large with out the ability to delete rows.");
        			$session['stagecompleted']=3;
        		}else{
        			output("`2Result: `@Pass`n");
        		}
        			output("`n`^Test: `#Locking a table`n");
        		$sql = "LOCK TABLES logd_environment_test WRITE";
        		db_query($sql);
        		if ($error = db_error()) {
        			output("`2Result: `$Fail`n");
        			rawoutput("<blockquote>$error</blockquote>");
        			array_push($issues,"`$Critical:`2 The game will not run correctly without the ability to lock tables.");
        			$session['stagecompleted']=3;
        		} else {
        			output("`2Result: `@Pass`n");
        		}
        			output("`n`^Test: `#Unlocking a table`n");
        		$sql = "UNLOCK TABLES";
        		db_query($sql);
        		if ($error = db_error()) {
        			output("`2Result: `$Fail`n");
        			rawoutput("<blockquote>$error</blockquote>");
        			array_push($issues,"`$Critical:`2 The game will not run correctly without the ability to unlock tables.");
        			$session['stagecompleted']=3;
        		} else {
        			output("`2Result: `@Pass`n");
        		}
        			output("`n`^Test: `#Deleting a table`n");
        		$sql = "DROP TABLE logd_environment_test";
        		db_query($sql);
        		if ($error=db_error()){
        			output("`2Result: `$Fail`n");
        			rawoutput("<blockquote>$error</blockquote>");
        			array_push($issues,"`^Warning:`2 The installer will not be able to delete old tables (if any).  Also, many modules need to be able to delete the tables they put in place when they are uninstalled.  Although the game will function, you may end up with a lot of old data sitting around.");
        		}else{
        			output("`2Result: `@Pass`n");
        		}
        		output("`n`^Test: `#Checking datacache`n");
        		if (!$session['dbinfo']['DB_USEDATACACHE']) {
        			output("-----skipping, not selected-----`n");
        		} else {
        			$fp = @fopen($session['dbinfo']['DB_DATACACHEPATH']."/dummy.php","w+");
        			if ($fp){
        				if (fwrite($fp,	$dbconnect)!==false){
        					output("`2Result: `@Pass`n");
        				}else{
        					output("`2Result: `$Fail`n");
        					rawoutput("<blockquote>");
        					array_push($issues,"`^I was not able to write to your datacache directory!`n");
        				}
        				fclose($fp);
        				@unlink($session['dbinfo']['DB_DATACACHEPATH']."/dummy.php");
        			}else{
        				output("`2Result: `$Fail`n");
        				array_push($issues,"`^I was not able to write to your datacache directory! Check your permissions there!`n");
        			}
        		}
        		output("`n`^Overall results:`2`n");
        		if (count($issues)==0){
        			output("You've passed all the tests, you're ready for the next stage.");
        		}else{
        			rawoutput("<ul>");
        			output("<li>".join("</li>\n<li>",$issues)."</li>",true);
        			rawoutput("</ul>");
        			output("Even if all of the above issues are merely warnings, you will probably periodically see database errors as a result of them.");
        			output("It would be a good idea to resolve these permissions issues before attempting to run this game.");
        			output("For you technical folk, the specific permissions suggested are: SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER and LOCK TABLES.");
        			output("I'm sorry, this is not something I can do for you.");
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
        if (httppostisset("DB_PREFIX") > ""){
        	$session['dbinfo']['DB_PREFIX'] = httppost("DB_PREFIX");
        }
        if ($session['dbinfo']['DB_PREFIX'] > "" && substr($session['dbinfo']['DB_PREFIX'],-1)!="_")
        $session['dbinfo']['DB_PREFIX'] .= "_";
        
        $descriptors = $this->descriptors($session['dbinfo']['DB_PREFIX']);
        $unique=0;
        $game=0;
        $missing=0;
        $conflict = array();
        
        $link = db_connect($session['dbinfo']['DB_HOST'],$session['dbinfo']['DB_USER'],$session['dbinfo']['DB_PASS']);
        db_select_db($session['dbinfo']['DB_NAME']);
        $sql = "SHOW TABLES";
        $result = db_query($sql);
        //the conflicts seems not to work - we should check this.
        while ($row = db_fetch_assoc($result)){
        	foreach ($row as $key=>$val){
        		if (isset($descriptors[$val])){
        			$game++;
        			array_push($conflict,$val);
        		}else{
        			$unique++;
        		}
        	}
        }
        
        
        $missing = count($descriptors)-$game;
        if ($missing*10 < $game){
        	//looks like an upgrade
        	$upgrade=true;
        }else{
        	$upgrade=false;
        }
        if (httpget("type")=="install") $upgrade=false;
        if (httpget("type")=="upgrade") $upgrade=true;
        $session['dbinfo']['upgrade']=$upgrade;
        if ($upgrade){
        	output("`@This looks like a game upgrade.");
               output("`^If this is not an upgrade from a previous version of LoGD, <a href='install/index.php?stage=5&type=install'>click here</a>.",true);
        	output("`2Otherwise, continue on to the next step.");
        }else{
        	//looks like a clean install
        	$upgrade=false;
        	output("`@This looks like a fresh install.");
               output("`2If this is not a fresh install, but rather an upgrade from a previous version of LoGD, chances are that you installed LoGD with a table prefix.  If that's the case, enter the prefix below.  If you are still getting this message, it's possible that I'm just spooked by how few tables are common to the current version, and in which case, I can try an upgrade if you <a href='install/index.php?stage=5&type=upgrade'>click here</a>.`n",true);
        	if (count($conflict)>0){
        		output("`n`n`$There are table conflicts.`2");
        		output("If you continue with an install, the following tables will be overwritten with the game's tables.  If the listed tables belong to LoGD, they will be upgraded, otherwise all existing data in those tables will be destroyed.  Once this is done, this cannot be undone unless you have a backup!`n");
        		output("`nThese tables conflict: `^".join(", ",$conflict)."`2`n");
        		if (httpget("op")=="confirm_overwrite") $session['sure i want to overwrite the tables']=true;
        		if (!$session['sure i want to overwrite the tables']){
        			$session['stagecompleted']=4;
                               output("`nIf you are sure that you wish to overwrite these tables, <a href='install/index.php?stage=5&op=confirm_overwrite'>click here</a>.`n",true);
        		}
        	}
        	output("`nYou can avoid table conflicts with other applications in the same database by providing a table name prefix.");
        	output("This prefix will get put on the name of every table in the database.");
        }
        
        //Display rights - I won't parse them, sue me for laziness, and this should work nicely to explain any errors
        $sql="SHOW GRANTS FOR CURRENT_USER()";
        $result=db_query($sql);
        output("`2These are the rights for your mysql user, `$make sure you have the 'LOCK TABLES' privileges OR a \"GRANT ALL PRIVLEGES\" on the tables.`2`n`n");
        output("If you do not know what this means, ask your hosting provider that supplied you with the database credentials.`n`n");
        rawoutput("<table cellspacing='1' cellpadding='2' border='0' bgcolor='#999999'>");
        $i=0;
        while ($row=db_fetch_assoc($result)) {
        	if ($i == 0) {
        		rawoutput("<tr class='trhead'>");
        		$keys = array_keys($row);
        		foreach ($keys as $value) {
        			rawoutput("<td>$value</td>");
        		}
        		rawoutput("</tr>");
        	}
        	rawoutput("<tr class='".($i%2==0?"trlight":"trdark")."'>");
        	foreach ($keys as $value) {
        		rawoutput("<td valign='top'>{$row[$value]}</td>");
        	}
        	rawoutput("</tr>");
        	$i++;
        }
        rawoutput("</table>");
        
        //done
        
        rawoutput("<form action='install/index.php?stage=5' method='POST'>");
        output("`nTo provide a table prefix, enter it here.");
        output("If you don't know what this means, you should either leave it blank, or enter an intuitive value such as \"logd\".`n");
        rawoutput("<input name='DB_PREFIX' value=\"".htmlentities($session['dbinfo']['DB_PREFIX'], ENT_COMPAT, getsetting("charset", "ISO-8859-1"))."\"><br>");
        $submit = translate_inline("Submit your prefix.");
        rawoutput("<input type='submit' value='$submit' class='button'>");
        rawoutput("</form>");
        if (count($conflict)==0){
        	output("`^It looks like you can probably safely skip this step if you don't know what it means.");
        }
        output("`n`n`@Once you have submitted your prefix, you will be returned to this page to select the next step.");
        output("If you don't need a prefix, just select the next step now.");
    }

    /**
     * Stage 6 - Create the dbconnect.php configuration file.
     */
    public function stage6(): void
    {
        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;
        if (file_exists("dbconnect.php")){
        	$success=true;
        	$initial=false;
        }else{
        	$initial = true;
        	output("`@`c`bWriting your dbconnect.php file`b`c");
        	output("`2I'm attempting to write a file named 'dbconnect.php' to your site root.");
        	output("This file tells LoGD how to connect to the database, and is necessary to continue installation.`n");
        	$dbconnect =
        	"<?php\n"
               ."//This file automatically created by install/index.php on ".date("M d, Y h:i a")."\n"
        	."$DB_HOST = \"{$session['dbinfo']['DB_HOST']}\";\n"
        	."$DB_USER = \"{$session['dbinfo']['DB_USER']}\";\n"
        	."$DB_PASS = \"{$session['dbinfo']['DB_PASS']}\";\n"
        	."$DB_NAME = \"{$session['dbinfo']['DB_NAME']}\";\n"
        	."$DB_PREFIX = \"{$session['dbinfo']['DB_PREFIX']}\";\n"
        	."$DB_USEDATACACHE = ". ((int)$session['dbinfo']['DB_USEDATACACHE']) .";\n"
        	."$DB_DATACACHEPATH = \"{$session['dbinfo']['DB_DATACACHEPATH']}\";\n"
        	."?>\n";
        	$fp = @fopen("dbconnect.php","w+");
        	$failure=false;
        	if ($fp){
        		if (fwrite($fp, $dbconnect)!==false){
        			output("`n`@Success!`2  I was able to write your dbconnect.php file, you can continue on to the next step.");
        		}else{
        			$failure=true;
        		}
        		fclose($fp);
        	}else{
        		$failure=true;
        	}
        	if ($failure){
        		output("`n`$Unfortunately, I was not able to write your dbconnect.php file.");
        		output("`2You will have to create this file yourself, and upload it to your web server.");
        		output("The contents of this file should be as follows:`3");
        		rawoutput("<blockquote><pre>".htmlentities($dbconnect, ENT_COMPAT, getsetting("charset", "ISO-8859-1"))."</pre></blockquote>");
        		output("`2Create a new file, past the entire contents from above into it (everything from and including `3<?php`2 up to and including `3?>`2 ).");
        		output("When you have that done, save the file as 'dbconnect.php' and upload this to the location you have LoGD at.");
        		output("You can refresh this page to see if you were successful.");
        	}else{
        		$success=true;
        	}
        }
        if ($success && !$initial){
        	$version = getsetting("installer_version","-1");
        	$sub = substr($version, 0, 5);
        	$sub = (int)str_replace(".", "", $sub);
        	if ($sub < 110) {
        		$fp = @fopen("dbconnect.php","r+");
        		if ($fp){
        			while(!feof($fp)) {
        				$buffer = fgets($fp, 4096);
        				if (strpos($buffer, "$DB") !== false) {
        					@eval($buffer);
        				}
        			}
        			fclose($fp);
        		}
        		$dbconnect =
        			"<?php\n"
                               ."//This file automatically created by install/index.php on ".date("M d, Y h:i a")."\n"
        			."$DB_HOST = \"{$DB_HOST}\";\n"
        			."$DB_USER = \"{$DB_USER}\";\n"
        			."$DB_PASS = \"{$DB_PASS}\";\n"
        			."$DB_NAME = \"{$DB_NAME}\";\n"
        			."$DB_PREFIX = \"{$DB_PREFIX}\";\n"
        			."$DB_USEDATACACHE = ". ((int)$DB_USEDATACACHE).";\n"
        			."$DB_DATACACHEPATH = \"".addslashes($DB_DATACACHEPATH)."\";\n"
        			."?>\n";
        		// Check if the file is writeable for us. If yes, we will change the file and notice the admin
        		// if not, they have to change the file themselves...
        		$fp = @fopen("dbconnect.php","w+");
        		$failure = false;
        		if ($fp){
        			if (fwrite($fp, $dbconnect)!==false){
        				output("`n`@Success!`2  I was able to write your dbconnect.php file.");
        			}else{
        				$failure=true;
        			}
        			fclose($fp);
        		}else{
        			$failure=true;
        		}
        		if ($failure) {
        			output("`2With this new version the settings for datacaching had to be moved to `idbconnect.php`i.");
        			output("Due to your system settings and privleges for this file, I was not able to perform the changes by myself.");
        			output("This part involves you: We have to ask you to replace the content of your existing `idbconnect.php`i with the following code:`n`n`&");
        			rawoutput("<blockquote><pre>".htmlentities($dbconnect, ENT_COMPAT, getsetting("charset", "ISO-8859-1"))."</pre></blockquote>");
        			output("`2This will let you use your existing datacaching settings.`n`n");
        			output("If you have done this, you are ready for the next step.");
        		} else {
        			output("`n`^You are ready for the next step.");
        		}
        	} else {
        		output("`n`^You are ready for the next step.");
        	}
        }else if(!$success) {
        	$session['stagecompleted']=5;
        }
    }

    /**
     * Stage 7 - Choose between new installation or upgrade.
     */
    public function stage7(): void
    {
        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;
        require(__DIR__ . "/../data/installer_sqlstatements.php");
        if (httppost("type")>""){
        	if (httppost("type")=="install") {
        		$session['fromversion']="-1";
        		$session['dbinfo']['upgrade']=false;
        	}else{
        		$session['fromversion']=httppost("version");
        		$session['dbinfo']['upgrade']=true;
        	}
        }
        
        if (!isset($session['fromversion']) || $session['fromversion']==""){
        	output("`@`c`bConfirmation`b`c");
        	output("`2Please confirm the following:`0`n");
               rawoutput("<form action='install/index.php?stage=7' method='POST'>");
        	rawoutput("<table border='0' cellpadding='0' cellspacing='0'><tr><td valign='top'>");
        	output("`2I should:`0");
        	rawoutput("</td><td>");
        	$version = getsetting("installer_version","-1");
        	if ($version != "-1") $session['dbinfo']['upgrade']=true;
        	rawoutput("<input type='radio' value='upgrade' name='type'".($session['dbinfo']['upgrade']?" checked":"").">");
        	output(" `2Perform an upgrade from ");
        	if ($version=="-1") $version="0.9.7";
        	reset($sql_upgrade_statements);
        	rawoutput("<select name='version'>");
        	foreach($sql_upgrade_statements as $key=>$val){
        		if ($key!="-1"){
        			rawoutput("<option value='$key'".($version==$key?" selected":"").">$key</option>");
        		}
        	}
        	rawoutput("</select>");
        	rawoutput("<br><input type='radio' value='install' name='type'".($session['dbinfo']['upgrade']?"":" checked").">");
        	output(" `2Perform a clean install.");
        	rawoutput("</td></tr></table>");
        	$submit=translate_inline("Submit");
        	rawoutput("<input type='submit' value='$submit' class='button'>");
        	rawoutput("</form>");
        	$session['stagecompleted']=$stage - 1;
        }else{
        	$session['stagecompleted']=$stage;
               header("Location: install/index.php?stage=".($stage+1));
        	exit();
        }
    }

    /**
     * Stage 8 - Select which modules to install and activate.
     */
    public function stage8(): void
    {
        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;
        if (array_key_exists('modulesok',$_POST)){
        	$session['moduleoperations'] = $_POST['modules'];
        	$session['stagecompleted'] = $stage;
               header("Location: install/index.php?stage=".($stage+1));
        	exit();
        }elseif (array_key_exists('moduleoperations',$session) && is_array($session['moduleoperations'])){
        	$session['stagecompleted'] = $stage;
        }else{
        	$session['stagecompleted'] = $stage - 1;
        }
        output("`@`c`bManage Modules`b`c");
        output("Legend of the Green Dragon supports an extensive module system.");
        output("Modules are small self-contained files that perform a specific function or event within the game.");
        output("For the most part, modules are independant of each other, meaning that one module can be installed, uninstalled, activated, and deactivated without negative impact on the rest of the game.");
        output("Not all modules are ideal for all sites, for example, there's a module called 'Multiple Cities,' which is intended only for large sites with many users online at the same time.");
        output("`n`n`^If you are not familiar with Legend of the Green Dragon, and how the game is played, it is probably wisest to choose the default set of modules to be installed.");
        output("`n`n`@There is an extensive community of users who write modules for LoGD at <a href='http://dragonprime.net/'>http://dragonprime.net/</a>.",true);
        $phpram = ini_get("memory_limit");
        if ($this->returnBytes($phpram) < 12582912 && $phpram!=-1 && !$session['overridememorylimit'] && !$session['dbinfo']['upgrade']) {// 12 MBytes
        															    // enter this ONLY if it's not an upgrade and if the limit is really too low
        	output("`n`n`$Warning: Your PHP memory limit is set to a very low level.");
        	output("Smaller servers should not be affected by this during normal gameplay but for this installation step you should assign at least 12 Megabytes of RAM for your PHP process.");
        	output("For now we will skip this step, but before installing any module, make sure to increase you memory limit.");
        	output("`nYou can proceed at your own risk. Be aware that a blank screen indicates you *must* increase the memory limit.");
        	output("`n`nTo override click again on \"Set Up Modules\".");
        	$session['stagecompleted'] = "8";
        	$session['overridememorylimit'] = true;
        	$session['skipmodules'] = true;
        } else {
        	if (isset($session['overridememorylimit']) && $session['overridememorylimit']) {
        		output("`4`n`nYou have been warned... you are now working on your own risk.`n`n");
        		$session['skipmodules'] = false;
        	}
        	$submit = translate_inline("Save Module Settings");
        	$install = translate_inline("Select Recommended Modules");
        	$reset = translate_inline("Reset Values");
        	$all_modules = array();
        
        	//check if we have no table there right now (fresh install)
        	if (isset($session['dbinfo']) && $session['dbinfo']['upgrade']) {
        		$sql = "SELECT * FROM ".db_prefix("modules")." ORDER BY category,active DESC,formalname";
        		$result = @db_query($sql);
        		if ($result!==false){
        			while ($row = db_fetch_assoc($result)){
        				if (!array_key_exists($row['category'],$all_modules)){
        					$all_modules[$row['category']] = array();
        				}
        				$row['installed']=true;
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
        			"version"=>"",
        			"author"=>"",
        			"category"=>"Invalid Modules",
        			"download"=>"",
        			"description"=>"",
        			"invalid"=>true,
        			);
        	foreach($uninstalled as $key=>$modulename){
        		$row = array();
        		//test if the file is a valid module or a lib file/whatever that got in, maybe even malcode that does not have module form
        		$file = file_get_contents("modules/$modulename.php");
        		if (strpos($file,$modulename."_getmoduleinfo")===false ||
        				//strpos($file,$shortname."_dohook")===false ||
        				//do_hook is not a necessity
        				strpos($file,$modulename."_install")===false ||
        				strpos($file,$modulename."_uninstall")===false) {
        			//here the files has neither do_hook nor getinfo, which means it won't execute as a module here --> block it + notify the admin who is the manage modules section
        			$moduleinfo=array_merge($invalidmodule,array("name"=>$modulename.".php ".appoencode(translate_inline("(`$Invalid Module! Contact Author or check file!`0)"))));
        		} else {
        			$moduleinfo= get_module_info($modulename,false,false);
        		}
        		//end of testing
        		$row['installed'] = false;
        		$row['active'] = false;
        		$row['category'] = $moduleinfo['category'];
        		$row['modulename'] = $modulename;
        		$row['formalname'] = $moduleinfo['name'];
        		$row['description'] = $moduleinfo['description'];
        		$row['moduleauthor'] = $moduleinfo['author'];
        		$row['invalid'] = (isset($moduleinfo['invalid']))?$moduleinfo['invalid']:false;
        		if (!array_key_exists($row['category'],$all_modules)){
        			$all_modules[$row['category']] = array();
        		}
        		$all_modules[$row['category']][$row['modulename']] = $row;
        	}
        	output_notl("`0");
               rawoutput("<form action='install/index.php?stage=".$stage."' method='POST'>");
        	rawoutput("<input type='submit' name='modulesok' value='$submit' class='button'>");
        	rawoutput("<input type='button' onClick='chooseRecommendedModules();' class='button' value='$install'>");
        	rawoutput("<input type='reset' value='$reset' class='button'><br>");
        	rawoutput("<table cellpadding='1' cellspacing='1'>");
        	ksort($all_modules);
        	reset($all_modules);
        	$x=0;
        	foreach($all_modules as $categoryName=>$categoryItems){
        		rawoutput("<tr class='trhead'><td colspan='6'>".tl($categoryName)."</td></tr>");
        		rawoutput("<tr class='trhead'><td>".tl("Uninstalled")."</td><td>".tl("Installed")."</td><td>".tl("Activated")."</td><td>".tl("Recommended")."</td><td>".tl("Module Name")."</td><td>".tl("Author")."</td></tr>");
        		foreach($categoryItems as $modulename=>$moduleinfo){
        			$x++;
        			//if we specified things in a previous hit on this page, let's update the modules array here as we go along.
        			$moduleinfo['realactive'] = $moduleinfo['active'];
        			$moduleinfo['realinstalled'] = $moduleinfo['installed'];
        			if (array_key_exists('moduleoperations',$session) && is_array($session['moduleoperations']) && array_key_exists($modulename,$session['moduleoperations'])){
        				$ops = explode(",",$session['moduleoperations'][$modulename]);
        				reset($ops);
        				foreach($ops as $op){
        					switch($op){
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
        			rawoutput("<tr class='".($x%2?"trlight":"trdark")."'>");
        			if ($moduleinfo['realactive']){
        				$uninstallop = "uninstall";
        				$installop = "deactivate";
        				$activateop = "donothing";
        			}elseif ($moduleinfo['realinstalled']){
        				$uninstallop = "uninstall";
        				$installop = "donothing";
        				$activateop = "activate";
        			}else{
        				$uninstallop = "donothing";
        				$installop = "install";
        				$activateop = "install,activate";
        			}
        			$uninstallcheck = false;
        			$installcheck = false;
        			$activatecheck = false;
        			if ($moduleinfo['active']){
        				$activatecheck = true;
        			}elseif ($moduleinfo['installed']){
        				//echo "<font color='red'>$modulename is installed but not active.</font><br>";
        				$installcheck = true;
        			}else{
        				//echo "$modulename is uninstalled.<br>";
        				$uninstallcheck = true;
        			}
        			if (isset($moduleinfo['invalid']) && $moduleinfo['invalid'] == true) {
        				rawoutput("<td><input type='radio' name='modules[$modulename]' id='uninstall-$modulename' value='$uninstallop' checked disabled></td>");
        				rawoutput("<td><input type='radio' name='modules[$modulename]' id='install-$modulename' value='$installop' disabled></td>");
        				rawoutput("<td><input type='radio' name='modules[$modulename]' id='activate-$modulename' value='$activateop' disabled></td>");
        			} else {
        				rawoutput("<td><input type='radio' name='modules[$modulename]' id='uninstall-$modulename' value='$uninstallop'".($uninstallcheck?" checked":"")."></td>");
        				rawoutput("<td><input type='radio' name='modules[$modulename]' id='install-$modulename' value='$installop'".($installcheck?" checked":"")."></td>");
        				rawoutput("<td><input type='radio' name='modules[$modulename]' id='activate-$modulename' value='$activateop'".($activatecheck?" checked":"")."></td>");
        			}
        			output_notl("<td>".(in_array($modulename,$recommended_modules)?tl("`^Yes`0"):tl("`$No`0"))."</td>",true);
        			require_once("lib/sanitize.php");
        			rawoutput("<td><span title=\"" .
        					(isset($moduleinfo['description']) &&
        					 $moduleinfo['description'] ?
        					 $moduleinfo['description'] :
        					 sanitize($moduleinfo['formalname'])). "\">");
        			output_notl("`@");
        			if (isset($moduleinfo['invalid']) && $moduleinfo['invalid'] == true) {
        				rawoutput($moduleinfo['formalname']);
        			} else {
        				output($moduleinfo['formalname']);
        			}
        			output_notl(" [`%$modulename`@]`0");
        			rawoutput("</span></td><td>");
        			output_notl("`#{$moduleinfo['moduleauthor']}`0", true);
        			rawoutput("</td>");
        			rawoutput("</tr>");
        		}
        	}
        	rawoutput("</table>");
        	rawoutput("<br><input type='submit' name='modulesok' value='$submit' class='button'>");
        	rawoutput("<input type='button' onClick='chooseRecommendedModules();' class='button' value='$install' class='button'>");
        	rawoutput("<input type='reset' value='$reset' class='button'>");
        	rawoutput("</form>");
        	rawoutput("<script language='JavaScript'>
        			function chooseRecommendedModules(){
        			var thisItem;
        			var selectedCount = 0;
        			");
        			foreach($recommended_modules as $key=>$val){
        				rawoutput("thisItem = document.getElementById('activate-$val'); ");
        				rawoutput("if (!thisItem.checked) { selectedCount++; thisItem.checked=true; }\n");
        			}
        			rawoutput("
        					alert('I selected '+selectedCount+' modules that I recommend, but which were not already selected.');
        					}");
        	if (!$session['dbinfo']['upgrade']){
        		rawoutput("
        				chooseRecommendedModules();");
        	}
        	rawoutput("
        			</script>");
        }
    }

    /**
     * Stage 9 - Build or upgrade the game tables.
     */
    public function stage9(): void
    {
        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;
        require_once(__DIR__ . "/../data/installer_sqlstatements.php");
        output("`@`c`bBuilding the Tables`b`c");
        output("`2I'm now going to build the tables.");
        output("If this is an upgrade, your current tables will be brought in line with the current version.");
        output("If it's an install, the necessary tables will be placed in your database.`n");
        output("`n`@Table Synchronization Logs:`n");
        rawoutput("<div style='width: 100%; height: 150px; max-height: 150px; overflow: auto;'>");
        $descriptors = $this->descriptors($DB_PREFIX);
        require_once("lib/tabledescriptor.php");
        foreach($descriptors as $tablename=>$descriptor){
        	output("`3Synchronizing table `#$tablename`3..`n");
        	synctable($tablename,$descriptor,true);
        	if ($session['dbinfo']['upgrade']==false){
        		//on a clean install, destroy all old data.
        		db_query("TRUNCATE TABLE $tablename");
        	}
        }
        rawoutput("</div>");
        output("`n`2The tables now have new fields and columns added, I'm going to begin importing data now.`n");
        rawoutput("<div style='width: 100%; height: 150px; max-height: 150px; overflow: auto;'>");
        $dosql = false;
        reset($sql_upgrade_statements);
        foreach ($sql_upgrade_statements as $key=>$val) {
        	if ($dosql){
        		output("`3Version `#%s`3: %s SQL statements...`n",$key,count($val));
        		if (count($val)>0){
        			output("`^Doing: `6");
        			reset($val);
        			$count=0;
        			foreach($val as $id=>$sql){
        				$onlyupgrade = 0;
        				if (substr($sql, 0, 2) == "1|") {
        					$sql = substr($sql, 2);
        					$onlyupgrade = 1;
        				}
        				// Skip any statements that should only be run during
        				// upgrades from previous versions.
        				if (!$session['dbinfo']['upgrade'] && $onlyupgrade) {
        					continue;
        				}
        				$count++;
        				if ($count%10==0 && $count!=count($val))
        					output_notl("`6$count...");
        				if (!db_query($sql)) {
        					output("`n`$Error: `^'%s'`7 executing `#'%s'`7.`n",
        							db_error(), $sql);
        				}
        			}
        			output("$count.`n");
        		}
        	}
        	if ($key == $session['fromversion'] ||
        			$session['dbinfo']['upgrade'] == false) $dosql=true;
        }
        rawoutput("</div>");
        /*
           output("`n`2Now I'll install the recommended modules.");
           output("Please note that these modules will be installed, but not activated.");
           output("Once installation is complete, you should use the Module Manager found in the superuser grotto to activate those modules you wish to use.");
           reset($recommended_modules);
           rawoutput("<div style='width: 100%; height: 150px; max-height: 150px; overflow: auto;'>");
           while (list($key,$modulename)=each($recommended_modules)){
           output("`3Installing `#$modulename`$`n");
           install_module($modulename, false);
           }
           rawoutput("</div>");
         */
        if (!$session['skipmodules']) {
        	output("`n`2Now I'll install and configure your modules.");
        	reset($session['moduleoperations']);
        	rawoutput("<div style='width: 100%; height: 150px; max-height: 150px; overflow: auto;'>");
        	foreach($session['moduleoperations'] as $modulename=>$val){
        		$ops = explode(",",$val);
        		reset($ops);
        		foreach($ops as $op){
        			switch($op){
        				case "uninstall":
        					output("`3Uninstalling `#$modulename`3: ");
        					if (uninstall_module($modulename)){
        						output("`@OK!`0`n");
        					}else{
        						output("`$Failed!`0`n");
        					}
        					break;
        				case "install":
        					output("`3Installing `#$modulename`3: ");
        					if (install_module($modulename)){
        						output("`@OK!`0`n");
        					}else{
        						output("`$Failed!`0`n");
        					}
        					install_module($modulename);
        					break;
        				case "activate":
        					output("`3Activating `#$modulename`3: ");
        					if (activate_module($modulename)){
        						output("`@OK!`0`n");
        					}else{
        						output("`$Failed!`0`n");
        					}
        					break;
        				case "deactivate":
        					output("`3Deactivating `#$modulename`3: ");
        					if (deactivate_module($modulename)){
        						output("`@OK!`0`n");
        					}else{
        						output("`$Failed!`0`n");
        					}
        					break;
        				case "donothing":
        					break;
        			}
        		}
        		$session['moduleoperations'][$modulename] = "donothing";
        	}
        	rawoutput("</div>");
        }
        output("`n`2Finally, I'll clean up old data.`n");
        rawoutput("<div style='width: 100%; height: 150px; max-height: 150px; overflow: auto;'>");
        foreach($descriptors as $tablename=>$descriptor){
        	output("`3Cleaning up `#$tablename`3...`n");
        	synctable($tablename,$descriptor);
        }
        rawoutput("</div>");
        output("`n`n`^You're ready for the next step.");
    }

    /**
     * Fallback stage handler when an unknown stage is requested.
     */
    public function stageDefault(): void
    {
        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;
        output("`$Requested installer step not found.`n");
        output("`2Restarting at stage 1...`n");
        redirect("install/index.php?stage=1");
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
        output("`n`2Attempting to create your database...`n");
        $sql = "CREATE DATABASE `$dbname`";
        if ($connection->query($sql) === TRUE) {
            $selected = $this->selectDatabase($connection, $dbname);
            if ($selected) {
                output("`@Success!`2  I was able to create the database and connect to it!`n");
            } else {
                output("`$It seems I was not successful.`2  I didn't get any errors trying to create the database, but I was not able to connect to it.");
                output("I'm not sure what would have caused this error, you might try asking around in <a href='http://lotgd.net/forum/' target='_blank'>the LotGD.net forums</a>.");
            }
        } else {
            if ($connection instanceof \mysqli && property_exists($connection, 'error')) {
                $error = $connection->error;
            } else {
                $error = $connection->error();
            }
            output("`$It seems I was not successful.`2 ");
            output("The error returned by the database server was:");
            rawoutput("<blockquote>$error</blockquote>");
        }
    }

    /**
     * Render a mouse over tip containing the supplied messages.
     *
     * Accepts the same parameters as output().
     */
    private function tip(...$args): void
    {
        $tip = translate_inline("Tip");
        output_notl("<div style='cursor: pointer; cursor: hand; display: inline;' onMouseOver=\"tip{$this->tipid}.style.visibility='visible'; tip{$this->tipid}.style.display='inline';\" onMouseOut=\"tip{$this->tipid}.style.visibility='hidden'; tip{$this->tipid}.style.display='none';\">`i[ `b{$tip}`b ]`i", true);
        rawoutput("<div class='debug' id='tip{$this->tipid}' style='position: absolute; width: 200px; max-width: 200px; float: right;'>");
        call_user_func_array('output', $args);
        rawoutput("</div></div>");
        rawoutput("<script language='JavaScript'>var tip{$this->tipid} = document.getElementById('tip{$this->tipid}'); tip{$this->tipid}.style.visibility='hidden'; tip{$this->tipid}.style.display='none';</script>");
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
            case 'm':
                $numericPart *= 1024;
            case 'k':
                $numericPart *= 1024;
        }
        return $numericPart;

    }

    // endregion
}
