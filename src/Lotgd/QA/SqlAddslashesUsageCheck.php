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
        'pages/clan/applicant_new.php:28' => [
            'hash' => '03502d5628a6cd419d213941ec533826328d4d5260c888a6b0bd0b4817c95cd2',
            'reason' => 'Legacy clan application workflow still escapes clan names pre-DBAL migration.',
            'owner' => 'core-legacy-maintainers',
            'target_removal_version' => '3.0.0',
        ],
        'pages/clan/clan_motd.php:28' => [
            'hash' => '7c0b62faa5c75bde868acc4e5e380f5ee2c583ce5ca903b28ff61aedcc54af29',
            'reason' => 'Legacy clan MOTD update path still uses interpolated SQL string construction.',
            'owner' => 'core-legacy-maintainers',
            'target_removal_version' => '3.0.0',
        ],
        'pages/clan/clan_motd.php:42' => [
            'hash' => 'cc73112aa7f7e0327d0f5b2d56498219afa252bce010af1bd8595f18daecf631',
            'reason' => 'Legacy clan description update path remains pre-DBAL and escaped inline.',
            'owner' => 'core-legacy-maintainers',
            'target_removal_version' => '3.0.0',
        ],
        'pages/clan/clan_withdraw.php:55' => [
            'hash' => '814d0b822836c513c98c315bab7ba4a9c35014c0de20dd707a0d5a6ef6e44f93',
            'reason' => 'Legacy serialized withdrawal subject is still injected into raw SQL.',
            'owner' => 'core-legacy-maintainers',
            'target_removal_version' => '3.0.0',
        ],
        'src/Lotgd/Motd.php:305' => [
            'hash' => '864ec7fed5f8965ea8cd9b0df737e7351526d91e6c671c2a58a9378ae59e5b20',
            'reason' => 'MOTD poll payload is serialized and escaped before legacy persistence flow.',
            'owner' => 'core-legacy-maintainers',
            'target_removal_version' => '3.0.0',
        ],
        'src/Lotgd/PlayerFunctions.php:269' => [
            'hash' => '9a9e396bb26a75acea49a054ddd88aac4109bb9458e4dec32a0900e037a01b8c',
            'reason' => 'Legacy IN-clause account list is still assembled as a raw SQL string.',
            'owner' => 'core-legacy-maintainers',
            'target_removal_version' => '3.0.0',
        ],
        'src/Lotgd/Pvp.php:309' => [
            'hash' => '210cbfa91dd74d70e294d4ecfa25693c871df1b063e1dc0501b8062dc0af379b',
            'reason' => 'PVP location storage still assigns escaped values before SQL assignment.',
            'owner' => 'core-legacy-maintainers',
            'target_removal_version' => '3.0.0',
        ],
        'src/Lotgd/Translator.php:167' => [
            'hash' => '64a1ec84cf168bb732d1ea1f29dea281bd15584cf2406a50f83544d51963d9d5',
            'reason' => 'Translator fallback inserts untranslated text via legacy interpolated SQL.',
            'owner' => 'core-legacy-maintainers',
            'target_removal_version' => '3.0.0',
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
