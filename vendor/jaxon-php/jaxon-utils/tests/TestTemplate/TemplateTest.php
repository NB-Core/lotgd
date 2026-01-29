<?php

namespace Jaxon\Utils\Tests\TestTemplate;

use PHPUnit\Framework\TestCase;
use Jaxon\Utils\Template\TemplateEngine;

final class TemplateTest extends TestCase
{
    /**
     * @var TemplateEngine
     */
    protected $xTemplateEngine;

    protected function setUp(): void
    {
        $this->xTemplateEngine = new TemplateEngine();
        $this->xTemplateEngine->addNamespace('test', __DIR__ . '/../templates', '.php');
    }

    public function testTemplate()
    {
        $this->assertEquals('Good morning Mr. Johnson',
            $this->xTemplateEngine->render('test::simple', ['title' => 'Mr.', 'name' => 'Johnson']));
    }

    public function testDefaultNamespace()
    {
        $this->xTemplateEngine->setDefaultNamespace('test');
        $this->assertEquals('Good morning Mr. Johnson',
            $this->xTemplateEngine->render('simple', ['title' => 'Mr.', 'name' => 'Johnson']));
    }

    public function testRenderEmbeddedTemplate()
    {
        $this->assertEquals('Good morning Mr. Johnson.',
            $this->xTemplateEngine->render('test::embedded-content', ['title' => 'Mr.', 'name' => 'Johnson']));
    }

    public function testIncludeEmbeddedTemplate()
    {
        $this->assertEquals('Good morning Mr. Johnson.',
            $this->xTemplateEngine->render('test::embedded-include', ['title' => 'Mr.', 'name' => 'Johnson']));
    }

    public function testMissingTemplate()
    {
        $this->assertEquals('',
            $this->xTemplateEngine->render('test::missing', ['title' => 'Mr.', 'name' => 'Johnson']));
    }

    public function testUnknownNamespace()
    {
        $this->assertEquals('',
            $this->xTemplateEngine->render('toast::embedded-include', ['title' => 'Mr.', 'name' => 'Johnson']));
    }
}
