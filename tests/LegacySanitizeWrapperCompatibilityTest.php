<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The real lib/sanitize.php wrappers forward to the Sanitize class.
 *
 * In its own process, and that is the whole point. tests/Stubs/Functions.php
 * defines color_sanitize() and its neighbours behind function_exists() and is
 * loaded first, so inside the suite the legacy name resolves to the stub --
 * never to lib/sanitize.php. A test that calls \color_sanitize() in-process
 * therefore compares Sanitize::colorSanitize() with itself and passes however
 * the real wrapper is written.
 *
 * I know because I wrote one: replacing the old source-grep here with an
 * in-process equivalence check looked like an improvement and was a
 * tautology. A mutation that made lib/sanitize.php reimplement the function
 * badly left the suite green, which is what exposed it.
 *
 * So the wrappers are exercised where nothing shadows them, the way
 * tests/LegacyHttpWrapperCompatibilityTest.php already does for lib/http.php.
 * Modules call these names; if a wrapper stops forwarding, the class can be
 * as correct as it likes and every module still gets the wrong answer.
 */
final class LegacySanitizeWrapperCompatibilityTest extends TestCase
{
    public function testTheWrappersReturnWhatTheClassReturns(): void
    {
        $payload = $this->runIsolatedPhp(<<<'PHP'
$cases = [
    'colour codes'    => 'Hello `n`&World',
    'several codes'   => '`b`iBold and italic`i`b',
    'no codes at all' => 'plain text',
    'a bare backtick' => '`',
    'empty'           => '',
];

$result = [];
foreach ($cases as $name => $value) {
    $result[$name] = [
        'wrapper' => color_sanitize($value),
        'class'   => \Lotgd\Sanitize::colorSanitize($value),
    ];
}

$array = ['greeting' => 'Hello `n`&World', 'nested' => ['`bBold', 'Plain'], 'empty' => null];
$result['an array'] = [
    'wrapper' => color_sanitize($array),
    'class'   => \Lotgd\Sanitize::colorSanitize($array),
];

// The `n colour code, not an actual newline: newlineSanitize() strips the
// code and leaves real line breaks alone, so "a\nb" would have compared
// equal however the wrapper was written.
$result['newline wrapper'] = [
    'wrapper' => newline_sanitize('line`nbreak'),
    'class'   => \Lotgd\Sanitize::newlineSanitize('line`nbreak'),
];
$result['comment wrapper'] = [
    'wrapper' => comment_sanitize('`bloud`b'),
    'class'   => \Lotgd\Sanitize::commentSanitize('`bloud`b'),
];

echo json_encode($result, JSON_THROW_ON_ERROR);
PHP);

        self::assertNotSame([], $payload);

        foreach ($payload as $name => $pair) {
            self::assertSame(
                $pair['class'],
                $pair['wrapper'],
                "lib/sanitize.php stopped forwarding for: $name"
            );
        }
    }

    /**
     * And the wrapper really is the file under test, not the suite's stub.
     *
     * Without this the case above could go quietly green again the moment
     * something in the bootstrap defines these names first -- which is the
     * exact way the previous version of this check was worthless.
     */
    public function testTheFunctionUnderTestComesFromLibSanitize(): void
    {
        $payload = $this->runIsolatedPhp(<<<'PHP'
$reflection = new ReflectionFunction('color_sanitize');
echo json_encode(['file' => $reflection->getFileName()], JSON_THROW_ON_ERROR);
PHP);

        self::assertSame(
            realpath(dirname(__DIR__) . '/lib/sanitize.php'),
            realpath((string) ($payload['file'] ?? '')),
            'the isolated process must be exercising lib/sanitize.php itself'
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function runIsolatedPhp(string $snippet): array
    {
        $root = dirname(__DIR__);
        $bootstrap = sprintf(
            "require %s;\nrequire %s;\n",
            var_export($root . '/autoload.php', true),
            var_export($root . '/lib/sanitize.php', true)
        );

        $command = sprintf('%s -r %s', escapeshellarg(PHP_BINARY), escapeshellarg($bootstrap . "\n" . $snippet));
        $output = shell_exec($command);

        self::assertNotNull($output, 'the isolated php process produced no output');
        self::assertIsString($output);

        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $output, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
