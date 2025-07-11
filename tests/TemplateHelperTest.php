<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Lotgd\Template;

final class TemplateHelperTest extends TestCase
{
    public function testSanitizesTemplateCookie(): void
    {
        $_COOKIE['template'] = '../foo';
        $result = Template::getTemplateCookie();
        $this->assertSame('foo', $result);
    }
}
