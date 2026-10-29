<?php

declare(strict_types=1);

namespace Lotgd\Tests\Modules;

use Lotgd\Modules;
use Lotgd\Page\PartialMode;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DoctrineConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A module lets its pages change in place by declaring them under the info
 * key `partial`. The declaration is stored in its row (column partial) when
 * inject() reads the info anyway, and read back from there for the partial
 * decision, before any module code runs.
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class PartialDeclarationTest extends TestCase
{
    private string $moduleDir;
    private string $moduleFile;
    private string $origCwd;
    private DoctrineConnection $connection;

    protected function setUp(): void
    {
        class_exists(Database::class);
        \Lotgd\MySQL\Database::$queryCacheResults = [];
        $this->connection = new DoctrineConnection();
        \Lotgd\MySQL\Database::setDoctrineConnection($this->connection);
        Modules::resetInjectedModules();

        $this->origCwd = (string) getcwd();
        $this->moduleDir = sys_get_temp_dir() . '/lotgd_module_' . uniqid();
        mkdir($this->moduleDir . '/modules', 0777, true);
        $this->moduleFile = $this->moduleDir . '/modules/partialModule.php';
        file_put_contents($this->moduleFile, <<<'PHP'
<?php
function partialModule_getmoduleinfo(): array
{
    return [
        'name' => 'Partial Module',
        'version' => '1.0',
        'partial' => ['get' => ['', 'list', 'list', 3], 'POST' => ['buy'], 'PUT' => ['x']],
    ];
}
function partialModule_install(): bool { return true; }
function partialModule_uninstall(): bool { return true; }
PHP);
    }

    protected function tearDown(): void
    {
        @unlink($this->moduleFile);
        @rmdir($this->moduleDir . '/modules');
        @rmdir($this->moduleDir);
        chdir($this->origCwd);
        \Lotgd\MySQL\Database::$queryCacheResults = [];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function storeRow(string $module, array $row): void
    {
        // Twice: the stub's fetchAssoc() takes the row out of the result.
        \Lotgd\MySQL\Database::$queryCacheResults['inject-' . $module] = [$row, $row];
    }

    public function testTheDeclarationIsStoredNormalized(): void
    {
        self::assertSame(
            ['GET' => ['', 'list'], 'POST' => ['buy']],
            Modules::normalizePartial(['get' => ['', 'list', 'list', 3], 'POST' => ['buy'], 'PUT' => ['x']])
        );
        self::assertNull(Modules::normalizePartial(['GET' => []]));
        self::assertNull(Modules::normalizePartial('everything'));
    }

    public function testInjectStoresADeclarationThatDiffersOnce(): void
    {
        $this->inject(['partial' => null]);

        $stored = $this->partialWrites();
        self::assertCount(1, $stored);
        self::assertSame('{"GET":["","list"],"POST":["buy"]}', $stored[0]['params']['partial']);
    }

    public function testInjectWritesNothingWhenTheStoredDeclarationMatches(): void
    {
        $this->inject(['partial' => '{"GET":["","list"],"POST":["buy"]}']);

        self::assertSame([], $this->partialWrites());
    }

    public function testInjectLeavesARowFromBeforeTheColumnAlone(): void
    {
        $this->inject([]);

        self::assertSame([], $this->partialWrites());
    }

    public function testTheStoredDeclarationIsReadWithoutRunningTheModule(): void
    {
        $this->storeRow('partialModule', ['active' => 1, 'partial' => '{"GET":["","list"],"POST":["buy"]}']);

        self::assertSame(['GET' => ['', 'list'], 'POST' => ['buy']], Modules::partialDeclaration('partialModule'));
        self::assertFalse(function_exists('partialModule_getmoduleinfo'), 'The module file was not loaded.');
    }

    public function testAnInactiveOrUnknownModuleDeclaresNothing(): void
    {
        $this->storeRow('sleepy', ['active' => 0, 'partial' => '{"GET":[""]}']);

        self::assertSame([], Modules::partialDeclaration('sleepy'));
        self::assertSame([], Modules::partialDeclaration('missing'));
        self::assertSame([], Modules::partialDeclaration('../village'));
    }

    /**
     * @param array<string,string> $query
     */
    #[DataProvider('runmoduleRequests')]
    public function testAModulePageChangesInPlaceOnlyAsDeclared(string $method, string $op, array $query, bool $allowed): void
    {
        $this->storeRow('partialModule', ['active' => 1, 'partial' => '{"GET":["","list"],"POST":["buy"]}']);

        self::assertSame($allowed, PartialMode::isAllowed('runmodule.php', $method, $op, $query));
    }

    /**
     * @return iterable<string,array{string,string,array<string,string>,bool}>
     */
    public static function runmoduleRequests(): iterable
    {
        yield 'declared page' => ['GET', 'list', ['module' => 'partialModule', 'op' => 'list'], true];
        yield 'declared page without op' => ['GET', '', ['module' => 'partialModule'], true];
        yield 'declared form' => ['POST', 'buy', ['module' => 'partialModule', 'op' => 'buy'], true];
        yield 'page not declared' => ['GET', 'buy', ['module' => 'partialModule', 'op' => 'buy'], false];
        yield 'form not declared' => ['POST', 'list', ['module' => 'partialModule', 'op' => 'list'], false];
        yield 'administration' => ['GET', 'list', ['module' => 'partialModule', 'op' => 'list', 'admin' => '1'], false];
        yield 'no module' => ['GET', '', [], false];
    }

    public function testThePageListsOnlyItsOwnFormsThatPostInPlace(): void
    {
        $this->storeRow('partialModule', ['active' => 1, 'partial' => '{"GET":["","list"],"POST":["buy"]}']);

        $forms = PartialMode::pageForms([
            'runmodule.php?module=partialModule&op=buy&id=3' => true,
            'runmodule.php?module=partialModule&op=list' => true,
            'bank.php?op=depositfinish' => true,
            // A form that posts back to the page's own address, page
            // counter and all (the bartender's colour test).
            'inn.php?op=bartender&act=colors&c=12-101010' => true,
            // A link to a page, not a form target.
            'bank.php?op=deposit&c=12-101010' => true,
            'mail.php?op=send' => true,
            'clan.php?op=motd' => false,
            'https://example.com/bank.php?op=depositfinish' => true,
        ]);

        self::assertSame(
            ['runmodule.php?module=partialModule&op=buy&id=3', 'bank.php?op=depositfinish', 'inn.php?op=bartender&act=colors&c=12-101010'],
            $forms
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    private function inject(array $row): void
    {
        $row += [
            'active' => 1,
            'infokeys' => '|name|version|partial|',
            'version' => '1.0',
            'filemoddate' => date('Y-m-d H:i:s', (int) filemtime($this->moduleFile)),
            'filehash' => sha1_file($this->moduleFile),
        ];
        $this->storeRow('partialModule', $row);
        chdir($this->moduleDir);
        self::assertTrue(Modules::inject('partialModule'));
        chdir($this->origCwd);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function partialWrites(): array
    {
        return array_values(array_filter(
            $this->connection->executeStatements,
            static fn (array $s): bool => str_contains((string) $s['sql'], 'SET partial = :partial')
        ));
    }
}
