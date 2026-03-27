<?php

declare(strict_types=1);

namespace Lotgd\Tests\Payment;

use Lotgd\Payment\IpnStatus;
use PHPUnit\Framework\TestCase;

final class IpnStatusTest extends TestCase
{
    public function testCompletedStatusIsAcceptedWithoutFeeMutation(): void
    {
        $normalized = IpnStatus::normalize('Completed', 1.25);

        self::assertTrue($normalized['accepted']);
        self::assertSame(1.25, $normalized['paymentFee']);
        self::assertSame('', $normalized['txnType']);
    }

    public function testRefundedStatusIsAcceptedAndFeeIsForcedToZero(): void
    {
        $normalized = IpnStatus::normalize('Refunded', 1.25);

        self::assertTrue($normalized['accepted']);
        self::assertSame(0.0, $normalized['paymentFee']);
        self::assertSame('refund', $normalized['txnType']);
    }

    public function testOtherStatusIsRejected(): void
    {
        $normalized = IpnStatus::normalize('Pending', 1.25);

        self::assertFalse($normalized['accepted']);
        self::assertSame(1.25, $normalized['paymentFee']);
    }
}
