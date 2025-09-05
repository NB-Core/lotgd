<?php

declare(strict_types=1);

/**
 * Navigation helper functions.
 */

namespace Lotgd;

use Lotgd\HolidayText;
use Lotgd\Output;
use Lotgd\Nav\NavigationItem;
use Lotgd\Nav\NavigationSection;
use Lotgd\Nav\NavigationSubSection;
use Lotgd\Modules\HookHandler;
use Lotgd\PageParts;
use Lotgd\Sanitize;
use Lotgd\Translator;

// Maintain state within the class instead of the global namespace

class Nav
{
    private static ?self $instance = null;

    private string $nav = '';
    private string $navsection = '';
    private string $header = '';
    /** @var array<int, array{0:mixed,1:mixed}> */
    private array $notranslate = [];

    private static array $blockednavs = [
        'blockpartial' => [],
        'blockfull' => [],
        'unblockpartial' => [],
        'unblockfull' => [],
    ];
    /** @var array<string, NavigationSection> */
    private static array $sections = [];
    private static ?NavigationSubSection $currentSubSection = null;
    private static array $navschema = [];
    private static bool $block_new_navs = false;
    /** @var array<string,bool> */
    private static array $accesskeys = [];
    private static array $quickkeys = [];

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getNav(): string
    {
        return $this->nav;
    }

    public function appendNav(string $string): void
    {
        $this->nav .= $string;
    }

    public function clearNavTree(): void
    {
        $this->nav = '';
    }

    public function getNavSection(): string
    {
        return $this->navsection;
    }

    public function setNavSection(string $section): void
    {
        $this->navsection = $section;
    }

    public function getHeader(): string
    {
        return $this->header;
    }

    public function setHeader(string $header): void
    {
        $this->header = $header;
    }

    public function addNoTranslate(array $entry): void
    {
        $this->notranslate[] = $entry;
    }

    public function isNoTranslate(array $entry): bool
    {
        return in_array($entry, $this->notranslate, true);
    }

    public function clearNoTranslate(): void
    {
        $this->notranslate = [];
    }
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
                if (
                    substr($link, 0, strlen($val)) == $val ||
                    substr($val, 0, strlen($link)) == $link
                ) {
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
     * Reset stored access keys and quick keys.
     */
    public static function resetAccessKeys(): void
    {
        self::$accesskeys = [];
        self::$quickkeys = [];
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
                if (
                    substr($link, 0, strlen($val)) == $val ||
                    substr($val, 0, strlen($link)) == $link
                ) {
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
        if (self::$block_new_navs) {
            return;
        }
        $instance = self::getInstance();
        $key = is_array($text) ? '!array!' . serialize($text) : $text;
        $instance->setNavSection($key);
        self::$currentSubSection = null;
        if (!array_key_exists($key, self::$navschema)) {
            self::$navschema[$key] = Translator::getNamespace();
        }
        $sectionKey = $instance->getNavSection();
        if (!isset(self::$sections[$sectionKey])) {
            self::$sections[$sectionKey] = new NavigationSection($text, $collapse);
        } else {
            self::$sections[$sectionKey]->collapse = $collapse;
            self::$sections[$sectionKey]->headline = $text;
        }
        if ($translate === false) {
            $instance->addNoTranslate([$key, '']);
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
        if (self::$block_new_navs) {
            return;
        }
        self::addHeader($text, $collapse);
        $sectionKey = self::getInstance()->getNavSection();
        if (isset(self::$sections[$sectionKey])) {
            self::$sections[$sectionKey]->colored = true;
        }
    }

    /**
     * Start a new sub navigation section.
     * Items added afterwards belong to this subsection until a new header is set.
     * Pass an empty string to stop using a subsection.
     */
    public static function addSubHeader($text, bool $translate = true): void
    {
        if (self::$block_new_navs) {
            return;
        }
        $instance = self::getInstance();
        if ($text === '') {
            self::$currentSubSection = null;
            return;
        }
        $sectionKey = $instance->getNavSection();
        if (!isset(self::$sections[$sectionKey])) {
            self::$sections[$sectionKey] = new NavigationSection($sectionKey);
        }
        $sub = new NavigationSubSection($text, $translate);
        self::$sections[$sectionKey]->addSubSection($sub);
        self::$currentSubSection = $sub;

        $key = is_array($text) ? '!array!' . serialize($text) : $text;
        if (!array_key_exists($key, self::$navschema)) {
            self::$navschema[$key] = Translator::getNamespace();
        }
        if ($translate === false) {
            $instance->addNoTranslate([$key, '']);
        }
    }

    /**
     * Start a new coloured sub navigation section.
     * Items added afterwards belong to this subsection until a new header is set.
     */
    public static function addColoredSubHeader(string $text, bool $translate = true): void
    {
        if (self::$block_new_navs) {
            return;
        }
        self::addSubHeader($text, $translate);
        if (self::$currentSubSection instanceof NavigationSubSection) {
            self::$currentSubSection->colored = true;
        }
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
        if (self::$block_new_navs) {
            return;
        }
        $instance = self::getInstance();
        if ($link === false) {
            if ($text != '') {
                self::addHeader($text, true, false);
            }
        } else {
            $args = func_get_args();
            if ($text == '') {
                call_user_func_array([self::class, 'privateAddNav'], $args);
            } else {
                $sectionKey = $instance->getNavSection();
                if (!isset(self::$sections[$sectionKey])) {
                    self::$sections[$sectionKey] = new NavigationSection($sectionKey);
                }
                $item = new NavigationItem($args[0], $args[1], $args[2] ?? false, $args[3] ?? false, $args[4] ?? '500x300', false);
                if (self::$currentSubSection !== null) {
                    self::$currentSubSection->addItem($item);
                } else {
                    self::$sections[$sectionKey]->addItem($item);
                }
                $instance->addNoTranslate([$args[0], $args[1]]);
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
        if (self::$block_new_navs) {
            return;
        }
        $instance = self::getInstance();
        if ($link === false) {
            if ($text != '') {
                self::addHeader($text);
            }
        } else {
            $args = func_get_args();
            if ($text == '') {
                call_user_func_array([self::class, 'privateAddNav'], $args);
            } else {
                $sectionKey = $instance->getNavSection();
                if (!isset(self::$sections[$sectionKey])) {
                    self::$sections[$sectionKey] = new NavigationSection($sectionKey);
                }
                $t = $args[0];
                if (is_array($t)) {
                    $t = $t[0];
                }
                if (!array_key_exists($t, self::$navschema)) {
                    self::$navschema[$t] = Translator::getNamespace();
                }
                $item = new NavigationItem($args[0], $args[1], $args[2] ?? false, $args[3] ?? false, $args[4] ?? '500x300');
                if (self::$currentSubSection !== null) {
                    self::$currentSubSection->addItem($item);
                } else {
                    self::$sections[$sectionKey]->addItem($item);
                }
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

        if (isset(self::$blockednavs['blockfull'][$link])) {
            return true;
        }
        foreach (self::$blockednavs['blockpartial'] as $l => $dummy) {
            if (substr($link, 0, strlen($l)) == $l) {
                if (isset(self::$blockednavs['unblockfull'][$link]) && self::$blockednavs['unblockfull'][$link]) {
                    return false;
                }
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
        if (!isset(self::$sections[$section])) {
            return 0;
        }
        $sec = self::$sections[$section];
        $val = $sec->getItems();
        foreach ($val as $nav) {
            if (!self::isBlocked($nav->link)) {
                $count++;
            }
        }
        foreach ($sec->getSubSections() as $sub) {
            foreach ($sub->getItems() as $nav) {
                if (!self::isBlocked($nav->link)) {
                    $count++;
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
        if (is_array($session['allowednavs']) && count($session['allowednavs']) > 0) {
            return true;
        }
        foreach (self::$sections as $key => $section) {
            if (self::countViableNavs($key) > 0) {
                if (count($section->getItems()) > 0 || count($section->getSubSections()) > 0) {
                    return true;
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
        $builtnavs   = '';
        $sectionOrder = self::normalizeOrder($session['user']['prefs']['sortedmenus'] ?? null);
        $subOrder     = self::normalizeOrder($session['user']['prefs']['navsort_headers'] ?? null);
        $itemOrder    = self::normalizeOrder($session['user']['prefs']['navsort_subheaders'] ?? null);

        if ($sectionOrder !== 'off' || $subOrder !== 'off' || $itemOrder !== 'off') {
            self::navSort($sectionOrder, $subOrder, $itemOrder);
        }
        foreach (self::$sections as $key => $section) {
            $tkey = $key;
            $navbanner = '';
            if (self::countViableNavs($key) > 0) {
                $headerKey = $section->headline;
                if ($key > '') {
                    if (isset($session['loggedin']) && $session['loggedin']) {
                        Translator::getInstance()->setSchema(self::$navschema[$key]);
                    }
                    $header = $headerKey;
                    if ($section->colored) {
                        if (is_array($header)) {
                            $header[0] .= '`0';
                        } else {
                            $header .= '`0';
                        }
                    }
                    $navbanner = self::privateAddNav($header);
                    if (isset($session['loggedin']) && $session['loggedin']) {
                        Translator::getInstance()->setSchema();
                    }
                }
                $style = 'default';
                $collapseheader = '';
                $collapsefooter = '';
                if ($tkey > '' && $section->collapse) {
                    if (is_array($headerKey)) {
                        $key_string = call_user_func_array('sprintf', $headerKey);
                    } else {
                        $key_string = $headerKey;
                    }
                    $args = ['name' => "nh-{$key_string}", 'title' => ($key_string ? $key_string : 'Unnamed Navs')];
                    $args = HookHandler::hook('collapse-nav{', $args);
                    if (isset($args['content'])) {
                        $collapseheader = $args['content'];
                    }
                    if (isset($args['style'])) {
                        $style = $args['style'];
                    }
                    if (!($key > '') && $style == 'classic') {
                        $navbanner = '<tr><td>';
                    }
                }
                $sublinks = '';
                foreach ($section->getItems() as $v) {
                    $sublinks .= $v->render();
                }
                foreach ($section->getSubSections() as $sub) {
                    $has = false;
                    foreach ($sub->getItems() as $navItem) {
                        if (!self::isBlocked($navItem->link)) {
                            $has = true;
                            break;
                        }
                    }
                    if (! $has) {
                        continue;
                    }
                    $header = $sub->headline;
                    if ($sub->colored) {
                        if (is_array($header)) {
                            $header[0] .= '`0';
                        } else {
                            $header .= '`0';
                        }
                    }
                    $sublinks .= self::privateAddSubHeader($header, $sub->translate ?? true);
                    foreach ($sub->getItems() as $v) {
                        $sublinks .= $v->render();
                    }
                }
                if ($tkey > '' && $section->collapse) {
                    $args = HookHandler::hook('}collapse-nav');
                    if (isset($args['content'])) {
                        $collapsefooter = $args['content'];
                    }
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
        self::$sections = [];
        return $builtnavs;
    }

    /**
     * Add a navigation link or header.
     *
     * @param string|array          $text    Link label or header text
     * @param string|false|null     $link    Target URL; false for header, '' or null for help text
     * @param bool                  $priv    Passed to appoencode()
     * @param bool                  $pop     Open in popup window
     * @param string                $popsize Popup size when $pop is true
     *
     * @return string|false HTML for the navigation item or false when blocked
     */
    public static function privateAddNav($text, string|false|null $link = false, $priv = false, $pop = false, $popsize = '500x300')
    {
        global $session;
        $output = Output::getInstance();
        $instance = self::getInstance();

        if ($link !== false) {
            $link = (string) $link;
            if (self::isBlocked($link)) {
                return false;
            }
        }
        $thisnav = '';
        $unschema = 0;
        $translate = true;
        if ($instance->isNoTranslate([$text, $link])) {
            $translate = false;
        }
        if (is_array($text)) {
            if ($text[0] && (isset($session['loggedin']) && $session['loggedin'])) {
                if ($link === false) {
                    $schema = '!array!' . serialize($text);
                } else {
                    $schema = $text[0];
                }
                if ($translate) {
                    if (isset(self::$navschema[$schema])) {
                        Translator::getInstance()->setSchema(self::$navschema[$schema]);
                    }
                    $unschema = 1;
                }
            }
            if ($link != '!!!addraw!!!') {
                if ($translate) {
                    $text[0] = Translator::translate($text[0]);
                }
                $text = call_user_func_array('sprintf', $text);
            } else {
                $text = call_user_func_array('sprintf', $text);
            }
        } else {
            if ($text && isset($session['loggedin']) && $session['loggedin'] && $translate) {
                if (isset(self::$navschema[$text])) {
                    Translator::getInstance()->setSchema(self::$navschema[$text]);
                }
                $unschema = 1;
            }
            if ($link != '!!!addraw!!!' && $text > '' && $translate) {
                $text = Translator::translate($text);
            }
        }
        $extra = '';
        $ignoreuntil = '';
        Output::getInstance()->closeOpenFont();
        if ($link === false) {
            $text = HolidayText::holidayize($text, 'nav');
            $thisnav .= Translator::tlbuttonPop() . Template::templateReplace('navhead', ['title' => $output->appoencode($text, $priv)]);
        } elseif ($link === '') {
            $text = HolidayText::holidayize($text, 'nav');
            $thisnav .= Translator::tlbuttonPop() . Template::templateReplace('navhelp', ['text' => $output->appoencode($text, $priv)]);
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
                        if ($hchar == ' ') {
                            $key = ' ';
                        }
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
                                    if ($char == '<') {
                                        $ignoreuntil = '>';
                                    }
                                    if ($char == '&') {
                                        $ignoreuntil = ';';
                                    }
                                    if ($char == '`') {
                                        $ignoreuntil = $text[$i + 1];
                                    }
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
                                if ($char == '<') {
                                    $ignoreuntil = '>';
                                }
                                if ($char == '&') {
                                    $ignoreuntil = ';';
                                }
                                if ($char == '`') {
                                    $ignoreuntil = substr($text, $i + 1, 1);
                                }
                            } else {
                                break;
                            }
                        }
                    }
                }
                if (!isset($i)) {
                    $i = 0;
                }
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
                            self::$quickkeys[$key] = PageParts::popup($link, $popsize);
                        }
                    } else {
                        self::$quickkeys[$key] = "window.location='$link$extra'";
                    }
                }
                if (is_string($text)) {
                    $text = preg_replace('/^`0+/', '', $text);
                }
                $n = Template::templateReplace('navitem', [
                    'text' => $output->appoencode($text, $priv),
                    'link' => $link . ($pop != true ? $extra : ''),
                    'accesskey' => $keyrep,
                    'popup' => ($pop == true ? "target='_blank'" . ($popsize > '' ? " onClick=\"" . PageParts::popup($link, $popsize) . "; return false;\"" : '') : ''),
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
        if ($unschema) {
            Translator::getInstance()->setSchema();
        }
        $instance->appendNav($thisnav);
        return $thisnav;
    }

    private static function privateAddSubHeader($text, bool $priv = false): string
    {
        global $session;
        $output = Output::getInstance();
        $link = false;
        $thisnav = '';
        $unschema = 0;
        $translate = true;
        $instance = self::getInstance();
        if ($instance->isNoTranslate([$text, $link])) {
            $translate = false;
        }
        if (is_array($text)) {
            if ($text[0] && (isset($session['loggedin']) && $session['loggedin'])) {
                $schema = '!array!' . serialize($text);
                if ($translate) {
                    if (isset(self::$navschema[$schema])) {
                        Translator::getInstance()->setSchema(self::$navschema[$schema]);
                    }
                    $unschema = 1;
                }
            }
            if ($translate) {
                $text[0] = Translator::translate($text[0]);
            }
            $text = call_user_func_array('sprintf', $text);
        } else {
            if ($text && isset($session['loggedin']) && $session['loggedin'] && $translate) {
                if (isset(self::$navschema[$text])) {
                    Translator::getInstance()->setSchema(self::$navschema[$text]);
                }
                $unschema = 1;
            }
            if ($text > '' && $translate) {
                $text = Translator::translate($text);
            }
        }
        $text = HolidayText::holidayize($text, 'nav');
        $thisnav .= Translator::tlbuttonPop() . Template::templateReplace('navheadsub', ['title' => $output->appoencode($text, $priv)]);
        if ($unschema) {
            Translator::getInstance()->setSchema();
        }
        $instance->appendNav($thisnav);
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
        foreach (self::$sections as $section) {
            $c += count($section->getItems());
            foreach ($section->getSubSections() as $sub) {
                $c += count($sub->getItems());
            }
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
        self::resetAccessKeys();
        $instance = self::getInstance();
        $instance->clearNavTree();
        $instance->setNavSection('');
        $instance->clearNoTranslate();
    }

    /**
     * Sort navigation entries alphabetically.
     *
     * @param string $sectionOrder Order for section headlines (asc, desc or off)
     * @param string $subOrder     Order for sub-headlines (asc, desc or off)
     * @param string $itemOrder    Order for items (asc, desc or off)
     */
    public static function navSort(string $sectionOrder = 'asc', string $subOrder = 'asc', string $itemOrder = 'asc'): void
    {
        global $session;

        $sections = self::$sections;
        if ($sectionOrder !== 'off') {
            uasort($sections, [self::class, 'navASortSection']);
            if ($sectionOrder === 'desc') {
                $sections = array_reverse($sections, true);
            }
        }

        foreach ($sections as $key => $section) {
            $val = $section->getItems();
            if ($itemOrder !== 'off') {
                usort($val, [self::class, 'navASortItem']);
                if ($itemOrder === 'desc') {
                    $val = array_reverse($val);
                }
            }
            $sections[$key]->setItems($val);

            $subs = $section->getSubSections();
            if ($subOrder !== 'off') {
                usort($subs, [self::class, 'navASortSub']);
                if ($subOrder === 'desc') {
                    $subs = array_reverse($subs);
                }
            }
            foreach ($subs as $s) {
                $items = $s->getItems();
                if ($itemOrder !== 'off') {
                    usort($items, [self::class, 'navASortItem']);
                    if ($itemOrder === 'desc') {
                        $items = array_reverse($items);
                    }
                }
                $s->setItems($items);
            }
            $sections[$key]->setSubSections($subs);
        }
        self::$sections = $sections;
    }

    protected static function navASort($a, $b)
    {
        $a = $a[0];
        $b = $b[0];
        if (is_array($a)) {
            $a = call_user_func_array('sprintf', $a);
        }
        if (is_array($b)) {
            $b = call_user_func_array('sprintf', $b);
        }
        $a = Sanitize::sanitize($a);
        $b = Sanitize::sanitize($b);
        $pos = strpos(substr($a, 0, 2), '?');
        $pos2 = strpos(substr($b, 0, 2), '?');
        if ($pos === false) {
            $pos = -1;
        }
        if ($pos2 === false) {
            $pos2 = -1;
        }
        $a = substr($a, $pos + 1);
        $b = substr($b, $pos2 + 1);
        return strcmp($a, $b);
    }

    protected static function navASortItem(NavigationItem $a, NavigationItem $b): int
    {
        $ta = $a->text;
        $tb = $b->text;
        if (is_array($ta)) {
            $ta = call_user_func_array('sprintf', $ta);
        }
        if (is_array($tb)) {
            $tb = call_user_func_array('sprintf', $tb);
        }
        $ta = Sanitize::sanitize($ta);
        $tb = Sanitize::sanitize($tb);
        $posA = strpos(substr($ta, 0, 2), '?');
        $posB = strpos(substr($tb, 0, 2), '?');
        if ($posA === false) {
            $posA = -1;
        }
        if ($posB === false) {
            $posB = -1;
        }
        $ta = substr($ta, $posA + 1);
        $tb = substr($tb, $posB + 1);
        return strcmp($ta, $tb);
    }

    protected static function navASortSection(NavigationSection $a, NavigationSection $b): int
    {
        $ta = $a->headline;
        $tb = $b->headline;
        if (is_array($ta)) {
            $ta = call_user_func_array('sprintf', $ta);
        }
        if (is_array($tb)) {
            $tb = call_user_func_array('sprintf', $tb);
        }
        $ta = Sanitize::sanitize($ta);
        $tb = Sanitize::sanitize($tb);
        $posA = strpos(substr($ta, 0, 2), '?');
        $posB = strpos(substr($tb, 0, 2), '?');
        if ($posA === false) {
            $posA = -1;
        }
        if ($posB === false) {
            $posB = -1;
        }
        $ta = substr($ta, $posA + 1);
        $tb = substr($tb, $posB + 1);
        return strcmp($ta, $tb);
    }

    protected static function navASortSub(NavigationSubSection $a, NavigationSubSection $b): int
    {
        $ta = $a->headline;
        $tb = $b->headline;
        if (is_array($ta)) {
            $ta = call_user_func_array('sprintf', $ta);
        }
        if (is_array($tb)) {
            $tb = call_user_func_array('sprintf', $tb);
        }
        $ta = Sanitize::sanitize($ta);
        $tb = Sanitize::sanitize($tb);
        $posA = strpos(substr($ta, 0, 2), '?');
        $posB = strpos(substr($tb, 0, 2), '?');
        if ($posA === false) {
            $posA = -1;
        }
        if ($posB === false) {
            $posB = -1;
        }
        $ta = substr($ta, $posA + 1);
        $tb = substr($tb, $posB + 1);
        return strcmp($ta, $tb);
    }

    /**
     * Normalize preference values into a standard format.
     *
     * This method converts various representations of preference values into
     * a consistent string format. The following rules are applied:
     * - `1`, `'1'` or `'asc'` return `'asc'`.
     * - `'desc'` returns `'desc'`.
     * - All other values return `'off'`.
     *
     * @param mixed $value The preference value to normalize. Can be `null`, a string, or a number.
     *
     * @return string The normalized preference value: `'off'`, `'asc'`, or `'desc'.
     */
    private static function normalizeOrder($value): string
    {
        if (in_array($value, ['asc', 1, '1'], true)) {
            return 'asc';
        }
        if ($value === 'desc') {
            return 'desc';
        }
        return 'off';
    }

    /**
     * Reset output buffers and navigation state.
     */
    public static function clearOutput(): void
    {
        self::clearNav();
        $ref = new \ReflectionClass(Output::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, new Output());
        self::getInstance()->setHeader('');
    }
}
