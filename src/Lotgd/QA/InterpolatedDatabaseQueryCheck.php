<?php

declare(strict_types=1);

namespace Lotgd\QA;

/**
 * Detect dynamic Database::query(...) calls in core Lotgd namespaces.
 *
 * Policy:
 *  - Prefer Doctrine DBAL prepared statements in core paths.
 *  - Database::query() remains allowed in explicitly whitelisted legacy files.
 *  - New usage in non-whitelisted core paths should be migrated to
 *    executeQuery()/executeStatement() with typed parameters.
 */
final class InterpolatedDatabaseQueryCheck
{
    /**
     * @var list<string>
     */
    private const SCAN_ROOTS = [
        'src/Lotgd',
    ];

    /**
     * @var list<string>
     */
    private const ALLOWED_PATHS = [
        // Self-exclusion so examples in this checker are not flagged.
        'src/Lotgd/QA/InterpolatedDatabaseQueryCheck.php',

        // Legacy-heavy core paths still in migration.
        'src/Lotgd/Modules.php',
        'src/Lotgd/Commentary.php',
        'src/Lotgd/Newday.php',
        'src/Lotgd/Pvp.php',
    ];

    /**
     * @return list<string>
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
            echo "Interpolated Database::query() usage check passed.\n";
            return 0;
        }

        fwrite(STDERR, "Detected dynamic Database::query() usage in src/Lotgd core paths.\n");
        fwrite(STDERR, "Use Doctrine executeQuery()/executeStatement() with bound parameters and types.\n");
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

        $tokenCount = count($tokens);
        for ($index = 0; $index < $tokenCount; $index++) {
            $token = $tokens[$index];
            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'Database') {
                continue;
            }

            $openParenIndex = 0;
            if (!$this->isStaticQueryCall($tokens, $index, $openParenIndex)) {
                continue;
            }

            if ($this->isFirstArgumentSafe($tokens, $openParenIndex)) {
                continue;
            }

            $lineNumber = (int) $token[2];
            $lineText = trim($lines[$lineNumber - 1] ?? '');
            $violations[] = sprintf('%s:%d:%s', $relativePath, $lineNumber, $lineText);
        }

        return $violations;
    }

    /**
     * @param list<array<int,int|string>|string> $tokens
     */
    private function isStaticQueryCall(array $tokens, int $index, int &$openParenIndex = 0): bool
    {
        $tokenCount = count($tokens);

        $i = $this->skipWhitespaceAndComments($tokens, $index + 1, $tokenCount);
        if ($i >= $tokenCount) {
            return false;
        }

        $doubleColonToken = $tokens[$i];
        $isDoubleColon = $doubleColonToken === '::'
            || (is_array($doubleColonToken) && ($doubleColonToken[0] ?? null) === T_DOUBLE_COLON);
        if (!$isDoubleColon) {
            return false;
        }

        $i = $this->skipWhitespaceAndComments($tokens, $i + 1, $tokenCount);
        if (
            $i >= $tokenCount
            || !is_array($tokens[$i])
            || ($tokens[$i][0] ?? null) !== T_STRING
            || strtolower((string) ($tokens[$i][1] ?? '')) !== 'query'
        ) {
            return false;
        }

        $i = $this->skipWhitespaceAndComments($tokens, $i + 1, $tokenCount);
        if ($i >= $tokenCount || $tokens[$i] !== '(') {
            return false;
        }

        $openParenIndex = $i;

        return true;
    }

    /**
     * Advance past whitespace and comment tokens.
     *
     * @param list<array<int,int|string>|string> $tokens
     */
    private function skipWhitespaceAndComments(array $tokens, int $start, int $tokenCount): int
    {
        while ($start < $tokenCount) {
            $token = $tokens[$start];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $start++;
                continue;
            }
            break;
        }

        return $start;
    }

    /**
     * Check whether the entire first argument to Database::query() is safe
     * (composed only of constant string literals, optionally concatenated).
     *
     * @param list<array<int,int|string>|string> $tokens
     * @param int $openParenIndex Index of the '(' token that opens the argument list.
     */
    private function isFirstArgumentSafe(array $tokens, int $openParenIndex): bool
    {
        $tokenCount = count($tokens);

        for ($i = $openParenIndex + 1; $i < $tokenCount; $i++) {
            $token = $tokens[$i];

            // Skip whitespace and comments.
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            // Closing paren or comma at the top level ends the first argument.
            if ($token === ')' || $token === ',') {
                return true;
            }

            // Constant string literals (single- or double-quoted without
            // interpolation) are safe.  Interpolated strings (tokenised as
            // '"', T_ENCAPSED_AND_WHITESPACE, etc.) and heredocs
            // (T_START_HEREDOC) will fall through to the unsafe return below.
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            // Dot (concatenation) of constant strings is safe.
            if ($token === '.') {
                continue;
            }

            // Everything else (variables, interpolated strings, heredocs,
            // function calls, etc.) is considered dynamic / unsafe.
            return false;
        }

        // Reached end of token stream without a closing paren – treat as safe
        // (malformed code; not our concern).
        return true;
    }
}
