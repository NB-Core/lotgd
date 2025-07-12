<?php

declare(strict_types=1);

namespace Lotgd {
    if (!function_exists('Lotgd\\getsetting')) {
        function getsetting(string|int $name, mixed $default = ''): mixed
        {
            if (function_exists('\\getsetting')) {
                return \getsetting($name, $default);
            }

            return $default;
        }
    }
}

namespace {
    use PHPUnit\Framework\TestCase;
    use Lotgd\OutputArray;
    use Lotgd\DumpItem;

    require_once __DIR__ . '/../config/constants.php';

    final class DumpOutputTest extends TestCase
    {
        public function testOutputArrayOutputFormatsNestedArray(): void
        {
            $array = ['a' => '1', 'b' => ['c' => '2']];
            $expected = "[a] = 1\n[b] = array{\n[b][c] = 2\n\n}\n";
            $this->assertSame($expected, OutputArray::output($array));
        }

        public function testOutputArrayCodeProducesEvaluatablePhp(): void
        {
            $array = ['a' => '1', 'b' => ['c' => '2']];
            $code = OutputArray::code($array);
            eval('$result = ' . $code . ';');
            $this->assertSame($array, $result);
        }

        public function testDumpItemDumpAndDumpAsCode(): void
        {
            $this->assertSame('foo', DumpItem::dump('foo'));
            $this->assertSame("'foo'", DumpItem::dumpAsCode('foo'));

            $array = ['x' => 'y'];
            $dumpExpected = "array(1) {<div style='padding-left:20pt;'>'x' = 'y'`n</div>}";
            $this->assertSame($dumpExpected, DumpItem::dump($array));

            $codeExpected = "array(\n\t'x'=&gt;'y'\n\t)";
            $this->assertSame($codeExpected, DumpItem::dumpAsCode($array));
        }
    }
}
