<?php
use Lotgd\DataCache;
// addnews ready
// translator ready
// mail ready

class DbMysqli {
    protected $link;

    public function connect($host, $user, $pass) {
        $this->link = mysqli_connect($host, $user, $pass);

        if (!$this->link) {
            $error = mysqli_connect_error();
            if (defined('IS_INSTALLER') && IS_INSTALLER && class_exists('Lotgd\\Installer\\InstallerLogger')) {
                \Lotgd\Installer\InstallerLogger::log($error);
            }
            echo $error;
        }

        return $this->link ? true : false;
    }

    public function pconnect($host, $user, $pass) {
        $this->link = mysqli_connect($host, $user, $pass);

        if (!$this->link) {
            $error = mysqli_connect_error();
            if (defined('IS_INSTALLER') && IS_INSTALLER && class_exists('Lotgd\\Installer\\InstallerLogger')) {
                \Lotgd\Installer\InstallerLogger::log($error);
            }
            echo $error;
        }

        return $this->link ? true : false;
    }

    public function selectDb($dbname) {
        return mysqli_select_db($this->link, $dbname);
    }

    public function setCharset($charset) {
        return mysqli_set_charset($this->link, $charset);
    }

    public function query($sql) {
        return mysqli_query($this->link, $sql);
    }

    public function fetchAssoc($result) {
        return mysqli_fetch_assoc($result);
    }

    public function insertId() {
        return mysqli_insert_id($this->link);
    }

    public function numRows($result) {
        return mysqli_num_rows($result);
    }

    public function affectedRows() {
        return mysqli_affected_rows($this->link);
    }

    public function error() {
        return mysqli_error($this->link);
    }

    public function escape($string) {
        return mysqli_real_escape_string($this->link, $string);
    }

    public function freeResult($result) {
        return mysqli_free_result($result);
    }

    public function tableExists($tablename) {
        $result = $this->query("SHOW TABLES LIKE '$tablename'");
        return ($result && mysqli_num_rows($result) > 0);
    }

    public function getServerVersion() {
        return mysqli_get_server_info($this->link);
    }
}

function db_get_instance() {
    static $inst = null;
    if ($inst === null) {
        $inst = new DbMysqli();
    }
    return $inst;
}

function db_set_charset($charset) {
    return db_get_instance()->setCharset($charset);
}

function db_query($sql, $die=true){
    if (defined("DB_NODB") && !defined("LINK")) return array();
    global $session,$dbinfo;
    $dbinfo['queriesthishit']++;
    $starttime = getmicrotime();
    $r = db_get_instance()->query($sql);

    if (!$r && $die === true) {
        if (defined("IS_INSTALLER") && IS_INSTALLER){
            return array();
        }else{
            if (isset($session['user']['superuser']) && ($session['user']['superuser'] & SU_DEVELOPER)){
                require_once("lib/show_backtrace.php");
                die(
                    "<pre>".HTMLEntities($sql, ENT_COMPAT, getsetting("charset", "ISO-8859-1"))."</pre>".
                    db_error().
                    show_backtrace()
                );
            }else{
                die("A most bogus error has occurred.  I apologise, but the page you were trying to access is broken.  Please use your browser's back button and try again.");
            }
        }
    }
    $endtime = getmicrotime();
    if ($endtime - $starttime >= 1.00 && isset($session['user']['superuser']) && ($session['user']['superuser'] & SU_DEBUG_OUTPUT)){
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

function &db_query_cached($sql,$name,$duration=900){
    global $dbinfo;
    $data = DataCache::datacache($name,$duration);
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
        DataCache::updatedatacache($name,$data);
        reset($data);
        return $data;
    }
}

if (file_exists("lib/dbremote.php")) {
    require_once("lib/dbremote.php");
}

function db_error(){
    $r = db_get_instance()->error();
    if ($r=="" && defined("DB_NODB") && !defined("DB_INSTALLER_STAGE4")) return "The database connection was never established";
    return $r;
}

function db_fetch_assoc(&$result){
    if (is_array($result)){
        if (is_array($result)) {
            $val = current($result);
            next($result);
            return $val;
        }
        else
            return false;
    }else{
        return db_get_instance()->fetchAssoc($result);
    }
}

function db_insert_id(){
    if (defined("DB_NODB") && !defined("LINK")) return -1;
    return db_get_instance()->insertId();
}

function db_num_rows($result){
    if (is_array($result)){
        return count($result);
    }else{
        if (defined("DB_NODB") && !defined("LINK")) return 0;
        return db_get_instance()->numRows($result);
    }
}

function db_affected_rows($link=false){
    global $dbinfo;
    if (isset($dbinfo['affected_rows'])) {
        return $dbinfo['affected_rows'];
    }
    if (defined("DB_NODB") && !defined("LINK")) return 0;
    return db_get_instance()->affectedRows();
}

function db_pconnect($host,$user,$pass){
    return db_get_instance()->pconnect($host,$user,$pass);
}

function db_connect($host,$user,$pass){
    return db_get_instance()->connect($host,$user,$pass);
}

function db_get_server_version() {
    return db_get_instance()->getServerVersion();
}

function db_select_db($dbname){
    return db_get_instance()->selectDb($dbname);
}

function db_real_escape_string($string){
    return db_get_instance()->escape($string);
}

function db_free_result($result){
    if (is_array($result)){
        unset($result);
        return true;
    }else{
        if (defined("DB_NODB") && !defined("LINK")) return false;
        db_get_instance()->freeResult($result);
        return true;
    }
}

function db_table_exists($tablename){
    if (defined("DB_NODB") && !defined("LINK")) return false;
    return db_get_instance()->tableExists($tablename);
}

function db_prefix($tablename, $force=false) {
    global $DB_PREFIX;

    if ($force === false) {
        $special_prefixes = array();
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
