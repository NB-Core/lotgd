<?php

declare(strict_types=1);

namespace Lotgd\Tests\Payment;

use Doctrine\DBAL\Connection;
use Lotgd\Payment\IpnPaymentProcessor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IpnPaymentProcessorTest extends TestCase
{
    public function testDuplicateTransactionReturnsNoSecondCredit(): void
    {
        $connection = $this->createConnectionMock();
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(['txnid' => 'TXN-1']);
        $connection->expects(self::never())
            ->method('executeStatement')
            ->willThrowException(new RuntimeException('Duplicate entry', 23000));

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
    }

    public function testFirstDeliveryCreditsOnceAndLogsOnce(): void
    {
        $connection = $this->createConnectionMock();
        $connection->expects(self::exactly(2))
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(false, ['acctid' => 13]);

        $calls = 0;
        $connection->expects(self::exactly(3))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params = [], array $types = []) use (&$calls): int {
                $calls++;
                if ($calls === 1) {
                    self::assertStringContainsString('INSERT INTO paylog', $sql);
                    self::assertArrayHasKey('txnid', $params);
                    self::assertArrayHasKey('txnid', $types);
                }
                if ($calls === 2) {
                    self::assertStringContainsString('UPDATE accounts SET donation', $sql);
                    self::assertArrayHasKey('points', $params);
                    self::assertArrayHasKey('acctid', $params);
                }
                if ($calls === 3) {
                    self::assertStringContainsString('UPDATE paylog SET processed', $sql);
                    self::assertArrayHasKey('processed', $params);
                }

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
    }

    public function testCreditWriteFailureAddsErrorAndDoesNotFatal(): void
    {
        $connection = $this->createConnectionMock();
        $connection->expects(self::exactly(2))
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(false, ['acctid' => 2]);

        $calls = 0;
        $connection->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params = [], array $types = []) use (&$calls): int {
                $calls++;
                if ($calls === 2) {
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
        $connection->expects(self::exactly(2))
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(false, false);
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
            ->willReturnOnConsecutiveCalls(false, ['acctid' => 13]);
        $connection->expects(self::exactly(3))->method('executeStatement')->willReturn(1);

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
            ->onlyMethods(['fetchAssociative', 'executeStatement'])
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
}
