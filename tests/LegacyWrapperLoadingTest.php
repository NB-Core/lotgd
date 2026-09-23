<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every file under lib/ can be required by a module, together with any other.
 *
 * lib/ is the 1.x surface: modules load it by name, one file at a time, in an
 * order no one controls -- `require_once("lib/systemmail.php")` is the idiom.
 * Nothing in core loads most of these files, so nothing in the ordinary suite
 * notices when one of them cannot be loaded at all. Four could not:
 *
 * - lib/mail.php redeclared systemmail(), send_email() and is_email(), which
 *   each have their own file, so loading it beside any of those was a fatal
 *   "Cannot redeclare function", in either order.
 * - lib/settings.class.php and lib/serverfunctions.class.php each declared a
 *   class extending itself, and failed in every context.
 * - lib/battle-functions.php did the same behind a guard, so it worked only
 *   if FightBar happened to be loaded first.
 *
 * All four arrived together in v2.0.5. The check is run in a child process,
 * because the failure is a fatal error that would take PHPUnit down with it,
 * and because the test suite defines stubs for some of these functions itself.
 * It runs forwards and backwards: a file that loads only after another one has
 * is caught by one direction or the other.
 */
final class LegacyWrapperLoadingTest extends TestCase
{
    /**
     * Files under lib/ that are actions rather than declarations: including
     * one does the thing. They are not wrappers, and loading them here would
     * run them.
     */
    private const ACTION_SCRIPTS = [
        'lib/expire_chars.php', // the 1.x cron entry: runs ExpireChars::expire()
    ];

    public function testEveryWrapperLoadsBesideEveryOtherInFileOrder(): void
    {
        $this->assertAllLoad(self::wrappers());
    }

    public function testEveryWrapperLoadsBesideEveryOtherInReverseOrder(): void
    {
        $this->assertAllLoad(array_reverse(self::wrappers()));
    }

    /**
     * lib/mail.php loaded first still leaves the 1.x declarations in effect.
     *
     * A function_exists() guard in lib/mail.php would have stopped the fatal
     * too, and this is what it would have got wrong: whichever file loaded
     * first would decide the signature, and lib/mail.php's copies were
     * stricter than the originals. Its systemmail() took `string $subject`,
     * while Mail::systemMail() and the 1.x wrapper accept the translation
     * array a module passes -- so with lib/mail.php first, that call was a
     * TypeError. The file each function is declared in is the thing to pin.
     */
    public function testTheOriginalDeclarationsWinWhenMailPhpLoadsFirst(): void
    {
        $result = self::runInRoot(<<<'PHP'
require_once 'lib/mail.php';

$declaredIn = [];
foreach (['systemmail', 'send_email', 'is_email'] as $function) {
    $declaredIn[$function] = basename((string) (new ReflectionFunction($function))->getFileName());
}

$subject = (new ReflectionFunction('systemmail'))->getParameters()[1]->getType();
$accepts = $subject === null ? ['mixed'] : array_map(
    static fn (ReflectionNamedType $type): string => $type->getName(),
    $subject instanceof ReflectionUnionType ? $subject->getTypes() : [$subject]
);

echo json_encode(['declaredIn' => $declaredIn, 'subjectAccepts' => $accepts], JSON_THROW_ON_ERROR);
PHP);

        self::assertSame(0, $result['status'], $result['output']);

        $payload = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            ['systemmail' => 'systemmail.php', 'send_email' => 'sendmail.php', 'is_email' => 'is_email.php'],
            $payload['declaredIn'],
            'each function is declared only by its own 1.x file'
        );
        self::assertContains(
            'array',
            $payload['subjectAccepts'],
            'systemmail() still takes the translation array a 1.x module passes as its subject'
        );
    }

    /**
     * @return list<string> lib/ files relative to the game root, as a module names them
     */
    private static function wrappers(): array
    {
        $root = dirname(__DIR__);
        $files = [];

        foreach (glob($root . '/lib/*.php') ?: [] as $path) {
            $relative = 'lib/' . basename($path);
            if (!in_array($relative, self::ACTION_SCRIPTS, true)) {
                $files[] = $relative;
            }
        }

        self::assertNotEmpty($files, 'found no lib/*.php files to load');

        // glob() already sorts, but through the C library, whose comparison
        // follows LC_COLLATE. PHP leaves that at "C" and nothing here changes
        // it, so the order is stable today -- as a default rather than as a
        // property of this test. SORT_STRING is a byte comparison and ignores
        // the locale, so a failure seen in one environment is the same failure
        // in another. Raised by Copilot.
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param list<string> $files
     */
    private function assertAllLoad(array $files): void
    {
        // Each file announces itself before it is loaded, so a fatal leaves
        // the name of the file that caused it as the last line before the
        // error rather than a count.
        $result = self::runInRoot(sprintf(
            <<<'PHP'
foreach (%s as $file) {
    echo "loading $file\n";
    require_once $file;
}
echo "loaded all\n";
PHP,
            var_export($files, true)
        ));

        $lines = explode("\n", trim($result['output']));

        self::assertSame(
            'loaded all',
            end($lines),
            sprintf("lib/ did not load as a whole:\n%s", implode("\n", array_slice($lines, -4)))
        );
        self::assertSame(0, $result['status'], $result['output']);
    }

    /**
     * Run a snippet in a fresh PHP process, from the game root and with the
     * game's autoloader -- the conditions a module's require_once meets.
     *
     * @return array{status: int, output: string}
     */
    private static function runInRoot(string $snippet): array
    {
        $root = dirname(__DIR__);
        $code = sprintf(
            "chdir(%s);\nrequire 'autoload.php';\n%s",
            var_export($root, true),
            $snippet
        );

        exec(
            sprintf('%s -d display_errors=stdout -r %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($code)),
            $output,
            $status
        );

        return ['status' => $status, 'output' => implode("\n", $output)];
    }
}
