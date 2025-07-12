<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Lotgd\Template;

require_once __DIR__ . '/../config/constants.php';

final class TemplateTest extends TestCase
{
    protected function setUp(): void
    {
        // Simple template fixture
        $GLOBALS['template'] = ['greet' => 'Hello {name}!'];
    }

    public function testTemplateReplace(): void
    {
        $result = Template::templateReplace('greet', ['name' => 'Bob']);
        $this->assertSame('Hello Bob!', $result);
    }

    public function testAddTypePrefixReturnsLegacyWhenTwigDirMissing(): void
    {
        $tempDir = sys_get_temp_dir() . '/templates_twig_aurora';
        mkdir($tempDir);
        try {
            $result = Template::addTypePrefix('aurora');
        } finally {
            rmdir($tempDir);
        }

        $this->assertSame('legacy:aurora', $result);
    }
}
