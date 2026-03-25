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
    private const DISALLOWED_PATTERNS = [
        '/\bhttpget\s*\(/i',
        '/\bhttppost\s*\(/i',
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

                $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES);
                if ($lines === false) {
                    continue;
                }

                foreach ($lines as $lineNumber => $line) {
                    $trimmed = ltrim($line);
                    if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')) {
                        continue;
                    }

                    foreach (self::DISALLOWED_PATTERNS as $pattern) {
                        if (preg_match($pattern, $line) === 1) {
                            $violations[] = sprintf(
                                '%s:%d:%s',
                                $relativePath,
                                $lineNumber + 1,
                                trim($line)
                            );
                            break;
                        }
                    }
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
}
