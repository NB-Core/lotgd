<?php

declare(strict_types=1);

namespace Lotgd\Payment;

/**
 * Mutable result DTO representing the outcome of a single IPN transaction processing attempt.
 */
final class IpnProcessingResult
{
    /** @var array<int, string> */
    public array $errors = [];

    /** @var array<int, string> */
    public array $warnings = [];

    /** @var array<int, string> */
    public array $debugMessages = [];

    public bool $duplicateTransaction = false;

    public bool $paylogInserted = false;

    public bool $credited = false;

    public int $accountId = 0;

    public int $processed = 0;

    public string $accountLogin = '';

    public int $creditedPoints = 0;

    public float $donationAmount = 0.0;
}
