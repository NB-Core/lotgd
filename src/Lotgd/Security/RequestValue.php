<?php

declare(strict_types=1);

namespace Lotgd\Security;

/**
 * Normalise request values before they are bound as SQL parameters.
 *
 * Binding a parameter keeps a value out of the statement text, but it does not
 * decide whether the value was acceptable in the first place: a row id that
 * arrives as "0", "-1" or "17foo" still reaches the database as a parameter and
 * still addresses the wrong row, or none. These two guards answer that, and
 * they answer it the same way everywhere.
 *
 * They used to be copied per page as deathmessages_normalize_optional_int() and
 * taunt_normalize_optional_int(), and the copies had drifted: the deathmessages
 * one cast to string before testing, so a boolean true or a float 1.0 became the
 * id 1, while the taunt one rejected both. Http::get() yields strings from a
 * real request, so nothing was exploitable through the web, but two guards with
 * one purpose and two behaviours is a bug waiting for its caller. This is the
 * stricter reading, which is also the one both comments described.
 */
final class RequestValue
{
    /**
     * An unsigned, non-zero row identifier, or null if the value is not one.
     *
     * Accepts an int above zero, or a string of digits that is above zero.
     * Everything else -- empty, null, arrays, objects, booleans, floats,
     * negative or signed strings, digits with a suffix -- is null.
     */
    public static function optionalPositiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value)) {
            if ($value === '' || ! ctype_digit($value)) {
                return null;
            }

            // A digit string longer than PHP_INT_MAX saturates on cast rather
            // than failing, so "<PHP_INT_MAX>0" would silently become
            // PHP_INT_MAX and address whatever row happens to hold it. Require
            // the value to survive the round trip instead.
            $intValue = (int) $value;

            if ((string) $intValue !== ltrim($value, '0')) {
                return null;
            }

            return $intValue > 0 ? $intValue : null;
        }

        // Booleans, floats, null, arrays and objects are not identifiers.
        return null;
    }

    /**
     * Did the request carry a value here at all?
     *
     * optionalPositiveInt() answers "is this a usable id", and returns null for
     * both "none was sent" and "one was sent but is unusable". A caller that
     * inserts when there is no id and updates when there is one needs those
     * two apart, or a malformed id silently becomes a new row. Ask this first
     * on the raw request value, before normalising it.
     *
     * Http::get() and Http::post() report an absent key as false, and an empty
     * field arrives as ''; neither is a value someone supplied.
     */
    public static function isPresent(mixed $value): bool
    {
        return $value !== null && $value !== false && $value !== '';
    }

    /**
     * A string payload safe to bind as ParameterType::STRING.
     *
     * Preserves the legacy coercion of scalars so existing pages keep behaving
     * the way they did, while arrays, objects, null and false become the empty
     * string rather than reaching a bind as something a string parameter cannot
     * represent.
     */
    public static function text(mixed $value): string
    {
        if ($value === false || $value === null || is_array($value)) {
            return '';
        }

        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
