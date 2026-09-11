<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use Lotgd\Security\RequestValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Replaces the source-text halves of the deathmessages and taunt binding
 * regression tests.
 *
 * Those asserted that the page files contained the string
 * "ctype_digit($valueString)". That pins a spelling, not a behaviour: it passed
 * while the two pages disagreed about whether boolean true is the row id 1, and
 * it would fail on a rename that changed nothing. These cases call the guard and
 * check what it returns.
 */
final class RequestValueTest extends TestCase
{
    #[DataProvider('identifierProvider')]
    public function testOptionalPositiveInt(mixed $value, ?int $expected): void
    {
        self::assertSame($expected, RequestValue::optionalPositiveInt($value));
    }

    /**
     * @return array<string, array{0: mixed, 1: ?int}>
     */
    public static function identifierProvider(): array
    {
        return [
            // Accepted: what a form field or a query string actually delivers.
            'digit string' => ['17', 17],
            'positive int' => [17, 17],
            'leading zeroes' => ['007', 7],

            // Rejected: out of range.
            'zero string' => ['0', null],
            'zero int' => [0, null],
            'negative int' => [-1, null],
            'negative string' => ['-1', null],
            'explicitly signed' => ['+1', null],

            // Rejected: not a number at all.
            'empty string' => ['', null],
            'null' => [null, null],
            'digits with a suffix' => ['17foo', null],
            'injection attempt' => ["1' OR 1=1 --", null],
            'whitespace padded' => [' 17 ', null],
            'array' => [['17'], null],
            'object' => [new \stdClass(), null],

            // Rejected: scalars that are not identifiers. These are the two the
            // deathmessages copy used to accept as the id 1.
            'boolean true' => [true, null],
            'boolean false' => [false, null],
            'float that looks whole' => [1.0, null],
            'float' => [1.5, null],

            // Rejected: too large to be an id, and not silently truncated.
            'integer overflow string' => [PHP_INT_MAX . '0', null],
        ];
    }

    /**
     * The pair of questions a caller actually has.
     *
     * optionalPositiveInt() returns null both for "nothing was sent" and for
     * "something was sent that is not an id". The editors insert a new row when
     * there is no id and update when there is one, so a caller that cannot tell
     * those apart turns a malformed edit link into a spurious row -- which is
     * exactly what the overflow case below used to do before isPresent()
     * existed, because the cast saturated to PHP_INT_MAX and took the update
     * path instead.
     */
    #[DataProvider('presenceProvider')]
    public function testPresenceIsSeparateFromValidity(mixed $value, bool $present, bool $valid): void
    {
        self::assertSame($present, RequestValue::isPresent($value), 'isPresent()');
        self::assertSame($valid, RequestValue::optionalPositiveInt($value) !== null, 'optionalPositiveInt()');
    }

    /**
     * @return array<string, array{0: mixed, 1: bool, 2: bool}>
     */
    public static function presenceProvider(): array
    {
        return [
            //                                      present, valid
            'absent (Http::get returns false)' => [false, false, false],
            'empty field' => ['', false, false],
            'null' => [null, false, false],

            'a real id' => ['17', true, true],

            // Present but unusable: the page must refuse, not insert.
            'overflowing digit string' => [PHP_INT_MAX . '0', true, false],
            'zero' => ['0', true, false],
            'negative' => ['-1', true, false],
            'digits with a suffix' => ['17foo', true, false],
            'injection attempt' => ["1' OR 1=1 --", true, false],
            'array' => [['17'], true, false],
        ];
    }

    #[DataProvider('textProvider')]
    public function testText(mixed $value, string $expected): void
    {
        self::assertSame($expected, RequestValue::text($value));
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function textProvider(): array
    {
        return [
            'plain string' => ['hello', 'hello'],
            'quotes and backslashes survive intact' => ['He said "hi" \\ it\'s fine', 'He said "hi" \\ it\'s fine'],
            'multibyte survives intact' => ['世界🌟からの"こんにちは"', '世界🌟からの"こんにちは"'],
            'empty string' => ['', ''],
            'int is coerced' => [17, '17'],
            'float is coerced' => [1.5, '1.5'],
            'true is coerced' => [true, '1'],
            'false becomes empty' => [false, ''],
            'null becomes empty' => [null, ''],
            'array becomes empty' => [['a'], ''],
            'object becomes empty' => [new \stdClass(), ''],
        ];
    }
}
