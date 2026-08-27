<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Guards the prepared-statement, validation, authorization, and CSRF boundaries
 * of the legacy superuser item editors.
 */
final class EditorDoctrineHardeningRegressionTest extends TestCase
{
    public function testMountXmlIsAuthorizedValidatedAndBound(): void
    {
        $source = $this->source('mounts.php');

        self::assertMatchesRegularExpression('/if \(\$op == "xml"\) \{\s+SuAccess::check\(SU_EDIT_MOUNTS\)/', $source);
        self::assertStringContainsString("['mountId' => \$id]", $source);
        self::assertStringContainsString("['mountId' => ParameterType::INTEGER]", $source);
        self::assertStringContainsString('function mountEditorPositiveInteger(mixed $value): ?int', $source);
        self::assertStringNotContainsString('WHERE hashorse=$id', $source);
    }

    public function testMountStateChangesArePostOnlyAndPrepared(): void
    {
        $source = $this->source('mounts.php');

        self::assertStringContainsString("(\$_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'", $source);
        self::assertStringContainsString("Http::post('csrf_token')", $source);
        self::assertStringContainsString("WHERE mountid = :mountId", $source);
        self::assertStringContainsString("WHERE hashorse = :mountId", $source);
        self::assertStringNotContainsString("mountid='\$id'", $source);
        self::assertStringContainsString("method='POST'", $source);
    }

    public function testCompanionColumnsComeOnlyFromTypedAllowlist(): void
    {
        $source = $this->source('companions.php');

        self::assertStringContainsString('function companionEditorFieldMap(): array', $source);
        self::assertStringContainsString('if (!is_string($field) || !isset($map[$field]))', $source);
        self::assertStringContainsString('$normalizedValue = serialize($abilities);', $source);
        self::assertStringNotContainsString('addslashes(serialize(', $source);
        self::assertStringContainsString("['id' => ParameterType::INTEGER]", $source);
        self::assertStringContainsString("(\$_SERVER['REQUEST_METHOD'] ?? '') === 'POST'", $source);
    }

    public function testEquipmentEditorsValidateIndicesAndBindNames(): void
    {
        foreach ([['armoreditor.php', 'defense'], ['weaponeditor.php', 'damage']] as [$file, $stat]) {
            $source = $this->source($file);

            self::assertStringContainsString("Http::post('$stat'), 1, count(\$values)", $source);
            self::assertStringContainsString("'name' => ParameterType::STRING", $source);
            self::assertStringContainsString("'$stat' => ParameterType::INTEGER", $source);
            self::assertStringContainsString("Http::post('csrf_token')", $source);
            self::assertStringContainsString("method='POST'", $source);
            self::assertStringNotContainsString("\$values[(int)", $source);
        }
    }

    public function testScalarValidatorsRejectQuoteBearingAndArrayInputs(): void
    {
        foreach (['armoreditor.php', 'weaponeditor.php', 'mounts.php', 'companions.php'] as $file) {
            $source = $this->source($file);
            self::assertStringContainsString('!is_int($value) && !is_string($value)', $source);
            self::assertStringContainsString('FILTER_VALIDATE_INT', $source);
        }
    }

    private function source(string $file): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);
    }
}
