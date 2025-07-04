<?php
namespace Lotgd;

use Lotgd\Buffs;
use Lotgd\CharStats;
use Lotgd\PlayerFunctions;
use Lotgd\HolidayText;
/**
 * Library (supporting) functions for page output
 *		addnews ready
 *		translator ready
 *		mail ready
 *
 * @author core_module
 * @package defaultPackage
 *
 */
class PageParts {
    /**
     * Tracks scripts that should not display popups.
     * @var array<string,bool>
     */
    private static array $noPopups = [];

    /**
     * Keeps track of which headers have already run to avoid duplicates.
     * @var array<string,bool>
     */
    private static array $runHeaders = [];

    /** Holds the character statistics for the current page. */
    private static ?CharStats $charstats = null;

    /** Name of the current stat section when building char stats. */
    private static string $lastCharstatLabel = "";

    /**
     * Starts page output. Initializes the template and translator modules.
     *
     * @param array|string $title
     * Hooks provided:
     *      everyheader
     *      header-{scriptname}
     */
public static function pageHeader(...$args): void {
        global $header,$SCRIPT_NAME,$session,$template;
        self::$noPopups["login.php"] = true;
        self::$noPopups["motd.php"] = true;
        self::$noPopups["index.php"] = true;
        self::$noPopups["create.php"] = true;
        self::$noPopups["about.php"] = true;
        self::$noPopups["mail.php"] = true;

	//in case this didn't already get called (such as on a database error)
	translator_setup();
	prepare_template();
	if (isset($SCRIPT_NAME)) {
		$script = substr($SCRIPT_NAME,0,strrpos($SCRIPT_NAME,"."));
                if ($script) {
                        if (!array_key_exists($script,self::$runHeaders))
                                self::$runHeaders[$script] = false;
                        if (!self::$runHeaders[$script]) {
                                if (!defined("IS_INSTALLER")) modulehook("everyheader", array('script'=>$script));
                                self::$runHeaders[$script] = true;
                                if (!defined("IS_INSTALLER")) modulehook("header-$script");
                        }
                }
	}

	$arguments = func_get_args();
	if (!$arguments || count($arguments) == 0) {
		$arguments = array("Legend of the Green Dragon");
	}
	$title = call_user_func_array("sprintf_translate", $arguments);
	$title = sanitize(HolidayText::holidayize($title,'title'));
	Buffs::calculateBuffFields();

	$header = $template['header'];
	$header=str_replace("{title}",$title,$header);
	$header.=tlbutton_pop();
	if (getsetting('debug',0)) {
		$session['debugstart']=microtime();
	}
}

/**
 * Returns an output formatted popup link based on JavaScript
 *
 * @param string $page The URL to open
 * @param string $size The size of the popup window (Default: 550x300)
 * @return string
 */
public static function popup(string $page, string $size="550x300"){
	// user prefs
	global $session;
	if ($size==="550x300" && isset($session['loggedin']) && $session['loggedin']) {
		if (!isset($session['user']['prefs'])) {
			$usersize='550x330';
		} else {
			$usersize = &$session['user']['prefs']['popupsize'];
			if ($usersize=='') $usersize='550x330';
		}
		$s=explode("x",$usersize);
		$s[0]=(int)max(50,$s[0]);
		$s[1]=(int)max(50,$s[1]);
	} else 	$s = explode("x",$size);
	//user prefs
	return "window.open('$page','".preg_replace("([^[:alnum:]])","",$page)."','scrollbars=yes,resizable=yes,width={$s[0]},height={$s[1]}').focus()";
}

/**
 * Brings all the output elements together and terminates the rendering of the page.  Saves the current user info and updates the rendering statistics
 * Hooks provided:
 *	footer-{$script name}
 *	everyfooter
 *
 */
public static function pageFooter(bool $saveuser=true){
        global $output,$header,$nav,$session,$REMOTE_ADDR,
               $REQUEST_URI,$pagestarttime,$quickkeys,$template,$y2,$z2,
               $logd_version,$copyright,$SCRIPT_NAME, $footer,
               $dbinfo;
	$z = $y2^$z2;
	$footer = $template['footer'];
	//page footer module hooks
	if (!empty($SCRIPT_NAME)) 
		$script = substr($SCRIPT_NAME,0,strpos($SCRIPT_NAME,"."));
	else
		$script = "";
	$replacementbits = array();
	$replacementbits = modulehook("footer-$script",$replacementbits);
	if ($script == "runmodule" && (($module = httpget('module'))) > "") {
		// This modulehook allows you to hook directly into any module without
		// the need to hook into footer-runmodule and then checking for the
		// required module.
		modulehook("footer-$module",$replacementbits);
	}
	// Pass the script file down into the footer so we can do something if
	// we need to on certain pages (much like we do on the header.
	// Problem is 'script' is a valid replacement token, so.. use an
	// invalid one which we can then blow away.
	$replacementbits['__scriptfile__'] = $script;
	$replacementbits = modulehook("everyfooter",$replacementbits);
	unset($replacementbits['__scriptfile__']);
	//output any template part replacements that above hooks need (eg,
	//advertising)
	foreach ($replacementbits as $key=>$val) {
		$header = str_replace("{".$key."}","{".$key."}".implode("",$val),$header);
		$footer = str_replace("{".$key."}","{".$key."}".implode("",$val),$footer);
	}

	$builtnavs = buildnavs();

	Buffs::restoreBuffFields();
	Buffs::calculateBuffFields();

	tlschema("common");

        $statsOutput = self::charStats();

	Buffs::restoreBuffFields();

	if (!defined("IS_INSTALLER")) { 
		$sql = "SELECT motddate FROM " . db_prefix("motd") . " ORDER BY motditem DESC LIMIT 1";
		$result = db_query($sql);
		$row = db_fetch_assoc($result);
		$headscript = "";
                if (db_num_rows($result)>0 && isset($session['user']['lastmotd']) &&
                                ($row['motddate']>$session['user']['lastmotd']) &&
                                (!isset(self::$noPopups[$SCRIPT_NAME]) || self::$noPopups[$SCRIPT_NAME]!=1) &&
                                $session['user']['loggedin']){
			if (getsetting('forcedmotdpopup',0)) $headscript.=self::popup("motd.php");
			$session['needtoviewmotd']=true;
		}else{
			$session['needtoviewmotd']=false;
		}
		$favicon = array ("favicon-link"=>"<link rel=\"shortcut icon\" HREF=\"favicon.ico\" TYPE=\"image/x-icon\"/>");
		$favicon = modulehook("pageparts-favicon", $favicon);
		$pre_headscript = $favicon['favicon-link'];
		//add AJAX notification stuff
		if (getsetting('ajax',0)==1 && isset($session['user']['prefs']['ajax']) && $session['user']['prefs']['ajax']) {
			if (file_exists('ext/ajax_base_setup.php')) {
				require("ext/ajax_base_setup.php");
			}
		}
		//END AJAX
	} else {
		$pre_headscript = "";
		$headscript = "";
	}
	if ($headscript>""){
		$header=str_replace("{headscript}",$pre_headscript."<script type='text/javascript' charset='UTF-8'>".$headscript."</script>",$header);
	}else{
		$header = str_replace("{headscript}",$pre_headscript,$header);
	}

	$script = "";

	if (!isset($session['user']['name'])) $session['user']['name']="";
	if (!isset($session['user']['login'])) $session['user']['login']="";

	//output keypress script
	$script.="<script type='text/javascript' charset='UTF-8'>
		<!--
		document.onkeypress=keyevent;
	function keyevent(e){
		var c;
		var target;
		var altKey;
		var ctrlKey;
		if (window.event != null) {
			c=String.fromCharCode(window.event.keyCode).toUpperCase();
			altKey=window.event.altKey;
			ctrlKey=window.event.ctrlKey;
		}else{
			c=String.fromCharCode(e.charCode).toUpperCase();
			altKey=e.altKey;
			ctrlKey=e.ctrlKey;
		}
		if (window.event != null)
			target=window.event.srcElement;
		else
			target=e.originalTarget;
		if (target.nodeName.toUpperCase()=='INPUT' || target.nodeName.toUpperCase()=='TEXTAREA' || altKey || ctrlKey){
		}else{";
			reset($quickkeys);
			foreach ($quickkeys as $key=>$val) {
				$script.="\n			if (c == '".strtoupper($key)."') { $val; return false; }";
			}
			$script.="
		}
	}
	//-->
	</script>";

	//handle paypal
	if (strpos($footer,"{paypal}") || strpos($header,"{paypal}")){ $palreplace="{paypal}"; }else{ $palreplace="{stats}"; }

	//NOTICE |
	//NOTICE | Although under the license, you're not required to keep this
	//NOTICE | paypal link, I do request, as the author of this software
	//NOTICE | which I have made freely available to you, that you leave it in.
	//NOTICE |
	$paypalstr = '<table align="center"><tr><td>';
	$currency = getsetting("paypalcurrency", "USD");

	if (!isset($_SESSION['logdnet']) || !isset($_SESSION['logdnet']['']) || $_SESSION['logdnet']['']=="" || date("Y-m-d H:i:s",strtotime("-1 hour"))>$session['user']['laston']){
		$already_registered_logdnet = false;
	}else{
		$already_registered_logdnet = true;
	}

	if (getsetting("logdnet",0) && $session['user']['loggedin'] && !$already_registered_logdnet){
		//account counting, just for my own records, I don't use this in the calculation for server order.
		$sql = "SELECT count(acctid) AS c FROM " . db_prefix("accounts");
		$result = db_query_cached($sql,"acctcount",600);
		$row = db_fetch_assoc($result);
		$c = $row['c'];
		$a = getsetting("serverurl","http://".$_SERVER['SERVER_NAME'].($_SERVER['SERVER_PORT'] == 80?"":":".$_SERVER['SERVER_PORT']).dirname($_SERVER['REQUEST_URI']));
		if (!preg_match("/\/$/", $a)) {
			$a = $a . "/";
			savesetting("serverurl", $a);
		}

		$l = getsetting("defaultlanguage","en");
		$d = getsetting("serverdesc","Another LoGD Server");
		$e = getsetting("gameadminemail", "postmaster@localhost.com");
		$u = getsetting("logdnetserver","http://logdnet.logd.com/");
		if (!preg_match("/\/$/", $u)) {
			$u = $u . "/";
			savesetting("logdnetserver", $u);
		}


		global $logd_version;
		$v = $logd_version;
		$c = rawurlencode($c);
		$a = rawurlencode($a);
		$l = rawurlencode($l);
		$d = rawurlencode($d);
		$e = rawurlencode($e);
		$v = rawurlencode($v);
		$u = rawurlencode($u);
		$paypalstr .= "<script type='text/javascript' charset='UTF-8' src='images/logdnet.php?op=register&c=$c&l=$l&v=$v&a=$a&d=$d&e=$e&u=$u'></script>";
	}else{
		$paypalstr .= "<form action=\"https://www.paypal.com/cgi-bin/webscr\" method=\"post\" target=\"_blank\" onsubmit=\"return confirm('You are donating to the author of Lotgd. Donation points can not be credited unless you petition. Press Ok to make a donation, or press Cancel.');\">".'
			<input type="hidden" name="cmd" value="_xclick">
			<input type="hidden" name="business" value="logd@mightye.org">
			<input type="hidden" name="item_name" value="Legend of the Green Dragon Author Donation from '.full_sanitize($session['user']['name']).'">
			<input type="hidden" name="item_number" value="'.htmlentities($session['user']['login'], ENT_COMPAT, getsetting("charset", "ISO-8859-1")).":".$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'].'">
			<input type="hidden" name="no_shipping" value="1">
			<input type="hidden" name="notify_url" value="http://lotgd.net/payment.php">
			<input type="hidden" name="cn" value="Your Character Name">
			<input type="hidden" name="cs" value="1">
			<input type="hidden" name="currency_code" value="USD">
			<input type="hidden" name="tax" value="0">
			<input type="image" src="images/paypal1.gif" border="0" name="submit" alt="Donate to Eric Stevens">
			</form>';
	}
	$paysite = getsetting("paypalemail", "");
	if ($paysite != "") {
		$paypalstr .= '</td><td>';
		$paypalstr .= '<form action="https://www.paypal.com/cgi-bin/webscr" method="post" target="_blank">
			<input type="hidden" name="cmd" value="_xclick">
			<input type="hidden" name="business" value="'.$paysite.'">
			<input type="hidden" name="item_name" value="'.getsetting("paypaltext","Legend of the Green Dragon Site Donation from").' '.full_sanitize($session['user']['name']).'">
			<input type="hidden" name="item_number" value="'.htmlentities($session['user']['login'], ENT_COMPAT, getsetting("charset", "ISO-8859-1")).":".$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'].'">
			<input type="hidden" name="no_shipping" value="1">';
		if (file_exists("payment.php")) {
			$paypalstr .= '<input type="hidden" name="notify_url" value="http://'.$_SERVER["HTTP_HOST"].dirname($_SERVER['REQUEST_URI']).'/payment.php">';
		}
		$paypalstr .= '<input type="hidden" name="cn" value="Your Character Name">
			<input type="hidden" name="cs" value="1">
			<input type="hidden" name="currency_code" value="'.$currency.'">
			<input type="hidden" name="lc" value="'.getsetting("paypalcountry-code","US").'">
			<input type="hidden" name="bn" value="PP-DonationsBF">
			<input type="hidden" name="tax" value="0">
			<input type="image" src="images/paypal2.gif" border="0" name="submit" alt="Donate to the Site">
			</form>';
	}
	$paypalstr .= '</td></tr></table>';
	$footer=str_replace($palreplace,(strpos($palreplace,"paypal")?"":"{stats}").$paypalstr,$footer);
	$header=str_replace($palreplace,(strpos($palreplace,"paypal")?"":"{stats}").$paypalstr,$header);
	//NOTICE |
	//NOTICE | Although I will not deny you the ability to remove the above
	//NOTICE | paypal link, I do request, as the author of this software
	//NOTICE | which I made available for free to you that you leave it in.
	//NOTICE |

	//output the nav
	$z = $y2^$z2;
	if (isset($$z)) {
		$footer = str_replace("{".($z)."}",$$z, $footer);
	} else {
		$footer = str_replace("{".($z)."}","",$footer);
	}
	$footer = str_replace("{".($z)."}",$z,$footer);
	$header=str_replace("{nav}",$builtnavs,$header);
	$footer=str_replace("{nav}",$builtnavs,$footer);
	//output the motd
	//use a modulehook to add more stuff by module or change the link
	$motd_link = self::motdLink();
	$motd_link = modulehook("motd-link",array("link"=>$motd_link));
	$motd_link = $motd_link['link'];
	//$header = str_replace("{motd}", self::motdLink(), $header);
	//$footer = str_replace("{motd}", self::motdLink(), $footer);
	$header = str_replace("{motd}", $motd_link, $header);
	$footer = str_replace("{motd}", $motd_link, $footer);
	//output the mail link
	if (isset($session['user']['acctid']) && $session['user']['acctid']>0 && $session['user']['loggedin']) {
		if (getsetting('ajax',0)==1 && isset($session['user']['prefs']['ajax']) && $session['user']['prefs']['ajax']) {
			if (file_exists('ext/ajax_maillink.php')) {
				require('ext/ajax_maillink.php');
			}
			$header=str_replace("{mail}",$maillink_add_pre."<div id='maillink'>".self::mailLink()."</div>".$maillink_add_after,$header);
		} else {
			//no AJAX for slower browsers etc
			$header=str_replace("{mail}",self::mailLink(),$header);
		}
		$footer=str_replace("{mail}",self::mailLink(),$footer);
	}else{
		$header=str_replace("{mail}","",$header);
		$footer=str_replace("{mail}","",$footer);
	}
	//output petition count

	$header=str_replace("{petition}","<a href='petition.php' onClick=\"".self::popup("petition.php").";return false;\" target='_blank' align='right' class='motd'>".translate_inline("Petition for Help")."</a>",$header);
	$footer=str_replace("{petition}","<a href='petition.php' onClick=\"".self::popup("petition.php").";return false;\" target='_blank' align='right' class='motd'>".translate_inline("Petition for Help")."</a>",$footer);
	if (isset($session['user']['superuser']) && $session['user']['superuser'] & SU_EDIT_PETITIONS){
		$sql = "SELECT count(petitionid) AS c,status FROM " . db_prefix("petitions") . " GROUP BY status";
		$result = db_query_cached($sql,"petition_counts");
		$petitions=array("P5"=>0,"P4"=>0,"P0"=>0,"P1"=>0,"P3"=>0,"P7"=>0,"P6"=>0,"P2"=>0);
		while ($row = db_fetch_assoc($result)) {
			$petitions["P".$row['status']] = $row['c'];
		}
		// add short links for the admin, depending on superuser rights (technically we could move this out of petitions and make it general, but alas...)
		$pet = translate_inline("`0`bPetitions:`b");
		$ued = translate_inline("`0`bUser Editor`b");
		$mod = translate_inline("`0`bManage Modules`b");
		db_free_result($result);
		$admin_array=array();
		if ($session['user']['superuser'] & SU_EDIT_USERS){
			$admin_array[] = "<a href='user.php'>$ued</a>";
			addnav("", "user.php");
		}
		if ($session['user']['superuser'] & SU_MANAGE_MODULES) {
			$admin_array[] = "<a href='modules.php'>$mod</a>";
			addnav("", "modules.php");
		}
		$admin_array[] = "<a href='viewpetition.php'>$pet</a>";
		addnav("", "viewpetition.php");
		$p = implode("|",$admin_array);
		$pcolors = array("`\$","`^","`6","`!","`#","`%","`v");
		$pets = "`n";
		foreach($petitions as $val) {
			if ($pets!="`n") $pets.="|";
			$color = array_shift($pcolors);
			if (!isset($color) || $color==null || $color=="") $color="`1";
			$pets.=$color.$val."`0";
		}
		$ret_args=array("petitioncount"=>$pets);
		$ret_args = modulehook("petitioncount",array("petitioncount"=>$pets));
		$pets = $ret_args['petitioncount'];
		$p .= $pets;
		$pcount = templatereplace("petitioncount", array("petitioncount"=>appoencode($p, true)));
		$footer = str_replace("{petitiondisplay}", $pcount, $footer);
		$header = str_replace("{petitiondisplay}", $pcount, $header);
	} else {
		$footer = str_replace("{petitiondisplay}", "", $footer);
		$header = str_replace("{petitiondisplay}", "", $header);
	}
	//output character stats
        $footer=str_replace("{stats}",$statsOutput,$footer);
        $header=str_replace("{stats}",$statsOutput,$header);
	//do something -- I don't know what
	$header=str_replace("{script}",$script,$header);
	//output view PHP source link
	$sourcelink = "source.php?url=".preg_replace("/[?].*/","",($_SERVER['REQUEST_URI']));
	$footer=str_replace("{source}","<a href='$sourcelink' onclick=\"".self::popup($sourcelink).";return false;\" target='_blank'>".translate_inline("View PHP Source")."</a>",$footer);
	$header=str_replace("{source}","<a href='$sourcelink' onclick=\"".self::popup($sourcelink).";return false;\" target='_blank'>".translate_inline("View PHP Source")."</a>",$header);
	//output version
	$footer=str_replace("{version}", "Version: $logd_version", $footer);
	//output page generation time
	$gentime = getmicrotime()-$pagestarttime;
	if (!isset($session['user']['gentime'])) $session['user']['gentime']=0;
	$session['user']['gentime']+=$gentime;
	if (!isset($session['user']['gentimecount'])) $session['user']['gentimecount']=0;
	$session['user']['gentimecount']++;
	if (getsetting('debug',0)) {	
		global $SCRIPT_NAME;
		$sql="INSERT INTO ".db_prefix('debug')." VALUES (0,'pagegentime','runtime','".$SCRIPT_NAME."','".($gentime)."');";
		$resultdebug=db_query($sql);
		$sql="INSERT INTO ".db_prefix('debug')." VALUES (0,'pagegentime','dbtime','".$SCRIPT_NAME."','".(round($dbinfo['querytime'],3))."');";
		$resultdebug=db_query($sql);
	}
    $queriesthishit = isset($dbinfo['queriesthishit']) ? $dbinfo['queriesthishit'] : 0;
    $querytime = isset($dbinfo['querytime']) ? $dbinfo['querytime'] : 0;
    $footer=str_replace("{pagegen}","Page gen: ".round($gentime,3)."s / ".$queriesthishit." queries (".round($querytime,3)."s), Ave: ".round($session['user']['gentime']/$session['user']['gentimecount'],3)."s - ".round($session['user']['gentime'],3)."/".round($session['user']['gentimecount'],3)."",$footer);

	tlschema();

	//clean up spare {fields}s from header and footer (in case they're not used)
	//note: if you put javascript code in, this has been killing {} javascript assignments...kudos... took me an hour to find why the injected code didn't work...
	$footer = preg_replace("/{[^} \t\n\r]*}/i","",$footer);
	$header = str_replace("{bodyad}","",$header);
	$header = str_replace("{verticalad}","",$header);
	$header = str_replace("{navad}","",$header);
	$header = str_replace("{headerad}","",$header);
	//	$header = preg_replace("/{[^} \t\n\r]*}/i","",$header);

	//finalize output
	$browser_output=$header.($output->get_output()).$footer;
	if (!isset($session['user']['gensize'])) $session['user']['gensize']=0;
	$session['user']['gensize']+=strlen($browser_output);
	$session['output']=$browser_output;
	if ($saveuser === true) {
		saveuser();
	}
	unset($session['output']);
	//this somehow allows some frames to load before the user's navs say it can
	session_write_close();
	echo $browser_output;
	exit();
}

/**
 * Page header for popup windows.
 *
 * @param string $title The title of the popup window
 */
public static function popupHeader(...$args): void {
	global $header, $template;

	translator_setup();
	prepare_template();

	modulehook("header-popup");

	$arguments = func_get_args();
	if (!$arguments || count($arguments) == 0) {
		$arguments = array("Legend of the Green Dragon");
	}
	$title = call_user_func_array("sprintf_translate", $arguments);
	$title = HolidayText::holidayize($title,'title');

	$header = $template['popuphead'];
	$header = str_replace("{title}", $title, $header);
}

/**
 * Ends page generation for popup windows.  Saves the user account info - doesn't update page generation stats
 *
 */
public static function popupFooter(){
	global $output,$header,$session,$y2,$z2,$copyright, $template;

	$headscript='';
	$footer = $template['popupfoot'];
	$pre_headscript='';
	$maillink_add_after='';
	//add AJAX stuff
	if (getsetting('ajax',0)==1 && isset($session['user']['prefs']['ajax']) && $session['user']['prefs']['ajax']) {
		if (file_exists('ext/ajax_base_setup.php')) {
			require("ext/ajax_base_setup.php");
		}
	}
	//END AJAX

	// Pass the script file down into the footer so we can do something if
	// we need to on certain pages (much like we do on the header.
	// Problem is 'script' is a valid replacement token, so.. use an
	// invalid one which we can then blow away.
	$replacementbits = modulehook("footer-popup",array());
	//output any template part replacements that above hooks need
	reset($replacementbits);
	foreach ($replacementbits as $key=>$val) {
		$header = str_replace("{".$key."}","{".$key."}".implode("",$val),$header);
		$footer = str_replace("{".$key."}","{".$key."}".implode("",$val),$footer);
	}

	$z = $y2^$z2;
	$footer = str_replace("{".($z)."}",$$z, $footer);
	if (isset($session['user']['acctid']) && $session['user']['acctid']>0 && $session['user']['loggedin']) {
		if (getsetting('ajax',0)==1 && isset($session['user']['prefs']['ajax']) && $session['user']['prefs']['ajax']) {
			if (file_exists('ext/ajax_maillink.php')) {
				require("ext/ajax_maillink.php");
			}
		} else {
			$maillink_add_after='';
			//no AJAX for slower browsers etc
		}
	}
	$header = str_replace("{headscript}",$pre_headscript.$headscript,$header);

	//clean up spare {fields}s from header and footer (in case they're not used)
	//note: if you put javascript code in, this has been killing {} javascript assignments...kudos... took me an hour to find why the injected code didn't work...
	$footer = preg_replace("/{[^} \t\n\r]*}/i","",$footer);
	$header = str_replace("{bodyad}","",$header);
	$header = str_replace("{verticalad}","",$header);
	$header = str_replace("{navad}","",$header);
	$header = str_replace("{headerad}","",$header);
	$header = str_replace("{script}","",$header);
	//	$header = preg_replace("/{[^} \t\n\r]*}/i","",$header);

	$browser_output=$header.$maillink_add_after.($output->get_output()).$footer;
	saveuser();
	session_write_close();
	echo $browser_output;
	exit();
}

/**
 * Resets the character stats array
 *
 */
public static function wipeCharStats(): void {
        self::$charstats = new CharStats();
        self::$lastCharstatLabel = "";
}

/**
 * Add a attribute and/or value to the character stats display
 *
 * @param string $label The label to use
 * @param mixed $value (optional) value to display
 */
public static function addCharStat(string $label, mixed $value = null): void {
        if ($value === null) {
                self::$lastCharstatLabel = $label;
        } else {
                if (self::$lastCharstatLabel === '') {
                        self::$lastCharstatLabel = 'Other Info';
                }
                self::$charstats?->addStat(self::$lastCharstatLabel, $label, $value);
        }
}

/**
 * Returns the character stat related to the category ($cat) and the label
 *
 * @param string $cat The relavent category for the stat
 * @param string $label The label of the character stat
 * @return mixed The value associated with the stat
 */
public static function getCharStat(string $cat, string $label) {
        return self::$charstats?->getStat($cat, $label);
}

/**
 * Sets a value to the passed category & label for character stats
 *
 * @param string $cat The category for the char stat
 * @param string $label The label associated with the value
 * @param mixed $val The value of the attribute
 */
public static function setCharStat(string $cat, string $label, mixed $val): void {
        self::$charstats?->setStat($cat, $label, $val);
}

/**
 * Returns output formatted character stats
 *
 * @param array $buffs
 * @return string
 */
public static function getCharStats(string $buffs): string{
        return self::$charstats?->render($buffs) ?? '';
}

/**
 * Returns the value associated with the section & label.  Returns an empty string if the stat isn't set
 *
 * @param string $section The character stat section
 * @param string $title The stat display label
 * @return mixed The value associated with the stat
 */
public static function getCharStatValue(string $section,string $title){
        return self::$charstats?->getStat($section, $title) ?? "";
}

/**
 * Returns the current character stats or (if the character isn't logged in) the currently online players
 * Hooks provided:
 *		charstats
 *
 * @return string The current stats for this character or the list of online players
 */
public static function charStats(): string{
	global $session, $playermount, $companions;

	if (defined("IS_INSTALLER")) return "";

	self::wipeCharStats();

	$u =& $session['user'];

	if (isset($session['loggedin']) && $session['loggedin']){
		$u['hitpoints']=round($u['hitpoints'],0);
		$u['experience']=round($u['experience'],0);
		$u['maxhitpoints']=round($u['maxhitpoints'],0);
		$spirits=array(-6=>"Resurrected",-2=>"Very Low",-1=>"Low","0"=>"Normal",1=>"High",2=>"Very High");
		if ($u['alive']){ }else{ $spirits[(int)$u['spirits']] = translate_inline("DEAD","stats"); }
		//calculate_buff_fields();
		reset($session['bufflist']);
		/*not so easy anymore
		  $atk=$u['attack'];
		  $def=$u['defense'];
		 */
                $o_atk=$atk=PlayerFunctions::getPlayerAttack();
                $o_def=$def=PlayerFunctions::getPlayerDefense();
                $spd=PlayerFunctions::getPlayerSpeed();

		$buffcount = 0;
		$buffs = "";
		foreach ($session['bufflist'] as $val) {
			if (isset($val['suspended']) && $val['suspended']) continue;
			if (isset($val['atkmod'])) {
				$atk *= $val['atkmod'];
			}
			if (isset($val['defmod'])) {
				$def *= $val['defmod'];
			}
			// Short circuit if the name is blank
			if ((isset($val['name']) && $val['name'] > "") || $session['user']['superuser'] & SU_DEBUG_OUTPUT){
				tlschema($val['schema']);
				//	if ($val['name']=="")
				//		$val['name'] = "DEBUG: {$key}";
				//	removed due to performance reasons. foreach is better with only $val than to have $key ONLY for the short happiness of one debug. much greater performance gain here
				if (is_array($val['name'])) {
					$val['name'][0] = str_replace("`%","`%%",$val['name'][0]);
					$val['name']=call_user_func_array("sprintf_translate", $val['name']);
				} else { //in case it's a string
					$val['name']=translate_inline($val['name']);
				}
				if ($val['rounds']>=0){
					// We're about to sprintf, so, let's makes sure that
					// `% is handled.
					//$n = translate_inline(str_replace("`%","`%%",$val['name']));
					$b = translate_inline("`#%s `7(%s rounds left)`n","buffs");
					$b = sprintf($b, $val['name'], $val['rounds']);
					$buffs.=appoencode($b, true);
				}else{
					$buffs.= appoencode("`#{$val['name']}`n",true);
				}
				tlschema();
				$buffcount++;
			}
		}
		if ($buffcount==0){
			$buffs.=appoencode(translate_inline("`^None`0"),true);
		}

		$atk = round($atk, 2);
		$def = round($def, 2);
		if ($atk < $o_atk){
			$atk = round($o_atk,1)."`\$".round($atk-$o_atk,1);
		}else if($atk > $o_atk){
			$atk = round($o_atk,1)."`@+".round($atk-$o_atk,1);
		} else {
			// They are equal, display in the 1 signifigant digit format.
			$atk = round($atk,1);
		}
		if ($def < $o_def){
			$def = round($o_def,1)."`\$".round($def-$o_def,1);
		}else if($def > $o_def){
			$def = round($o_def,1)."`@+".round($def-$o_def,1);
		} else {
			// They are equal, display in the 1 signifigant digit format.
			$def = round($def, 1);
		}
		$point=getsetting('moneydecimalpoint',".");
		$sep=getsetting('moneythousandssep',",");

		self::addCharStat("Character Info");
		self::addCharStat("Name", $u['name']);
		self::addCharStat("Level", "`b".$u['level'].check_temp_stat("level",1)."`b");
		if ($u['alive']) {
			self::addCharStat("Hitpoints", $u['hitpoints'].check_temp_stat("hitpoints",1)."`0/".$u['maxhitpoints'].check_temp_stat("maxhitpoints",1));
			self::addCharStat("Experience",  number_format($u['experience'].check_temp_stat("experience",1),0,$point,$sep));
			self::addCharStat("Strength", $u['strength'].check_temp_stat("strength",1));
			self::addCharStat("Dexterity", $u['dexterity'].check_temp_stat("dexterity",1));
			self::addCharStat("Intelligence", $u['intelligence'].check_temp_stat("intelligence",1));
			self::addCharStat("Constitution", $u['constitution'].check_temp_stat("constitution",1));
			self::addCharStat("Wisdom", $u['wisdom'].check_temp_stat("wisdom",1));
                        self::addCharStat("Attack", $atk."`\$<span title='".PlayerFunctions::explainedGetPlayerAttack()."'>(?)</span>`0".check_temp_stat("attack",1));
                        self::addCharStat("Defense", $def."`\$<span title='".PlayerFunctions::explainedGetPlayerDefense()."'>(?)</span>`0".check_temp_stat("defense",1));
			self::addCharStat("Speed", $spd.check_temp_stat("speed",1));
		} else {
			$maxsoul = 50 + 10 * $u['level']+$u['dragonkills']*2;
			self::addCharStat("Soulpoints", $u['soulpoints'].check_temp_stat("soulpoints",1)."`0/".$maxsoul);
			self::addCharStat("Torments", $u['gravefights'].check_temp_stat("gravefights",1));
			self::addCharStat("Psyche", 10+round(($u['level']-1)*1.5));
			self::addCharStat("Spirit", 10+round(($u['level']-1)*1.5));
		}
		if ($u['race'] != RACE_UNKNOWN) {
			self::addCharStat("Race", translate_inline($u['race'],"race"));
		}else {
			self::addCharStat("Race", translate_inline(RACE_UNKNOWN,"race"));
		}
		if (is_array($companions) && count($companions)>0) {
			self::addCharStat("Companions");
			foreach ($companions as $name=>$companion) {
				if ((isset($companion['hitpoints']) && $companion['hitpoints'] > 0) ||(isset($companion['cannotdie']) && $companion['cannotdie'] == true)) {
					if ($companion['hitpoints']<0) {
						$companion['hitpoints'] = 0;
					}
					if($companion['hitpoints']<$companion['maxhitpoints']) {
						$color = "`\$";
					}else{
						$color = "`@";
					}
					if (isset($companion['suspended']) && $companion['suspended'] == true) {
						$suspcode = "`7 *";
					} else {
						$suspcode = "";
					}
					self::addCharStat($companion['name'], $color.($companion['hitpoints'])."`7/`&".($companion['maxhitpoints'])."$suspcode`0");
				}
			}
		}
		self::addCharStat("Personal Info");
		if ($u['alive']) {
			self::addCharStat("Turns", $u['turns'].check_temp_stat("turns",1));
			self::addCharStat("PvP", $u['playerfights']);
			self::addCharStat("Spirits", translate_inline("`b".$spirits[(int)$u['spirits']]."`b"));
			self::addCharStat("Currency");
			self::addCharStat("Gold", number_format($u['gold'].check_temp_stat("gold",1),0,$point,$sep));
			self::addCharStat("Bankgold", number_format($u['goldinbank'].check_temp_stat("goldinbank",1),0,$point,$sep));
		} else {
			self::addCharStat("Favor", $u['deathpower'].check_temp_stat("deathpower",1));
			self::addCharStat("Currency");
		}
		self::addCharStat("Gems", number_format($u['gems'].check_temp_stat("gems",1),0,$point,$sep));
		self::addCharStat("Equipment Info");
		self::addCharStat("Weapon", $u['weapon']);
		self::addCharStat("Armor", $u['armor']);
		if ($u['hashorse'] && isset($playermount['mountname']))
			self::addCharStat("Creature", $playermount['mountname'] . "`0");

		modulehook("charstats");

		$charstat = self::getCharStats($buffs);

		if (!is_array($session['bufflist'])) $session['bufflist']=array();
		return $charstat;
	}else{
		$ret = "";
		if ($ret = datacache("charlisthomepage")){

		}else{
			$onlinecount=0;
            $list = modulehook("onlinecharlist", array("count"=>0, "list"=>""));
            if (isset($list['handled']) && $list['handled']) {
                $onlinecount = $list['count'];
                $ret = $list['list'];
            } else {
                $sql="SELECT name,alive,location,sex,level,laston,loggedin,lastip,uniqueid FROM " . db_prefix("accounts") . " WHERE locked=0 AND loggedin=1 AND laston>'".date("Y-m-d H:i:s",strtotime("-".getsetting("LOGINTIMEOUT",900)." seconds"))."' ORDER BY level DESC";
                $result = db_query($sql);
                $rows = array();
                while ($row = db_fetch_assoc($result)) {
                    $rows[] = $row;
                }
                db_free_result($result);
                $rows = modulehook("loggedin", $rows);
                $ret .= appoencode(sprintf(translate_inline("`bOnline Characters (%s players):`b`n"), count($rows)));
                foreach ($rows as $row) {
                    $ret .= appoencode("`^{$row['name']}`n");
                    $onlinecount++;
                }
                if ($onlinecount == 0) {
                    $ret .= appoencode(translate_inline("`iNone`i"));
               	}
            	}
			savesetting("OnlineCount",$onlinecount);
			savesetting("OnlineCountLast",strtotime("now"));
			updatedatacache("charlisthomepage",$ret);
		}
		return $ret;
	}
}
/**
 * Loads the template into the current session.  If the template doesn't
 * exist - uses the default (admin-defined) template, and then falls back
 * to jade.htm
 *
 * @param string $templatename The template name (minus the path)
 * @return array The template split into the sections defined by <!--!
 * @see Templates
 * @todo Template Help
 */
public static function loadTemplate($templatename){
	if ($templatename=="" || !file_exists("templates/$templatename"))
		$templatename=getsetting("defaultskin",$_defaultskin);
	if ($templatename=="" || !file_exists("templates/$templatename"))
		$templatename=$_defaultskin;
	$fulltemplate = file_get_contents("templates/$templatename");
	$fulltemplate = explode("<!--!",$fulltemplate);
	foreach ($fulltemplate as $val) {
		$fieldname=substr($val,0,strpos($val,"-->"));
		if ($fieldname!=""){
			$template[$fieldname]=substr($val,strpos($val,"-->")+3);
			if (!defined("IS_INSTALLER")) modulehook("template-{$fieldname}",
					array("content"=>$template[$fieldname]));
		}
	}
	return $template;
}

/**
 * Returns a display formatted (and popup enabled) mail link - determines if unread mail exists and highlights the link if needed
 *
 * @return string The formatted mail link
 */
public static function mailLink(){
	global $session;
	$sql = "SELECT sum(if(seen=1,1,0)) AS seencount, sum(if(seen=0,1,0)) AS notseen FROM " . db_prefix("mail") . " WHERE msgto=\"".$session['user']['acctid']."\"";
	$result = db_query_cached($sql,"mail-{$session['user']['acctid']}",86400);
	$row = db_fetch_assoc($result);
	db_free_result($result);
	$row['seencount']=(int)$row['seencount'];
	$row['notseen']=(int)$row['notseen'];
	if ($row['notseen']>0){
		return sprintf("<a href='mail.php' target='_blank' onClick=\"".self::popup("mail.php").";return false;\" class='hotmotd'>".translate_inline("Ye Olde Mail: %s new, %s old","common")."</a>",$row['notseen'],$row['seencount']);
	}else{
		return sprintf("<a href='mail.php' target='_blank' onClick=\"".self::popup("mail.php").";return false;\" class='motd'>".translate_inline("Ye Olde Mail: %s new, %s old","common")."</a>",$row['notseen'],$row['seencount']);
	}
}
/* same, but only the text for the tab */
public static function mailLinkTabText(){
	global $session;
	$sql = "SELECT sum(if(seen=1,1,0)) AS seencount, sum(if(seen=0,1,0)) AS notseen FROM " . db_prefix("mail") . " WHERE msgto=\"".$session['user']['acctid']."\"";
	$result = db_query_cached($sql,"mail-{$session['user']['acctid']}",86400);
	$row = db_fetch_assoc($result);
	db_free_result($result);
	$row['seencount']=(int)$row['seencount'];
	$row['notseen']=(int)$row['notseen'];
	if ($row['notseen']>0){
		return sprintf(translate_inline("%s new mail(s)","common"),$row['notseen']);
	}else{
		return '';
	}
}


/**
 * Returns a display formatted (and popup enabled) MOTD link - determines if unread MOTD items exist and highlights the link if needed
 *
 * @return string The formatted MOTD link
 */
public static function motdLink(){
	global $session;
	if ($session['needtoviewmotd']){
		return "<a href='motd.php' target='_blank' onClick=\"".self::popup("motd.php").";return false;\" class='hotmotd'><b>".translate_inline("MoTD")."</b></a>";
	}else{
		return "<a href='motd.php' target='_blank' onClick=\"".self::popup("motd.php").";return false;\" class='motd'><b>".translate_inline("MoTD")."</b></a>";
	}
}
}
?>
