<?php

declare(strict_types=1);

namespace Lotgd\Tests\Async;

use Lotgd\Async\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testAnEmptyResponseHasTheDocumentedShape(): void
    {
        self::assertSame(['status' => 'ok', 'commands' => [], 'data' => []], (new Response())->toArray());
    }

    public function testCommandsAreKeptInTheOrderTheyWereAdded(): void
    {
        $response = (new Response())
            ->assign('a', 'innerHTML', '<b>x</b>')
            ->call('fn', 1, 'two', ['three'])
            ->title('Title')
            ->append('b', 'innerHTML', '<i>y</i>')
            ->call('noargs');

        self::assertSame([
            ['cmd' => 'assign', 'id' => 'a', 'prop' => 'innerHTML', 'value' => '<b>x</b>'],
            ['cmd' => 'call', 'fn' => 'fn', 'args' => [1, 'two', ['three']]],
            ['cmd' => 'title', 'value' => 'Title'],
            ['cmd' => 'append', 'id' => 'b', 'prop' => 'innerHTML', 'value' => '<i>y</i>'],
            ['cmd' => 'call', 'fn' => 'noargs', 'args' => []],
        ], $response->getCommands());
    }

    public function testCallArgumentsAreAList(): void
    {
        $response = (new Response())->call('fn', ...['x' => 1, 'y' => 2]);

        self::assertSame([1, 2], $response->getCommands()[0]['args']);
    }

    public function testDataIsKeyedAndLaterWritesToTheSameKeyWin(): void
    {
        $response = (new Response())->data('k', 1)->data('other', ['a' => 1])->data('k', 2);

        self::assertSame(['k' => 2, 'other' => ['a' => 1]], $response->getData());
    }

    public function testMergeAppendsTheCommandsOfTheOtherResponseAfterTheOwn(): void
    {
        $first = (new Response())->assign('a', 'innerHTML', '1')->call('one');
        $second = (new Response())->title('t')->call('two');

        $result = $first->merge($second);

        self::assertSame($first, $result, 'merge is fluent');
        self::assertSame(
            ['assign', 'call', 'title', 'call'],
            array_column($first->getCommands(), 'cmd')
        );
        self::assertSame('one', $first->getCommands()[1]['fn']);
        self::assertSame('two', $first->getCommands()[3]['fn']);
        self::assertCount(2, $second->getCommands(), 'the merged response is left alone');
    }

    public function testMergedDataWinsOverTheReceiversOnTheSameKey(): void
    {
        // The response merged in is the later one, and the later write wins.
        $first = (new Response())->data('shared', 'first')->data('only-first', 1);
        $second = (new Response())->data('shared', 'second')->data('only-second', 2);

        $first->merge($second);

        self::assertEqualsCanonicalizing(
            ['shared' => 'second', 'only-first' => 1, 'only-second' => 2],
            $first->getData()
        );
    }

    public function testMergingSeveralResponsesKeepsTheLatestDataAndAllCommandsInOrder(): void
    {
        $result = (new Response())->call('a');
        $result->merge((new Response())->call('b')->data('k', 'b'));
        $result->merge((new Response())->call('c')->data('k', 'c')->data('z', 'c'));

        self::assertSame(['a', 'b', 'c'], array_column($result->getCommands(), 'fn'));
        self::assertSame(['k' => 'c', 'z' => 'c'], $result->getData());
    }

    public function testToArrayIsJsonSerialisableAndKeepsTheShape(): void
    {
        $response = (new Response())
            ->assign('x', 'innerHTML', 'ä<>&"')
            ->data('realtime', ['disabled' => true]);

        $decoded = json_decode((string) json_encode($response->toArray()), true);

        self::assertSame('ok', $decoded['status']);
        self::assertSame($response->getCommands(), $decoded['commands']);
        self::assertSame(['realtime' => ['disabled' => true]], $decoded['data']);
        self::assertSame(['status', 'commands', 'data'], array_keys($decoded));
    }
}
