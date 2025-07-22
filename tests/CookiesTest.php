<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Cookies;
use PHPUnit\Framework\TestCase;

final class CookiesTest extends TestCase
{
    public function testSetLgiPopulatesCookie(): void
    {
        $id = str_repeat('a', 32);
        Cookies::setLgi($id);
        $this->assertSame($id, $_COOKIE['lgi']);
    }

    public function testGetLgiReturnsNullWhenShort(): void
    {
        $_COOKIE['lgi'] = 'short';
        $this->assertNull(Cookies::getLgi());
    }

    public function testSetTemplateStoresSanitizedValue(): void
    {
        Cookies::setTemplate('../foo');
        $this->assertArrayNotHasKey('template', $_COOKIE);
    }

    public function testSetTemplateDeletesWhenEmpty(): void
    {
        $_COOKIE['template'] = 'bar';
        Cookies::setTemplate('..');
        $this->assertArrayNotHasKey('template', $_COOKIE);
    }

    public function testGetTemplateSanitizesValue(): void
    {
        $_COOKIE['template'] = '../baz';
        $this->assertSame('', Cookies::getTemplate());
    }
}
