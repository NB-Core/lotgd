<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Lotgd\TwigTemplate;

require_once __DIR__ . '/../config/constants.php';

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
        if (is_dir($this->cacheDir)) {
            foreach (glob($this->cacheDir . '/*') as $f) { unlink($f); }
            rmdir($this->cacheDir);
        }
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
