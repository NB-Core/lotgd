<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Template;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class TemplateTest extends TestCase
{
    protected function setUp(): void
    {
        // Simple template fixture
        Template::getInstance()->setTemplate(['greet' => 'Hello {name}!']);
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

    public function testLoadTemplateAppliesModuleHookChanges(): void
    {
        global $modulehook_returns;

        if (! class_exists('Lotgd\\Modules\\HookHandler', false)) {
            eval('namespace Lotgd\\Modules; class HookHandler { public static function hook($name, $data = [], $allowinactive = false, $only = false) { global $modulehook_returns; return $modulehook_returns[$name] ?? $data; } }');
        }

        $path = dirname(__DIR__) . '/templates/test_template.htm';
        file_put_contents($path, "<!--!test-->original");
        $modulehook_returns = ['template-test' => ['content' => 'modified']];

        try {
            $result = Template::loadTemplate('test_template.htm');
        } finally {
            unlink($path);
            unset($modulehook_returns);
        }

        $this->assertSame('modified', $result['test']);
    }
}
