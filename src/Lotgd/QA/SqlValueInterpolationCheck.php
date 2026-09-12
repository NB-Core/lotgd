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
     * Expressions that read straight from the request.
     *
     * Used only by the concatenation pass. Interpolation reports any variable
     * in value position, because by the time a value is in a variable its
     * provenance is gone; a concatenated slot is usually a call, and a call
     * says where it got its value. Keeping the concatenation rule to these
     * names is what makes it safe to run in CI -- see the note on
     * collectConcatenationViolations().
     */
    private const REQUEST_SOURCE_PATTERN = '/\b(Http::(get|post)|httpget|httppost)\s*\(|\$_(GET|POST|REQUEST|COOKIE|SERVER)\b/i';

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
                if ($this->isWhitelistedPath($normalized) || !$this->isInScanScope($normalized)) {
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
     * Is this a file the full-tree scan would look at?
     *
     * --changed-since used to accept any .php path the diff named, so it
     * scanned files the audit mode never reads -- tests above all. The two
     * modes disagreeing is a trap rather than extra coverage: a finding that
     * the audit will not report should not be able to fail CI either, and the
     * first file to fall into it was this checker's own test fixtures, which
     * contain the bad shapes on purpose.
     */
    private function isInScanScope(string $relativePath): bool
    {
        if (!str_contains($relativePath, '/')) {
            return true; // a root-level entry point
        }

        foreach (self::SCAN_ROOTS as $root) {
            if (str_starts_with($relativePath, $root . '/')) {
                return true;
            }
        }

        return false;
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

            [$text, $slots, $line, $endIndex, $slotLines] = $parsed;
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
                    'line' => $slotLines[$position] ?? $line,
                    'expression' => trim($expression),
                    'context' => $this->summarizeContext($text, $offset),
                ];
            }
        }

        foreach ($this->collectConcatenationViolations($tokens, $tokenCount, $relativePath) as $violation) {
            $violations[] = $violation;
        }

        return $violations;
    }

    /**
     * Request values joined into a query with `.` rather than interpolated.
     *
     * The interpolation pass above reads variables *inside* a string literal.
     * A value concatenated around one is a separate token entirely, so
     *
     *     "DELETE FROM news WHERE newsid='" . Http::get('newsid') . "'"
     *
     * was invisible to it -- a gap found while auditing the tests that had
     * been guarding this shape by searching login.php and superuser.php for
     * its exact spelling.
     *
     * Deliberately narrower than the interpolation rule: the expression must
     * *name* a request source. That was measured rather than assumed. Asking
     * only "is this in value position" over the whole tree turns every
     * `Nav::add("page.php?x=" . $v)` into a finding, because a URL query
     * string has an `=` before its slot too; the assembled text is put
     * through looksLikeSql() for exactly that reason, and the one candidate
     * in the tree today is such a URL and is correctly passed over. With both
     * gates the tree reports nothing, so the rule needs no baseline.
     *
     * The limit is worth stating plainly rather than discovering later: a
     * request value assigned to a variable first, and that variable
     * concatenated, is not reported here. The interpolation pass catches it
     * when the variable is interpolated; concatenated, it is still a gap.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return list<array{file: string, line: int, expression: string, context: string}>
     */
    private function collectConcatenationViolations(array $tokens, int $tokenCount, string $relativePath): array
    {
        $violations = [];

        for ($index = 0; $index < $tokenCount; $index++) {
            $token = $tokens[$index];
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $dotIndex = $this->skipTrivia($tokens, $index + 1, $tokenCount);
            if (($tokens[$dotIndex] ?? null) !== '.') {
                continue;
            }

            [$expression, $afterIndex, $line] = $this->readConcatenatedExpression($tokens, $dotIndex + 1, $tokenCount);
            if ($expression === '' || preg_match(self::REQUEST_SOURCE_PATTERN, $expression) !== 1) {
                continue;
            }
            if (preg_match(self::SAFE_EXPRESSION_PATTERN, $expression) === 1) {
                continue;
            }

            if (!$this->looksLikeSql($this->statementLiterals($tokens, $index, $tokenCount))) {
                continue;
            }

            // No position test here, unlike the interpolation pass above, and
            // the difference is deliberate. There, a slot after FROM or INTO
            // is usually Database::prefix() and interpolating a table name is
            // normal, so identifiers are passed over and only value position
            // is reported. Here the expression has already been established
            // to read straight from the request, and a request value has no
            // business anywhere in a statement -- `"ORDER BY " . Http::get()`
            // and `"SELECT " . Http::get()` are injections as surely as one
            // inside quotes. Measured before relaxing it: with the position
            // tests and without, the tree reports the same 118 findings, so
            // the stricter reading costs nothing and covers more.
            $literals = $this->statementLiterals($tokens, $index, $tokenCount);
            $violations[] = [
                'file' => $relativePath,
                'line' => $line,
                'expression' => trim($expression),
                'context' => $this->summarizeContext($literals . "\x00", strlen($literals)),
            ];
        }

        return $violations;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function skipTrivia(array $tokens, int $index, int $tokenCount): int
    {
        while (
            $index < $tokenCount
            && is_array($tokens[$index])
            && in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ) {
            $index++;
        }

        return $index;
    }

    /**
     * The concatenated expression starting at $index, to the next top-level `.`.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private function readConcatenatedExpression(array $tokens, int $index, int $tokenCount): array
    {
        $expression = '';
        $depth = 0;
        $line = 0;

        for (; $index < $tokenCount; $index++) {
            $token = $tokens[$index];
            $text = is_array($token) ? $token[1] : $token;

            if ($line === 0 && is_array($token) && !in_array($token[0], [T_WHITESPACE, T_COMMENT], true)) {
                $line = (int) $token[2];
            }

            if ($text === '(' || $text === '[') {
                $depth++;
            } elseif ($text === ')' || $text === ']') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($depth === 0 && in_array($text, ['.', ';', ','], true)) {
                break;
            }

            $expression .= $text;
        }

        return [trim($expression), $index, $line];
    }

    /**
     * Every string literal in the statement the token at $index belongs to.
     *
     * Scoped to the statement rather than to the run of literals adjacent to
     * the slot, and that distinction is load-bearing. Walking only outward
     * from the slot stops at the first token that is not a literal, so in
     *
     *     "SELECT * FROM t WHERE a = '" . Http::get('a')
     *         . "' AND b = '" . Http::get('b') . "'"
     *
     * the second slot sees only "' AND b = '" and "'", neither of which
     * carries a keyword -- the statement was reported once and its second
     * injected value passed unnoticed. Found by a mutation that would not
     * die: disabling the outward walk changed nothing, because no case
     * needed it.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function statementLiterals(array $tokens, int $index, int $tokenCount): string
    {
        $start = $index;
        $depth = 0;
        for ($cursor = $index; $cursor >= 0; $cursor--) {
            $token = $tokens[$cursor];
            $text = is_array($token) ? $token[1] : $token;

            if ($text === ')' || $text === ']') {
                $depth++;
            } elseif ($text === '(' || $text === '[') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($depth === 0 && in_array($text, [';', '{', '}'], true)) {
                break;
            } elseif (is_array($token) && $token[0] === T_OPEN_TAG) {
                break;
            }

            $start = $cursor;
        }

        $literals = '';
        $depth = 0;
        for ($cursor = $start; $cursor < $tokenCount; $cursor++) {
            $token = $tokens[$cursor];
            $text = is_array($token) ? $token[1] : $token;

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

            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $literals .= $this->unquote($token[1]);
            }
        }

        return $literals;
    }

    private function unquote(string $literal): string
    {
        if (strlen($literal) < 2) {
            return $literal;
        }

        $quote = $literal[0];

        return ($quote === '"' || $quote === "'") ? substr($literal, 1, -1) : $literal;
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
     * The line is recorded per slot rather than once for the string. A
     * multi-line statement interpolates on the line the expression sits on, and
     * that is the line --changed-since matches against: attributing every slot
     * to where the string opened meant a value added on a continuation line of
     * an otherwise untouched call was filtered out as "not added by this
     * change", which is the common shape for this codebase's executeStatement()
     * calls.
     *
     * @return array{0: string, 1: list<string>, 2: int, 3: int, 4: list<int>}|null
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
        $slotLines = [];
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
                $slotLine = (int) $current[2];
                $cursor = $this->consumeVariableSuffix($tokens, $cursor + 1, $tokenCount, $expression) - 1;
                $text .= "\x00" . count($slots) . "\x00";
                $slots[] = $expression;
                $slotLines[] = $slotLine;
                continue;
            }

            if (
                $current === '{'
                || (is_array($current) && in_array($current[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))
            ) {
                $expression = '';
                $slotLine = is_array($current) ? (int) $current[2] : 0;
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
                $slotLines[] = $slotLine > 0 ? $slotLine : $line;
                continue;
            }

            $text .= is_array($current) ? ($current[1] ?? '') : (string) $current;
        }

        return [$text, $slots, $line, $cursor, $slotLines];
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
