<?php
// addnews ready
// translator ready
// mail ready

function db_set_charset($charset) {
	global $mysqli_resource;
	/* change character set to requested */
	if (!mysqli_set_charset($mysqli_resource,$charset)) {
		return false;
	} else {
		return true;
	}
}

function db_query($sql, $die=true){
	if (defined("DB_NODB") && !defined("LINK")) return array();
	global $session,$dbinfo,$mysqli_resource;
	$dbinfo['queriesthishit']++;
	// $fname = DBTYPE."_query";
	$starttime = getmicrotime();
	//$r = $fname($sql);
	$r = mysqli_Query($mysqli_resource, $sql);

	if (!$r && $die === true) {
		if (defined("IS_INSTALLER") && IS_INSTALLER){
			return array();
		}else{
			if (($session['user']['superuser'] & SU_DEVELOPER)){
				require_once("lib/show_backtrace.php");
				die(
						"<pre>".HTMLEntities($sql, ENT_COMPAT, getsetting("charset", "ISO-8859-1"))."</pre>"
						.db_error(LINK)
						.show_backtrace()
				   );
			}else{
				die("A most bogus error has occurred.  I apologise, but the page you were trying to access is broken.  Please use your browser's back button and try again.");
			}
		}
	}
	$endtime = getmicrotime();
	if ($endtime - $starttime >= 1.00 && ($session['user']['superuser'] & SU_DEBUG_OUTPUT)){
		$s = trim($sql);
		if (strlen($s) > 800) $s = substr($s,0,400)." ... ".substr($s,strlen($s)-400);
		debug("Slow Query (".round($endtime-$starttime,2)."s): ".(HTMLEntities($s, ENT_COMPAT, getsetting("charset", "ISO-8859-1")))."`n");
	}
	unset($dbinfo['affected_rows']);
	$dbinfo['affected_rows']=db_affected_rows();
	if (!isset($dbinfo['querytime'])) $dbinfo['querytime']=0;
	$dbinfo['querytime'] += $endtime-$starttime;
	return $r;
}

//& at the start returns a reference to the data array.
//since it's possible this array is large, we'll save ourselves
//the overhead of duplicating the array, then destroying the old
//one by returning a reference instead.
function &db_query_cached($sql,$name,$duration=900){
	//this function takes advantage of the data caching library to make
	//all of the other db_functions act just like MySQL queries but rely
	//instead on disk cached data.
	//if (getsetting("usedatacache", 0) == 1) debug("DataCache: $name");
	//standard is 15 minutes, als hooks don't need to be cached *that* often, normally you invalidate the cache properly
	global $dbinfo;
	$data = datacache($name,$duration);
	if (is_array($data)){
		reset($data);
		$dbinfo['affected_rows']=-1;
		return $data;
	}else{
		$result = db_query($sql);
		$data = array();
		while ($row = db_fetch_assoc($result)) {
			$data[] = $row;
		}
		updatedatacache($name,$data);
		reset($data);
		return $data;
	}
}

if (file_exists("lib/dbremote.php")) {
	require_once("lib/dbremote.php");
}

function db_error(){
	global $mysqli_resource;

	$r = mysqli_error($mysqli_resource);
	if ($r=="" && defined("DB_NODB") && !defined("DB_INSTALLER_STAGE4")) return "The database connection was never established";
	return $r;
}

function db_fetch_assoc(&$result){
	if (is_array($result)){
		//cached data
		if (is_array($result)) {
			$val = current($result);
			next($result);
			return $val;
		}
		else
			return false;
	}else{
		$r = mysqli_fetch_assoc($result);
		return $r;
	}
}

function db_insert_id(){
	global $mysqli_resource;
	if (defined("DB_NODB") && !defined("LINK")) return -1;
	$r = mysqli_insert_id($mysqli_resource);
	return $r;
}

function db_num_rows($result){
	if (is_array($result)){
		return count($result);
	}else{
		if (defined("DB_NODB") && !defined("LINK")) return 0;
		$r = mysqli_num_rows($result);
		return $r;
	}
}

function db_affected_rows($link=false){
	global $dbinfo, $mysqli_resource;
	if (isset($dbinfo['affected_rows'])) {
		return $dbinfo['affected_rows'];
	}
	if (defined("DB_NODB") && !defined("LINK")) return 0;

	$r = mysqli_affected_rows($mysqli_resource);

	return $r;
}

function db_pconnect($host,$user,$pass){
	global $mysqli_resource;
	try {
		$mysqli_resource = mysqli_connect($host, $user, $pass);
	} catch (Exception $e) {
		echo "Critical error while connecting to database: ",$e->getMessage();
		exit(1);
	}

	if($mysqli_resource) {
		return true;
	}
	else {
		return false;
	}
}

function db_connect($host,$user,$pass){
	global $mysqli_resource;
	try {
		$mysqli_resource = mysqli_connect($host, $user, $pass);
	} catch (Exception $e) {
		echo "Critical error while connecting to database: ",$e->getMessage();
		exit(1);
	}

	if($mysqli_resource) {
		return true;
	}
	else {
		return false;
	}
}

function db_get_server_version() {
	global $mysqli_resource;
	return mysqli_get_server_info($mysqli_resource);
}

function db_select_db($dbname){
	global $mysqli_resource;
	$r = mysqli_select_db($mysqli_resource, $dbname);
	return $r;
}

function db_real_escape_string($string){
	global $mysqli_resource;
	$r = mysqli_real_escape_string($mysqli_resource,$string);
	return $r;
}

function db_free_result($result){
	if (is_array($result)){
		//cached data
		unset($result);
	}else{
		if (defined("DB_NODB") && !defined("LINK")) return false;
		mysqli_free_result($result);
		return true;
	}
}

function db_table_exists($tablename){
	global $mysqli_resource;
	if (defined("DB_NODB") && !defined("LINK")) return false;
	$result = $mysqli_resource->Query("SHOW TABLES LIKE '$tablename'");
	if (mysqli_num_rows($result)>0) return true;
	return false;
}

function db_prefix($tablename, $force=false) {
	global $DB_PREFIX;

	if ($force === false) {
		$special_prefixes = array();

		// The following file should be used to override or modify the
		// special_prefixes array to be correct for your site.  Do NOT
		// do this unles you know EXACTLY what this means to you, your
		// game, your county, your state, your nation, your planet and
		// your universe!
		if (file_exists("prefixes.php")) require_once("prefixes.php");

		$prefix = $DB_PREFIX;
		if (isset($special_prefixes[$tablename])) {
			$prefix = $special_prefixes[$tablename];
		}
	} else {
		$prefix = $force;
	}
	return $prefix . $tablename;
}
?>
