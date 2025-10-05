<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Paylog\LegacyPayloadNormalizer;
use Lotgd\Serialization;
use PHPUnit\Framework\TestCase;

final class PaylogLegacyPayloadTest extends TestCase
{
    public function testNormalizeHandlesCorruptedInfoWithoutWarnings(): void
    {
        $row = [
            'info' => "corrupted payload",
            'txnid' => 'LEGACY123',
            'amount' => '12.50',
            'txfee' => '1.25',
            'processdate' => '2024-01-01 00:00:00',
        ];

        $errors = [];
        set_error_handler(
            function (int $severity, string $message) use (&$errors): bool {
                if (!(error_reporting() & $severity)) {
                    return false;
                }
                $errors[] = $message;
                return true;
            }
        );

        $info = Serialization::safeUnserialize($row['info']);
        $normalized = LegacyPayloadNormalizer::normalize($row, $info, 'USD');

        restore_error_handler();

        $this->assertSame([], $errors, 'Decoding corrupted info should not emit warnings.');
        $this->assertFalse($normalized['is_valid']);
        $this->assertSame(LegacyPayloadNormalizer::LEGACY_PLACEHOLDER, $normalized['placeholder']);
        $this->assertSame(12.5, $normalized['gross']);
        $this->assertSame(1.25, $normalized['fee']);
        $this->assertSame('USD', $normalized['currency']);
        $this->assertNull($normalized['paymentDate']);
        $this->assertSame('', $normalized['memo']);
        $this->assertNull($normalized['itemNumber']);
    }
}
