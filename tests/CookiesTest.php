<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Lotgd\Cookies;
require_once __DIR__ . '/../config/constants.php';

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
}
