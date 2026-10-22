<?php

declare(strict_types=1);

namespace Lotgd\Tests\Encounter;

use Lotgd\Presence;
use Lotgd\Realtime;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DbMysqli;
use Lotgd\Tests\Stubs\DoctrineConnection;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\TestCase;

/**
 * Who is where (docs/ReactiveCore.md, Phase 7): the footer notes a coarse
 * place, writes only when it changed or a minute passed, and announces only
 * a change.
 */
final class PresenceTest extends TestCase
{
    private const ROW = '/SELECT place, seen FROM presence WHERE acctid = :acctid/';

    private DoctrineConnection $connection;

    protected function setUp(): void
    {
        class_exists(DbMysqli::class);
        class_exists(Database::class);
        $this->connection = new DoctrineConnection();
        Database::setDoctrineConnection($this->connection);
        Settings::setInstance(new DummySettings(['realtime_enabled' => 1, 'LOGINTIMEOUT' => 900]));
        Realtime::resetState();
    }

    protected function tearDown(): void
    {
        Database::resetDoctrineConnection();
    }

    public function testAFirstPageWritesThePlaceAndAnnouncesIt(): void
    {
        Presence::note($this->user(), 'village');

        $write = $this->statement('INSERT INTO presence');
        self::assertSame('village', $write['params']['place']);
        self::assertStringContainsString('changed_us = VALUES(changed_us)', $write['sql']);
        $event = $this->statement('INSERT INTO events');
        self::assertSame('presence', $event['params']['channel']);
        self::assertStringContainsString('"region":"presence"', (string) $event['params']['payload']);
    }

    public function testTheSamePlaceWithinAMinuteWritesNothing(): void
    {
        $this->connection->queryResultsByPattern[self::ROW] = [['place' => 'village', 'seen' => date('Y-m-d H:i:s', time() - 10)]];

        Presence::note($this->user(), 'bank');

        self::assertSame([], $this->connection->executeStatements, 'The bank is in the village.');
    }

    public function testTheSamePlaceAfterAMinuteIsWrittenButNotAnnounced(): void
    {
        $this->connection->queryResultsByPattern[self::ROW] = [['place' => 'village', 'seen' => date('Y-m-d H:i:s', time() - 120)]];

        Presence::note($this->user(), 'village');

        self::assertStringNotContainsString('changed_us = VALUES', $this->statement('INSERT INTO presence')['sql']);
        self::assertSame([], $this->statements('INSERT INTO events'));
    }

    public function testANewPlaceIsAnnounced(): void
    {
        $this->connection->queryResultsByPattern[self::ROW] = [['place' => 'village', 'seen' => date('Y-m-d H:i:s', time() - 10)]];

        Presence::note($this->user(), 'forest');

        self::assertSame('forest', $this->statement('INSERT INTO presence')['params']['place']);
        self::assertCount(1, $this->statements('INSERT INTO events'));
    }

    public function testAReturnAfterTimingOutIsAnnounced(): void
    {
        $this->connection->queryResultsByPattern[self::ROW] = [['place' => 'village', 'seen' => date('Y-m-d H:i:s', time() - 1000)]];

        Presence::note($this->user(), 'village');

        self::assertCount(1, $this->statements('INSERT INTO events'));
    }

    public function testAPageNoPlaceIsKnownForIsElsewhere(): void
    {
        self::assertSame('elsewhere', Presence::placeOf('runmodule'));
        self::assertSame('arena', Presence::placeOf('encounter'));
    }

    public function testWithoutRealtimeOrALoginNothingIsNoted(): void
    {
        Presence::note(['acctid' => 5, 'loggedin' => 0], 'village');

        Settings::setInstance(new DummySettings(['realtime_enabled' => 0]));
        Realtime::resetState();
        Presence::note($this->user(), 'village');

        self::assertSame([], $this->connection->executeStatements);
        self::assertSame([], $this->connection->fetchAssociativeLog);
    }

    public function testLoggingOutRemovesThePlayerAndAnnouncesIt(): void
    {
        $this->connection->statementResultsByPattern['/^UPDATE presence/'] = 1;

        Presence::leave(5);

        $left = $this->statement('UPDATE presence');
        self::assertSame(5, $left['params']['acctid']);
        self::assertStringContainsString("seen = '1970-01-01 00:00:00'", $left['sql'], 'Kept, so the version only grows.');
        $event = $this->statement('INSERT INTO events');
        self::assertSame($left['params']['changed'], json_decode((string) $event['params']['payload'], true)['version']);
    }

    /**
     * @return array<string,mixed>
     */
    private function user(): array
    {
        return ['acctid' => 5, 'loggedin' => 1];
    }

    /**
     * @return list<array{sql:string,params:array<string,mixed>}>
     */
    private function statements(string $prefix): array
    {
        return array_values(array_filter(
            $this->connection->executeStatements,
            static fn (array $s): bool => str_starts_with((string) $s['sql'], $prefix)
        ));
    }

    /**
     * @return array{sql:string,params:array<string,mixed>}
     */
    private function statement(string $prefix): array
    {
        return $this->statements($prefix)[0] ?? self::fail('No statement ' . $prefix);
    }
}
