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
        $nonCommentSource = (string) preg_replace('/^\\s*\\/\\/.*$/m', '', $source);
        self::assertMatchesRegularExpression('/WHERE\\s+petitionid\\s*=\\s*:petitionid/i', $source);
        self::assertMatchesRegularExpression('/[\'"]petitionid[\'"]\\s*=>\\s*ParameterType::INTEGER/', $source);
        self::assertDoesNotMatchRegularExpression('/petitionid\\s*=\\s*[\'"]\\$id[\'"]/', $nonCommentSource);
    }

    public function testStablesUsesPreparedStatementForMountLookup(): void
    {
        $source = file_get_contents(__DIR__ . '/../../stables.php');
        self::assertIsString($source);
        self::assertMatchesRegularExpression('/WHERE\\s+mountid\\s*=\\s*:mountid/i', $source);
        self::assertMatchesRegularExpression('/[\'"]mountid[\'"]\\s*=>\\s*ParameterType::INTEGER/', $source);
        self::assertDoesNotMatchRegularExpression('/mountid\\s*=\\s*[\'"]\\$id[\'"]/', $source);
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

        self::assertMatchesRegularExpression('/rawurlencode\\(\\$return\\)/', $healer);
        self::assertMatchesRegularExpression('/modulename_sanitize\\(\\$moduleRequest\\)/', $user);
        self::assertMatchesRegularExpression('/rawurlencode\\(\\$moduleSlug\\)/', $user);
        self::assertMatchesRegularExpression('/ctype_digit\\(\\$petitionRequest\\)/', $userEdit);
        self::assertMatchesRegularExpression('/\\$allowedOps\\s*=\\s*\\[[^\\]]*kills[^\\]]*resurrects[^\\]]*\\]/', $hof);
        self::assertMatchesRegularExpression('/array_key_exists\\(\\$catRequest,\\s*\\$seencats\\)/', $modules);
        self::assertMatchesRegularExpression('/rawurlencode\\(\\$cat\\)/', $modules);
    }
}
