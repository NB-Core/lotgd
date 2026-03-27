<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SuperuserEndpointHardeningWaveRegressionTest extends TestCase
{
    public function testModerateUsesArrayParameterBindingForDynamicInClauses(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../moderate.php');

        self::assertStringContainsString('ArrayParameterType::INTEGER', $source);
        self::assertStringContainsString('DELETE FROM {$commentaryTable} WHERE commentid IN (?)', $source);
        self::assertStringContainsString('DELETE FROM {$moderatedCommentsTable} WHERE modid IN (?)', $source);
        self::assertStringContainsString('function normalizeIntegerKeys', $source);
        self::assertStringContainsString('array_filter($keys, static fn (int $value): bool => $value > 0)', $source);
    }

    public function testPaymentPersistsIpnAndCreditsAccountsWithTypedParameters(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../payment.php');

        self::assertStringContainsString('UPDATE {$accountsTable} SET donation = donation + :points WHERE acctid = :acctid', $source);
        self::assertStringContainsString('INSERT INTO {$paylogTable}', $source);
        self::assertStringContainsString("'txnid' => ParameterType::STRING", $source);
        self::assertStringContainsString("'acctid' => ParameterType::INTEGER", $source);
        self::assertStringContainsString('fetchAssociative(', $source);
    }

    public function testLegacyHttpWrappersRemainEscapingForModuleCompatibility(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../lib/http.php');

        self::assertStringContainsString('function legacy_http_escape', $source);
        self::assertStringContainsString('return addslashes($value);', $source);
        self::assertStringContainsString('return legacy_http_escape(Http::post($var));', $source);
        self::assertStringContainsString('return legacy_http_escape(Http::get($var));', $source);
    }
}
