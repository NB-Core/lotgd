<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\Settings;
use Lotgd\MySQL\Database;
use Lotgd\DataCache;
use Lotgd\Sanitize;
use Lotgd\Cookies;
use Lotgd\Output;
use Lotgd\PageParts;
use Lotgd\PhpGenericEnvironment;
use Doctrine\DBAL\Exception\TableNotFoundException;

class Translator
{
    private static array $translation_table = [];
    private static array $translatorbuttons = [];
    private static array $seentlbuttons = [];
    private static bool $translation_is_enabled = true;
    private static array $translation_namespace_stack = [];
    // Maintain translation state within the class
    private static string $translation_namespace = "";
    private static string $language = '';
    private static string $schema = '';

    public static function getInstance(): self
    {
        return new self();
    }

    public function getLanguage(): string
    {
        return self::$language;
    }

    public function setLanguage(string $language): void
    {
        self::$language = $language;
    }

    public function getSchema(): string
    {
        return self::$schema;
    }

    public function setSchema(string|false|null $schema = false): void
    {
        self::tlschema($schema);
        self::$schema = self::$translation_namespace;
    }
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
        $settings = Settings::hasInstance() ? Settings::getInstance() : null;
        //Determine what language to use
        if (defined("TRANSLATOR_IS_SET_UP")) {
            return;
        }
        define("TRANSLATOR_IS_SET_UP", true);

        global $session;
        $language = '';
        if (isset($session['user']['prefs']['language'])) {
            $language = $session['user']['prefs']['language'];
        } else {
            $cookieLanguage = Cookies::get('language');
            if (null !== $cookieLanguage) {
                $language = $cookieLanguage;
            }
        }
        if ($language == '') {
            $language = $settings instanceof Settings
                ? $settings->getSetting('defaultlanguage', 'en')
                : 'en';
        }

        self::getInstance()->setLanguage($language);

        define('LANGUAGE', preg_replace('/[^a-z]/i', '', $language));
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
    public static function translate(string|array $indata, string|false|null $namespace = false): string|array
    {
        global $session;
        $settings = Settings::hasInstance() ? Settings::getInstance() : null;

        if (
            ! self::$translation_is_enabled
            || ($settings instanceof Settings && $settings->getSetting('enabletranslation', true) == false)
        ) {
            return $indata;
        }

        if (!$namespace) {
            $namespace = self::$translation_namespace;
        }
        $outdata = $indata;
        if ($namespace === "") {
            self::tlschema();
        }

        $foundtranslation = false;
        if ($namespace != "notranslate") {
            if (
                !isset(self::$translation_table[$namespace]) ||
                                        !is_array(self::$translation_table[$namespace])
            ) {
                //build translation table for this page hit.
                    self::$translation_table[$namespace] =
                self::translateLoadnamespace($namespace, (isset($session['tlanguage']) ? $session['tlanguage'] : false));
            }
        }

        if (is_array($indata)) {
            //recursive translation on arrays.
            $outdata = array();
            foreach ($indata as $key => $val) {
                $outdata[$key] = self::translate($val, $namespace);
            }
        } else {
            if ($namespace != "notranslate") {
                if (isset(self::$translation_table[$namespace][$indata])) {
                        $outdata = self::$translation_table[$namespace][$indata];
                    $foundtranslation = true;
                    // Remove this from the untranslated texts table if it is
                    // in there and we are collecting texts
                    // This delete is horrible on very heavily translated games.
                    // It has been requested to be removed.
                    /*
                    if (Settings::getInstance()->getSetting("collecttexts", false)) {
        $sql = "DELETE FROM " . Database::prefix("untranslated") .
            " WHERE intext='" . addslashes($indata) .
            "' AND language='" . (defined('LANGUAGE') ? constant('LANGUAGE') : '') . "'";
        Database::query($sql);
                    }
                    */
                } elseif ($settings instanceof Settings && $settings->getSetting("collecttexts", false)) {
                    $sql = "INSERT IGNORE INTO " .  Database::prefix("untranslated") .  " (intext,language,namespace) VALUES ('" .  addslashes($indata) . "', '" . (defined('LANGUAGE') ? constant('LANGUAGE') : '') . "', " .  "'$namespace')";
                    Database::query($sql, false);
                }
                                self::tlbuttonPush($indata, !$foundtranslation, $namespace);
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
        if (!$args) {
            return '';
        }

        $setschema = false;

    // If first arg is an array, treat it as a nested sprintfTranslate call
        if (is_array($args[0])) {
            $args[0] = self::sprintfTranslate(...$args[0]);
        } else {
            // Preserve original semantics:
            // If first arg is bool, shift it (condition), then shift schema name and set it temporarily
            if (is_bool($args[0]) && array_shift($args)) {
                self::tlschema(array_shift($args));
                $setschema = true;
            }

            // Escape backtick-percent -> backtick-double-percent so it doesn't act as a format marker
            $args[0] = str_replace("`%", "`%%", (string)$args[0]);

            // Translate the format string
            $args[0] = self::translate((string)$args[0]);

            // Reset schema if we set it above
            if ($setschema) {
                self::tlschema();
            }
        }

    // Skip first entry (the format string) and recursively translate any sub-arrays
        $skipped = false;
        foreach ($args as $key => $val) {
            if (!$skipped) {
                $skipped = true;
                continue;
            }
            if (is_array($val)) {
                // Sub-translation; its result will be inserted into the master format
                $args[$key] = self::sprintfTranslate(...$val);
            }
        }

    // --------- Robust counting, padding, and safe vsprintf ---------
        $format = (string)$args[0];

    // 1) Match all regular printf-style placeholders (including flags, width, precision, *)
    //    Supported types: b,c,d,e,E,f,F,g,G,o,s,u,x,X (as in PHP)
        preg_match_all(
            '/(?<!%)%(?:(\d+)\$)?[-+0#\']*(?:\*(?:\d+\$)?|\d+)?(?:\.(?:\*(?:\d+\$)?|\d+))?[bcdefgGosuxX]/',
            $format,
            $m
        );
        $placeholderCount = count($m[0]);

    // Positional value indices from %2$s etc.
        $posNumbers = array_map('intval', array_filter($m[1]));
        $maxPosFromValue = $posNumbers ? max($posNumbers) : 0;

    // Positional indices referenced by star width/precision, e.g. %*3$s or %. *4$f
        preg_match_all('/\*(\d+)\$/', $format, $starPosAll);
        $posStarNumbers = array_map('intval', $starPosAll[1]);
        $maxPosFromStars = $posStarNumbers ? max($posStarNumbers) : 0;

    // Non-positional stars (each consumes an extra argument), e.g. %*s and %. *f
        $nonPosStarWidth = preg_match_all('/(?<!%)%(?:\d+\$)?[-+0#\']*\*(?!\d+\$)/', $format);
        $nonPosStarPrec  = preg_match_all('/(?<!%)%(?:\d+\$)?[-+0#\']*(?:\d+)?\.\*(?!\d+\$)/', $format);
        $nonPosStarCount = (int)$nonPosStarWidth + (int)$nonPosStarPrec;

    // Determine expected argument count:
    // - without positional args: placeholders + non-positional * args
    // - with positional args: max of all referenced positions (values and *-positions)
        $maxPosition = max($maxPosFromValue, $maxPosFromStars);
        $expected = max($placeholderCount + $nonPosStarCount, $maxPosition);

    // Guard: if our strict regex missed something but the format still has a lone '%' specifier,
    // ensure expected >= 1 to avoid PHP 8 ValueError on vsprintf([])
        if ($expected === 0 && preg_match('/(?<!%)%(?!%)/', $format)) {
            $expected = 1;
        }

    // Build the values array: take all user-supplied values after the format
    // (do NOT hard-cut to $expected here; extra values are ignored by vsprintf)
        $values = array_slice($args, 1);

    // If too few values, pad to expected with empty strings
        if ($expected > count($values)) {
            $values = array_pad($values, $expected, '');
        }

    // Guard against stray single '%' (turn into '%%' unless it's a valid specifier or '%%')
        $format = preg_replace(
            '/(?<!%)%(?!(?:\d+\$)?[-+0#\']*(?:\*(?:\d+\$)?|\d+)?(?:\.(?:\*(?:\d+\$)?|\d+))?[bcdeEfFgGosuxX]|%)/',
            '%%',
            $format
        );

    // Render – use vsprintf(format, array). In PHP 8, too-few args throw ValueError.
    // We catch it, pad based on the message, and retry once.
        try {
            $return = vsprintf($format, $values);
        } catch (\ValueError $ex) {
            // Try to parse "must contain N items, M given"
            if (preg_match('/contain\s+(\d+)\s+items,\s+(\d+)\s+given/i', $ex->getMessage(), $mm)) {
                $need = (int)$mm[1];
                $have = (int)$mm[2];
                if ($have < $need) {
                    $values = array_pad($values, $need, '');
                    // Retry once after padding
                    $return = vsprintf($format, $values);
                } else {
                    // Unexpected, fall back to raw format
                    $return = $format;
                }
            } else {
                // Unknown error shape; return format to avoid a hard crash
                $return = $format;
            }
        }

    // Optional: keep existing debug hookup via output buffering for other warnings (if any)
    // Note: ValueError is already handled above; typical warnings are rare at this point.

        return (string)$return;
    }


    /**
     * Translate text and append translator controls inline.
     *
     * @param string      $in        Text to translate
     * @param string|false $namespace Translation namespace
     *
     * @return string Translated string
     */
    public static function translateInline(string|array $in, string|false|null $namespace = false): string|array
    {
        if (!self::$translation_is_enabled) {
            return $in;
        }
        $out = self::translate($in, $namespace);
        if (class_exists(Output::class)) {
            Output::getInstance()->rawOutput(self::clearButton());
        }
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
    public static function translateMail(mixed $in, int $to = 0): string
    {
        if (!self::$translation_is_enabled) {
            if (!is_array($in)) {
                return (string) $in;
            }
            $args = $in;
            $format = (string) array_shift($args);
            return vsprintf($format, $args);
        }

        global $session;
        self::tlschema('mail'); // should be same schema like systemmails!
        if (!is_array($in)) {
            $in = array($in);
        }
            //this is done by sprintfTranslate.
        //$in[0] = str_replace("`%","`%%",$in[0]);
        $settings = Settings::hasInstance() ? Settings::getInstance() : null;
        if ($to > 0) {
            $result = Database::query("SELECT prefs FROM " . Database::prefix("accounts") . " WHERE acctid=$to");
            $language = Database::fetchAssoc($result);
            $language['prefs'] = unserialize($language['prefs']);
            $session['tlanguage'] = (isset($language['prefs']['language']) && $language['prefs']['language'] != '') ? $language['prefs']['language'] : ($settings instanceof Settings ? $settings->getSetting("defaultlanguage", "en") : "en");
        }
        reset($in);
        // translation offered within translation tool here is in language
        // of sender!
        // translation of mails can't be done in language of recipient by
        // the sender via translation tool.

        $out = self::sprintfTranslate(...$in);

        self::tlschema();
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
        if (!self::$translation_is_enabled) {
            return $in;
        }
        $out = self::translate($in);
        return self::clearButton() . $out;
    }

    /**
     * Load all translations for a namespace.
     *
     * @param string      $namespace Namespace identifier
     * @param string|false $language  Language to load
     *
     * @return array Translation table
     */
    public static function translateLoadNamespace(string $namespace, string|false $language = false)
    {
        $settings = Settings::hasInstance() ? Settings::getInstance() : null;
        if (!defined('DB_CHOSEN') || !DB_CHOSEN) {
            return [];
        }

        if (!Database::tableExists(Database::prefix('translations'))) {
            self::$translation_is_enabled = false;
            return [];
        }

        if ($language === false) {
            if (defined('LANGUAGE')) {
                $language = constant('LANGUAGE');
            } else {
                self::translatorSetup();
                $language = self::getInstance()->getLanguage();
            }
        }
        $page = Sanitize::translatorPage($namespace);
        $uri = Sanitize::translatorUri($namespace);

        $conn = Database::getDoctrineConnection();
        $sql = 'SELECT intext, outtext FROM ' . Database::prefix('translations')
            . ' WHERE language = :language AND (uri = :page OR uri = :uri)';
        $params = [
            'language' => $language,
            'page'     => $page,
            'uri'      => $uri,
        ];

        try {
            if ($settings instanceof Settings && $settings->getSetting('cachetranslations', 1)) {
                $cacheNamespace = $namespace;
                if (strlen($cacheNamespace) > Sanitize::URI_MAX_LENGTH) {
                    $cacheNamespace = sha1($cacheNamespace);
                }
                $cacheKey = 'translations-' . $cacheNamespace . '-' . $language;
                \Lotgd\MySQL\Database::$lastCacheName = $cacheKey;
                $cache = DataCache::getInstance();
                $data  = $cache->datacache($cacheKey, 600);
                if ($data === false) {
                    $data = $conn->fetchAllAssociative($sql, $params);
                    $cache->updatedatacache($cacheKey, $data);
                }
            } else {
                $data = $conn->fetchAllAssociative($sql, $params);
            }
        } catch (TableNotFoundException $e) {
            self::$translation_is_enabled = false;
            return [];
        }

        $out = [];
        foreach ($data as $row) {
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
    public static function tlbuttonPush(string $indata, bool $hot = false, string|false $namespace = false)
    {
        global $session;
        $language = self::getInstance()->getLanguage();
        if (!self::$translation_is_enabled) {
            return;
        }
        $seentlbuttons =& self::$seentlbuttons;
        $translatorbuttons =& self::$translatorbuttons;
        // Texts cannot be translated because this would could (if not already translated) an infinite loop...
        $nothotText = "This text has already been translated.";
        $hotText = "This text has not been translated yet.";
        if (!$namespace) {
            $namespace = "unknown";
        }
        if (isset($session['user']['superuser']) && $session['user']['superuser'] & SU_IS_TRANSLATOR) {
            if (!in_array($language, explode(',', $session['user']['translatorlanguages']))) {
                return true;
            }
            if (preg_replace("/[ 	\n\r]|`./", '', $indata) > "") {
                if (isset($seentlbuttons[$namespace][$indata])) {
                    $link = "";
                } else {
                    $seentlbuttons[$namespace][$indata] = true;
                            $uri = Sanitize::cmdSanitize($namespace);
                            $uri = Sanitize::comscrollSanitize($uri);
                    $link = "translatortool.php?u=" .
                        rawurlencode($uri) . "&t=" . rawurlencode($indata);
                    $link = "<a href='$link' target='_blank' onClick=\"" .
                        PageParts::popup($link) . ";return false;\" class='t" .
                        ($hot ? "hot" : "") .
                        "' title='" .
                        ($hot ? $hotText : $nothotText) .
                        "'>T</a>";
                }
                array_push($translatorbuttons, $link);
            }
            return true;
        } else {
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
        if (isset($session['user']['superuser']) && $session['user']['superuser'] & SU_IS_TRANSLATOR) {
                return array_pop(self::$translatorbuttons) ?? "";
        } else {
                return "";
        }
    }

    /**
     * Clear and return all queued translator buttons.
     */
    public static function clearButton(): string
    {
        global $session;
        if (isset($session['user']['superuser']) && ($session['user']['superuser'] & SU_IS_TRANSLATOR)) {
                $return = self::tlbuttonPop() . join("", self::$translatorbuttons);
                self::$translatorbuttons = [];

                return $return;
        }

        return "";
    }

    /**
     * @deprecated Use clearButton() instead.
     */
    public static function tlbuttonClear(): string
    {
        return self::clearButton();
    }


    /**
     * Enable or disable the translator output.
     *
     * @param bool $enable Enable state
     *
     * @return void
     */
    public static function enableTranslation(bool $enable = true): void
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
    public static function tlschema(string|false|null $schema = false): void
    {
        $stack =& self::$translation_namespace_stack;

        if ($schema === false) {
            // Revert one entry: remove current namespace and set previous
            if (!empty($stack)) {
                self::$translation_namespace = (string) array_pop($stack);
            } else {
                // Default to empty string when REQUEST_URI is unavailable
                self::$translation_namespace = Sanitize::translatorUri(PhpGenericEnvironment::getRequestUri());
            }
        } else {
            // Push current namespace to stack, set new one
            array_push($stack, self::$translation_namespace);
            self::$translation_namespace = (string)$schema;
        }

        self::$schema = self::$translation_namespace;
    }

    /**
     * Periodically enable or disable text collection based on configuration.
     *
     * @return void
     */
    public static function translatorCheckCollectTexts(): void
    {
        global $session;
        $settings = Settings::hasInstance() ? Settings::getInstance() : null;
        if (! $settings instanceof Settings) {
            return;
        }

        $tlmax = $settings->getSetting('tl_maxallowed', 0);

        if ($settings->getSetting("permacollect", 0)) {
            $settings->saveSetting("collecttexts", 1);
        } elseif ($tlmax && $settings->getSetting("OnlineCount", 0) <= $tlmax) {
            $settings->saveSetting("collecttexts", 1);
        } else {
            $settings->saveSetting("collecttexts", 0);
        }
    }
}
