<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the second superuser/account SQL hardening wave.
 *
 * This suite intentionally verifies source-level hardening signals so we can
 * detect accidental reintroduction of string-built writes in legacy endpoints.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class SuperuserEndpointHardeningWave2RegressionTest extends TestCase
{
    public function testMastersDeleteUsesBoundIntegerParameterAndDelGuard(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/masters.php');

        self::assertStringContainsString('if ($op == "del")', $source);
        self::assertStringContainsString('DELETE FROM {$mastersTable} WHERE creatureid = :id', $source);
        self::assertStringContainsString("'id' => ParameterType::INTEGER", $source);
    }

    public function testCreaturesWritesUseExecuteStatementAndBoundDeleteId(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/creatures.php');

        self::assertStringContainsString('if ($subop == "")', $source);
        self::assertStringContainsString('UPDATE {$creaturesTable} SET ', $source);
        self::assertStringContainsString('INSERT INTO {$creaturesTable}', $source);
        self::assertStringContainsString('DELETE FROM {$creaturesTable} WHERE creatureid = :id', $source);
        self::assertStringContainsString("'id' => ParameterType::INTEGER", $source);
    }

    public function testReferersCleanupAndRebuildWritesAreParameterized(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/referers.php');

        self::assertStringContainsString('if ($op == "rebuild")', $source);
        self::assertStringContainsString('DELETE FROM {$referersTable} WHERE last < :cutoff', $source);
        self::assertStringContainsString('UPDATE {$referersTable} SET site = :site WHERE refererid = :refererid', $source);
        self::assertStringContainsString("'refererid' => ParameterType::INTEGER", $source);
    }

    public function testPaylogBackfillUpdateUsesBoundParamsBehindPaymentDateGuard(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/paylog.php');

        self::assertStringContainsString("if (\$normalized['paymentDate'] !== null)", $source);
        self::assertStringContainsString('UPDATE {$paylogTable} SET processdate = :processdate WHERE txnid = :txnid', $source);
        self::assertStringContainsString("'txnid' => ParameterType::STRING", $source);
    }

    public function testConfigurationRenameMassUpdatesUseNamedParametersWithGuards(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/configuration.php');

        self::assertStringContainsString("if (\$villageName !== '' && \$villageName != \$settings->getSetting('villagename', LOCATION_FIELDS))", $source);
        self::assertStringContainsString("if (\$innName !== '' && \$innName != \$settings->getSetting('innname', LOCATION_INN))", $source);
        self::assertStringContainsString('UPDATE {$accountsTable} SET location = :newLocation WHERE location = :oldLocation', $source);
        self::assertStringContainsString("'newLocation' => ParameterType::STRING", $source);
    }

    public function testCreateValidationAndForgotPasswordWritesUseBoundParams(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/create.php');

        self::assertStringContainsString('if ($op == "forgotval")', $source);
        self::assertStringContainsString('if ($op == "forgot")', $source);
        self::assertStringContainsString("if (\$row['replaceemail'] != '')", $source);
        self::assertStringContainsString('SET emailaddress = :replaceemail, replaceemail = :replaceemailReset, forgottenpassword = :forgottenpassword', $source);
        self::assertStringContainsString('SET forgottenpassword = :forgottenpassword WHERE login = :login', $source);
        self::assertStringContainsString("'acctid' => ParameterType::INTEGER", $source);
    }

    public function testCreateRegistrationWritesUseBoundParametersForAccountsAndAccountsOutput(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/create.php');

        self::assertStringContainsString(
            'INSERT INTO {$accountsTable}',
            $source
        );
        self::assertStringContainsString(':playername, :name, :superuser, :title, :password, :password_algo, :sex, :login, :laston, :uniqueid, :lastip, :gold, :location, :emailaddress, :emailvalidation, :referer, NOW(), :badguy, :allowednavs',
            $source
        );
        self::assertStringContainsString("'playername' => ParameterType::STRING", $source);
        self::assertStringContainsString("'superuser' => ParameterType::INTEGER", $source);
        self::assertStringContainsString("'gold' => ParameterType::INTEGER", $source);
        self::assertStringContainsString("'referer' => ParameterType::INTEGER", $source);
        self::assertStringContainsString('INSERT INTO {$accountsOutputTable} VALUES (:acctid, :output)', $source);
        self::assertStringContainsString("'output' => ParameterType::STRING", $source);
        self::assertStringNotContainsString(
            "('$shortname','$title $shortname'",
            $source
        );
    }
}
