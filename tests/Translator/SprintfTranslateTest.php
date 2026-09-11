<?php

declare(strict_types=1);

namespace Lotgd\Tests\Translator;

use Lotgd\Tests\Stubs\DummySettings;
use Lotgd\Translator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * sprintfTranslate() formats strings whose placeholders come from a translation
 * file and whose arguments come from the calling page. The two can disagree --
 * a translator adds a placeholder, a caller drops an argument -- and under
 * PHP 8 plain vsprintf() answers that by throwing a ValueError, which
 * historically meant a broken page. The contract here is that it pads or drops
 * silently instead.
 *
 * Every case therefore checks the result *and* that nothing was raised on the
 * way, which is why each one runs through the recorder below. The recorder
 * takes E_ALL on purpose: this code path raises nothing of its own -- it never
 * calls trigger_error() -- so whatever does show up comes from PHP, at
 * whichever level PHP chooses, and the point is to hear all of them rather
 * than to predict which. A recorder scoped to E_USER_WARNING, as this one was,
 * listens for the one level this path cannot produce, which makes the
 * assertion read as a guarantee while proving nothing.
 *
 * Runs in its own process: this class define()s process-global constants, and a
 * constant cannot be undefined. Without isolation the first test to run here
 * decides them for every test that follows, which is one of the two reasons the
 * suite used to pass only in alphabetical order.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SprintfTranslateTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['settings'] = new DummySettings(['enabletranslation' => true]);
        $GLOBALS['session'] = [];
        $GLOBALS['REQUEST_URI'] = '/';
        if (!defined('LANGUAGE')) {
            define('LANGUAGE', 'en');
        }
        $GLOBALS['language'] = 'en';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['settings'], $GLOBALS['session'], $GLOBALS['REQUEST_URI'], $GLOBALS['language']);
    }

    /**
     * @param array<int, mixed> $args
     */
    #[DataProvider('formatProvider')]
    public function testFormatsWithoutRaisingAnything(string $format, array $args, string $expected): void
    {
        $raised = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$raised): bool {
            $raised[] = [$errno, $errstr];

            return true;
        }, E_ALL);

        try {
            $result = Translator::sprintfTranslate($format, ...$args);
        } finally {
            restore_error_handler();
        }

        self::assertSame($expected, $result);
        self::assertSame([], $raised, 'sprintfTranslate() must not raise anything on a format/argument mismatch');
    }

    /**
     * @return array<string, array{0: string, 1: array<int, mixed>, 2: string}>
     */
    public static function formatProvider(): array
    {
        return [
            'arguments match the placeholders' => ['Hello %s', ['World'], 'Hello World'],
            'no argument at all pads empty' => ['Value: %s', [], 'Value: '],
            'missing argument pads empty' => ['Value: %s %s', ['First'], 'Value: First '],
            'extra arguments are dropped' => ['Values: %s and %s', ['First', 'Second', 'Third'], 'Values: First and Second'],
            'position, width and literal percent' => ['Progress: %1$s %2$02d%%', ['Done', 3], 'Progress: Done 03%'],
            'non-sequential position, missing argument' => ['%1$s %3$s', ['First'], 'First '],
            'non-sequential position with a prefix' => ['Value: %1$s %3$s', ['foo'], 'Value: foo '],
            'a stray percent is left alone' => ['Value with stray % sign', [], 'Value with stray % sign'],
        ];
    }
}
