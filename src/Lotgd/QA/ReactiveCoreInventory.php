<?php

declare(strict_types=1);

namespace Lotgd\QA;

/**
 * Inventory of the code paths the reactive-core work has to respect.
 *
 * This is a report, not a gate: it never fails the build. It lists, per file
 * and line, everything that a request transaction, partial rendering or a
 * realtime outbox would have to account for before it can be introduced:
 *
 *  - finish_path:         where a request ends and the account is saved
 *                         (saveUser, redirect, page/popup footers, exit/die).
 *  - accounts_write:      SQL that changes the accounts table outside of
 *                         Accounts::saveUser(), i.e. writes an optimistic
 *                         lock would have to cover.
 *  - transaction_breaker: statements that end or forbid an open transaction
 *                         under MySQL (LOCK TABLES, DDL, OPTIMIZE/ANALYZE/
 *                         REPAIR TABLE) and code that opens its own
 *                         transaction.
 *  - side_effect:         work that cannot be rolled back (e-mail, HTTP,
 *                         data cache writes).
 *  - inline_script:       markup that emits a <script> element, which a
 *                         partial swap would not run as-is.
 *
 * The scan is token based so comments and docblocks do not count. Matches are
 * deliberately broad; the report is the starting point for a manual review,
 * see docs/ReactiveCore-Inventory.md.
 */
final class ReactiveCoreInventory
{
    public const CATEGORY_FINISH_PATH = 'finish_path';
    public const CATEGORY_ACCOUNTS_WRITE = 'accounts_write';
    public const CATEGORY_TRANSACTION_BREAKER = 'transaction_breaker';
    public const CATEGORY_SIDE_EFFECT = 'side_effect';
    public const CATEGORY_INLINE_SCRIPT = 'inline_script';

    public const CATEGORIES = [
        self::CATEGORY_FINISH_PATH,
        self::CATEGORY_ACCOUNTS_WRITE,
        self::CATEGORY_TRANSACTION_BREAKER,
        self::CATEGORY_SIDE_EFFECT,
        self::CATEGORY_INLINE_SCRIPT,
    ];

    /**
     * Directories scanned below the repository root, in addition to the PHP
     * files that sit directly in the root.
     *
     * @var list<string>
     */
    private const SCAN_ROOTS = [
        'async',
        'lib',
        'pages',
        'src',
    ];

    /**
     * Path fragments never scanned. The checker excludes itself so its own
     * pattern strings are not reported.
     *
     * @var list<string>
     */
    private const EXCLUDED_FRAGMENTS = [
        '/vendor/',
        '/tools/',
        '/tests/',
        '/node_modules/',
        // Archived copy of the old DragonPrime modules in the modules
        // repository; it is not shipped and would count every module twice.
        '/_old_dragonprime_snapshot/',
        'src/Lotgd/QA/ReactiveCoreInventory.php',
    ];

    /**
     * Calls that end a request or persist the session user (lower-case).
     *
     * @var list<string>
     */
    private const FINISH_CALLS = [
        'saveuser',
        'redirect',
        'pagefooter',
        'page_footer',
        'popupfooter',
        'popup_footer',
    ];

    /**
     * Calls whose effect survives a database rollback (lower-case).
     *
     * @var array<string,string>
     */
    private const SIDE_EFFECT_CALLS = [
        'mail' => 'mail()',
        'systemmail' => 'systemMail()',
        'curl_exec' => 'curl_exec()',
        'fsockopen' => 'fsockopen()',
        'pullurl' => 'pullurl()',
        'pull' => 'PullUrl::pull()',
        'updatedatacache' => 'updatedatacache()',
        'invalidatedatacache' => 'invalidatedatacache()',
        'massinvalidate' => 'massinvalidate()',
    ];

    private const SQL_WRITE_PATTERN = '/\b(?:UPDATE|INSERT\s+(?:IGNORE\s+)?INTO|DELETE\s+FROM|REPLACE\s+INTO)\b/i';
    private const ACCOUNTS_TABLE_PATTERN = '/prefix\s*\(\s*[\'"]accounts[\'"]\s*\)|\baccounts\b(?!_)/i';
    // DDL and the table maintenance statements, all of which MySQL commits
    // implicitly.
    private const BREAKER_SQL_PATTERN = '/\b(?:LOCK\s+TABLES?|(?:CREATE|ALTER|DROP|TRUNCATE|RENAME|OPTIMIZE|ANALYZE|REPAIR)\s+TABLE)\b/i';
    private const INLINE_SCRIPT_PATTERN = '/<script\b/i';

    /**
     * Collect findings for the core tree and any extra directories.
     *
     * @param list<string> $extraRoots Absolute directories scanned in full,
     *                                 e.g. a checkout of the modules repository.
     *
     * @return list<array{file:string,line:int,category:string,detail:string}>
     */
    public function collect(string $repositoryRoot, array $extraRoots = []): array
    {
        $repositoryRoot = rtrim($repositoryRoot, DIRECTORY_SEPARATOR);
        $findings = [];

        foreach ((array) glob($repositoryRoot . DIRECTORY_SEPARATOR . '*.php') as $rootFile) {
            if (is_string($rootFile) && is_file($rootFile)) {
                $this->scanInto($findings, $rootFile, basename($rootFile));
            }
        }

        foreach (self::SCAN_ROOTS as $relativeRoot) {
            $this->scanDirectoryInto($findings, $repositoryRoot . DIRECTORY_SEPARATOR . $relativeRoot, $repositoryRoot);
        }

        foreach ($extraRoots as $extraRoot) {
            $extraRoot = rtrim($extraRoot, DIRECTORY_SEPARATOR);
            $this->scanDirectoryInto($findings, $extraRoot, dirname($extraRoot));
        }

        usort(
            $findings,
            static fn (array $a, array $b): int => [$a['file'], $a['line'], $a['category']] <=> [$b['file'], $b['line'], $b['category']]
        );

        return $findings;
    }

    /**
     * Scan a single source string; used by collect() and by the tests.
     *
     * @return list<array{file:string,line:int,category:string,detail:string}>
     */
    public function scanSource(string $source, string $relativePath): array
    {
        $tokens = token_get_all($source);
        $findings = [];
        $count = count($tokens);

        foreach ($tokens as $index => $token) {
            if (!is_array($token)) {
                continue;
            }

            [$id, $text, $line] = $token;

            if ($id === T_EXIT) {
                $findings[] = $this->finding($relativePath, $line, self::CATEGORY_FINISH_PATH, strtolower($text));
                continue;
            }

            if ($id === T_STRING && $this->isCall($tokens, $index, $count)) {
                $name = strtolower($text);
                if (in_array($name, self::FINISH_CALLS, true)) {
                    $findings[] = $this->finding($relativePath, $line, self::CATEGORY_FINISH_PATH, $text . '()');
                } elseif ($name === 'begintransaction') {
                    $findings[] = $this->finding($relativePath, $line, self::CATEGORY_TRANSACTION_BREAKER, 'beginTransaction()');
                } elseif (isset(self::SIDE_EFFECT_CALLS[$name]) && $this->isSideEffectCall($tokens, $index, $name)) {
                    $findings[] = $this->finding($relativePath, $line, self::CATEGORY_SIDE_EFFECT, self::SIDE_EFFECT_CALLS[$name]);
                }
                continue;
            }

            if ($id === T_NEW) {
                $class = $this->nextNameAfter($tokens, $index, $count);
                if ($class !== null && strcasecmp($this->shortName($class), 'PHPMailer') === 0) {
                    $findings[] = $this->finding($relativePath, $line, self::CATEGORY_SIDE_EFFECT, 'new PHPMailer');
                }
                continue;
            }

            if ($id !== T_CONSTANT_ENCAPSED_STRING && $id !== T_ENCAPSED_AND_WHITESPACE && $id !== T_INLINE_HTML) {
                continue;
            }

            if (preg_match(self::INLINE_SCRIPT_PATTERN, $text) === 1) {
                $findings[] = $this->finding($relativePath, $line, self::CATEGORY_INLINE_SCRIPT, '<script>');
            }

            if ($id === T_INLINE_HTML) {
                continue;
            }

            if (preg_match(self::BREAKER_SQL_PATTERN, $text, $match) === 1) {
                $findings[] = $this->finding($relativePath, $line, self::CATEGORY_TRANSACTION_BREAKER, strtoupper((string) preg_replace('/\s+/', ' ', $match[0])));
            }

            if (
                preg_match(self::SQL_WRITE_PATTERN, $text, $match) === 1
                && preg_match(self::ACCOUNTS_TABLE_PATTERN, $this->statementText($tokens, $index, $count)) === 1
            ) {
                $findings[] = $this->finding($relativePath, $line, self::CATEGORY_ACCOUNTS_WRITE, strtoupper((string) preg_replace('/\s+/', ' ', $match[0])) . ' accounts');
            }
        }

        // A statement split over several string tokens reports once per line.
        $unique = [];
        foreach ($findings as $finding) {
            $unique[$finding['line'] . "\0" . $finding['category'] . "\0" . $finding['detail']] = $finding;
        }

        return array_values($unique);
    }

    /**
     * Count findings per category.
     *
     * @param list<array{file:string,line:int,category:string,detail:string}> $findings
     *
     * @return array<string,int>
     */
    public static function summarize(array $findings): array
    {
        $summary = array_fill_keys(self::CATEGORIES, 0);
        foreach ($findings as $finding) {
            $summary[$finding['category']]++;
        }

        return $summary;
    }

    /**
     * Print the report and return the process exit code (always 0).
     *
     * @param list<string> $extraRoots
     */
    public function run(string $repositoryRoot, array $extraRoots = [], ?string $onlyCategory = null): int
    {
        $findings = $this->collect($repositoryRoot, $extraRoots);
        if ($onlyCategory !== null) {
            $findings = array_values(array_filter($findings, static fn (array $f): bool => $f['category'] === $onlyCategory));
        }

        echo "Reactive core inventory\n";
        foreach (self::summarize($findings) as $category => $total) {
            if ($onlyCategory === null || $category === $onlyCategory) {
                printf("  %-20s %5d\n", $category, $total);
            }
        }
        echo "\n";

        foreach ($findings as $finding) {
            printf("%s:%d: [%s] %s\n", $finding['file'], $finding['line'], $finding['category'], $finding['detail']);
        }

        return 0;
    }

    /**
     * @param list<array{file:string,line:int,category:string,detail:string}> $findings
     */
    private function scanDirectoryInto(array &$findings, string $absoluteRoot, string $relativeTo): void
    {
        if (!is_dir($absoluteRoot)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absoluteRoot, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!($file instanceof \SplFileInfo) || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($relativeTo) + 1));
            $this->scanInto($findings, $file->getPathname(), $relativePath);
        }
    }

    /**
     * @param list<array{file:string,line:int,category:string,detail:string}> $findings
     */
    private function scanInto(array &$findings, string $absolutePath, string $relativePath): void
    {
        foreach (self::EXCLUDED_FRAGMENTS as $fragment) {
            if (str_contains('/' . $relativePath, $fragment)) {
                return;
            }
        }

        $source = file_get_contents($absolutePath);
        if ($source === false) {
            return;
        }

        foreach ($this->scanSource($source, $relativePath) as $finding) {
            $findings[] = $finding;
        }
    }

    /**
     * @return array{file:string,line:int,category:string,detail:string}
     */
    private function finding(string $file, int $line, string $category, string $detail): array
    {
        return ['file' => $file, 'line' => $line, 'category' => $category, 'detail' => $detail];
    }

    /**
     * True when the identifier at $index is called, not declared.
     *
     * @param array<int,array{0:int,1:string,2:int}|string> $tokens
     */
    private function isCall(array $tokens, int $index, int $count): bool
    {
        $next = $index + 1;
        while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
            $next++;
        }
        if (($tokens[$next] ?? null) !== '(') {
            return false;
        }

        $previous = $this->previousSignificant($tokens, $index);

        return !(is_array($previous) && $previous[0] === T_FUNCTION);
    }

    /**
     * Narrow ambiguous names: a bare pull() or mail() method on an unrelated
     * object is not a side effect we care about.
     *
     * @param array<int,array{0:int,1:string,2:int}|string> $tokens
     */
    private function isSideEffectCall(array $tokens, int $index, string $name): bool
    {
        $previous = $this->previousSignificant($tokens, $index);
        $isMember = is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);

        if ($name === 'pull') {
            if (!is_array($previous) || $previous[0] !== T_DOUBLE_COLON) {
                return false;
            }
            $class = $this->previousSignificant($tokens, $this->indexOf($tokens, $previous, $index));

            return is_array($class) && strcasecmp($this->shortName($class[1]), 'PullUrl') === 0;
        }

        if ($name === 'mail') {
            return !$isMember;
        }

        return true;
    }

    /**
     * @param array<int,array{0:int,1:string,2:int}|string> $tokens
     * @param array{0:int,1:string,2:int}|string           $needle
     */
    private function indexOf(array $tokens, array|string $needle, int $before): int
    {
        for ($i = $before - 1; $i >= 0; $i--) {
            if ($tokens[$i] === $needle) {
                return $i;
            }
        }

        return 0;
    }

    /**
     * @param array<int,array{0:int,1:string,2:int}|string> $tokens
     *
     * @return array{0:int,1:string,2:int}|string|null
     */
    private function previousSignificant(array $tokens, int $index): array|string|null
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
     * @param array<int,array{0:int,1:string,2:int}|string> $tokens
     */
    private function nextNameAfter(array $tokens, int $index, int $count): ?string
    {
        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }
            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                return $token[1];
            }

            return null;
        }

        return null;
    }

    private function shortName(string $name): string
    {
        $position = strrpos($name, '\\');

        return $position === false ? $name : substr($name, $position + 1);
    }

    /**
     * Source text of the statement around $index, bounded by ';', '{' or '}'.
     *
     * @param array<int,array{0:int,1:string,2:int}|string> $tokens
     */
    private function statementText(array $tokens, int $index, int $count): string
    {
        $start = $index;
        while ($start > 0 && !in_array($tokens[$start - 1], [';', '{', '}'], true)) {
            $start--;
        }
        $end = $index;
        while ($end < $count - 1 && !in_array($tokens[$end + 1], [';', '{', '}'], true)) {
            $end++;
        }

        $text = '';
        for ($i = $start; $i <= $end; $i++) {
            $text .= is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
        }

        return $text;
    }
}
