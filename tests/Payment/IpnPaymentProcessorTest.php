<?php

declare(strict_types=1);

namespace Lotgd\Tests\Payment;

use Doctrine\DBAL\Connection;
use Lotgd\Payment\IpnPaymentProcessor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IpnPaymentProcessorTest extends TestCase
{
    public function testDuplicateTransactionWithMissingAccountSkipsCrediting(): void
    {
        $connection = $this->createConnectionMock();
        $connection->expects(self::exactly(2))
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(['payid' => 900], false);
        $connection->expects(self::never())->method('beginTransaction');
        $connection->expects(self::never())->method('lastInsertId');
        $connection->expects(self::once())
            ->method('executeStatement')
            ->willReturn(0);

        $processor = new IpnPaymentProcessor($connection, 'accounts', 'paylog');

        $result = $processor->processVerifiedPayment(
            ['foo' => 'bar'],
            $this->buildPayload(),
            static fn (array $data): array => $data
        );

        self::assertTrue($result->duplicateTransaction);
        self::assertFalse($result->paylogInserted);
        self::assertFalse($result->credited);
        self::assertNotEmpty($result->warnings);
        self::assertSame(900, $result->paylogId);
    }

    public function testFirstDeliveryCreditsOnceAndLogsOnce(): void
    {
        $connection = $this->createConnectionMock();
        $connection->expects(self::exactly(2))
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(['acctid' => 13], ['payid' => 501, 'processed' => 0]);
        $connection->expects(self::once())->method('lastInsertId')->willReturn(501);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');
        $connection->expects(self::never())->method('rollBack');

        $calls = 0;
        $statements = [];
        $connection->expects(self::exactly(4))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params = [], array $types = []) use (&$calls, &$statements): int {
                $calls++;
                $statements[] = ['sql' => $sql, 'params' => $params, 'types' => $types];

                return 1;
            });

        $processor = new IpnPaymentProcessor($connection, 'accounts', 'paylog');

        $result = $processor->processVerifiedPayment(
            ['foo' => 'bar'],
            $this->buildPayload(),
            static fn (array $data): array => ['points' => $data['points'], 'messages' => ['ok']]
        );

        self::assertTrue($result->paylogInserted);
        self::assertTrue($result->credited);
        self::assertSame(1, $result->processed);
        self::assertSame(1000, $result->creditedPoints);
        self::assertStringContainsString('INSERT INTO paylog', $statements[0]['sql']);
        self::assertStringContainsString('NOT EXISTS', $statements[0]['sql']);
        self::assertArrayHasKey('txnid', $statements[0]['params']);
        self::assertArrayHasKey('txnid', $statements[0]['types']);
        self::assertStringContainsString('UPDATE paylog SET acctid', $statements[1]['sql']);
        self::assertArrayHasKey('acctid', $statements[1]['params']);
        self::assertArrayHasKey('payid', $statements[1]['params']);
        self::assertStringContainsString('UPDATE paylog', $statements[2]['sql']);
        self::assertArrayHasKey('processed', $statements[2]['params']);
        self::assertStringContainsString('UPDATE accounts SET donation', $statements[3]['sql']);
        self::assertArrayHasKey('points', $statements[3]['params']);
        self::assertArrayHasKey('acctid', $statements[3]['params']);
    }

    public function testCreditWriteFailureAddsErrorAndDoesNotFatal(): void
    {
        $connection = $this->createConnectionMock();
        $connection->expects(self::exactly(2))
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(['acctid' => 2], ['payid' => 502, 'processed' => 0]);
        $connection->expects(self::once())->method('lastInsertId')->willReturn(502);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::once())->method('rollBack');
        $connection->expects(self::never())->method('commit');

        $calls = 0;
        $connection->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params = [], array $types = []) use (&$calls): int {
                $calls++;
                if ($calls === 4) {
                    throw new RuntimeException('write failed');
                }

                return 1;
            });

        $processor = new IpnPaymentProcessor($connection, 'accounts', 'paylog');
        $result = $processor->processVerifiedPayment(
            ['foo' => 'bar'],
            $this->buildPayload(),
            static fn (array $data): array => $data
        );

        self::assertTrue($result->paylogInserted);
        self::assertFalse($result->credited);
        self::assertStringContainsString('Failed to credit donation points', implode("\n", $result->errors));
    }

    public function testAccountLookupMissStillPersistsPaylogWithProcessedZero(): void
    {
        $connection = $this->createConnectionMock();
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(false);
        $connection->expects(self::never())->method('beginTransaction');
        $connection->expects(self::once())->method('lastInsertId')->willReturn(503);
        $connection->expects(self::once())->method('executeStatement')->willReturn(1);

        $processor = new IpnPaymentProcessor($connection, 'accounts', 'paylog');

        $result = $processor->processVerifiedPayment(
            ['foo' => 'bar'],
            $this->buildPayload(),
            static fn (array $data): array => $data
        );

        self::assertTrue($result->paylogInserted);
        self::assertFalse($result->credited);
        self::assertSame(0, $result->accountId);
        self::assertSame(0, $result->processed);
    }

    public function testEmptyTransactionIdIsHardFailureAndStopsProcessing(): void
    {
        $connection = $this->createConnectionMock();
        $connection->expects(self::never())->method('fetchAssociative');
        $connection->expects(self::never())->method('lastInsertId');
        $connection->expects(self::never())->method('executeStatement');

        $processor = new IpnPaymentProcessor($connection, 'accounts', 'paylog');

        $payload = $this->buildPayload();
        $payload['txnId'] = '';

        $result = $processor->processVerifiedPayment(
            ['foo' => 'bar'],
            $payload,
            static fn (array $data): array => $data
        );

        self::assertFalse($result->paylogInserted);
        self::assertFalse($result->credited);
        self::assertStringContainsString('empty transaction ID', implode("\n", $result->errors));
    }

    public function testReversalUsesFeeAdjustedDonationAmountForPointCalculation(): void
    {
        $connection = $this->createConnectionMock();
        $connection->expects(self::exactly(2))
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(['acctid' => 13], ['payid' => 504, 'processed' => 0]);
        $connection->expects(self::once())->method('lastInsertId')->willReturn(504);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');
        $connection->expects(self::never())->method('rollBack');
        $connection->expects(self::exactly(4))->method('executeStatement')->willReturn(1);

        $processor = new IpnPaymentProcessor($connection, 'accounts', 'paylog');
        $payload = $this->buildPayload();
        $payload['txnType'] = 'reversal';

        $result = $processor->processVerifiedPayment(
            ['foo' => 'bar'],
            $payload,
            static fn (array $data): array => $data
        );

        self::assertTrue($result->credited);
        self::assertSame(900, $result->creditedPoints);
        self::assertSame(9.0, $result->donationAmount);
    }

    private function createConnectionMock(): Connection
    {
        return $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchAssociative', 'executeStatement', 'lastInsertId', 'beginTransaction', 'commit', 'rollBack', 'isTransactionActive'])
            ->getMock();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(): array
    {
        return [
            'itemNumber' => 'alice:123',
            'response' => 'VERIFIED',
            'txnId' => 'TXN-1',
            'paymentAmount' => '10.00',
            'paymentFee' => '1.00',
            'processDate' => '2026-01-01 00:00:00',
            'pointsPerCurrencyUnit' => 100,
        ];
    }

    public function testItemNumberWithoutLegacyDelimiterDoesNotResolveAccountLogin(): void
    {
        $connection = $this->createConnectionMock();
        $connection->expects(self::once())->method('lastInsertId')->willReturn(505);
        $connection->expects(self::never())->method('beginTransaction');
        $connection->expects(self::once())->method('executeStatement')->willReturn(1);
        $connection->expects(self::never())->method('fetchAssociative');

        $processor = new IpnPaymentProcessor($connection, 'accounts', 'paylog');
        $payload = $this->buildPayload();
        $payload['itemNumber'] = 'legacy-button-without-delimiter';

        $result = $processor->processVerifiedPayment(
            ['foo' => 'bar'],
            $payload,
            static fn (array $data): array => $data
        );

        self::assertSame('', $result->accountLogin);
        self::assertSame(0, $result->accountId);
        self::assertTrue($result->paylogInserted);
        self::assertFalse($result->credited);
    }

    public function testLegacyDuplicateRowsOnlyCanonicalOwnerMayCredit(): void
    {
        $connection = $this->createConnectionMock();
        $connection->expects(self::exactly(2))
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(['acctid' => 13], ['payid' => 100, 'processed' => 0]);
        $connection->expects(self::once())->method('lastInsertId')->willReturn(101);
        $connection->expects(self::never())->method('beginTransaction');
        $connection->expects(self::exactly(2))
            ->method('executeStatement')
            ->willReturnOnConsecutiveCalls(1, 1);

        $processor = new IpnPaymentProcessor($connection, 'accounts', 'paylog');
        $result = $processor->processVerifiedPayment(
            ['foo' => 'bar'],
            $this->buildPayload(),
            static fn (array $data): array => $data
        );

        self::assertFalse($result->credited);
        self::assertTrue($result->duplicateTransaction);
        self::assertStringContainsString('non-canonical', implode("\n", $result->warnings));
    }

    public function testSecondProcessingAttemptOnSameCanonicalRowSkipsCredit(): void
    {
        $connection = $this->createConnectionMock();
        $connection->expects(self::exactly(2))
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(['acctid' => 13], ['payid' => 777, 'processed' => 1]);
        $connection->expects(self::once())->method('lastInsertId')->willReturn(777);
        $connection->expects(self::never())->method('beginTransaction');
        $connection->expects(self::exactly(2))
            ->method('executeStatement')
            ->willReturnOnConsecutiveCalls(1, 1);

        $processor = new IpnPaymentProcessor($connection, 'accounts', 'paylog');
        $result = $processor->processVerifiedPayment(
            ['foo' => 'bar'],
            $this->buildPayload(),
            static fn (array $data): array => $data
        );

        self::assertFalse($result->credited);
        self::assertTrue($result->duplicateTransaction);
        self::assertStringContainsString('already processed', implode("\n", $result->warnings));
    }

    public function testNonCanonicalRowAttemptMustNotCredit(): void
    {
        $connection = $this->createConnectionMock();
        $connection->expects(self::exactly(2))
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(['acctid' => 13], ['payid' => 200, 'processed' => 0]);
        $connection->expects(self::once())->method('lastInsertId')->willReturn(201);
        $connection->expects(self::never())->method('beginTransaction');
        $connection->expects(self::exactly(2))
            ->method('executeStatement')
            ->willReturnOnConsecutiveCalls(1, 1);

        $processor = new IpnPaymentProcessor($connection, 'accounts', 'paylog');
        $result = $processor->processVerifiedPayment(
            ['foo' => 'bar'],
            $this->buildPayload(),
            static fn (array $data): array => $data
        );

        self::assertFalse($result->credited);
        self::assertSame(0, $result->processed);
        self::assertTrue($result->duplicateTransaction);
    }

    public function testDuplicateDeliveryCanResumeCanonicalCreditWhenUnprocessed(): void
    {
        $connection = $this->createConnectionMock();
        $connection->expects(self::exactly(3))
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(
                ['payid' => 300], // duplicate insert path resolves canonical payid
                ['acctid' => 13], // account lookup
                ['payid' => 300, 'processed' => 0] // canonical row state before claim
            );
        $connection->expects(self::never())->method('lastInsertId');
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');
        $connection->expects(self::never())->method('rollBack');
        $connection->expects(self::exactly(4))
            ->method('executeStatement')
            ->willReturnOnConsecutiveCalls(
                0, // insert skipped (duplicate)
                1, // update paylog acctid using canonical payid
                1, // claim canonical row processed=0->1
                1  // credit account donation
            );

        $processor = new IpnPaymentProcessor($connection, 'accounts', 'paylog');
        $result = $processor->processVerifiedPayment(
            ['foo' => 'bar'],
            $this->buildPayload(),
            static fn (array $data): array => $data
        );

        self::assertTrue($result->duplicateTransaction);
        self::assertFalse($result->paylogInserted);
        self::assertTrue($result->credited);
        self::assertSame(1, $result->processed);
        self::assertSame(300, $result->paylogId);
    }

    public function testDuplicateDeliveryFailsWhenCanonicalPaylogCannotBeResolved(): void
    {
        $connection = $this->createConnectionMock();
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(false);
        $connection->expects(self::never())->method('lastInsertId');
        $connection->expects(self::never())->method('beginTransaction');
        $connection->expects(self::once())
            ->method('executeStatement')
            ->willReturn(0);

        $processor = new IpnPaymentProcessor($connection, 'accounts', 'paylog');
        $result = $processor->processVerifiedPayment(
            ['foo' => 'bar'],
            $this->buildPayload(),
            static fn (array $data): array => $data
        );

        self::assertFalse($result->credited);
        self::assertSame(0, $result->paylogId);
        self::assertStringContainsString('Unable to continue duplicate transaction processing', implode("\n", $result->errors));
    }
}
