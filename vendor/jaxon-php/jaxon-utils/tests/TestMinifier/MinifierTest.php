<?php

namespace Jaxon\Utils\Tests\TestMinifier;

use Jaxon\Utils\File\FileMinifier;
use PHPUnit\Framework\TestCase;

use function file_exists;
use function file_get_contents;
use function filesize;
use function strlen;

final class MinifierTest extends TestCase
{
    /**
     * @var FileMinifier
     */
    protected $xMinifier;

    protected function setUp(): void
    {
        $this->xMinifier = new FileMinifier();
    }

    public function testFileNotFound()
    {
        $sSrcFile = __DIR__ . '/../minifier/nosrc.js';
        $sDstMinFile = __DIR__ . '/../minifier/dst.min.js';

        $this->assertFalse($this->xMinifier->minify($sSrcFile, $sDstMinFile));
    }

    public function testFileError()
    {
        $sSrcFile = __DIR__ . '/../minifier/error.js';
        $sDstMinFile = __DIR__ . '/../minifier/error.min.js';

        $this->assertFalse($this->xMinifier->minify($sSrcFile, $sDstMinFile));
    }

    public function testJsFileMinifier()
    {
        $sSrcFile = __DIR__ . '/../minifier/src.js';
        $sSrcMinFile = __DIR__ . '/../minifier/src.min.js';
        $sDstMinFile = __DIR__ . '/../minifier/dst.min.js';

        $this->assertTrue($this->xMinifier->minifyJsFile($sSrcFile, $sDstMinFile));
        $this->assertTrue(file_exists($sDstMinFile));
        $this->assertEquals(filesize($sSrcMinFile), filesize($sDstMinFile));
    }

    public function testCssFileMinifier()
    {
        $sSrcFile = __DIR__ . '/../minifier/src.css';
        $sSrcMinFile = __DIR__ . '/../minifier/src.min.css';
        $sDstMinFile = __DIR__ . '/../minifier/dst.min.css';

        $this->assertTrue($this->xMinifier->minifyCssFile($sSrcFile, $sDstMinFile));
        $this->assertTrue(file_exists($sDstMinFile));
        $this->assertEquals(filesize($sSrcMinFile), filesize($sDstMinFile));
    }

    public function testJsCodeMinifier()
    {
        $sSrcFile = __DIR__ . '/../minifier/src.js';
        $sSrcMinFile = __DIR__ . '/../minifier/src.min.js';

        $sMinCode = $this->xMinifier->minifyJsCode(file_get_contents($sSrcFile));

        $this->assertNotFalse($sMinCode);
        $this->assertEquals(filesize($sSrcMinFile), strlen($sMinCode));
    }

    public function testCssCodeMinifier()
    {
        $sSrcFile = __DIR__ . '/../minifier/src.css';
        $sSrcMinFile = __DIR__ . '/../minifier/src.min.css';

        $sMinCode = $this->xMinifier->minifyCssCode(file_get_contents($sSrcFile));

        $this->assertNotFalse($sMinCode);
        $this->assertEquals(filesize($sSrcMinFile), strlen($sMinCode));
    }
}
