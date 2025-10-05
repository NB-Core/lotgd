<?php

declare(strict_types=1);

namespace Lotgd\Paylog;

/**
 * Normalizes paylog payloads for display.
 */
class LegacyPayloadNormalizer
{
    public const LEGACY_PLACEHOLDER = 'Legacy record - details unavailable';

    /**
     * Normalize a paylog database row into safe display values.
     *
     * @param array  $row             Database row from the paylog table.
     * @param mixed  $info            Result of Serialization::safeUnserialize().
     * @param string $defaultCurrency Configured default currency code.
     *
     * @return array{
     *     info: array,
     *     is_valid: bool,
     *     paymentDate: ?string,
     *     txnType: ?string,
     *     gross: float,
     *     currency: string,
     *     fee: float,
     *     memo: string,
     *     itemNumber: ?string,
     *     placeholder: ?string
     * }
     */
    public static function normalize(array $row, mixed $info, string $defaultCurrency): array
    {
        $infoArray = is_array($info) ? $info : [];
        $isValid = is_array($info);

        $paymentDate = self::getString($infoArray, 'payment_date');
        $txnType = self::getString($infoArray, 'txn_type');
        $gross = self::getFloat($infoArray, 'mc_gross', (float) ($row['amount'] ?? 0));
        $currency = self::getString($infoArray, 'mc_currency') ?: $defaultCurrency;
        $fee = self::getFloat($infoArray, 'mc_fee', (float) ($row['txfee'] ?? 0));
        $memo = self::getString($infoArray, 'memo') ?? '';
        $itemNumber = self::getString($infoArray, 'item_number');

        return [
            'info' => $infoArray,
            'is_valid' => $isValid,
            'paymentDate' => $paymentDate,
            'txnType' => $txnType,
            'gross' => $gross,
            'currency' => $currency,
            'fee' => $fee,
            'memo' => $memo,
            'itemNumber' => $itemNumber,
            'placeholder' => $isValid ? null : self::LEGACY_PLACEHOLDER,
        ];
    }

    private static function getString(array $info, string $key): ?string
    {
        if (!array_key_exists($key, $info) || $info[$key] === null) {
            return null;
        }

        $value = $info[$key];
        if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
            $value = (string) $value;
        }

        return $value === '' ? null : $value;
    }

    private static function getFloat(array $info, string $key, float $fallback): float
    {
        if (!array_key_exists($key, $info)) {
            return $fallback;
        }

        $value = $info[$key];
        if (is_numeric($value)) {
            return (float) $value;
        }

        return $fallback;
    }
}
