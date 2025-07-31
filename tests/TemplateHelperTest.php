<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Template;
use PHPUnit\Framework\TestCase;

final class TemplateHelperTest extends TestCase
{
    public function testSanitizesTemplateCookie(): void
    {
        $_COOKIE['template'] = '../foo';
        $result = Template::getTemplateCookie();
        $this->assertSame('', $result);
    }

    public function testSetTemplateCookieStoresSanitizedValue(): void
    {
        Template::setTemplateCookie('../bar');
        $this->assertArrayNotHasKey('template', $_COOKIE);
    }

    public function testDefaultTemplateIsAvailable(): void
    {
        $templates = Template::getAvailableTemplates();
        $this->assertArrayHasKey(DEFAULT_TEMPLATE, $templates);
    }

    public function testInvalidTemplateIsRejected(): void
    {
        $this->assertFalse(Template::isValidTemplate('nonexistent-template'));
    }

    public function testDirectoriesWithoutConfigAreIgnored(): void
    {
        $base = dirname(__DIR__) . '/templates_twig';
        $tempDir = $base . '/test_no_config';
        $hiddenDir = $base . '/.git';

        mkdir($tempDir);
        mkdir($hiddenDir);

        try {
            $templates = Template::getAvailableTemplates();
        } finally {
            rmdir($tempDir);
            rmdir($hiddenDir);
        }

        $this->assertArrayNotHasKey('twig:test_no_config', $templates);
        $this->assertArrayNotHasKey('twig:.git', $templates);
    }
}
