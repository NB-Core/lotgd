<?php

declare(strict_types=1);

namespace Lotgd\Tests\Support;

/**
 * Small token-stream questions about a legacy page's source.
 *
 * A last resort, and worth saying why it exists at all. The test audit's
 * preference is to run the code: a rule that can be exercised gets a unit and
 * a table of inputs. But a handful of rules in this codebase are about
 * *wiring* -- that a page still routes a request value through the guard that
 * narrows it -- and no harness in this suite runs a root-level page, so there
 * is nothing to observe. The choice there is a source check or no check, and
 * #1533 established which is worse: removing a wiring witness without
 * replacing it let the game's only automatic ban be disabled with all 1272
 * tests green.
 *
 * What these do that a substring search cannot is ask a *structural*
 * question. "The page mentions modulename_sanitize somewhere" is satisfied by
 * a comment; "the value handed to rawurlencode came from a variable assigned
 * by modulename_sanitize" is not. That is the difference between a test that
 * fails when the code is reformatted and one that fails when the guard is
 * unhooked.
 */
final class SourceFlow
{
    /**
     * The tokens of a PHP file.
     *
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    public static function tokenize(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Cannot read $path");
        }

        return token_get_all($contents);
    }

    /**
     * The single variable passed to $function, or null if it is not one.
     *
     * Null for a literal, a nested call or a second argument: the point is to
     * follow a named value, and anything else is not one.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    public static function argumentOf(array $tokens, string $function): ?string
    {
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== $function) {
                continue;
            }
            if (!self::isPlainFunctionCall($tokens, $index)) {
                continue;
            }

            $argument = null;
            for ($cursor = $index + 1; $cursor < $count; $cursor++) {
                $inner = $tokens[$cursor];
                if (is_array($inner) && $inner[0] === T_WHITESPACE) {
                    continue;
                }
                if ($inner === '(') {
                    continue;
                }
                if ($inner === ')') {
                    return $argument;
                }
                if (is_array($inner) && $inner[0] === T_VARIABLE && $argument === null) {
                    $argument = $inner[1];
                    continue;
                }

                return null;
            }
        }

        return null;
    }

    /**
     * A T_STRING that is really a call to the free function of that name.
     *
     * Not a definition and not a method: `function rawurlencode($x) {}` and
     * `Foo::rawurlencode($x)` both put the name before a parenthesis, so
     * matching on the token alone returned a parameter or an unrelated
     * argument and the test then reasoned about the wrong value. Reported by
     * Copilot on #1535; the same guard exists in
     * src/Lotgd/QA/SqlAddslashesUsageCheck.php for the same reason.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isPlainFunctionCall(array $tokens, int $index): bool
    {
        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            $token = $tokens[$cursor];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ($token === '->' || $token === '::') {
                return false;
            }
            if (
                is_array($token)
                && in_array($token[0], [T_FUNCTION, T_FN, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW], true)
            ) {
                return false;
            }

            break;
        }

        return true;
    }

    /**
     * The right-hand side of every assignment to $variable, as text.
     *
     * Every one, and that is the whole design. An earlier version of this
     * answered "is it assigned from the guard *somewhere*", which returns
     * true for a page that sanitizes once and then overwrites the result
     * with the raw request value two lines later -- the guard is named, the
     * value never passes through it, and the test is green. Codex found that
     * on #1535 while it was guarding exactly such a value.
     *
     * Handing back all of them puts the burden on the caller to say what must
     * hold for each, which is the honest shape: "every path to this value
     * goes through the guard" is checkable, "one of them does" is not worth
     * checking.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return list<string>
     */
    public static function assignmentsTo(array $tokens, string $variable): array
    {
        $count = count($tokens);
        $assignments = [];

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if (!is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== $variable) {
                continue;
            }

            $cursor = self::skipTrivia($tokens, $index + 1, $count);
            // Only a plain assignment. `$x == $y` is a comparison, `$x[] =`
            // appends, and `$x .= ` extends -- none of them replaces the
            // value, so none of them is a path this asks about.
            if (($tokens[$cursor] ?? null) !== '=') {
                continue;
            }

            $expression = '';
            $depth = 0;
            for ($cursor++; $cursor < $count; $cursor++) {
                $inner = $tokens[$cursor];
                $text = is_array($inner) ? $inner[1] : $inner;

                if ($text === '(' || $text === '[') {
                    $depth++;
                } elseif ($text === ')' || $text === ']') {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                } elseif ($depth === 0 && $text === ';') {
                    break;
                }

                if (is_array($inner)) {
                    // A comment is not code, and the content of a string is
                    // not code either. Both were being concatenated into the
                    // expression, so a caller asking whether it "passes
                    // through" a guard was satisfied by the guard's name in a
                    // `/* in_array */` comment or in `?: 'array_key_exists'`.
                    // Copilot caught that on #1535, and it is the sharper
                    // version of the point: this class exists because a
                    // comment satisfies a substring search, and it had the
                    // same hole. Literals keep their quotes so the failure
                    // message still reads like the statement it came from.
                    if (in_array($inner[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    if ($inner[0] === T_CONSTANT_ENCAPSED_STRING) {
                        $expression .= substr($text, 0, 1) . substr($text, -1);
                        continue;
                    }
                    // And the literal runs inside an interpolated string or a
                    // heredoc, which are a different token type. Stripping
                    // only the first left "$cat = \"array_key_exists $x\";"
                    // satisfying the guard -- the same hole one token over,
                    // which is the shape of mistake this audit has hit before.
                    if ($inner[0] === T_ENCAPSED_AND_WHITESPACE) {
                        continue;
                    }
                }

                $expression .= $text;
            }

            $assignments[] = trim($expression);
        }

        return $assignments;
    }

    /**
     * Does every assignment to $variable mention one of $guards?
     *
     * False when there are no assignments at all: a value nothing ever sets
     * is not a value that passed through a guard.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param list<string>                                  $guards
     */
    public static function everyAssignmentPassesThrough(array $tokens, string $variable, array $guards): bool
    {
        $assignments = self::assignmentsTo($tokens, $variable);
        if ($assignments === []) {
            return false;
        }

        foreach ($assignments as $expression) {
            $narrowed = false;
            foreach ($guards as $guard) {
                if (str_contains($expression, $guard)) {
                    $narrowed = true;
                    break;
                }
            }

            if (!$narrowed) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every literal value in the array assigned to $variable.
     *
     * For the pages that narrow a request value against a written-out list
     * rather than a function -- healer.php's return targets, hof.php's ops.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return list<string>|null Null when no such assignment exists.
     */
    public static function arrayAssignedTo(array $tokens, string $variable): ?array
    {
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if (!is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== $variable) {
                continue;
            }

            $cursor = self::skipTrivia($tokens, $index + 1, $count);
            if (($tokens[$cursor] ?? null) !== '=') {
                continue;
            }

            $cursor = self::skipTrivia($tokens, $cursor + 1, $count);
            $opensArray = ($tokens[$cursor] ?? null) === '['
                || (is_array($tokens[$cursor] ?? null) && $tokens[$cursor][0] === T_ARRAY);
            if (!$opensArray) {
                continue;
            }

            // Depth-counted rather than stopping at the first closer: an
            // entry that is itself an array, or an array(...) spelling with a
            // call inside it, ended the scan early and returned a truncated
            // list -- which reads as "these are all the allowed values" while
            // silently omitting some. Reported by Copilot on #1535.
            $values = [];
            $depth = 0;
            for (; $cursor < $count; $cursor++) {
                $inner = $tokens[$cursor];
                $text = is_array($inner) ? $inner[1] : $inner;

                if ($text === '[' || $text === '(') {
                    $depth++;
                    continue;
                }
                if ($text === ']' || $text === ')') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                    continue;
                }
                if ($depth === 0 && $text === ';') {
                    break;
                }
                if (is_array($inner) && $inner[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $values[] = trim($inner[1], "'\"");
                }
            }

            return $values;
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function skipTrivia(array $tokens, int $index, int $count): int
    {
        while (
            $index < $count
            && is_array($tokens[$index])
            && in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ) {
            $index++;
        }

        return $index;
    }
}
