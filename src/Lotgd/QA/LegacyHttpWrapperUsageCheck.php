<?php

declare(strict_types=1);

namespace Lotgd\QA;

/**
 * Detects legacy httpget()/httppost() helper usage in core/refactored code.
 *
 * Policy:
 *  - Core/refactored code must use Lotgd\Http directly.
 *  - Legacy wrappers are permitted only in explicitly whitelisted paths.
 */
final class LegacyHttpWrapperUsageCheck
{
    /**
     * @var list<string>
     */
    private const DISALLOWED_FUNCTIONS = [
        'httpget',
        'httppost',
    ];

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
    private const ALLOWED_ROOTS = [
        'lib/',
        'modules/',
        'install/',
        'tests/',
        'vendor/',
        'src/Lotgd/QA/LegacyHttpWrapperUsageCheck.php',
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

                $violations = array_merge($violations, $this->collectFileViolations($file->getPathname(), $relativePath));
            }
        }

        sort($violations);

        return $violations;
    }

    public function run(string $repositoryRoot): int
    {
        $violations = $this->collectViolations($repositoryRoot);
        if ($violations === []) {
            echo "Legacy HTTP wrapper usage check passed.\n";
            return 0;
        }

        fwrite(STDERR, "Legacy HTTP wrapper usage detected in core/refactored paths.\n");
        fwrite(STDERR, "Use Lotgd\\\\Http::* in core paths and keep httpget()/httppost() for legacy compatibility paths only.\n");
        foreach ($violations as $violation) {
            fwrite(STDERR, " - {$violation}\n");
        }

        return 1;
    }

    private function isWhitelistedPath(string $relativePath): bool
    {
        foreach (self::ALLOWED_ROOTS as $allowedRoot) {
            if (str_starts_with($relativePath, $allowedRoot)) {
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
        $lines = file($absolutePath, FILE_IGNORE_NEW_LINES) ?: [];
        $violations = [];

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            $functionName = strtolower($token[1]);
            if (!in_array($functionName, self::DISALLOWED_FUNCTIONS, true)) {
                continue;
            }

            if (!$this->isFunctionCallToken($tokens, $index)) {
                continue;
            }

            $lineNumber = (int) $token[2];
            $lineText = trim($lines[$lineNumber - 1] ?? '');
            $violations[] = sprintf('%s:%d:%s', $relativePath, $lineNumber, $lineText);
        }

        return $violations;
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
