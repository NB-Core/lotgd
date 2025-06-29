<?php
declare(strict_types=1);
// translator ready
// addnews ready
// mail ready

function checkban(?string $login = null)
{
        global $session;
        if (isset($session['banoverride']) && $session['banoverride'])
                return false;
        if ($login === null){
                $ip=$_SERVER['REMOTE_ADDR'];
                if (isset($_COOKIE['lgi']))
                        $id=$_COOKIE['lgi'];
                        else
                        $id='';
        }else{
		$sql = "SELECT lastip,uniqueid,banoverride,superuser FROM " . db_prefix("accounts") . " WHERE login='$login'";
		$result = db_query($sql);
		$row = db_fetch_assoc($result);
		if ($row['banoverride'] || ($row['superuser'] &~ SU_DOESNT_GIVE_GROTTO)){
			$session['banoverride']=true;
			return false;
		}
		db_free_result($result);
		$ip=$row['lastip'];
		$id=$row['uniqueid'];
	}
	//first, remove bans, then select them.
	db_query("DELETE FROM " . db_prefix("bans") . " WHERE banexpire < \"".date("Y-m-d H:m:s")."\" AND banexpire<'".DATETIME_DATEMAX."'");
	$sql = "SELECT * FROM " . db_prefix("bans") . " where ((substring('$ip',1,length(ipfilter))=ipfilter AND ipfilter<>'') OR (uniqueid='$id' AND uniqueid<>'')) AND banexpire>='".date("Y-m-d H:m:s")."'";
	$result = db_query($sql);
	if (db_num_rows($result)>0){
		$session=array();
		tlschema("ban");
		if (!isset($session['message'])) $session['message']='';
		$session['message'].=translate_inline("`n`4You fall under a ban currently in place on this website:`n");
		while ($row = db_fetch_assoc($result)) {
			$session['message'].=$row['banreason']."`n";
			if ($row['banexpire']==DATETIME_DATEMAX)
				$session['message'].=translate_inline("`\$This ban is permanent!`0");
				else {
					$leftover=strtotime($row['banexpire'])-strtotime("now");
					$hours=floor($leftover/3600);
					$tl_hours=($hours!=1?"hours":"hour");
					$mins=round(($leftover-($hours*3600))/60,2);
					$tl_mins=($mins!=1?"minutes":"minute");
					$session['message'].=sprintf_translate("`^This ban will be removed `\$after`^ %s.`n`0",date("M d, Y",strtotime($row['banexpire'])));
					$session['message'].=sprintf_translate("`^(This means in %s %s and %s %s)`0",$hours,$tl_hours,$mins,$tl_mins);
				}
			$sql = "UPDATE " . db_prefix("bans") . " SET lasthit='".date("Y-m-d H:i:s")."' WHERE ipfilter='{$row['ipfilter']}' AND uniqueid='{$row['uniqueid']}'";
			db_query($sql);
			$session['message'].="`n";
			$session['message'].=sprintf_translate("`n`4The ban was issued by %s`^.`n",$row['banner']);
		}
		$session['message'].=translate_inline("`4If you wish, you may appeal your ban with the petition link.");
		tlschema();
		header("Location: index.php");
		exit();
	}
	db_free_result($result);
}

?>
