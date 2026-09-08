<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the request values that reached SQL unbound.
 *
 * SqlValueInterpolationCheck already refuses newly added interpolation, but it
 * only examines the lines a change adds and only runs where a merge base with
 * the target branch exists. These assertions pin the fixed statements
 * themselves, in the same style the surrounding hardening tests use.
 */
final class ClanDetailAndDonationBindingRegressionTest extends TestCase
{
    private function source(string $relativePath): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
    }

    /**
     * The clan rename was the one confirmed, reachable injection: both values
     * arrive in the request body, where the navigation allowlist does not
     * reach, and Sanitize only removed colour codes from them.
     */
    public function testClanDetailBindsRenameAndDescriptionParameters(): void
    {
        $detail = $this->source('pages/clan/detail.php');

        self::assertStringContainsString('SET clanname = :clanname, clanshort = :clanshort', $detail);
        self::assertStringContainsString('SET descauthor = 4294967295, clandesc = :clandesc', $detail);
        self::assertStringContainsString("'clanid' => ParameterType::INTEGER", $detail);
        self::assertStringContainsString("'clanname' => ParameterType::STRING", $detail);
        self::assertStringContainsString("'clanshort' => ParameterType::STRING", $detail);

        self::assertStringNotContainsString("clanname='\$clanname'", $detail);
        self::assertStringNotContainsString("clanshort='\$clanshort'", $detail);
        self::assertStringNotContainsString("clandesc='\$blockdesc'", $detail);
        self::assertStringNotContainsString("clanid='\$detail'", $detail);
    }

    /**
     * clan.php feeds pages/clan/detail.php, which puts the id into SQL, a
     * cache key and generated URLs.
     */
    public function testClanIdIsNormalizedAtItsSource(): void
    {
        self::assertStringContainsString(
            "\$detail = (int) Http::get('detail');",
            $this->source('clan.php')
        );
    }

    /**
     * txnid came straight from the request while its neighbours were validated
     * with filter_var, and the point total can be supplied by a module through
     * the donation_adjustments hook.
     */
    public function testDonationUpdatesBindTransactionAndPoints(): void
    {
        $donators = $this->source('donators.php');

        self::assertStringContainsString('SET donation = donation + :points WHERE acctid = :acctid', $donators);
        self::assertStringContainsString('SET acctid = :acctid, processed = 1 WHERE txnid = :txnid', $donators);
        self::assertStringContainsString("'txnid' => ParameterType::STRING", $donators);

        self::assertStringNotContainsString("donation=donation+'\$points'", $donators);
        self::assertStringNotContainsString("txnid='\$txnid'", $donators);
    }

    /**
     * This month filter was exploited in the past. Its safety previously rested
     * on a length cut interacting with an unanchored pattern; both values are
     * bound now, and the pattern is anchored so neither line carries the
     * defence alone.
     */
    public function testMotdMonthWindowIsBoundAndAnchored(): void
    {
        $motd = $this->source('motd.php');

        self::assertStringContainsString('motddate >= :monthstart AND motddate <= :monthend', $motd);
        self::assertStringContainsString("'monthstart' => ParameterType::STRING", $motd);
        self::assertStringContainsString("preg_match('/^[0-9]{4}-[0-9]{2}$/', \$month_post)", $motd);

        self::assertStringNotContainsString("motddate >= '{\$month_post}-01'", $motd);
    }

    /**
     * Ids that reach an interpolated query are cast where they are read, so
     * the value cannot be a string by the time it gets there.
     */
    public function testIntegerIdsAreCastAtTheirSource(): void
    {
        self::assertStringContainsString('$id = (int) Http::get("id");', $this->source('weapons.php'));
        self::assertStringContainsString("\$id = (int) Http::get('id');", $this->source('mercenarycamp.php'));
        self::assertStringContainsString('$mid = (int) Http::get("master");', $this->source('train.php'));
        self::assertStringContainsString("\$id = (int) Http::get('id');", $this->source('titleedit.php'));
    }
}
