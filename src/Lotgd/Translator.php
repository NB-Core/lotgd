<?php
declare(strict_types=1);

namespace Lotgd;
use Lotgd\MySQL\Database;

use Lotgd\Sanitize;
use Lotgd\Cookies;

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

    /**
     * Initialise language settings for the translation system.
     *
     * @return void
     */
    public static function translatorSetup(): void
    {
		global $settings;
		//Determine what language to use
		if (defined("TRANSLATOR_IS_SET_UP")) return;
		define("TRANSLATOR_IS_SET_UP",true);

		if (!isset($settings)) return; // not yet setup most likely

		global $language, $session;
		$language = "";
		if (isset($session['user']['prefs']['language'])) {
			$language = $session['user']['prefs']['language'];
                } else {
                        $cookieLanguage = Cookies::get('language');
                        if (null !== $cookieLanguage) {
                            $language = $cookieLanguage;
                        }
                }
		if ($language=="") {
			$language=$settings->getsetting("defaultlanguage","en");
		}

                define("LANGUAGE",preg_replace("/[^a-z]/i","",$language));
        }

    /**
     * Return the current translation namespace.
     *
     * @return string Translation namespace
     */
    public static function getNamespace(): string
    {
        return self::$translation_namespace;
    }

    /**
     * Translate a string or array within an optional namespace.
     *
     * @param mixed       $indata    Text or array to translate
     * @param string|false $namespace Translation namespace
     *
     * @return mixed Translated value
     */
    public static function translate(string|array $indata, string|false|null $namespace=FALSE): string|array
    {
        global $session,$settings;

		if (!isset($settings) || $settings->getSetting("enabletranslation", true) == false) return $indata;

		if (!$namespace) $namespace=self::$translation_namespace;
		$outdata = $indata;
		if (!isset($namespace) || $namespace=="")
			self::tlschema();

		$foundtranslation = false;
		if ($namespace != "notranslate") {
                        if (!isset(self::$translation_table[$namespace]) ||
                                        !is_array(self::$translation_table[$namespace])){
				//build translation table for this page hit.
                                self::$translation_table[$namespace] =
					self::translateLoadnamespace($namespace,(isset($session['tlanguage'])?$session['tlanguage']:false));
			}
		}

		if (is_array($indata)){
			//recursive translation on arrays.
			$outdata = array();
			foreach ($indata as $key=>$val) {
				$outdata[$key] = self::translate($val,$namespace);
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
						$sql = "DELETE FROM " . Database::prefix("untranslated") .
							" WHERE intext='" . addslashes($indata) .
							"' AND language='" . LANGUAGE . "'";
						Database::query($sql);
					}
					*/
				} elseif ($settings->getsetting("collecttexts", false)) {
					$sql = "INSERT IGNORE INTO " .  Database::prefix("untranslated") .  " (intext,language,namespace) VALUES ('" .  addslashes($indata) . "', '" . LANGUAGE . "', " .  "'$namespace')";
					Database::query($sql,false);
				}
                                self::tlbuttonPush($indata,!$foundtranslation,$namespace);
			} else {
				$outdata = $indata;
			}
		}
		return $outdata;
	}

    /**
     * Wrapper around sprintf which translates arguments first.
     *
     * @return string Result of sprintf translation
     */
    public static function sprintfTranslate(): string
    {
		$args = func_get_args();
		$setschema = false;
		// Handle if an array is passed in as the first arg
		if (is_array($args[0])) {
                    $args[0] = call_user_func_array([self::class, 'sprintfTranslate'], $args[0]);
		} else {
			// array_shift returns the first element of an array and shortens this array by one...
			if (is_bool($args[0]) && array_shift($args)) {
				tlschema(array_shift($args));
				$setschema = true;
			}
			$args[0] = str_replace("`%","`%%",$args[0]);
			$args[0] = self::translate($args[0]);
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
                                $args[$key]=call_user_func_array("sprintfTranslate",$val);
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

    /**
     * Translate text and append translator controls inline.
     *
     * @param string      $in        Text to translate
     * @param string|false $namespace Translation namespace
     *
     * @return string Translated string
     */
    public static function translateInline(string|array $in,string|false|null $namespace=FALSE): string|array
    {
		$out = self::translate($in,$namespace);
            rawoutput(self::tlbuttonClear());
		return $out;
	}

    /**
     * Translate mail text for a specific user.
     *
     * @param mixed $in Message or parameter array
     * @param int   $to Recipient account id
     *
     * @return string Translated message
     */
    public static function translateMail(mixed $in,int $to=0): string
    {
		global $session;
		tlschema("mail"); // should be same schema like systemmails!
		if (!is_array($in)) $in=array($in);
            //this is done by sprintfTranslate.
		//$in[0] = str_replace("`%","`%%",$in[0]);
		if ($to>0){
			$result = Database::query("SELECT prefs FROM ".Database::prefix("accounts")." WHERE acctid=$to");
			$language = Database::fetchAssoc($result);
			$language['prefs'] = unserialize($language['prefs']);
			$session['tlanguage'] = (isset($language['prefs']['language']) && $language['prefs']['language']!='')?$language['prefs']['language']:getsetting("defaultlanguage","en");
		}
		reset($in);
		// translation offered within translation tool here is in language
		// of sender!
		// translation of mails can't be done in language of recipient by
		// the sender via translation tool.

                $out = call_user_func_array("sprintfTranslate", $in);

		tlschema();
		unset($session['tlanguage']);
		return $out;
	}

    /**
     * Translate a string and return with translation control button.
     *
     * @param string $in Text to translate
     *
     * @return string Translated string
     */
    public static function tl(string $in): string
    {
		$out = self::translate($in);
        return self::tlbuttonClear().$out;
	}

    /**
     * Load all translations for a namespace.
     *
     * @param string      $namespace Namespace identifier
     * @param string|false $language  Language to load
     *
     * @return array Translation table
     */
    public static function translateLoadNamespace(string $namespace,string|false $language=false)
    {
		global $language, $session;
		if (defined("LANGUAGE")) {
			if ($language===false) $language = LANGUAGE;
		} else {
			self::translatorSetup();
		}
		$page = Sanitize::translatorPage($namespace);
		$uri = Sanitize::translatorUri($namespace);
		if ($page==$uri)
			$where = "uri = '$page'";
		else
			$where = "(uri='$page' OR uri='$uri')";
		$sql = "
			SELECT intext,outtext
			FROM ".Database::prefix("translations")."
			WHERE language='$language'
				AND $where";
	/*	debug(nl2br(htmlentities($sql, ENT_COMPAT, getsetting("charset", "ISO-8859-1")))); */
		if (isset($settings) && !$settings->getSetting("cachetranslations",0)) {
			$result = Database::query($sql);
		} else {
			$result = Database::queryCached($sql,"translations-".$namespace."-".$language,600);
			//store it for 10 Minutes, normally you don't need to refresh this often
		}
		$out = array();
		while ($row = Database::fetchAssoc($result)){
			$out[$row['intext']] = $row['outtext'];
		}
		return $out;
	}


   /**
    * Display a translate button for a given string.
    *
    * @param string      $indata    Text to translate
    * @param bool        $hot       Highlight button
    * @param string|false $namespace Namespace identifier
    *
    * @return bool True when button shown
    */
   public static function tlbuttonPush(string $indata,bool $hot=false,string|false $namespace=FALSE)
   {
        global $session,$language;
        if (!self::$translation_is_enabled) return;
        $seentlbuttons =& self::$seentlbuttons;
        $translatorbuttons =& self::$translatorbuttons;
		// Texts cannot be translated because this would could (if not already translated) an infinite loop...
		$nothotText = "This text has already been translated.";
		$hotText = "This text has not been translated yet.";
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
						($hot?"hot":"").
						"' title='".
						($hot?$hotText:$nothotText).
						"'>T</a>";
				}
                array_push($translatorbuttons,$link);
			}
			return true;
		}else{
			//when user is not a translator, return false.
			return false;
		}
	}

    /**
     * Pop the last translator button from the stack.
     *
     * @return string Button HTML
     */
    public static function tlbuttonPop(): string
    {
        global $session;
        if (isset($session['user']['superuser']) && $session['user']['superuser'] & SU_IS_TRANSLATOR){
                return array_pop(self::$translatorbuttons) ?? "";
        }else{
                return "";
        }
    }

    /**
     * Clear and return all queued translator buttons.
     *
     * @return string Buttons HTML
     */
    public static function tlbuttonClear(): string
    {
        global $session;
        if (isset($session['user']['superuser']) && ($session['user']['superuser'] & SU_IS_TRANSLATOR)){
                $return = self::tlbuttonPop().join("",self::$translatorbuttons);
                self::$translatorbuttons = array();
                return $return;
        }else{
                return "";
        }
    }


    /**
     * Enable or disable the translator output.
     *
     * @param bool $enable Enable state
     *
     * @return void
     */
    public static function enableTranslation(bool $enable=true): void
    {
        self::$translation_is_enabled = $enable;
    }


    /**
     * Manage the translation namespace stack.
     *
     * @param string|false $schema New namespace or false to pop
     *
     * @return void
     */
    public static function tlschema(string|false|null $schema=false): void
    {
        global $REQUEST_URI;
        $stack =& self::$translation_namespace_stack;

        if ($schema === false) {
            // Revert one entry: remove current namespace and set previous
            if (!empty($stack)) {
                self::$translation_namespace = (string)array_pop($stack);
            } else {
                self::$translation_namespace = Sanitize::translatorUri($REQUEST_URI);
            }
        } else {
            // Push current namespace to stack, set new one
            array_push($stack, self::$translation_namespace);
            self::$translation_namespace = (string)$schema;
        }
    }

    /**
     * Periodically enable or disable text collection based on configuration.
     *
     * @return void
     */
    public static function translatorCheckCollectTexts(): void
    {
		global $session, $settings;
		if (!isset($settings)) return; // not yet setup most likely

		$tlmax = $settings->getSetting("tl_maxallowed",0);

		if ($settings->getSetting("permacollect", 0))
			$settings->saveSetting("collecttexts", 1);
		elseif ($tlmax && getsetting("OnlineCount", 0) <= $tlmax)
			$settings->saveSetting("collecttexts", 1);
		else
			$settings->saveSetting("collecttexts", 0);
	}

}

