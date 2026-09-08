<?php

declare(strict_types=1);

namespace Lotgd\QA;

/**
 * Detect request-shaped values interpolated into the VALUE position of an SQL
 * string.
 *
 * Policy:
 *  - Values belong in bound parameters. Interpolating one into a query is how
 *    an injection reaches the database, and a cast to string or a pass through
 *    a display sanitizer does not change that.
 *  - Identifiers are different: table and column names cannot be bound, so
 *    interpolation after FROM/JOIN/INTO/UPDATE or inside backticks is normal
 *    and is not reported.
 *  - An integer cast is a complete defence for an integer column and is
 *    treated as safe. A string cast is not.
 *
 * This follows the value rather than the shape of the call. A rule keyed on
 * "Database::query() must receive a literal" is unusable in this codebase:
 * nearly every call site passes a previously assembled $sql variable.
 */
final class SqlValueInterpolationCheck
{
    /**
     * @var list<string>
     */
    private const SCAN_ROOTS = [
        'src',
        'lib',
        'pages',
        'async',
    ];

    /**
     * @var list<string>
     */
    private const ALLOWED_PATHS = [
        // Self-exclusion so the policy examples below are not flagged.
        'src/Lotgd/QA/SqlValueInterpolationCheck.php',
    ];

    /**
     * Keywords that make a string literal look like SQL rather than markup.
     */
    private const SQL_PATTERN = '/\b(SELECT|INSERT\s+INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO|WHERE|VALUES|SET|HAVING|ORDER\s+BY|LIMIT|OFFSET)\b/i';

    /**
     * A slot sits in value position when the text before it ends with a
     * comparison, an opening quote, a list separator, or LIMIT/OFFSET.
     */
    private const VALUE_POSITION_PATTERN = "/(=|<|>|<>|!=|\\bLIKE\\b|\\bIN\\b\\s*\\(|\\bLIMIT\\b|\\bOFFSET\\b|,|\\()\\s*'?\\s*$/i";

    /**
     * Interpolation right after one of these is an identifier, not a value.
     */
    private const IDENTIFIER_POSITION_PATTERN = '/\b(FROM|JOIN|INTO|UPDATE|TABLE|DESCRIBE|SHOW)\s+$|`\s*$/i';

    /**
     * Expressions that cannot carry an injection.
     *
     * A (string) cast is deliberately absent: it changes the type and nothing
     * about the content, which is exactly the misunderstanding this check
     * exists to catch.
     */
    private const SAFE_EXPRESSION_PATTERN = '/^\s*\(\s*(int|integer|float|double)\s*\)|^\s*(intval|floatval|count|abs)\s*\(/i';

    /**
     * @param list<string>|null $relativePaths Restrict the scan to these files.
     *
     * @return list<array{file: string, line: int, expression: string, context: string}>
     */
    public function collectViolations(string $repositoryRoot, ?array $relativePaths = null): array
    {
        $root = rtrim($repositoryRoot, DIRECTORY_SEPARATOR);
        $violations = [];

        foreach ($this->resolveFiles($root, $relativePaths) as $relativePath) {
            $absolutePath = $root . DIRECTORY_SEPARATOR . $relativePath;
            if (!is_file($absolutePath)) {
                continue;
            }

            foreach ($this->collectFileViolations($absolutePath, $relativePath) as $violation) {
                $violations[] = $violation;
            }
        }

        usort($violations, static function (array $a, array $b): int {
            return [$a['file'], $a['line']] <=> [$b['file'], $b['line']];
        });

        return $violations;
    }

    /**
     * @param list<array{file: string, line: int, expression: string, context: string}> $violations
     */
    public function report(array $violations): void
    {
        fwrite(STDERR, "Request values must be bound, not interpolated into SQL.\n");
        fwrite(STDERR, "Use executeQuery()/executeStatement() with named parameters and explicit\n");
        fwrite(STDERR, "types. An (int) cast is accepted for integer columns; a (string) cast is\n");
        fwrite(STDERR, "not a defence. Identifier interpolation (table/column names) is allowed.\n\n");

        foreach ($violations as $violation) {
            fwrite(STDERR, sprintf(
                " - %s:%d\n     value:   %s\n     context: %s\n",
                $violation['file'],
                $violation['line'],
                $violation['expression'],
                $violation['context']
            ));
        }
    }

    /**
     * @param list<string>|null $relativePaths
     *
     * @return list<string>
     */
    private function resolveFiles(string $root, ?array $relativePaths): array
    {
        if ($relativePaths !== null) {
            $files = [];
            foreach ($relativePaths as $relativePath) {
                $normalized = str_replace('\\', '/', trim($relativePath));
                if ($normalized === '' || !str_ends_with($normalized, '.php')) {
                    continue;
                }
                if ($this->isWhitelistedPath($normalized)) {
                    continue;
                }
                $files[] = $normalized;
            }

            return $files;
        }

        $files = [];
        foreach (glob($root . '/*.php') ?: [] as $entryPointPath) {
            $files[] = basename($entryPointPath);
        }

        foreach (self::SCAN_ROOTS as $relativeRoot) {
            $absoluteRoot = $root . DIRECTORY_SEPARATOR . $relativeRoot;
            if (!is_dir($absoluteRoot)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absoluteRoot, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!($file instanceof \SplFileInfo) || $file->getExtension() !== 'php') {
                    continue;
                }
                $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                if ($this->isWhitelistedPath($relativePath)) {
                    continue;
                }
                $files[] = $relativePath;
            }
        }

        return $files;
    }

    private function isWhitelistedPath(string $relativePath): bool
    {
        return in_array($relativePath, self::ALLOWED_PATHS, true);
    }

    /**
     * @return list<array{file: string, line: int, expression: string, context: string}>
     */
    private function collectFileViolations(string $absolutePath, string $relativePath): array
    {
        $contents = file_get_contents($absolutePath);
        if ($contents === false || !preg_match(self::SQL_PATTERN, $contents)) {
            return [];
        }

        $tokens = @token_get_all($contents);
        $tokenCount = count($tokens);
        $violations = [];
        $integerVariables = $this->collectIntegerCastVariables($contents);

        for ($index = 0; $index < $tokenCount; $index++) {
            $parsed = $this->readInterpolatedString($tokens, $index, $tokenCount);
            if ($parsed === null) {
                continue;
            }

            [$text, $slots, $line, $endIndex] = $parsed;
            $index = $endIndex;

            if ($slots === [] || !$this->looksLikeSql($text)) {
                continue;
            }

            foreach ($slots as $position => $expression) {
                $marker = "\x00" . $position . "\x00";
                $offset = strpos($text, $marker);
                if ($offset === false) {
                    continue;
                }

                $before = substr($text, 0, $offset);
                if (preg_match(self::IDENTIFIER_POSITION_PATTERN, $before) === 1) {
                    continue;
                }
                if (preg_match(self::VALUE_POSITION_PATTERN, $before) !== 1) {
                    continue;
                }
                if (preg_match(self::SAFE_EXPRESSION_PATTERN, $expression) === 1) {
                    continue;
                }
                if ($this->isProvenIntegerVariable($expression, $integerVariables)) {
                    continue;
                }

                $violations[] = [
                    'file' => $relativePath,
                    'line' => $line,
                    'expression' => trim($expression),
                    'context' => $this->summarizeContext($text, $offset),
                ];
            }
        }

        return $violations;
    }

    /**
     * A string is only interesting when it reads like a query rather than like
     * HTML that happens to contain the word "select".
     */
    private function looksLikeSql(string $text): bool
    {
        if (preg_match(self::SQL_PATTERN, $text) !== 1) {
            return false;
        }

        return preg_match('/<\s*\/?\s*[a-z][a-z0-9]*(\s|>|\/)/i', $text) !== 1;
    }

    /**
     * Read a double-quoted or heredoc string, replacing each interpolated
     * expression with a positional marker.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{0: string, 1: list<string>, 2: int, 3: int}|null
     */
    private function readInterpolatedString(array $tokens, int $index, int $tokenCount): ?array
    {
        $token = $tokens[$index];
        $isHeredoc = is_array($token) && $token[0] === T_START_HEREDOC;
        if (!$isHeredoc && $token !== '"') {
            return null;
        }

        $line = is_array($token) ? (int) $token[2] : 0;
        if ($line === 0) {
            for ($back = $index; $back >= 0; $back--) {
                if (is_array($tokens[$back])) {
                    $line = (int) $tokens[$back][2];
                    break;
                }
            }
        }

        $text = '';
        $slots = [];
        $cursor = $index + 1;

        for (; $cursor < $tokenCount; $cursor++) {
            $current = $tokens[$cursor];

            if ($current === '"' || (is_array($current) && $current[0] === T_END_HEREDOC)) {
                break;
            }

            if (is_array($current) && $current[0] === T_ENCAPSED_AND_WHITESPACE) {
                $text .= $current[1];
                continue;
            }

            if (is_array($current) && $current[0] === T_VARIABLE) {
                $expression = $current[1];
                $cursor = $this->consumeVariableSuffix($tokens, $cursor + 1, $tokenCount, $expression) - 1;
                $text .= "\x00" . count($slots) . "\x00";
                $slots[] = $expression;
                continue;
            }

            if (
                $current === '{'
                || (is_array($current) && in_array($current[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))
            ) {
                $expression = '';
                $depth = 1;
                $cursor++;
                while ($cursor < $tokenCount && $depth > 0) {
                    $inner = $tokens[$cursor];
                    if ($inner === '{') {
                        $depth++;
                    } elseif ($inner === '}') {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    }
                    $expression .= is_array($inner) ? $inner[1] : $inner;
                    $cursor++;
                }
                $text .= "\x00" . count($slots) . "\x00";
                $slots[] = $expression;
                continue;
            }

            $text .= is_array($current) ? ($current[1] ?? '') : (string) $current;
        }

        return [$text, $slots, $line, $cursor];
    }

    /**
     * Absorb array and property access that follows a simple $variable inside
     * an interpolated string.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function consumeVariableSuffix(array $tokens, int $cursor, int $tokenCount, string &$expression): int
    {
        while ($cursor < $tokenCount) {
            $next = $tokens[$cursor];

            if ($next === '[') {
                $depth = 1;
                $expression .= '[';
                $cursor++;
                while ($cursor < $tokenCount && $depth > 0) {
                    $inner = $tokens[$cursor];
                    if ($inner === '[') {
                        $depth++;
                    } elseif ($inner === ']') {
                        $depth--;
                    }
                    $expression .= is_array($inner) ? $inner[1] : $inner;
                    $cursor++;
                }
                continue;
            }

            if (is_array($next) && $next[0] === T_OBJECT_OPERATOR) {
                $expression .= '->';
                $cursor++;
                if ($cursor < $tokenCount && is_array($tokens[$cursor])) {
                    $expression .= $tokens[$cursor][1];
                    $cursor++;
                }
                continue;
            }

            break;
        }

        return $cursor;
    }

    /**
     * Variables whose every assignment in this file applies an integer cast.
     *
     * The cast usually sits at the assignment rather than at the query, so a
     * check that only looked at the interpolation site would report values
     * that are provably integers. Any assignment without a cast disqualifies
     * the variable, so a later reassignment cannot smuggle a string past this.
     *
     * @return array<string, true>
     */
    private function collectIntegerCastVariables(string $contents): array
    {
        $assignments = [];
        $castPattern = '/\(\s*(int|integer|float|double)\s*\)|\b(intval|floatval)\s*\(|FILTER_VALIDATE_INT/i';
        // A plain integer literal such as `$id = 0;` is just as provably not a
        // string, but has no cast to look for.
        $literalPattern = '/^\s*-?\d+\s*$/';

        foreach (preg_split("/\r\n|\n|\r/", $contents) ?: [] as $line) {
            if (
                preg_match_all(
                    '/\$([A-Za-z_][A-Za-z0-9_]*)\s*=(?!=)/',
                    $line,
                    $matches,
                    PREG_OFFSET_CAPTURE
                ) < 1
            ) {
                continue;
            }

            foreach ($matches[1] as $position => $match) {
                $name = (string) $match[0];

                // Examine this assignment's own right-hand side, not the whole
                // line: several assignments can share a line, and a cast in one
                // of them says nothing about the others. The offset of the full
                // match covers "$name =", so the value starts after it.
                $fullMatch = $matches[0][$position];
                $valueStart = (int) $fullMatch[1] + strlen((string) $fullMatch[0]);
                $rightHandSide = substr($line, $valueStart);
                $terminator = strpos($rightHandSide, ';');
                if ($terminator !== false) {
                    $rightHandSide = substr($rightHandSide, 0, $terminator);
                }

                $isCast = preg_match($castPattern, $rightHandSide) === 1
                    || preg_match($literalPattern, $rightHandSide) === 1;

                // One assignment without a cast is enough to disqualify the
                // variable, whichever order the assignments appear in.
                $assignments[$name] = ($assignments[$name] ?? true) && $isCast;
            }
        }

        return array_filter($assignments, static fn (bool $safe): bool => $safe);
    }

    /**
     * @param array<string, true> $integerVariables
     */
    private function isProvenIntegerVariable(string $expression, array $integerVariables): bool
    {
        // Only a bare $variable can be traced this way; an array element or a
        // property has no single assignment to look at.
        if (preg_match('/^\s*\$([A-Za-z_][A-Za-z0-9_]*)\s*$/', $expression, $match) !== 1) {
            return false;
        }

        return isset($integerVariables[$match[1]]);
    }

    private function summarizeContext(string $text, int $offset): string
    {
        $window = substr($text, max(0, $offset - 45), 95);
        $window = preg_replace('/\x00(\d+)\x00/', '{\1}', $window) ?? $window;

        return trim((string) preg_replace('/\s+/', ' ', $window));
    }
}
