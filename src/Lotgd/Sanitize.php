<?php

declare(strict_types=1);

namespace Lotgd;

class Sanitize
{
    public const URI_MAX_LENGTH = 200;

    /**
     * Strip game colour codes from a string.
     *
     * @param string|int|float|null $in Input value
     *
     * @return string Sanitized value
     */
    public static function sanitize(string|int|float|null $in): string
    {
        if ($in === '' || $in === null) {
            return '';
        }
        $out = preg_replace('/[`][0' . Output::getInstance()->getColormapEscaped() . 'bicnHw]/', '', (string) $in);
        return $out;
    }

    /**
     * Remove new line codes from a string.
     *
     * @param string|null $in Input value
     *
     * @return string Sanitized value
     */
    public static function newlineSanitize(?string $in): string
    {
        if ($in == '' || $in === null) {
            return '';
        }
        $out = preg_replace('/`n/', '', $in);
        return $out;
    }

    /**
     * Remove colour codes except text formatting.
     *
     * @param string|array|null $in Input value
     *
     * @return string|array Sanitized value
     */
    public static function colorSanitize(string|array|null $in): string|array
    {
        if (is_array($in)) {
            $sanitized = [];

            foreach ($in as $key => $value) {
                if (is_array($value)) {
                    $sanitized[$key] = self::colorSanitize($value);
                    continue;
                }

                if ($value === null) {
                    $sanitized[$key] = '';
                    continue;
                }

                $sanitized[$key] = self::colorSanitize((string) $value);
            }

            return $sanitized;
        }

        if ($in === null) {
            return '';
        }

        $value = (string) $in;

        if ($value === '') {
            return '';
        }

        $out = preg_replace('/[`][0' . Output::getInstance()->getColormapEscaped() . 'cbi]/', '', $value);

        return $out ?? '';
    }

    /**
     * Prepare a comment string for output.
     *
     * @param string|null $in Input value
     *
     * @return string Sanitized value
     */
    public static function commentSanitize(?string $in, string $keep = ''): string
    {
        if ($in == '' || $in === null) {
            return '';
        }
        // Every code but the colours (and the formatting codes in $keep)
        // becomes literal text: the backtick is doubled.
        $allowed = '0' . Output::getInstance()->getColormapEscaped() . preg_quote($keep, '/');
        if ($keep === '') {
            $out = preg_replace('/[`](?=[^' . $allowed . '])/', chr(1) . chr(1), $in);
            return str_replace(chr(1), '`', $out);
        }
        // With codes kept, an escaped backtick (``) stays one: doubling its
        // first half would turn ``b (a literal `b) into an escaped backtick
        // followed by a real `b, unlike the preview and what was typed.
        // A pair is matched as a whole and written back unchanged.
        return (string) preg_replace('/``|`(?=[^' . $allowed . '])/', '``', $in);
    }

    /**
     * The formatting codes players may use where it is allowed: bold and
     * italic.
     */
    public const USER_FORMATTING = 'bi';

    /**
     * Make bold and italic in a player's text safe to show: nested properly
     * and all closed again at its end, so nothing it opens reaches past it
     * (the next chat line, the page's navigation).
     *
     * `b and `i toggle, as appoencode() reads them. A code that closes a
     * format below another open one closes that one first and opens it again
     * after, so `b`ia`bb`i becomes `b`ia`i`b`ib`i. Escaped backticks (``)
     * stay as they are.
     */
    public static function balanceFormatting(string $in): string
    {
        if (!str_contains($in, '`')) {
            return $in;
        }
        $out = '';
        $open = [];
        $length = strlen($in);
        for ($pos = 0; $pos < $length; $pos++) {
            $char = $in[$pos];
            if ($char !== '`') {
                $out .= $char;
                continue;
            }
            if ($pos + 1 >= $length) {
                // A lone backtick at the end would pair with whatever comes
                // next (the closing codes below, the next line) into an
                // escaped backtick, leaving the format open: make it literal.
                $out .= '``';
                continue;
            }
            $code = $in[$pos + 1];
            if ($code === '`') {
                $out .= '``';
                $pos++;
                continue;
            }
            if (!str_contains(self::USER_FORMATTING, $code)) {
                $out .= '`' . $code;
                $pos++;
                continue;
            }
            $pos++;
            $at = array_search($code, $open, true);
            if ($at === false) {
                $open[] = $code;
                $out .= '`' . $code;
                continue;
            }
            // Close the ones opened after it, then it, then open those again.
            $after = array_slice($open, $at + 1);
            foreach (array_reverse($after) as $inner) {
                $out .= '`' . $inner;
            }
            $out .= '`' . $code;
            foreach ($after as $inner) {
                $out .= '`' . $inner;
            }
            $open = array_merge(array_slice($open, 0, $at), $after);
        }
        foreach (array_reverse($open) as $code) {
            $out .= '`' . $code;
        }

        return $out;
    }

    /**
     * Make the given formatting codes literal text (the backtick doubled),
     * leaving escaped backticks and every other code as they are.
     */
    public static function escapeCodes(string $in, string $codes): string
    {
        if ($codes === '' || !str_contains($in, '`')) {
            return $in;
        }

        return (string) preg_replace_callback(
            '/``|`([' . preg_quote($codes, '/') . '])/',
            static fn (array $m): string => $m[0] === '``' ? '``' : '``' . $m[1],
            $in
        );
    }

    /**
     * Whether the game lets players use bold and italic in an area: the
     * setting formatting_<area>. The defaults are what each area did before
     * the settings: chat lines and bios showed the codes as text, mail and
     * clan texts used them.
     *
     * @param string $area commentary, bio, mail or clan
     */
    public static function formattingAllowed(string $area): bool
    {
        $defaults = ['commentary' => 0, 'bio' => 0, 'mail' => 1, 'clan' => 1];
        if (!Settings::hasInstance()) {
            return (bool) ($defaults[$area] ?? 0);
        }

        return (int) Settings::getInstance()->getSetting('formatting_' . $area, $defaults[$area] ?? 0) === 1;
    }

    /** Emoji in chat lines (commentary_emoji): kept. */
    public const EMOJI_KEEP = 0;

    /** Emoji in chat lines: pictographs removed, text symbols (©, ™, arrows) kept. */
    public const EMOJI_PICTOGRAPHS = 1;

    /** Emoji in chat lines: everything Unicode marks as an emoji removed. */
    public const EMOJI_ALL = 2;

    /**
     * The patterns stripEmoji() removes, without delimiters or flags, written
     * so JavaScript reads them the same way after \x{...} becomes \u{...}
     * (the comment previews get them through emojiPatternsForScript()).
     *
     * An emoji is matched as a whole sequence: a pictograph, its modifiers
     * (variation selectors, skin tones, tag characters) and further
     * pictographs joined by a zero width joiner. A joiner elsewhere (Indic
     * shaping) is not part of an emoji and stays. Flag letters go; a keycap
     * keeps its digit or sign.
     *
     * @return list<string>
     */
    public static function emojiPatterns(int $mode): array
    {
        if ($mode !== self::EMOJI_PICTOGRAPHS && $mode !== self::EMOJI_ALL) {
            return [];
        }
        // Pictographs only: from U+2300 on, or anything written in emoji form.
        $pictograph = $mode === self::EMOJI_ALL
            ? '\p{Extended_Pictographic}'
            : '(?:(?=\p{Extended_Pictographic})[\x{2300}-\x{10FFFF}]|\p{Extended_Pictographic}(?=\x{FE0F}))';
        $modifiers = '[\x{FE0E}\x{FE0F}\x{1F3FB}-\x{1F3FF}\x{E0020}-\x{E007F}]*';

        return [
            $pictograph . $modifiers . '(?:\x{200D}' . $pictograph . $modifiers . ')*',
            '[\x{1F1E6}-\x{1F1FF}\x{1F3FB}-\x{1F3FF}]',
            '(?<=[0-9#*])\x{FE0F}?\x{20E3}',
        ];
    }

    /**
     * emojiPatterns() for JavaScript's RegExp (with the u flag).
     *
     * @return list<string>
     */
    public static function emojiPatternsForScript(int $mode): array
    {
        return array_map(static fn (string $p): string => str_replace('\\x{', '\\u{', $p), self::emojiPatterns($mode));
    }

    /**
     * Remove emoji from a text.
     *
     * EMOJI_ALL removes every emoji sequence built on a character Unicode
     * marks Extended_Pictographic (this includes ©, ®, ™ and arrows), flag
     * letters and lone skin tones; a keycap keeps its digit or sign.
     * EMOJI_PICTOGRAPHS removes those from U+2300 on and any character
     * written in emoji form (followed by U+FE0F), so text symbols below
     * U+2300 stay. A character reference (&#x1F600;, &hearts;) that the page
     * would show as such an emoji goes too. Colour and formatting codes,
     * letters of any script, joiners outside emoji and text emoticons like
     * :-) are never touched; a text that is not valid UTF-8 comes back
     * unchanged.
     */
    public static function stripEmoji(string $in, int $mode): string
    {
        $patterns = self::emojiPatterns($mode);
        if ($patterns === [] || !mb_check_encoding($in, 'UTF-8')) {
            return $in;
        }
        $regexes = array_map(static fn (string $p): string => '/' . $p . '/u', $patterns);
        $out = preg_replace($regexes, '', $in);
        if (!is_string($out)) {
            return $in;
        }
        // References the browser turns into an emoji (the page keeps & as is).
        $out = preg_replace_callback(
            '/&(?:#[xX][0-9a-fA-F]{1,6}|#[0-9]{1,7}|[A-Za-z][A-Za-z0-9]{1,31});/',
            static function (array $m) use ($regexes): string {
                $char = html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($char === $m[0] || $char === '') {
                    return $m[0];
                }
                $left = preg_replace($regexes, '', $char);

                return is_string($left) && $left === '' ? '' : $m[0];
            },
            $out
        );

        return is_string($out) ? $out : $in;
    }

    /**
     * How the game treats emoji in chat lines: the setting commentary_emoji,
     * EMOJI_KEEP unless it holds one of the other modes.
     */
    public static function commentaryEmojiMode(): int
    {
        if (!Settings::hasInstance()) {
            return self::EMOJI_KEEP;
        }
        $mode = (int) Settings::getInstance()->getSetting('commentary_emoji', self::EMOJI_KEEP);

        return in_array($mode, [self::EMOJI_PICTOGRAPHS, self::EMOJI_ALL], true) ? $mode : self::EMOJI_KEEP;
    }

    /**
     * A player's text as it may be shown: bold and italic balanced where the
     * game allows them, literal where it does not.
     */
    public static function userFormatting(string $in, bool $allowed): string
    {
        return $allowed ? self::balanceFormatting($in) : self::escapeCodes($in, self::USER_FORMATTING);
    }

    /**
     * Sanitize string for sending via Logdnet.
     *
     * @param string $in Input value
     *
     * @return string Sanitized value
     */
    public static function logdnetSanitize(string $in): string
    {
        $out = preg_replace('/[`](?=[^0' . Output::getInstance()->getColormapEscaped() . 'bicn])/', chr(1) . chr(1), $in);
        $out = str_replace(chr(1), '`', $out);
        return $out;
    }

    /**
     * Remove all colour codes from a string, including text formatting.
     *
     * Unlike colorSanitize(), which keeps formatting markers, this strips every
     * backtick sequence. It is display sanitization only: the result is not
     * escaped for SQL, HTML or any other context.
     *
     * @param string|null $in Input value
     *
     * @return string Value without colour codes
     */
    public static function stripAllColorCodes(?string $in): string
    {
        if ($in == '' || $in === null) {
            return '';
        }
        $out = preg_replace('/[`]./', '', $in);
        return $out;
    }

    /**
     * Remove all colour codes from a string.
     *
     * @deprecated 2.x Use stripAllColorCodes(). The old name suggested general
     *             purpose sanitization and led to values being treated as safe
     *             for SQL, which this has never provided. Kept as an alias so
     *             existing modules keep working.
     *
     * @param string|null $in Input value
     *
     * @return string Sanitized value
     */
    public static function fullSanitize(?string $in): string
    {
        return self::stripAllColorCodes($in);
    }

    /**
     * Strip colour information from a command string.
     *
     * @param string|null $in Input value
     *
     * @return string Sanitized value
     */
    public static function cmdSanitize(?string $in): string
    {
        if ($in == '' || $in === null) {
            return '';
        }
        $out = preg_replace("'[&?]c=[[:digit:]-]+'", '', $in);
        return $out;
    }

    /**
     * Remove commentary scroll parameters from a URI.
     *
     * @param string|null $in Input value
     *
     * @return string Sanitized value
     */
    public static function comscrollSanitize(?string $in): string
    {
        if ($in == '' || $in === null) {
            return '';
        }
        $out = preg_replace("'&c(omscroll)?=([[:digit:]]|-)*'", '', $in);
        $out = preg_replace("'\\?c(omscroll)?=([[:digit:]]|-)*'", '?', $out);
        $out = preg_replace("'&(refresh|comment)=1'", '', $out);
        $out = preg_replace("'\\?(refresh|comment)=1'", '?', $out);
        return $out;
    }

    /**
     * Escape colour markers to prevent rendering.
     *
     * @param string $in Input value
     *
     * @return string Sanitized value
     */
    public static function preventColors(string $in): string
    {
        return str_replace('`', '&#0096;', $in);
    }

    /**
     * Clean a URI for translator usage.
     *
     * @param string $in Input URI
     *
     * @return string Sanitized URI
     */
    public static function translatorUri(string $in): string
    {
        $uri = self::comscrollSanitize($in);
        $uri = self::cmdSanitize($uri);
        $uri = str_replace(["'", '"', '`', ';'], '', $uri);
        if (substr($uri, -1) == '?') {
            $uri = substr($uri, 0, -1);
        }
        if (strlen($uri) > self::URI_MAX_LENGTH) {
            $uri = substr($uri, 0, self::URI_MAX_LENGTH);
        }
        return $uri;
    }

    /**
     * Extract the page path from a URI.
     *
     * @param string $in Input URI
     *
     * @return string Page path
     */
    public static function translatorPage(string $in): string
    {
        $page = $in;
        if (strpos($page, '?') !== false) {
            $page = substr($page, 0, strpos($page, '?'));
        }
        $page = str_replace(["'", '"', '`', ';'], '', $page);
        return $page;
    }

    /**
     * Sanitize a module name.
     *
     * @param string $in Module name
     *
     * @return string Sanitized name
     */
    public static function modulenameSanitize(string $in): string
    {
        return preg_replace("'[^0-9A-Za-z_]'", '', $in);
    }

    /**
     * Recursively stripslashes input values.
     *
     * @param array|string $given Input value
     *
     * @return array|string Cleaned value
     */
    public static function stripslashesArray(array|string $given): array|string
    {
        return is_array($given) ? array_map([self::class, 'stripslashesArray'], $given) : stripslashes($given);
    }

    /**
     * Strip non alphabetic characters from a name.
     *
     * @param bool   $spaceallowed Allow spaces
     * @param string $inname       Input name
     *
     * @return string Sanitized name
     */
    public static function sanitizeName(bool $spaceallowed, string $inname): string
    {
        $expr = $spaceallowed ? '([^[:alpha:] _-])' : '([^[:alpha:]])';
        return preg_replace($expr, '', $inname);
    }

    /**
     * Sanitize a color name respecting admin settings.
     *
     * @param bool   $spaceallowed Allow spaces
     * @param string $inname       Name to sanitize
     * @param bool   $admin        Whether admin overrides are allowed
     *
     * @return string Sanitized name
     */
    public static function sanitizeColorname(bool $spaceallowed, string $inname, bool $admin = false): string
    {
        $settings = Settings::getInstance();
        if ($admin && $settings->getSetting('allowoddadminrenames', 0)) {
            return $inname;
        }
        $output = Output::getInstance();
        $expr = $spaceallowed ? '([^[:alpha:]`0' . $output->getColormapEscaped() . ' _-])' : '([^[:alpha:]`0' . $output->getColormapEscaped() . '])';
        return preg_replace($expr, '', $inname);
    }

    /**
     * Remove HTML, script and style tags from a string.
     *
     * @param string $str Input HTML
     *
     * @return string Sanitized string
     */
    public static function sanitizeHtml(string $str): string
    {
        $str = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $str);
        $str = preg_replace('/<style[^>]*>.+<\/style[^>]*>/', '', $str);
        $str = preg_replace('/<!--.*-->/', '', $str);
        $str = strip_tags($str);
        return $str;
    }

    /**
     * Ensure a multibyte string is valid for the current charset.
     *
     * @param string $str Input string
     *
     * @return string Sanitized string
     */
    public static function sanitizeMb(string $str): string
    {
        if ($str == '') {
            return '';
        }
        $settings = Settings::getInstance();
        $charset = $settings->getSetting('charset', 'UTF-8');

        while (!mb_check_encoding($str, $charset) && mb_strlen($str, $charset) > 0) {
            $str = mb_substr($str, 0, mb_strlen($str, $charset) - 1, $charset);
        }
        return $str;
    }
}
