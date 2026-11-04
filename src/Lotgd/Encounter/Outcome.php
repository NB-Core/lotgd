<?php

declare(strict_types=1);

namespace Lotgd\Encounter;

/**
 * How an encounter ended, whatever decided it (docs/ReactiveCore.md,
 * Phase 7): the round duel today, an arena server later. Encounter::settle()
 * takes it from there and does not ask who fought.
 */
final readonly class Outcome
{
    public const KNOCKOUT = 'knockout';
    public const SURRENDER = 'surrender';
    public const ROUNDS = 'rounds';
    public const ABANDONED = 'abandoned';

    public function __construct(
        public ?int $winner,
        public ?int $loser,
        public string $reason,
        public int $rounds,
    ) {
    }

    public static function won(int $winner, int $loser, string $reason, int $rounds): self
    {
        return new self($winner, $loser, $reason, $rounds);
    }

    public static function draw(string $reason, int $rounds): self
    {
        return new self(null, null, $reason, $rounds);
    }

    public function isDraw(): bool
    {
        return $this->winner === null;
    }

    /**
     * @return array{winner:?int,loser:?int,reason:string,rounds:int}
     */
    public function toArray(): array
    {
        return ['winner' => $this->winner, 'loser' => $this->loser, 'reason' => $this->reason, 'rounds' => $this->rounds];
    }
}
