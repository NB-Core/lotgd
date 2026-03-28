<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for query hardening in creatures/referers superuser pages.
 *
 * These assertions intentionally validate source-level patterns so accidental
 * string interpolation of request values is caught quickly.
 */
final class CreatureRefererQueryBindingRegressionTest extends TestCase
{
    public function testCreaturesSearchUsesNamedLikeBindingForSpecialCharacters(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/creatures.php');

        self::assertStringContainsString('creaturename LIKE :searchTerm', $source);
        self::assertStringContainsString("createdby LIKE :searchTerm", $source);
        self::assertStringContainsString("\$params['searchTerm'] = '%' . \$q . '%';", $source);
        self::assertStringContainsString("\$types['searchTerm'] = ParameterType::STRING;", $source);
        self::assertStringNotContainsString("creaturename LIKE '%\$q%'", $source);
    }

    public function testCreaturesListLevelFilterIsBoundIntegerParameter(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/creatures.php');

        self::assertStringContainsString('WHERE creaturelevel = :level', $source);
        self::assertStringContainsString("\$types['level'] = ParameterType::INTEGER;", $source);
        self::assertStringNotContainsString("creaturelevel='\$level'", $source);
    }

    public function testReferersSortUsesAllowlistAndDefaultOrderingFallback(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/referers.php');

        self::assertStringContainsString("\$summaryOrder = 'count DESC';", $source);
        self::assertStringContainsString("\$detailOrder = 'count DESC';", $source);
        self::assertStringContainsString("\$sortColumnMapSummary", $source);
        self::assertStringContainsString("\$sortColumnMapDetail", $source);
        self::assertStringContainsString("'uri'   => 'site'", $source);
        self::assertStringContainsString("'uri'   => 'uri'", $source);
        self::assertStringContainsString("'ASC'  => 'ASC'", $source);
        self::assertStringContainsString("'DESC' => 'DESC'", $source);
        self::assertStringContainsString('$requestedSortDirection = strtoupper((string) ($parts[1] ?? \'ASC\'));', $source);
        self::assertStringContainsString('$summaryOrder = $sortColumnMapSummary[$sortKey] . \' \' . $sortDirection;', $source);
        self::assertStringContainsString('$detailOrder = $sortColumnMapDetail[$sortKey] . \' \' . $sortDirection;', $source);
        self::assertStringNotContainsString('ORDER BY $sort', $source);
    }

    public function testReferersQueriesDoNotInjectRawRequestValuesIntoSql(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/referers.php');

        self::assertStringContainsString('LIMIT :summaryLimit', $source);
        self::assertStringContainsString('WHERE site = :site', $source);
        self::assertStringContainsString('LIMIT :detailLimit', $source);
        self::assertStringContainsString('referers.php?sort=count+{$nextCountDirection}', $source);
        self::assertStringContainsString('referers.php?sort=uri+{$nextUriDirection}', $source);
        self::assertStringContainsString('referers.php?sort=last+{$nextLastDirection}', $source);
        self::assertStringNotContainsString("WHERE site='\" . addslashes(\$row['site']) . \"'", $source);
    }
}
