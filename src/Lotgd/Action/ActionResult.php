<?php

declare(strict_types=1);

namespace Lotgd\Action;

/**
 * What became of a guarded action.
 *
 * done      the action ran and its changes are committed;
 * replayed  the same submission had already run: data is the stored result;
 * conflict  the account changed since its page was rendered, nothing ran;
 * rejected  the submission cannot run (missing or reused action id).
 */
final class ActionResult
{
    public const DONE = 'done';
    public const REPLAYED = 'replayed';
    public const CONFLICT = 'conflict';
    public const REJECTED = 'rejected';

    /**
     * @param array<string,mixed> $data
     */
    private function __construct(
        public readonly string $status,
        public readonly array $data = [],
        public readonly string $reason = '',
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function done(array $data): self
    {
        return new self(self::DONE, $data);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function replayed(array $data): self
    {
        return new self(self::REPLAYED, $data);
    }

    public static function conflict(): self
    {
        return new self(self::CONFLICT);
    }

    public static function rejected(string $reason): self
    {
        return new self(self::REJECTED, [], $reason);
    }

    /**
     * The action's effect is in place: it ran now or ran before.
     */
    public function succeeded(): bool
    {
        return $this->status === self::DONE || $this->status === self::REPLAYED;
    }
}
