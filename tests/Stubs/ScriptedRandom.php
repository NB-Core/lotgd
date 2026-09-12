<?php

declare(strict_types=1);

namespace Lotgd\Tests\Stubs;

use Lotgd\Battle\RandomSource;
use RuntimeException;

/**
 * A RandomSource that hands out values a test wrote down in advance.
 *
 * The damage roll re-rolls while both damage figures are zero, and the player
 * version has no escape hatch from that loop. A source that quietly repeated
 * its last value, or returned a default once empty, would therefore turn a
 * mis-scripted test into a hung test run -- which is worse than a failing one,
 * because CI cannot tell it from a slow one. So running dry throws.
 */
final class ScriptedRandom implements RandomSource
{
    /** @var list<int> */
    private array $ints;

    /** @var list<int|float> */
    private array $bells;

    /** @var list<array{0: int|float, 1: int|float}> */
    private array $bellRanges = [];

    /** @var list<array{0: int, 1: int}> */
    private array $intRanges = [];

    /**
     * @param list<int>       $ints  values for int(), in call order
     * @param list<int|float> $bells values for bell(), in call order
     */
    public function __construct(array $ints = [], array $bells = [])
    {
        $this->ints = $ints;
        $this->bells = $bells;
    }

    public function int(int $min, int $max): int
    {
        if ($this->ints === []) {
            throw new RuntimeException(
                sprintf('the script ran out of int() values (asked for %d..%d)', $min, $max)
            );
        }

        $this->intRanges[] = [$min, $max];

        return array_shift($this->ints);
    }

    public function bell(int|float $min, int|float $max): int|float
    {
        if ($this->bells === []) {
            throw new RuntimeException(
                sprintf('the script ran out of bell() values (asked for %s..%s)', $min, $max)
            );
        }

        $this->bellRanges[] = [$min, $max];

        return array_shift($this->bells);
    }

    /**
     * Every scripted value was consumed.
     *
     * Worth asserting: a leftover means the roll took a different path than the
     * test intended, and the assertions below it may be passing for the wrong
     * reason.
     */
    public function isDrained(): bool
    {
        return $this->ints === [] && $this->bells === [];
    }

    /**
     * @return array{ints:int, bells:int}
     */
    public function remaining(): array
    {
        return ['ints' => count($this->ints), 'bells' => count($this->bells)];
    }

    /**
     * The ranges bell() was asked for, in call order.
     *
     * The scripted value ignores the range, so this is the only way to see what
     * the roll actually computed -- the adjusted creature defence, the doubled
     * attack score after a crit, the creature attack the power move multiplied.
     *
     * @return list<array{0: int|float, 1: int|float}>
     */
    public function bellRanges(): array
    {
        return $this->bellRanges;
    }

    /**
     * The ranges int() was asked for, in call order.
     *
     * @return list<array{0: int, 1: int}>
     */
    public function intRanges(): array
    {
        return $this->intRanges;
    }
}
