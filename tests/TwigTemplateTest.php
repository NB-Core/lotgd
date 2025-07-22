<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\TwigTemplate;
use PHPUnit\Framework\TestCase;

final class TwigTemplateTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/twig_cache_' . uniqid();
        mkdir($this->cacheDir, 0700, true);
        TwigTemplate::init('aurora', $this->cacheDir);
    }

    protected function tearDown(): void
    {
        TwigTemplate::deactivate();
        $this->removeDir($this->cacheDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }

    public function testRenderSimpleTemplate(): void
    {
        $html = TwigTemplate::render('navitem.twig', [
            'link' => 'foo.php',
            'accesskey' => '',
            'popup' => '',
            'text' => 'Foo'
        ]);
        $this->assertStringContainsString('href="foo.php"', $html);
        $this->assertStringContainsString('Foo', $html);
    }

    public function testGetPathAndIsActive(): void
    {
        $this->assertTrue(TwigTemplate::isActive());
        $this->assertSame('templates_twig/aurora', TwigTemplate::getPath());
    }
}
