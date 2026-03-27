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
        $connection->expects(self::never())->method('fetchAssociative');
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
    }

    public function testFirstDeliveryCreditsOnceAndLogsOnce(): void
    {
        $connection = $this->createConnectionMock();
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(['acctid' => 13]);
        $connection->expects(self::once())->method('lastInsertId')->willReturn(501);

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
        self::assertStringContainsString('UPDATE accounts SET donation', $statements[2]['sql']);
        self::assertArrayHasKey('points', $statements[2]['params']);
        self::assertArrayHasKey('acctid', $statements[2]['params']);
        self::assertStringContainsString('UPDATE paylog SET processed', $statements[3]['sql']);
        self::assertArrayHasKey('processed', $statements[3]['params']);
    }

    public function testCreditWriteFailureAddsErrorAndDoesNotFatal(): void
    {
        $connection = $this->createConnectionMock();
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(['acctid' => 2]);
        $connection->expects(self::once())->method('lastInsertId')->willReturn(502);

        $calls = 0;
        $connection->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params = [], array $types = []) use (&$calls): int {
                $calls++;
                if ($calls === 3) {
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
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(['acctid' => 13]);
        $connection->expects(self::once())->method('lastInsertId')->willReturn(504);
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
            ->onlyMethods(['fetchAssociative', 'executeStatement', 'lastInsertId'])
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
}
