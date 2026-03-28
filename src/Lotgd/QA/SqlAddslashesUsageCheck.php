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
     * Temporary baseline for known legacy usages.
     *
     * Keep this list narrow and remove entries as call sites are migrated.
     *
     * @var array<string,list<string>>
     */
    private const ALLOWED_SQL_ADDSLASHES_PATTERNS = [
        'pages/clan/applicant_new.php' => ['$clanname = addslashes($clanname);'],
        'pages/clan/clan_motd.php' => [
            "clanmotd='\" . addslashes(\$clanmotd)",
            "clandesc='\" . addslashes(mb_substr(stripslashes(\$clandesc)",
        ],
        'pages/clan/clan_withdraw.php' => ["subject='\" . addslashes(serialize(\$withdraw_subj))"],
        'src/Lotgd/Accounts.php' => [
            "\" SET output='\" . addslashes(gzcompress(\$session['output'], 1))",
            "VALUES ({\$session['user']['acctid']},'\" . addslashes(gzcompress(\$session['output'], 1))",
        ],
        'src/Lotgd/PlayerFunctions.php' => [
            "addslashes(implode(',', \$players))",
        ],
        'src/Lotgd/Translator.php' => [
            "VALUES ('\" .  addslashes(\$indata) . \"', '\"",
        ],
        'src/Lotgd/Motd.php' => [
            '$body = addslashes(serialize($data));',
        ],
        'src/Lotgd/Pvp.php' => [
            '$loc = addslashes($location);',
        ],
    ];

    /**
     * @return list<string> Human-readable violations in "file:line:text" format.
     */
    public function collectViolations(string $repositoryRoot): array
    {
        $violations = [];

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
            if (!$this->isSqlContext($lines, $lineNumber)) {
                continue;
            }

            $lineText = trim($lines[$lineNumber - 1] ?? '');
            if ($this->isKnownLegacyViolation($relativePath, $lineText)) {
                continue;
            }
            $violations[] = sprintf('%s:%d:%s', $relativePath, $lineNumber, $lineText);
        }

        return $violations;
    }

    private function isKnownLegacyViolation(string $relativePath, string $lineText): bool
    {
        $knownPatterns = self::ALLOWED_SQL_ADDSLASHES_PATTERNS[$relativePath] ?? [];
        foreach ($knownPatterns as $knownPattern) {
            if (str_contains($lineText, $knownPattern)) {
                return true;
            }
        }

        return false;
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
