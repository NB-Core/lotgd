<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Security regression coverage for the superuser endpoint hardening wave.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class SuperuserEndpointHardeningWaveRegressionTest extends TestCase
{
    public function testModerateUsesArrayParameterBindingForDynamicInClauses(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/moderate.php');

        self::assertStringContainsString('ArrayParameterType::INTEGER', $source);
        self::assertMatchesRegularExpression('/DELETE FROM\\s+\\{\\$commentaryTable\\}\\s+WHERE\\s+commentid\\s+IN\\s*\\(\\?\\)/m', $source);
        self::assertMatchesRegularExpression('/DELETE FROM\\s+\\{\\$moderatedCommentsTable\\}\\s+WHERE\\s+modid\\s+IN\\s*\\(\\?\\)/m', $source);
        self::assertStringContainsString('function moderateNormalizeIntegerKeys', $source);
        self::assertStringContainsString('array_filter($keys, static fn (int $value): bool => $value > 0)', $source);
        self::assertStringContainsString("unserialize(\$row['comment'], ['allowed_classes' => false])", $source);
    }

    public function testPaymentPersistsIpnAndCreditsAccountsWithTypedParameters(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Lotgd/Payment/IpnPaymentProcessor.php');

        self::assertStringContainsString('UPDATE {$this->accountsTable} SET donation = donation + :points WHERE acctid = :acctid', $source);
        self::assertStringContainsString('INSERT INTO {$this->paylogTable}', $source);
        self::assertStringContainsString("'txnid' => ParameterType::STRING", $source);
        self::assertStringContainsString("'acctid' => ParameterType::INTEGER", $source);
        self::assertStringContainsString('fetchAssociative(', $source);
    }

    public function testLegacyHttpWrappersRemainEscapingForModuleCompatibility(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/http.php');

        self::assertStringContainsString('function legacy_http_escape', $source);
        self::assertStringContainsString('return addslashes($value);', $source);
        self::assertStringContainsString('return legacy_http_escape(Http::post($var));', $source);
        self::assertStringContainsString('return legacy_http_escape(Http::get($var));', $source);
    }
}
