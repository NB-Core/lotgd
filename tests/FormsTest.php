<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Forms;
use Lotgd\Output;
use PHPUnit\Framework\TestCase;

final class FormsTest extends TestCase
{
    protected function setUp(): void
    {
        global $forms_output;
        $forms_output = '';
        $ref = new \ReflectionClass(Output::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    public function testCheckboxChecked(): void
    {
        Forms::showForm(['flag' => 'Flag,checkbox'], ['flag' => 1]);
        $output = Output::getInstance()->getRawOutput();
        $this->assertStringContainsString("type='checkbox' name='flag' value='1' checked", $output);
    }

    public function testCheckboxUnchecked(): void
    {
        Forms::showForm(['flag' => 'Flag,checkbox'], ['flag' => 0]);
        $output = Output::getInstance()->getRawOutput();
        $this->assertStringContainsString("type='checkbox' name='flag' value='1'", $output);
        $this->assertStringNotContainsString('checked', $output);
    }

    public function testThemeFieldSkipsInvalidDirectories(): void
    {
        $base = dirname(__DIR__) . '/templates_twig';
        $tempDir = $base . '/test_no_config';
        $hiddenDir = $base . '/.git';

        mkdir($tempDir);
        mkdir($hiddenDir);

        try {
            Forms::showForm(['skin' => 'Skin,theme'], ['skin' => 'aurora']);
        } finally {
            rmdir($tempDir);
            rmdir($hiddenDir);
        }
        $output = Output::getInstance()->getRawOutput();
        $this->assertStringNotContainsString("value='twig:test_no_config'", $output);
        $this->assertStringNotContainsString("value='twig:.git'", $output);
    }

    public function testThemeFieldHandlesNullValue(): void
    {
        Forms::showForm(['skin' => 'Skin,theme'], ['skin' => null]);
        $output = Output::getInstance()->getRawOutput();
        $this->assertStringContainsString("<option value='' selected>---</option>", $output);
    }
}
