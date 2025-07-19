<?php
declare(strict_types=1);
namespace Lotgd;

use Lotgd\HolidayText;
use Lotgd\Output;
use Lotgd\Translator;

// Maintain state within the class instead of the global namespace


/**
 * Navigation helper functions.
 */
class Nav
{
    private static array $blockednavs = [
        'blockpartial' => [],
        'blockfull' => [],
        'unblockpartial' => [],
        'unblockfull' => [],
    ];
    private static string $navsection = '';
    private static array $navbysection = [];
    private static array $navschema = [];
    private static array $navnocollapse = [];
    private static bool $block_new_navs = false;
    private static array $accesskeys = [];
    private static array $quickkeys = [];
    /**
     * Store which nav sections use colored headlines so we can
     * append the colour reset when rendering.
     *
     * @var array<string,bool>
     */
    private static array $coloredsections = [];
    /**
     * Block a navigation link.
     *
     * @param string $link    URL to block
     * @param bool   $partial Whether to treat the link as a prefix
     */
    public static function blockNav(string $link, bool $partial = false): void
    {
        
        $p = ($partial ? 'partial' : 'full');
        self::$blockednavs["block$p"][$link] = true;
        if (isset(self::$blockednavs["unblock$p"][$link])) {
            unset(self::$blockednavs["unblock$p"][$link]);
        }
        if ($partial) {
            foreach (self::$blockednavs['unblockpartial'] as $val) {
                if (substr($link, 0, strlen($val)) == $val ||
                    substr($val, 0, strlen($link)) == $link) {
                    unset(self::$blockednavs['unblockpartial'][$val]);
                }
            }
        }
    }

    /**
     * Get the quick keys array assigned to navigation items.
     *
     * @return array<string,string>
     */
    public static function getQuickKeys(): array
    {
        return self::$quickkeys;
    }

    /**
     * Unblock a navigation link.
     *
     * @param string $link    URL to unblock
     * @param bool   $partial Whether the link is a prefix
     */
    public static function unblockNav(string $link, bool $partial = false): void
    {
        
        $p = ($partial ? 'partial' : 'full');
        self::$blockednavs["unblock$p"][$link] = true;
        if (isset(self::$blockednavs["block$p"][$link])) {
            unset(self::$blockednavs["block$p"][$link]);
        }
        if ($partial) {
            foreach (self::$blockednavs['blockpartial'] as $val) {
                if (substr($link, 0, strlen($val)) == $val ||
                    substr($val, 0, strlen($link)) == $link) {
                    unset(self::$blockednavs['blockpartial'][$val]);
                }
            }
        }
    }

    /**
     * Append the current session counter to a link.
     *
     * @param string $link Base URL
     *
     * @return string Updated URL containing the counter
     */
    public static function appendCount(string $link): string
    {
        global $session;
        if (! (isset($session['loggedin']) && $session['loggedin'])) {
            return $link;
        }

        return self::appendLink($link, 'c=' . $session['counter'] . '-' . date('His'));
    }

    /**
     * Append a query fragment to a link.
     *
     * @param string $link Base URL
     * @param string $new  Query string to append (without ?)
     *
     * @return string
     */
    public static function appendLink(string $link, string $new): string
    {
        if (strpos($link, '?') !== false) {
            return $link . '&' . $new;
        }
        return $link . '?' . $new;
    }

    /**
     * Allow header/footer code to block or unblock newly added navs.
     *
     * @param bool $block True to block nav creation, false to allow
     */
    public static function setBlockNewNavs(bool $block): void
    {
        
        self::$block_new_navs = $block;
    }

    /**
     * Start a new navigation section.
     *
     * @param string|array $text      Header text or sprintf format
     * @param bool         $collapse  Whether the section can collapse
     * @param bool         $translate Disable translation when false
     */
    public static function addHeader($text, bool $collapse = true, bool $translate = true): void
    {
        global $notranslate;
        if (self::$block_new_navs) return;
        if (is_array($text)) {
            $text = '!array!' . serialize($text);
        }
        self::$navsection = $text;
        if (!array_key_exists($text, self::$navschema)) {
            self::$navschema[$text] = Translator::getNamespace();
        }
        if (!isset(self::$navbysection[self::$navsection])) {
            self::$navbysection[self::$navsection] = [];
        }
        if ($collapse === false) {
            self::$navnocollapse[$text] = true;
        }
        if ($translate === false) {
            if (!isset($notranslate)) {
                $notranslate = [];
            }
            array_push($notranslate, [$text, '']);
        }
    }

    /**
     * Add a navigation headline that can include LOTGD colour codes.
     * The headline is automatically terminated with `0 so any
     * open colour span closes before rendering.
     *
     * @param string $text     Headline text
     * @param bool   $collapse Whether the section can collapse
     */
    public static function addColoredHeadline(string $text, bool $collapse = true): void
    {
        if (self::$block_new_navs) return;
        self::addHeader($text, $collapse);
        self::$coloredsections[self::$navsection] = true;
    }

    /**
     * Add a link without translating the label.
     *
     * @param string|array $text    Link label or header text
     * @param string|false $link    Target URL; false for header, '' for help text
     * @param bool         $priv    Passed to appoencode()
     * @param bool         $pop     Open in popup window
     * @param string       $popsize Popup size when $pop is true
     */
    public static function addNotl($text, $link = false, $priv = false, $pop = false, $popsize = '500x300'): void
    {
        global $notranslate;
        if (self::$block_new_navs) return;
        if ($link === false) {
            if ($text != '') {
                self::addHeader($text, true, false);
            }
        } else {
            $args = func_get_args();
            if ($text == '') {
                call_user_func_array([self::class, 'privateAddNav'], $args);
            } else {
                if (!isset(self::$navbysection[self::$navsection])) {
                    self::$navbysection[self::$navsection] = [];
                }
                if (!isset($notranslate)) {
                    $notranslate = [];
                }
                array_push(self::$navbysection[self::$navsection], $args);
                array_push($notranslate, $args);
            }
        }
    }

    /**
     * Add a translated navigation link or header.
     *
     * @param string|array $text    Link label or header text
     * @param string|false $link    Target URL; false for header, '' for help text
     * @param bool         $priv    Passed to appoencode()
     * @param bool         $pop     Open in popup window
     * @param string       $popsize Popup size when $pop is true
     */
    public static function add($text, $link = false, $priv = false, $pop = false, $popsize = '500x300'): void
    {
        if (self::$block_new_navs) return;
        if ($link === false) {
            if ($text != '') {
                self::addHeader($text);
            }
        } else {
            $args = func_get_args();
            if ($text == '') {
                call_user_func_array([self::class, 'privateAddNav'], $args);
            } else {
                if (!isset(self::$navbysection[self::$navsection])) {
                    self::$navbysection[self::$navsection] = [];
                }
                $t = $args[0];
                if (is_array($t)) {
                    $t = $t[0];
                }
                if (!array_key_exists($t, self::$navschema)) {
                    self::$navschema[$t] = Translator::getNamespace();
                }
                array_push(self::$navbysection[self::$navsection], array_merge($args, ['translate' => false]));
            }
        }
    }

    /**
     * Check whether a navigation link is blocked.
     *
     * @param string $link Link URL
     *
     * @return bool
     */
    public static function isBlocked(string $link): bool
    {
        
        if (isset(self::$blockednavs['blockfull'][$link])) return true;
        foreach (self::$blockednavs['blockpartial'] as $l => $dummy) {
            if (substr($link, 0, strlen($l)) == $l) {
                if (isset(self::$blockednavs['unblockfull'][$link]) && self::$blockednavs['unblockfull'][$link]) return false;
                foreach (self::$blockednavs['unblockpartial'] as $l2 => $dummy2) {
                    if (substr($link, 0, strlen($l2)) == $l2) {
                        return false;
                    }
                }
                return true;
            }
        }
        return false;
    }

    /**
     * Count unblocked links in a section.
     *
     * @param string $section Section identifier
     *
     * @return int
     */
    public static function countViableNavs($section): int
    {
        
        $count = 0;
        $val = self::$navbysection[$section];
        if (count($val) > 0) {
            foreach ($val as $nav) {
                if (is_array($nav) && count($nav) > 0) {
                    $link = $nav[1];
                    if (!self::isBlocked($link)) $count++;
                }
            }
        }
        return $count;
    }

    /**
     * Determine if any navigation items are available.
     *
     * @return bool
     */
    public static function checkNavs(): bool
    {
        global $session;
        if (is_array($session['allowednavs']) && count($session['allowednavs']) > 0) return true;
        foreach (self::$navbysection as $key => $val) {
            if (self::countViableNavs($key) > 0) {
                foreach ($val as $v) {
                    if (is_array($v) && count($v) > 0) return true;
                }
            }
        }
        return false;
    }

    /**
     * Render stored navigation items and clear the cache.
     *
     * @return string HTML output
     */
    public static function buildNavs(): string
    {
        global $session;
        $builtnavs = '';
        if (isset($session['user']['prefs']['sortedmenus']) && $session['user']['prefs']['sortedmenus'] == 1) self::navSort();
        foreach (self::$navbysection as $key => $val) {
            $tkey = $key;
            $navbanner = '';
            if (self::countViableNavs($key) > 0) {
                if ($key > '') {
                    if (isset($session['loggedin']) && $session['loggedin']) tlschema(self::$navschema[$key]);
                    if (substr($key, 0, 7) == '!array!') {
                        $key = unserialize(substr($key, 7));
                    }
                    $header = $key;
                    if (isset(self::$coloredsections[$tkey]) && self::$coloredsections[$tkey]) {
                        if (is_array($header)) {
                            $header[0] .= '`0';
                        } else {
                            $header .= '`0';
                        }
                    }
                    $navbanner = self::privateAddNav($header);
                    if (isset($session['loggedin']) && $session['loggedin']) tlschema();
                }
                $style = 'default';
                $collapseheader = '';
                $collapsefooter = '';
                if ($tkey > '' && (!array_key_exists($tkey, self::$navnocollapse) || !self::$navnocollapse[$tkey])) {
                    if (is_array($key)) {
                        $key_string = call_user_func_array('sprintf', $key);
                    } else {
                        $key_string = $key;
                    }
                    $args = ['name' => "nh-{$key_string}", 'title' => ($key_string ? $key_string : 'Unnamed Navs')];
                    $args = modulehook('collapse-nav{', $args);
                    if (isset($args['content'])) $collapseheader = $args['content'];
                    if (isset($args['style'])) $style = $args['style'];
                    if (!($key > '') && $style == 'classic') {
                        $navbanner = '<tr><td>';
                    }
                }
                $sublinks = '';
                foreach ($val as $v) {
                    if (is_array($v) && count($v) > 0) {
                        unset($v['translate']);
                        $sublinks .= call_user_func_array([self::class, 'privateAddNav'], $v);
                    }
                }
                if ($tkey > '' && (!array_key_exists($tkey, self::$navnocollapse) || !self::$navnocollapse[$tkey])) {
                    $args = modulehook('}collapse-nav');
                    if (isset($args['content'])) $collapsefooter = $args['content'];
                }
                switch ($style) {
                    case 'classic':
                        $navbanner = str_replace('</tr>', '', $navbanner);
                        $navbanner = str_replace('</td>', '', $navbanner);
                        $builtnavs .= "{$navbanner}{$collapseheader}<table align='left'>{$sublinks}</table>{$collapsefooter}</tr></td>\n";
                        break;
                    case 'default':
                    default:
                        $builtnavs .= "{$navbanner}{$collapseheader}{$sublinks}{$collapsefooter}\n";
                        break;
                }
            }
        }
        self::$navbysection = [];
        self::$coloredsections = [];
        return $builtnavs;
    }

    protected static function privateAddNav($text, $link = false, $priv = false, $pop = false, $popsize = '500x300')
    {
        global $nav, $session, $REQUEST_URI, $notranslate, $settings;
        if ($link != false)
            if (self::isBlocked($link)) return false;
        $thisnav = '';
        $unschema = 0;
        $translate = true;
        if (isset($notranslate)) {
            if (in_array([$text, $link], $notranslate)) $translate = false;
        }
        if (is_array($text)) {
            if ($text[0] && (isset($session['loggedin']) && $session['loggedin'])) {
                if ($link === false) $schema = '!array!' . serialize($text);
                else $schema = $text[0];
                if ($translate) {
                    if (isset(self::$navschema[$schema])) {
                        tlschema(self::$navschema[$schema]);
                    }
                    $unschema = 1;
                }
            }
            if ($link != '!!!addraw!!!') {
                if ($translate) $text[0] = translate($text[0]);
                $text = call_user_func_array('sprintf', $text);
            } else {
                $text = call_user_func_array('sprintf', $text);
            }
        } else {
            if ($text && isset($session['loggedin']) && $session['loggedin'] && $translate) {
                if (isset(self::$navschema[$text])) {
                    tlschema(self::$navschema[$text]);
                }
                $unschema = 1;
            }
            if ($link != '!!!addraw!!!' && $text > '' && $translate) $text = Translator::translate($text);
        }
        $extra = '';
        $ignoreuntil = '';
        if ($link === false) {
            $text = HolidayText::holidayize($text, 'nav');
            $thisnav .= Translator::tlbuttonPop() . Template::templateReplace('navhead', ['title' => appoencode($text, $priv)]);
        } elseif ($link === '') {
            $text = HolidayText::holidayize($text, 'nav');
            $thisnav .= Translator::tlbuttonPop() . Template::templateReplace('navhelp', ['text' => appoencode($text, $priv)]);
        } elseif ($link == '!!!addraw!!!') {
            $thisnav .= $text;
        } else {
            if ($text != '') {
                $extra = '';
                if (isset($session['loggedin']) && $session['loggedin']) {
                    if (!isset($session['counter'])) {
                        $session['counter'] = '';
                    }
                    if (strpos($link, '?')) {
                        $extra = "&c={$session['counter']}";
                    } else {
                        $extra = "?c={$session['counter']}";
                    }
                    $extra .= '-' . date('His');
                }
                $key = '';
                if ($text[1] == '?') {
                    $hchar = strtolower($text[0]);
                    if ($hchar == ' ' || array_key_exists($hchar, self::$accesskeys) && self::$accesskeys[$hchar] == 1) {
                        $text = substr($text, 2);
                        $text = HolidayText::holidayize($text, 'nav');
                        if ($hchar == ' ') $key = ' ';
                    } else {
                        $key = $text[0];
                        $text = substr($text, 2);
                        $text = HolidayText::holidayize($text, 'nav');
                        $found = false;
                        $text_len = strlen($text);
                        for ($i = 0; $i < $text_len; ++$i) {
                            $char = $text[$i];
                            if ($ignoreuntil == $char) {
                                $ignoreuntil = '';
                            } else {
                                if ($ignoreuntil <> '') {
                                    if ($char == '<') $ignoreuntil = '>';
                                    if ($char == '&') $ignoreuntil = ';';
                                    if ($char == '`') $ignoreuntil = $text[$i + 1];
                                } else {
                                    if ($char == $key) {
                                        $found = true;
                                        break;
                                    }
                                }
                            }
                        }
                        if ($found == false) {
                            if (strpos($text, '__') !== false) {
                                $text = str_replace('__', '(' . $key . ') ', $text);
                            } else {
                                $text = '(' . strtoupper($key) . ') ' . $text;
                            }
                            $i = strpos($text, $key);
                        }
                    }
                } else {
                    $text = HolidayText::holidayize($text, 'nav');
                }
                if ($key == '') {
                    for ($i = 0; $i < strlen($text); $i++) {
                        $char = substr($text, $i, 1);
                        if ($ignoreuntil == $char) {
                            $ignoreuntil = '';
                        } else {
                            if ((isset(self::$accesskeys[strtolower($char)]) && self::$accesskeys[strtolower($char)] == 1) || (strpos('abcdefghijklmnopqrstuvwxyz0123456789', strtolower($char)) === false) || $ignoreuntil <> '') {
                                if ($char == '<') $ignoreuntil = '>';
                                if ($char == '&') $ignoreuntil = ';';
                                if ($char == '`') $ignoreuntil = substr($text, $i + 1, 1);
                            } else {
                                break;
                            }
                        }
                    }
                }
                if (!isset($i)) $i = 0;
                if ($i < strlen($text) && $key != ' ') {
                    $key = substr($text, $i, 1);
                    self::$accesskeys[strtolower($key)] = 1;
                    $keyrep = " accesskey=\"$key\" ";
                } else {
                    $key = '';
                    $keyrep = '';
                }
                if ($key == '' || $key == ' ') {
                } else {
                    $pattern1 = "/^" . preg_quote($key, "/") . "/";
                    $pattern2 = "/([^`])" . preg_quote($key, "/") . "/";
                    $rep1 = "`H$key`H";
                    $rep2 = "\$1`H$key`H";
                    $text = preg_replace($pattern1, $rep1, $text, 1);
                    if (strpos($text, '`H') === false) {
                        $text = preg_replace($pattern2, $rep2, $text, 1);
                    }
                    if ($pop) {
                        if ($popsize == '') {
                            self::$quickkeys[$key] = "window.open('$link')";
                        } else {
                            self::$quickkeys[$key] = popup($link, $popsize);
                        }
                    } else {
                        self::$quickkeys[$key] = "window.location='$link$extra'";
                    }
                }
                $n = Template::templateReplace('navitem', [
                    'text' => appoencode($text, $priv),
                    'link' => $link . ($pop != true ? $extra : ''),
                    'accesskey' => $keyrep,
                    'popup' => ($pop == true ? "target='_blank'" . ($popsize > '' ? " onClick=\"" . popup($link, $popsize) . "; return false;\"" : '') : ''),
                ]);
                $n = str_replace('<a ', Translator::tlbuttonPop() . '<a ', $n);
                $thisnav .= $n;
            }
            if (isset($session['loggedin']) && $session['loggedin']) {
                $session['allowednavs'][$link . $extra] = true;
                $session['allowednavs'][str_replace(' ', '%20', $link) . $extra] = true;
                $session['allowednavs'][str_replace(' ', '+', $link) . $extra] = true;
                if (($pos = strpos($link, '#')) !== false) {
                    $sublink = substr($link, 0, $pos);
                    $session['allowednavs'][$sublink . $extra] = true;
                }
            }
        }
        if ($unschema) tlschema();
        $nav .= $thisnav;
        return $thisnav;
    }

    /**
     * Get the total number of navigation elements.
     *
     * @return int
     */
    public static function navCount(): int
    {
        global $session;
        $c = count($session['allowednavs']);
        if (!is_array(self::$navbysection)) return $c;
        foreach (self::$navbysection as $val) {
            if (is_array($val)) $c += count($val);
        }
        return $c;
    }

    /**
     * Reset the list of allowed navigation links.
     */
    public static function clearNav(): void
    {
        global $session;
        $session['allowednavs'] = [];
    }

    /**
     * Sort navigation entries alphabetically.
     */
    public static function navSort(): void
    {
        global $session;
        if (!is_array(self::$navbysection)) {
            return;
        }
        foreach (self::$navbysection as $key => $val) {
            if (is_array($val)) {
                usort($val, [self::class, 'navASort']);
                self::$navbysection[$key] = $val;
            }
        }
    }

    protected static function navASort($a, $b)
    {
        $a = $a[0];
        $b = $b[0];
        if (is_array($a)) $a = call_user_func_array('sprintf', $a);
        if (is_array($b)) $b = call_user_func_array('sprintf', $b);
        $a = sanitize($a);
        $b = sanitize($b);
        $pos = strpos(substr($a, 0, 2), '?');
        $pos2 = strpos(substr($b, 0, 2), '?');
        if ($pos === false) $pos = -1;
        if ($pos2 === false) $pos2 = -1;
        $a = substr($a, $pos + 1);
        $b = substr($b, $pos2 + 1);
        return strcmp($a, $b);
    }

    /**
     * Reset output buffers and navigation state.
     */
    public static function clearOutput(): void
    {
        global $output, $nestedtags, $header, $nav, $session;
        self::clearNav();
        $output = new Output();
        $header = '';
        $nav = '';
    }
}
