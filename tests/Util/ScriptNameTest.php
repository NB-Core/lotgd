<?php

declare(strict_types=1);

namespace Lotgd\Tests\Util;

use Lotgd\Util\ScriptName;
use PHPUnit\Framework\TestCase;

class ScriptNameTest extends TestCase
{
    public function testScriptWithoutExtension(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/village';
        $this->assertSame('village', ScriptName::current());
    }

    public function testDirectoryStyleRequest(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/village/';
        $this->assertSame('village', ScriptName::current());
    }
}
