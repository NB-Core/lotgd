<?php

declare(strict_types=1);

namespace Lotgd\Payment;

/**
 * Pure helper for webhook status branching.
 */
final class IpnStatus
{
    /**
     * Normalize status-specific flags used by payment processing.
     *
     * @return array{accepted: bool, paymentFee: float, txnType: string}
     */
    public static function normalize(string $status, float $paymentFee): array
    {
        if ($status === 'Completed') {
            return [
                'accepted' => true,
                'paymentFee' => $paymentFee,
                'txnType' => '',
            ];
        }

        if ($status === 'Refunded') {
            return [
                'accepted' => true,
                'paymentFee' => 0.0,
                'txnType' => 'refund',
            ];
        }

        return [
            'accepted' => false,
            'paymentFee' => $paymentFee,
            'txnType' => '',
        ];
    }
}
