<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Http;
use PHPUnit\Framework\TestCase;

final class HttpHelperTest extends TestCase
{
    protected function setUp(): void
    {
        $this->resetSuperglobals();
    }

    protected function tearDown(): void
    {
        $this->resetSuperglobals();
    }

    private function resetSuperglobals(): void
    {
        $_GET = [];
        $_POST = [];
        unset($GLOBALS['HTTP_GET_VARS'], $GLOBALS['HTTP_POST_VARS']);
    }

    public function testGetReturnsValueFromGet(): void
    {
        $_GET['foo'] = 'bar';
        $this->assertSame('bar', Http::get('foo'));
    }

    public function testGetFallsBackToGlobal(): void
    {
        $_GET['foo'] = '';
        $GLOBALS['HTTP_GET_VARS']['foo'] = 'baz';
        $this->assertSame('baz', Http::get('foo'));
    }

    public function testAllGetReturnsEntireArray(): void
    {
        $_GET['a'] = '1';
        $_GET['b'] = '2';
        $this->assertSame($_GET, Http::allGet());
    }

    public function testSetUpdatesExistingValue(): void
    {
        $_GET['foo'] = 'old';
        Http::set('foo', 'new');
        $this->assertSame('new', $_GET['foo']);
    }

    public function testSetUsesForceWhenMissing(): void
    {
        Http::set('foo', 'bar', true);
        $this->assertSame('bar', $_GET['foo']);
    }

    public function testPostReturnsValueFromPost(): void
    {
        $_POST['foo'] = 'bar';
        $this->assertSame('bar', Http::post('foo'));
    }

    public function testPostIssetChecksPresence(): void
    {
        $_POST['foo'] = 'bar';
        $this->assertTrue(Http::postIsset('foo'));
        unset($_POST['foo']);
        $this->assertFalse(Http::postIsset('foo'));
    }

    public function testPostSetUpdatesValue(): void
    {
        $_POST['foo'] = 'old';
        Http::postSet('foo', 'new');
        $this->assertSame('new', $_POST['foo']);
    }

    public function testPostSetUpdatesSubValue(): void
    {
        $_POST['foo'] = ['bar' => 'old'];
        Http::postSet('foo', 'new', 'bar');
        $this->assertSame('new', $_POST['foo']['bar']);
    }

    public function testAllPostReturnsEntireArray(): void
    {
        $_POST['a'] = '1';
        $_POST['b'] = '2';
        $this->assertSame($_POST, Http::allPost());
    }

    public function testPostParseReturnsColumnsPlaceholdersAndParameters(): void
    {
        $_POST = ['foo' => 'bar', 'baz' => 'qux'];
        [$columns, $placeholders, $parameters] = Http::postParse();
        $this->assertSame(['foo', 'baz'], $columns);
        $this->assertSame(['?', '?'], $placeholders);
        $this->assertSame(['bar', 'qux'], $parameters);
    }

    public function testPostParseHandlesSubvalAndSerializesArrays(): void
    {
        $_POST['user'] = ['id' => 1, 'prefs' => ['a' => 'b']];
        [$columns, $placeholders, $parameters] = Http::postParse(false, 'user');
        $this->assertSame(['id', 'prefs'], $columns);
        $this->assertSame(['?', '?'], $placeholders);
        $this->assertSame([1, serialize(['a' => 'b'])], $parameters);
    }

    public function testPostParseDoesNotEscapeQuotes(): void
    {
        $_POST = ['title' => "O'Reilly"];
        [, , $parameters] = Http::postParse();
        $this->assertSame(["O'Reilly"], $parameters);
    }

    public function testPostParsePreservesUtf8Characters(): void
    {
        $_POST = ['greeting' => 'こんにちは', 'emoji' => '😊'];
        [$columns, $placeholders, $parameters] = Http::postParse();
        $this->assertSame(['greeting', 'emoji'], $columns);
        $this->assertSame(['?', '?'], $placeholders);
        $this->assertSame(['こんにちは', '😊'], $parameters);
    }

    public function testPostParseRespectsVerifyList(): void
    {
        $_POST = ['allowed' => 'yes', 'ignored' => 'no'];
        [$columns, $placeholders, $parameters] = Http::postParse(['allowed' => true]);
        $this->assertSame(['allowed'], $columns);
        $this->assertSame(['?'], $placeholders);
        $this->assertSame(['yes'], $parameters);
    }
}
