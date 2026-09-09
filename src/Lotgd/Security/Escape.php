<?php

declare(strict_types=1);

namespace Lotgd\Security;

/**
 * Output encoding, in one place.
 *
 * The same three recipes were written by hand across the tree, each slightly
 * differently: `htmlspecialchars()` with and without `ENT_QUOTES`, with and
 * without a charset, and translated strings dropped raw into
 * `onClick='return confirm("…")'`. That last one is not a style question — the
 * strings come from the translations table, which `SU_IS_TRANSLATOR` writes, so
 * an apostrophe closed the attribute and a double quote closed the JS string on
 * pages every player opens.
 *
 * Encoding depends only on *where* a value lands, never on where it came from,
 * so there are exactly two questions to answer and one class that answers them:
 * {@see self::html()} for markup, {@see self::js()} for script. Everything else
 * here is a shorthand built from those two.
 *
 * This is deliberately not part of {@see \Lotgd\Sanitize}. That class rewrites
 * game text — colour codes, comment rules, name cleanup — and changes what a
 * value *means*. These methods change only how a value is *spelled* for one
 * destination, and must never be applied twice or the user sees `&amp;amp;`.
 */
final class Escape
{
    /**
     * A value for use in HTML — element text or an attribute, quoted either way.
     *
     * `ENT_QUOTES` covers both quote characters because this codebase writes
     * single-quoted attributes far more often than double-quoted ones, and a
     * helper that is only safe for one of them is a trap.
     */
    public static function html(string|int|float|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * A value as a complete JavaScript literal, including its own quotes.
     *
     * Use it bare: `confirm(" . Escape::js($text) . ")`, never wrapped in
     * quotes of your own — adding them is what produced the bug this replaces.
     *
     * The flags matter. `JSON_HEX_APOS` and `JSON_HEX_QUOT` make the result
     * safe inside an HTML attribute quoted either way; `JSON_HEX_TAG` and
     * `JSON_HEX_AMP` keep a value containing `</script>` from ending an inline
     * script block. json_encode also escapes backslashes and control
     * characters, which a hand-rolled `str_replace` chain reliably forgets.
     */
    public static function js(mixed $value): string
    {
        $encoded = json_encode(
            $value,
            JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
        );

        // Only unencodable input (malformed UTF-8, a resource) reaches this,
        // and an empty string is the safe thing to emit: a literal `false` or
        // `null` in the page would be a silent behaviour change.
        return $encoded === false ? '""' : $encoded;
    }

    /**
     * A ready-made `onclick` attribute that asks before proceeding.
     *
     * The whole attribute rather than just the message, because the six places
     * that wanted this each rebuilt the quoting around it and the quoting is
     * the part that was wrong. Emit it directly into a tag:
     *
     *     "<a href='…'" . Escape::confirmAttribute($areYouSure) . ">"
     *
     * A handler that needs more than a confirmation keeps its own JavaScript
     * and uses {@see self::js()} for the message — same encoder, one shape less
     * to get wrong.
     *
     * @param string $event Where it hangs: `onclick` for a link or a button,
     *                      `onsubmit` for a form that asks before it posts.
     */
    public static function confirmAttribute(string $message, string $event = 'onclick'): string
    {
        return ' ' . $event . "='return confirm(" . self::js($message) . ");'";
    }
}
