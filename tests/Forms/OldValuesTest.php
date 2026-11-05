<?php

declare(strict_types=1);

namespace Lotgd\Tests\Forms;

use Lotgd\Forms;
use PHPUnit\Framework\TestCase;

/**
 * The values a form showed travel in a hidden field (prefs.php, the user
 * editor): they must survive the browser, which sends a line break as CRLF,
 * and reading them must never create objects.
 */
final class OldValuesTest extends TestCase
{
    public function testTheValuesComeBackAsTheyWere(): void
    {
        $values = ['bio' => "line one\nline two", 'ajax' => 1, 'name' => "Ünï 'quoted' \"too\"", 'empty' => ''];

        self::assertSame($values, Forms::decodeOldValues(Forms::encodeOldValues($values)));
    }

    public function testTheFieldHoldsNoLineBreakForTheBrowserToChange(): void
    {
        $encoded = Forms::encodeOldValues(['bio' => "a\nb\r\nc"]);

        self::assertDoesNotMatchRegularExpression('/[\r\n]/', $encoded);
    }

    public function testAFormFromBeforeTheUpdateIsReadWithItsLineBreaksAsSent(): void
    {
        // Serialized PHP, its line break sent back as CRLF: the stored
        // length no longer matched (the prefs.php:238 warning).
        $sent = str_replace("\n", "\r\n", serialize(['bio' => "a\nb", 'ajax' => '1']));

        self::assertSame(['bio' => "a\nb", 'ajax' => '1'], Forms::decodeOldValues($sent));
    }

    public function testAnObjectInAnOldFormIsNotCreated(): void
    {
        $decoded = Forms::decodeOldValues(serialize(['x' => new \ArrayObject([1])]));

        self::assertArrayHasKey('x', $decoded);
        self::assertInstanceOf(\__PHP_Incomplete_Class::class, $decoded['x']);
    }

    /**
     * @return iterable<string,array{0:string}>
     */
    public static function unreadable(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'garbage' => ['not a value'];
        yield 'base64 of no JSON' => [base64_encode('nope')];
        yield 'a broken serialization' => ['a:1:{s:3:"bio";s:9:"a'];
        yield 'a scalar' => [base64_encode('5')];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unreadable')]
    public function testAnUnreadableFieldGivesNoValues(string $field): void
    {
        self::assertSame([], Forms::decodeOldValues($field));
    }
}
