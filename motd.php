<?php
use Lotgd\Commentary;
use Lotgd\Accounts;
// addnews ready
// translator ready
// mail ready
define("ALLOW_ANONYMOUS",true);
define("OVERRIDE_FORCED_NAV",true);
require_once("common.php");
require_once("lib/nltoappon.php");
require_once("lib/http.php");
use Lotgd\Motd;

tlschema("motd");

$op = httpget('op');
$id = httpget('id');

Commentary::addCommentary();
popup_header("LoGD Message of the Day (MoTD)");

if ($session['user']['superuser'] & SU_POST_MOTD) {
	$addm = translate_inline("Add MoTD");
	$addp = translate_inline("Add Poll");
	rawoutput(" [ <a href='motd.php?op=add'>$addm</a> | <a href='motd.php?op=addpoll'>$addp</a> ]<br/><br/>");
}

if ($op=="vote"){
	$motditem = httppost('motditem');
	$choice = (string)httppost('choice');
	$sql = "DELETE FROM " . db_prefix("pollresults") . " WHERE motditem='$motditem' AND account='{$session['user']['acctid']}'";
	db_query($sql);
	$sql = "INSERT INTO " . db_prefix("pollresults") . " (choice,account,motditem) VALUES ('$choice','{$session['user']['acctid']}','$motditem')";
	db_query($sql);
	invalidatedatacache("poll-$motditem");
	header("Location: motd.php");
	exit();
}
if (($op == "save" || $op == "savenew") && ($session['user']['superuser'] & SU_POST_MOTD)) {
        if ($op == "save") {
                Motd::saveMotd((int)$id);
        } else {
                Motd::savePoll();
        }
        header("Location: motd.php");
        exit();
}
if ($op == "add" || $op == "addpoll" || $op == "del")  {
	if ($session['user']['superuser'] & SU_POST_MOTD) {
            if ($op == "add") Motd::motdForm($id);
            elseif ($op == "addpoll") Motd::motdPollForm($id);
            elseif ($op == "del") Motd::motdDel($id);
	} else {
		if ($session['user']['loggedin']){
			$session['user']['experience'] =
				round($session['user']['experience']*0.9,0);
			AddNews::add("%s was penalized for attempting to defile the gods.",
					$session['user']['name']);
			output("You've attempted to defile the gods.  You are struck with a wand of forgetfulness.  Some of what you knew, you no longer know.");
			Accounts::saveUser();
		}
	}
}
if ($op=="") {
	$count = getsetting("motditems", 5);
	$newcount = (int)httppost("newcount");
	if ($newcount==0 || httppost('proceed')=='') $newcount=0;
        /*
        Motd::motditem("Beta!","Please see the beta message below.","","", "");
        */
	$month_post = httppost("month");
	//SQL Injection attack possible -> kill it off after 7 letters as format is i.e. "2000-05"
	$month_post = substr($month_post,0,7);
	if (preg_match("/[0-9][0-9][0-9][0-9]-[0-9][0-9]/",$month_post)!==1) {
		//hack attack
		$month_post="";
	}
	if ($month_post > ""){
		$date_array = explode("-",$month_post);
		$p_year = $date_array[0];
		$p_month = $date_array[1];
		$month_post_end = date("Y-m-t", strtotime($p_year."-".$p_month."-"."01")); // get last day of month this way, it's a valid DATETIME now
		$sql = "SELECT " . db_prefix("motd") . ".*,name AS motdauthorname FROM " . db_prefix("motd") . " LEFT JOIN " . db_prefix("accounts") . " ON " . db_prefix("accounts") . ".acctid = " . db_prefix("motd") . ".motdauthor WHERE motddate >= '{$month_post}-01' AND motddate <= '{$month_post_end}' ORDER BY motddate DESC";
                $result = db_query_cached($sql, "motd-$month_post");
                $result = db_query($sql);
	}else{
		$sql = "SELECT " . db_prefix("motd") . ".*,name AS motdauthorname FROM " . db_prefix("motd") . " LEFT JOIN " . db_prefix("accounts") . " ON " . db_prefix("accounts") . ".acctid = " . db_prefix("motd") . ".motdauthor ORDER BY motddate DESC limit $newcount,".($newcount+$count);
		if ($newcount=0) //cache only the last x items
			$result = db_query_cached($sql,"motd");
			else
			$result = db_query($sql);
	}
	while ($row = db_fetch_assoc($result)) {
		if (!isset($session['user']['lastmotd']))
			$session['user']['lastmotd']=DATETIME_DATEMIN;
		if ($row['motdauthorname']=="")
			$row['motdauthorname']="`@Green Dragon Staff`0";
		if ($row['motdtype']==0){
                        Motd::motditem($row['motdtitle'], $row['motdbody'],
                                        $row['motdauthorname'], $row['motddate'],
                                        $row['motditem']);
		}else{
                        Motd::pollitem($row['motditem'], $row['motdtitle'], $row['motdbody'],
                                        $row['motdauthorname'],$row['motddate'],
                                        $row['motditem']);
		}
	}
	/*
        Motd::motditem("Beta!","For those who might be unaware, this website is still in beta mode.  I'm working on it when I have time, which generally means a couple of changes a week.  Feel free to drop suggestions, I'm open to anything :-)","","", "");
	*/

	$result = db_query("SELECT mid(motddate,1,7) AS d, count(*) AS c FROM ".db_prefix("motd")." GROUP BY d ORDER BY d DESC");
	$row = db_fetch_assoc($result);
	rawoutput("<form action='motd.php' method='POST'>");
	output("MoTD Archives:");
	rawoutput("<select name='month' onChange='this.form.submit();' >");
	rawoutput("<option value=''>--Current--</option>");
	while ($row = db_fetch_assoc($result)){
		$time = strtotime("{$row['d']}-01");
		$m = translate_inline(date("M",$time));
		rawoutput ("<option value='{$row['d']}'".($month_post==$row['d']?" selected":"").">$m".date(", Y",$time)." ({$row['c']})</option>");
	}
	rawoutput("</select>".tlbutton_clear());
	$showmore=translate_inline("Show more");
	rawoutput("<input type='hidden' name='newcount' value='".($count+$newcount)."'>");
	rawoutput("<input type='submit' value='$showmore' name='proceed'  class='button'>");
	rawoutput(" <input type='submit' value='".translate_inline("Submit")."' class='button'>");
	rawoutput("</form>");

    Commentary::commentDisplay("`n`@Commentary:`0`n", "motd");
}

$session['needtoviewmotd']=false;

$sql = "SELECT motddate FROM " . db_prefix("motd") ." ORDER BY motditem DESC LIMIT 1";
$result = db_query_cached($sql, "motddate");
$row = db_fetch_assoc($result);
$session['user']['lastmotd']=$row['motddate'];

popup_footer();
?>
