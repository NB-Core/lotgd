<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Template;
use PHPUnit\Framework\TestCase;

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
        $dir = dirname(__DIR__) . '/templates_twig/aurora';
        $temp = $dir . '.tmp';
        $renamed = false;
        if (is_dir($dir)) {
            $renamed = rename($dir, $temp);
        }
        try {
            $result = Template::addTypePrefix('aurora');
        } finally {
            if ($renamed) {
                rename($temp, $dir);
            }
        }

        $this->assertSame('twig:aurora', $result);
    }
}
