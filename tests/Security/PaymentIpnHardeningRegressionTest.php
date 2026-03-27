<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Regression checks for critical payment IPN hardening paths.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class PaymentIpnHardeningRegressionTest extends TestCase
{
    public function testDuplicateTransactionPathShortCircuitsProcessing(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/payment.php');

        self::assertMatchesRegularExpression(
            '/if \(\$existing !== false\) \{[^}]*payment_error\([^)]*\);[^}]*continue;/s',
            $source
        );
        self::assertStringContainsString('Already logged this transaction ID', $source);
    }

    public function testDbalLookupsAreCaughtAndReportedViaPaymentError(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/payment.php');

        self::assertMatchesRegularExpression('/try \{\s*\$existing = \$conn->fetchAssociative\(/s', $source);
        self::assertStringContainsString('Failed to verify transaction duplication:', $source);
        self::assertMatchesRegularExpression('/try \{\s*\$row = \$conn->fetchAssociative\(/s', $source);
        self::assertStringContainsString('Failed to resolve donation account:', $source);
        self::assertStringContainsString('if (!is_array($row)) {', $source);
    }

    public function testDonationCreditWriteFailureIsCaughtAndDoesNotCrashIpnHandler(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/payment.php');

        self::assertMatchesRegularExpression('/try \{\s*\$result = \$conn->executeStatement\(/s', $source);
        self::assertStringContainsString('Failed to credit donation points:', $source);
        self::assertStringContainsString('$result = 0;', $source);
    }
}
