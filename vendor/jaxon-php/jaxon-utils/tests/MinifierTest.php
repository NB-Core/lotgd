<?php

namespace Jaxon\Utils\Tests;

use Jaxon\Utils\File\FileMinifier;
use PHPUnit\Framework\TestCase;

use function file_exists;
use function filesize;

final class MinifierTest extends TestCase
{
    public function testFileNotFound()
    {
        $sSrcFile = __DIR__ . '/minifier/nosrc.js';
        $sDstMinFile = __DIR__ . '/minifier/dst.min.js';
        $xMinifier = new FileMinifier();

        $this->assertFalse($xMinifier->minify($sSrcFile, $sDstMinFile));
    }

    public function testFileError()
    {
        $sSrcFile = __DIR__ . '/minifier/error.js';
        $sDstMinFile = __DIR__ . '/minifier/dst.min.js';
        $xMinifier = new FileMinifier();

        $this->assertFalse($xMinifier->minify($sSrcFile, $sDstMinFile));
    }

    public function testMinifier()
    {
        $sSrcFile = __DIR__ . '/minifier/src.js';
        $sSrcMinFile = __DIR__ . '/minifier/src.min.js';
        $sDstMinFile = __DIR__ . '/minifier/dst.min.js';
        $xMinifier = new FileMinifier();

        $this->assertTrue($xMinifier->minify($sSrcFile, $sDstMinFile));
        $this->assertTrue(file_exists($sDstMinFile));
        $this->assertEquals(filesize($sSrcMinFile), filesize($sDstMinFile));
    }
}
