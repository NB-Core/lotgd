<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for creature edit lookup query hardening.
 *
 * These assertions ensure creature edit reads stay parameterized and preserve
 * the existing not-found user experience.
 */
final class CreatureEditLookupQueryBindingRegressionTest extends TestCase
{
    public function testEditLookupUsesExecuteQueryWithBoundIntegerIdForValidInput(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/creatures.php');

        self::assertStringContainsString(
            'SELECT * FROM {$creaturesTable} WHERE creatureid = :id',
            $source
        );
        self::assertStringContainsString(
            "['id' => (int) \$id]",
            $source
        );
        self::assertStringContainsString(
            "['id' => ParameterType::INTEGER]",
            $source
        );
        self::assertStringContainsString(
            '$row = $result->fetchAssociative();',
            $source
        );
    }

    public function testEditLookupSafelyCastsMalformedInputAndKeepsNotFoundBehavior(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/creatures.php');

        self::assertStringContainsString(
            "['id' => (int) \$id]",
            $source
        );
        self::assertStringContainsString(
            "if (\$row === false) {",
            $source
        );
        self::assertStringContainsString(
            '$output->output("`4Error`0, that creature was not found!");',
            $source
        );
        self::assertStringNotContainsString(
            'WHERE creatureid=$id',
            $source
        );
    }
}
