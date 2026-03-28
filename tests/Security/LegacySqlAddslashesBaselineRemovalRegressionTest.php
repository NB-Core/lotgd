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
    }
}
