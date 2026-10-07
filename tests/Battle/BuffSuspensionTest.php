<?php

declare(strict_types=1);

namespace Lotgd\Tests\Battle;

use Lotgd\Battle;
use Lotgd\Output;
use Lotgd\Template;
use Lotgd\Translator;
use PHPUnit\Framework\TestCase;

/**
 * Looking up a buff by name must not assume it exists.
 *
 * `unsuspendBuffByName()` and `isBuffActive()` indexed the buff list directly,
 * so asking about a buff the player does not have -- unsuspending "mount" for a
 * player without one is the common case -- raised "Undefined array key" on
 * every call. `suspendBuffByName()` already checked; these pin the other two to
 * the same contract without changing what they do for a buff that is there.
 */
final class BuffSuspensionTest extends TestCase
{
    protected function setUp(): void
    {
        global $session;

        $session = ['user' => ['name' => 'Hero'], 'bufflist' => []];
        Template::getInstance()->setTemplate([]);
        Output::getInstance()->resetOutput();
    }

    protected function tearDown(): void
    {
        global $session;

        unset($session);
        Translator::getInstance()->setSchema();
        Template::getInstance()->setTemplate([]);
    }

    /**
     * Runs $call with every warning and notice turned into a failure.
     */
    private function strictly(callable $call): mixed
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }

    public function testUnsuspendingABuffThePlayerDoesNotHaveIsANoOp(): void
    {
        global $session;

        $this->strictly(static fn () => Battle::unsuspendBuffByName('mount'));

        self::assertSame([], $session['bufflist']);
        self::assertSame('', Output::getInstance()->getRawOutput());
    }

    public function testUnsuspendingABuffWithoutASuspendedFlagIsANoOp(): void
    {
        global $session;

        $session['bufflist']['mount'] = ['name' => 'Pony', 'rounds' => -1];

        $this->strictly(static fn () => Battle::unsuspendBuffByName('mount'));

        self::assertSame(['name' => 'Pony', 'rounds' => -1], $session['bufflist']['mount']);
        self::assertSame('', Output::getInstance()->getRawOutput());
    }

    public function testASuspendedBuffIsRestoredAndAnnounced(): void
    {
        global $session;

        $session['bufflist']['mount'] = ['name' => 'Pony', 'rounds' => -1, 'suspended' => 1];

        $this->strictly(static fn () => Battle::unsuspendBuffByName('mount', 'Your pony is back.'));

        self::assertSame(0, $session['bufflist']['mount']['suspended']);
        self::assertStringContainsString('Your pony is back.', Output::getInstance()->getRawOutput());
    }

    public function testAMissingBuffIsNotActive(): void
    {
        self::assertSame(0, $this->strictly(static fn () => Battle::isBuffActive('mount')));
    }

    public function testABuffWithoutASuspendedFlagIsActive(): void
    {
        global $session;

        $session['bufflist']['mount'] = ['name' => 'Pony', 'rounds' => -1];

        self::assertSame(1, $this->strictly(static fn () => Battle::isBuffActive('mount')));
    }

    public function testASuspendedBuffIsNotActive(): void
    {
        global $session;

        $session['bufflist']['mount'] = ['name' => 'Pony', 'rounds' => -1, 'suspended' => 1];

        self::assertSame(0, $this->strictly(static fn () => Battle::isBuffActive('mount')));
    }
}
