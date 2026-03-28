<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for baseline SQL addslashes hardening migrations.
 */
final class LegacySqlAddslashesBaselineRemovalRegressionTest extends TestCase
{
    public function testClanApplicantAndMotdPagesUseBoundParametersInSource(): void
    {
        $applicant = (string) file_get_contents(dirname(__DIR__, 2) . '/pages/clan/applicant_new.php');
        self::assertStringContainsString('WHERE clanname = :clanname', $applicant);
        self::assertStringContainsString('VALUES (:clanname, :clanshort)', $applicant);
        self::assertStringNotContainsString('addslashes($clanname)', $applicant);

        $motd = (string) file_get_contents(dirname(__DIR__, 2) . '/pages/clan/clan_motd.php');
        self::assertStringContainsString('SET clanmotd = :clanmotd', $motd);
        self::assertStringContainsString('SET clandesc = :clandesc', $motd);
        self::assertStringContainsString("'clanid' => ParameterType::INTEGER", $motd);
    }

    public function testClanWithdrawAndTranslatorFallbackUseBoundParametersInSource(): void
    {
        $withdraw = (string) file_get_contents(dirname(__DIR__, 2) . '/pages/clan/clan_withdraw.php');
        self::assertStringContainsString('subject = :subject', $withdraw);
        self::assertStringNotContainsString("addslashes(serialize(\$withdraw_subj))", $withdraw);

        $translator = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Lotgd/Translator.php');
        self::assertStringContainsString('INSERT IGNORE INTO " . Database::prefix("untranslated") . " (intext,language,namespace) VALUES (:intext, :language, :namespace)', $translator);
        self::assertStringContainsString("'namespace' => ParameterType::STRING", $translator);
        self::assertStringNotContainsString("VALUES ('\" .  addslashes(\$indata)", $translator);
    }

    public function testPvpLocationQueryUsesBoundLocationInSource(): void
    {
        $pvp = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Lotgd/Pvp.php');
        self::assertStringContainsString('AND location = :location', $pvp);
        self::assertStringContainsString("'location' => ParameterType::STRING", $pvp);
        self::assertStringNotContainsString('$loc = addslashes($location);', $pvp);
        self::assertStringNotContainsString('Database::query((string) $sql)', $pvp);
    }

    public function testCommentaryPathsUseBoundParametersInSource(): void
    {
        $commentary = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Lotgd/Commentary.php');
        self::assertStringContainsString('WHERE section = :section AND postdate > :postdate', $commentary);
        self::assertStringContainsString('AND commentid > :commentid', $commentary);
        self::assertStringContainsString("'limit' => ParameterType::INTEGER", $commentary);
        self::assertStringNotContainsString("WHERE section='$section'", $commentary);
    }

    public function testModulesSettingsAndPrefsUseBoundParametersInSource(): void
    {
        $modules = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Lotgd/Modules.php');
        self::assertStringContainsString('WHERE modulename IN (:moduleNames)', $modules);
        self::assertStringContainsString("'moduleNames' => ArrayParameterType::STRING", $modules);
        self::assertStringContainsString('WHERE modulename = :module AND setting = :setting AND userid = :userid', $modules);
        self::assertStringNotContainsString("WHERE modulename='$module' AND userid='$user'", $modules);
    }

    public function testNewdayMaintenanceUsesDbalStatementForOptimizeInSource(): void
    {
        $newday = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Lotgd/Newday.php');
        self::assertStringContainsString("executeStatement('OPTIMIZE TABLE ' . Database::getDoctrineConnection()->quoteIdentifier((string) \$val))", $newday);
        self::assertStringNotContainsString('Database::query("OPTIMIZE TABLE $val")', $newday);
    }
}
