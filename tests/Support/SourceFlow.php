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
     * Is $variable ever assigned from a statement calling one of $functions?
     *
     * Deliberately loose about the shape of the expression -- a ternary, a
     * null-coalesce and a plain call all count. The question is whether the
     * value came through the named guard, not how the page spelled it.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param list<string>                                  $functions
     */
    public static function isAssignedFromAnyOf(array $tokens, string $variable, array $functions): bool
    {
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if (!is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== $variable) {
                continue;
            }

            $sawAssignment = false;
            for ($cursor = $index + 1; $cursor < $count; $cursor++) {
                $inner = $tokens[$cursor];
                if ($inner === ';') {
                    break;
                }
                if ($inner === '=') {
                    $sawAssignment = true;
                    continue;
                }
                if (!$sawAssignment) {
                    continue;
                }
                if (is_array($inner) && $inner[0] === T_STRING && in_array($inner[1], $functions, true)) {
                    return true;
                }
            }
        }

        return false;
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

            $values = [];
            for (; $cursor < $count; $cursor++) {
                $inner = $tokens[$cursor];
                if ($inner === ']' || $inner === ')') {
                    break;
                }
                if ($inner === ';') {
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
