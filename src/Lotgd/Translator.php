<?php
namespace Lotgd;

use Lotgd\Sanitize;

class Translator
{
    private static array $translation_table = [];
    private static array $translatorbuttons = [];
    private static array $seentlbuttons = [];
    private static bool $translation_is_enabled = true;
    private static array $translation_namespace_stack = [];
	// Maintain translation state within the class
	private static string $translation_namespace = "";
// translator ready
// addnews ready
// mail ready

    public static function translator_setup(){
		//Determine what language to use
		if (defined("TRANSLATOR_IS_SET_UP")) return;
		define("TRANSLATOR_IS_SET_UP",true);

		global $language, $session;
		$language = "";
		if (isset($session['user']['prefs']['language'])) {
			$language = $session['user']['prefs']['language'];
		}elseif(isset($_COOKIE['language'])){
			$language = $_COOKIE['language'];
		}
		if ($language=="") {
			$language=getsetting("defaultlanguage","en");
		}

		define("LANGUAGE",preg_replace("/[^a-z]/i","",$language));
	}

    public static function translate($indata,$namespace=FALSE){
		if (getsetting("enabletranslation", true) == false) return $indata;
                global $session;
		if (!$namespace) $namespace=self::$translation_namespace;
		$outdata = $indata;
		if (!isset($namespace) || $namespace=="")
			tlschema();

		$foundtranslation = false;
		if ($namespace != "notranslate") {
                        if (!isset(self::$translation_table[$namespace]) ||
                                        !is_array(self::$translation_table[$namespace])){
				//build translation table for this page hit.
                                self::$translation_table[$namespace] =
					translate_loadnamespace($namespace,(isset($session['tlanguage'])?$session['tlanguage']:false));
			}
		}

		if (is_array($indata)){
			//recursive translation on arrays.
			$outdata = array();
			foreach ($indata as $key=>$val) {
				$outdata[$key] = translate($val,$namespace);
			}
		}else{
			if ($namespace != "notranslate") {
                                if (isset(self::$translation_table[$namespace][$indata])) {
                                        $outdata = self::$translation_table[$namespace][$indata];
					$foundtranslation = true;
					// Remove this from the untranslated texts table if it is
					// in there and we are collecting texts
					// This delete is horrible on very heavily translated games.
					// It has been requested to be removed.
					/*
					if (getsetting("collecttexts", false)) {
						$sql = "DELETE FROM " . db_prefix("untranslated") .
							" WHERE intext='" . addslashes($indata) .
							"' AND language='" . LANGUAGE . "'";
						db_query($sql);
					}
					*/
				} elseif (getsetting("collecttexts", false)) {
					$sql = "INSERT IGNORE INTO " .  db_prefix("untranslated") .  " (intext,language,namespace) VALUES ('" .  addslashes($indata) . "', '" . LANGUAGE . "', " .  "'$namespace')";
					db_query($sql,false);
				}
				tlbutton_push($indata,!$foundtranslation,$namespace);
			} else {
				$outdata = $indata;
			}
		}
		return $outdata;
	}

    public static function sprintf_translate(){
		$args = func_get_args();
		$setschema = false;
		// Handle if an array is passed in as the first arg
		if (is_array($args[0])) {
			$args[0] = call_user_func_array("sprintf_translate", $args[0]);
		} else {
			// array_shift returns the first element of an array and shortens this array by one...
			if (is_bool($args[0]) && array_shift($args)) {
				tlschema(array_shift($args));
				$setschema = true;
			}
			$args[0] = str_replace("`%","`%%",$args[0]);
			$args[0] = translate($args[0]);
			if ($setschema) {
				tlschema();
			}
		}
		//skip the first entry which is the output text
		$skipped = false;
		foreach ($args as $key=>$val) {
			if (!$skipped) {
				$skipped = true;
				continue;
			}
			if (is_array($val)){
				//When passed a sub-array this represents an independant
				//translation to happen then be inserted in the master string.
				$args[$key]=call_user_func_array("sprintf_translate",$val);
			}
		}
		ob_start();
		if (is_array($args) && count($args)>0) {
			//if it is an array
			//which it should be
			$return = call_user_func_array("sprintf",$args);
		} else $return=$args;
		$err = ob_get_contents();
		ob_end_clean();
		if ($err > ""){
			$args['error'] = $err;
			debug($err);
		}
		return $return;
	}

    public static function translate_inline($in,$namespace=FALSE){
		$out = translate($in,$namespace);
		rawoutput(tlbutton_clear());
		return $out;
	}

		public static function translate_mail($in,$to=0){
		global $session;
		tlschema("mail"); // should be same schema like systemmails!
		if (!is_array($in)) $in=array($in);
		//this is done by sprintf_translate.
		//$in[0] = str_replace("`%","`%%",$in[0]);
		if ($to>0){
			$result = db_query("SELECT prefs FROM ".db_prefix("accounts")." WHERE acctid=$to");
			$language = db_fetch_assoc($result);
			$language['prefs'] = unserialize($language['prefs']);
			$session['tlanguage'] = (isset($language['prefs']['language']) && $language['prefs']['language']!='')?$language['prefs']['language']:getsetting("defaultlanguage","en");
		}
		reset($in);
		// translation offered within translation tool here is in language
		// of sender!
		// translation of mails can't be done in language of recipient by
		// the sender via translation tool.

		$out = call_user_func_array("sprintf_translate", $in);

		tlschema();
		unset($session['tlanguage']);
		return $out;
	}

		public static function tl($in){
		$out = translate($in);
		return tlbutton_clear().$out;
	}

    public static function translate_loadnamespace($namespace,$language=false){
		if (defined("LANGUAGE")) {
			if ($language===false) $language = LANGUAGE;
		} else {
			translator_setup();	
		}
		$page = translator_page($namespace);
		$uri = translator_uri($namespace);
		if ($page==$uri)
			$where = "uri = '$page'";
		else
			$where = "(uri='$page' OR uri='$uri')";
		$sql = "
			SELECT intext,outtext
			FROM ".db_prefix("translations")."
			WHERE language='$language'
				AND $where";
	/*	debug(nl2br(htmlentities($sql, ENT_COMPAT, getsetting("charset", "ISO-8859-1")))); */
		if (!getsetting("cachetranslations",0)) {
			$result = db_query($sql);
		} else {
			$result = db_query_cached($sql,"translations-".$namespace."-".$language,600);
			//store it for 10 Minutes, normally you don't need to refresh this often
		}
		$out = array();
		while ($row = db_fetch_assoc($result)){
			$out[$row['intext']] = $row['outtext'];
		}
		return $out;
	}


	public static function tlbutton_push($indata,$hot=false,$namespace=FALSE){
                global $session,$language;
                if (!self::$translation_is_enabled) return;
                $seentlbuttons =& self::$seentlbuttons;
                $translatorbuttons =& self::$translatorbuttons;
		if (!$namespace) $namespace="unknown";
		if (isset($session['user']['superuser']) && $session['user']['superuser'] & SU_IS_TRANSLATOR){
			if (!in_array($language,explode(',',$session['user']['translatorlanguages']))) return true;
			if (preg_replace("/[ 	\n\r]|`./",'',$indata)>""){
				if (isset($seentlbuttons[$namespace][$indata])){
					$link = "";
				}else{
                                $seentlbuttons[$namespace][$indata] = true;
                                        $uri = Sanitize::cmdSanitize($namespace);
                                        $uri = Sanitize::comscrollSanitize($uri);
					$link = "translatortool.php?u=".
						rawurlencode($uri)."&t=".rawurlencode($indata);
					$link = "<a href='$link' target='_blank' onClick=\"".
						popup($link).";return false;\" class='t".
						($hot?"hot":"")."'>T</a>";
				}
                                array_push($translatorbuttons,$link);
			}
			return true;
		}else{
			//when user is not a translator, return false.
			return false;
		}
	}

    public static function tlbutton_pop(){
                global $session;
                if (isset($session['user']['superuser']) && $session['user']['superuser'] & SU_IS_TRANSLATOR){
                        return array_pop(self::$translatorbuttons);
                }else{
                        return "";
                }
        }

    public static function tlbutton_clear(){
                global $session;
                if (isset($session['user']['superuser']) && ($session['user']['superuser'] & SU_IS_TRANSLATOR)){
                        $return = tlbutton_pop().join("",self::$translatorbuttons);
                        self::$translatorbuttons = array();
                        return $return;
                }else{
                        return "";
                }
        }


    public static function enable_translation($enable=true){
                self::$translation_is_enabled = $enable;
        }


    public static function tlschema($schema=false){
                global $REQUEST_URI;
                $stack =& self::$translation_namespace_stack;
                if ($schema===false){
                        self::$translation_namespace = array_pop($stack);
                        if (empty(self::$translation_namespace))
                                self::$translation_namespace = translator_uri($REQUEST_URI);
                }else{
                        array_push($stack,self::$translation_namespace);
                        self::$translation_namespace = $schema;
                }
	}

    public static function translator_check_collect_texts()
	{
		$tlmax = getsetting("tl_maxallowed",0);

		if (getsetting("permacollect", 0))
			savesetting("collecttexts", 1);
		elseif ($tlmax && getsetting("OnlineCount", 0) <= $tlmax)
			savesetting("collecttexts", 1);
		else
			savesetting("collecttexts", 0);
	}

}

