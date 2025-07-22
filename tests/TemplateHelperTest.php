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
}
