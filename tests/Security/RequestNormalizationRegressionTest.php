<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use PHPUnit\Framework\TestCase;

final class RequestNormalizationRegressionTest extends TestCase
{
    public function testViewpetitionUsesTypedBindingsForRequestDrivenPetitionIdQueries(): void
    {
        $source = file_get_contents(__DIR__ . '/../../viewpetition.php');
        self::assertIsString($source);
        self::assertStringContainsString('WHERE petitionid = :petitionid', $source);
        self::assertStringContainsString("'petitionid' => ParameterType::INTEGER", $source);
        self::assertStringNotContainsString("petitionid='$id'", $source);
    }

    public function testStablesUsesPreparedStatementForMountLookup(): void
    {
        $source = file_get_contents(__DIR__ . '/../../stables.php');
        self::assertIsString($source);
        self::assertStringContainsString('WHERE mountid = :mountid', $source);
        self::assertStringContainsString("'mountid' => ParameterType::INTEGER", $source);
        self::assertStringNotContainsString("mountid='$id'", $source);
    }

    public function testNavCompositionUsesNormalizedOrEncodedQueryComponents(): void
    {
        $healer = file_get_contents(__DIR__ . '/../../healer.php');
        $user = file_get_contents(__DIR__ . '/../../user.php');
        $userEdit = file_get_contents(__DIR__ . '/../../pages/user/user_edit.php');
        $hof = file_get_contents(__DIR__ . '/../../hof.php');
        $modules = file_get_contents(__DIR__ . '/../../modules.php');

        self::assertIsString($healer);
        self::assertIsString($user);
        self::assertIsString($userEdit);
        self::assertIsString($hof);
        self::assertIsString($modules);

        self::assertStringContainsString("rawurlencode(\$return)", $healer);
        self::assertStringContainsString('modulename_sanitize($moduleRequest)', $user);
        self::assertStringContainsString("rawurlencode(\$moduleSlug)", $user);
        self::assertStringContainsString("ctype_digit(\$petitionRequest)", $userEdit);
        self::assertStringContainsString("\$allowedOps = ['kills', 'money', 'gems', 'charm', 'tough', 'resurrects', 'days'];", $hof);
        self::assertStringContainsString("array_key_exists(\$catRequest, \$seencats)", $modules);
        self::assertStringContainsString("rawurlencode(\$cat)", $modules);
    }
}
