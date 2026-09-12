<?php

declare(strict_types=1);

namespace Lotgd\QA;

/**
 * Detect addslashes() usage when building SQL in core/refactored code.
 *
 * Policy:
 *  - SQL escaping must be done at the query sink via parameterized DBAL calls.
 *  - Global/pre-escaped values must not be used for SQL construction.
 *  - Legacy compatibility wrappers may still expose escaped values, but core
 *    paths must not rely on that behavior.
 *
 * Baseline maintenance:
 *  - Adding a baseline entry requires a justification in metadata (`reason`)
 *    plus traceability fields (`owner`, `target_removal_version`).
 *  - Baseline removals are preferred whenever a call site is migrated to
 *    parameterized DBAL queries.
 */
final class SqlAddslashesUsageCheck
{
    /**
     * @var list<string>
     */
    private const SCAN_ROOTS = [
        'pages',
        'src',
    ];

    /**
     * Scan the PHP files that sit directly in the repository root as well.
     *
     * The directory roots above missed every legacy entry point, which is how
     * gamelog.php kept building its category filter with addslashes() long after
     * the rest of the tree had moved to bound parameters. Only the top level is
     * scanned: install/ and vendor/ are deliberately out of scope.
     */
    private const SCAN_ROOT_FILES = true;

    /**
     * @var list<string>
     */
    private const SQL_KEYWORDS = [
        'select',
        'insert',
        'update',
        'delete',
        'replace',
        'where',
        'values',
        'set',
    ];

    /**
     * @var list<string>
     */
    private const ALLOWED_PATHS = [
        // Self-exclusion so policy examples in this checker are not flagged.
        'src/Lotgd/QA/SqlAddslashesUsageCheck.php',
    ];

    /**
     * Exact baseline for known legacy SQL addslashes() usage.
     *
     * Entry key format: "{relative_file_path}:{line_number}".
     * Hash algorithm: sha256 of the normalized source line returned by
     * self::normalizeLineForBaseline().
     *
     * @var array<string,array{
     *     hash: string,
     *     reason: string,
     *     owner: string,
     *     target_removal_version: string
     * }>
     */
    private const LEGACY_SQL_ADDSLASHES_BASELINE = [
        // Baseline intentionally empty after staged Doctrine migrations.
        // Keep array entry format documented above when temporary exceptions
        // are unavoidable for legacy-only compatibility paths.
    ];

    /**
     * @return list<string> Human-readable violations in "file:line:text" format.
     */
    public function collectViolations(string $repositoryRoot): array
    {
        $violations = [];

        if (self::SCAN_ROOT_FILES) {
            foreach ((array) glob(rtrim($repositoryRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.php') as $rootFile) {
                if (!is_string($rootFile) || !is_file($rootFile)) {
                    continue;
                }

                $relativePath = basename($rootFile);
                if ($this->isWhitelistedPath($relativePath)) {
                    continue;
                }

                foreach ($this->collectFileViolations($rootFile, $relativePath) as $violation) {
                    $violations[] = $violation;
                }
            }
        }

        foreach (self::SCAN_ROOTS as $relativeRoot) {
            $absoluteRoot = rtrim($repositoryRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relativeRoot;
            if (!is_dir($absoluteRoot)) {
                continue;
            }

            $directoryIterator = new \RecursiveDirectoryIterator($absoluteRoot, \FilesystemIterator::SKIP_DOTS);
            $iterator = new \RecursiveIteratorIterator($directoryIterator);

            foreach ($iterator as $file) {
                if (!($file instanceof \SplFileInfo) || $file->getExtension() !== 'php') {
                    continue;
                }

                $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen(rtrim($repositoryRoot, DIRECTORY_SEPARATOR)) + 1));
                if ($this->isWhitelistedPath($relativePath)) {
                    continue;
                }

                foreach ($this->collectFileViolations($file->getPathname(), $relativePath) as $violation) {
                    $violations[] = $violation;
                }
            }
        }

        sort($violations);

        return $violations;
    }

    public function run(string $repositoryRoot): int
    {
        $violations = $this->collectViolations($repositoryRoot);
        if ($violations === []) {
            echo "SQL addslashes() usage check passed.\n";
            return 0;
        }

        fwrite(STDERR, "Detected addslashes() usage in SQL-building contexts.\n");
        fwrite(STDERR, "Use executeQuery()/executeStatement() with bound parameters and explicit types instead.\n");
        foreach ($violations as $violation) {
            fwrite(STDERR, " - {$violation}\n");
        }

        return 1;
    }

    private function isWhitelistedPath(string $relativePath): bool
    {
        foreach (self::ALLOWED_PATHS as $allowedPath) {
            if ($relativePath === $allowedPath) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function collectFileViolations(string $absolutePath, string $relativePath): array
    {
        $contents = file_get_contents($absolutePath);
        if ($contents === false) {
            return [];
        }

        $tokens = token_get_all($contents);
        $lines = preg_split("/\r\n|\n|\r/", $contents) ?: [];
        $violations = [];

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'addslashes') {
                continue;
            }

            if (!$this->isFunctionCallToken($tokens, $index)) {
                continue;
            }

            $lineNumber = (int) $token[2];
            if (
                !$this->buildsSqlStatement($tokens, $index)
                && !$this->isSqlContext($lines, $lineNumber)
            ) {
                continue;
            }

            $lineText = trim($lines[$lineNumber - 1] ?? '');
            if ($this->isKnownLegacyViolation($relativePath, $lineNumber, $lineText)) {
                continue;
            }
            $violations[] = sprintf('%s:%d:%s', $relativePath, $lineNumber, $lineText);
        }

        return $violations;
    }

    private function isKnownLegacyViolation(string $relativePath, int $lineNumber, string $lineText): bool
    {
        $baselineKey = sprintf('%s:%d', $relativePath, $lineNumber);
        $baseline = self::LEGACY_SQL_ADDSLASHES_BASELINE[$baselineKey] ?? null;
        if ($baseline === null) {
            return false;
        }

        $lineHash = hash('sha256', $this->normalizeLineForBaseline($lineText));
        return hash_equals($baseline['hash'], $lineHash);
    }

    private function normalizeLineForBaseline(string $lineText): string
    {
        $trimmed = trim($lineText);
        return (string) preg_replace('/\s+/', ' ', $trimmed);
    }

    /**
     * @param list<string> $lines
     */
    private function isSqlContext(array $lines, int $lineNumber): bool
    {
        $start = max(1, $lineNumber - 6);
        $end = min(count($lines), $lineNumber + 12);
        $windowLines = array_slice($lines, $start - 1, $end - $start + 1);
        $window = strtolower(implode("\n", $windowLines));

        if (!$this->containsSqlKeywords($window)) {
            return false;
        }

        $lineText = strtolower($lines[$lineNumber - 1] ?? '');

        if (
            $this->containsSqlSinkMarker($window)
            && (
                $this->containsSqlKeywords($lineText)
                || str_contains($lineText, '$sql')
                || $this->hasDirectNearbySqlLine($lines, $lineNumber)
            )
        ) {
            return true;
        }

        /**
         * Catch split SQL building patterns:
         *   $escaped = addslashes(...);
         *   ... (several lines later)
         *   $sql = "... '$escaped' ...";
         */
        $assignedVariable = $this->extractAssignedVariable($lines[$lineNumber - 1] ?? '');
        if ($assignedVariable === null) {
            return false;
        }

        foreach ($windowLines as $windowLine) {
            $normalizedLine = strtolower($windowLine);
            if (!str_contains($normalizedLine, '$sql')) {
                continue;
            }
            if (!str_contains($windowLine, '$' . $assignedVariable)) {
                continue;
            }
            // At this point we know:
            //  - The surrounding window contains SQL keywords (line 205),
            //  - This line is part of that window,
            //  - It references both $sql and the escaped variable.
            // Treat this as SQL context even if this specific line has no keyword.
            return true;
        }

        return false;
    }

    /**
     * Detect direct SQL assignment/building very close to addslashes().
     *
     * @param list<string> $lines
     */
    private function hasDirectNearbySqlLine(array $lines, int $lineNumber): bool
    {
        $start = max(1, $lineNumber - 1);
        $end = min(count($lines), $lineNumber + 1);
        for ($line = $start; $line <= $end; $line++) {
            $normalizedLine = strtolower($lines[$line - 1] ?? '');
            if (!str_contains($normalizedLine, '$sql')) {
                continue;
            }
            if ($this->containsSqlKeywords($normalizedLine)) {
                return true;
            }
        }

        return false;
    }

    private function containsSqlKeywords(string $text): bool
    {
        $keywordsPattern = '/\b(' . implode('|', self::SQL_KEYWORDS) . ')\b/';
        return preg_match($keywordsPattern, $text) === 1;
    }

    /**
     * Is this addslashes() call being concatenated into a SQL statement?
     *
     * Answered from the token stream rather than the line text, and that is the
     * whole point. {@see self::isSqlContext()} reads the surrounding lines and
     * needs a sink -- `$sql`, `Database::query`, `executeQuery(`,
     * `executeStatement(` -- within six lines above and twelve below, so a
     * helper that builds a query and hands it back is invisible to it: the
     * execution is at the caller, usually in another file.
     *
     *     return "SELECT * FROM t WHERE n = '" . addslashes($n) . "'";
     *
     * A line-based test for that shape was tried first and was wrong twice
     * over. It could not see a statement whose opening quote sits on an earlier
     * line, which is how most multi-line SQL here is written; and it treated
     * any quote on the line as a string delimiter, so prose reporting
     *
     *     $msg = "Choose 'delete' to remove " . addslashes($n);
     *
     * as a query because of the apostrophes around the word. Tokens settle
     * both: a string literal is one token whatever lines it spans, and the
     * quotes inside it are content rather than delimiters.
     */
    private function buildsSqlStatement(array $tokens, int $index): bool
    {
        $fragments = $this->concatenatedStringFragments($tokens, $index);

        return $fragments !== '' && $this->fragmentsOpenSqlStatement($fragments);
    }

    /**
     * The string literals this call is joined to by `.`, in source order.
     *
     * Walks outward from the addslashes() call in both directions for as long
     * as the expression continues to be a concatenation, collecting literal
     * text and ignoring everything else. Walking backwards matters as much as
     * forwards: the statement that identifies a query is almost always the
     * fragment *before* the escaped value.
     *
     * @param list<array<int, int|string>|string> $tokens
     */
    private function concatenatedStringFragments(array $tokens, int $index): string
    {
        $before = [];
        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            $token = $tokens[$cursor];
            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }
            if ($token === '.') {
                continue;
            }
            if (is_array($token) && $this->isStringFragment($token[0])) {
                array_unshift($before, $this->literalText($token));
                continue;
            }
            // Anything else ends the concatenation -- an assignment, a return,
            // an opening bracket, another call.
            break;
        }

        $after = [];
        for ($cursor = $this->endOfCall($tokens, $index) + 1; $cursor < count($tokens); $cursor++) {
            $token = $tokens[$cursor];
            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }
            if ($token === '.') {
                continue;
            }
            if (is_array($token) && $this->isStringFragment($token[0])) {
                $after[] = $this->literalText($token);
                continue;
            }
            break;
        }

        return implode('', $before) . ' ' . implode('', $after);
    }

    /**
     * A token that carries literal string text.
     *
     * T_CONSTANT_ENCAPSED_STRING is a whole single- or double-quoted string
     * with no interpolation; T_ENCAPSED_AND_WHITESPACE is the literal run
     * between interpolations inside one that has them, and inside a heredoc.
     */
    private function isStringFragment(int $tokenType): bool
    {
        return $tokenType === T_CONSTANT_ENCAPSED_STRING
            || $tokenType === T_ENCAPSED_AND_WHITESPACE;
    }

    /**
     * @param array<int, int|string> $token
     */
    private function literalText(array $token): string
    {
        $text = (string) $token[1];

        if ($token[0] === T_CONSTANT_ENCAPSED_STRING && $text !== '') {
            $quote = $text[0];
            if ($quote === '"' || $quote === "'") {
                $text = substr($text, 1, -1);
            }
        }

        return $text;
    }

    /**
     * The index of the closing parenthesis of the call starting at $index.
     *
     * Counted by depth rather than found by the next ')', because the escaped
     * expression can carry calls of its own.
     *
     * @param list<array<int, int|string>|string> $tokens
     */
    private function endOfCall(array $tokens, int $index): int
    {
        $count = count($tokens);
        $depth = 0;
        for ($cursor = $index; $cursor < $count; $cursor++) {
            if ($tokens[$cursor] === '(') {
                $depth++;
                continue;
            }
            if ($tokens[$cursor] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $cursor;
                }
            }
        }

        return $count - 1;
    }

    /**
     * Does the assembled literal text open a SQL statement?
     *
     * Anchored at the start on purpose. "Contains a SQL keyword" was the first
     * version of this test and reported prose that merely mentions select, set
     * or delete -- two false positives out of four probes. A query string
     * begins with its verb; a sentence begins with a word of its own.
     */
    private function fragmentsOpenSqlStatement(string $fragments): bool
    {
        return preg_match(
            '/^\s*(select|insert|update|delete|replace)\b/i',
            ltrim($fragments)
        ) === 1;
    }

    private function containsSqlSinkMarker(string $text): bool
    {
        return str_contains($text, '$sql')
            || str_contains($text, 'database::query')
            || str_contains($text, 'executequery(')
            || str_contains($text, 'executestatement(');
    }

    private function extractAssignedVariable(string $lineText): ?string
    {
        if (preg_match('/\$(?<variable>[A-Za-z_]\w*)\s*=\s*.*addslashes\s*\(/i', $lineText, $matches) !== 1) {
            return null;
        }

        return $matches['variable'];
    }

    /**
     * @param list<array<int, int|string>|string> $tokens
     */
    private function isFunctionCallToken(array $tokens, int $index): bool
    {
        $previousToken = $this->findPreviousSignificantToken($tokens, $index);
        if (is_array($previousToken) && in_array($previousToken[0], [T_FUNCTION, T_FN, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
            return false;
        }
        if ($previousToken === '->' || $previousToken === '::') {
            return false;
        }

        $nextToken = $this->findNextSignificantToken($tokens, $index);
        return $nextToken === '(';
    }

    /**
     * @param list<array<int, int|string>|string> $tokens
     */
    private function findPreviousSignificantToken(array $tokens, int $index): array|string|null
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }

    /**
     * @param list<array<int, int|string>|string> $tokens
     */
    private function findNextSignificantToken(array $tokens, int $index): array|string|null
    {
        $count = count($tokens);
        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }
}
