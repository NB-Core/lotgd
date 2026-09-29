<?php

declare(strict_types=1);

namespace Lotgd\Tests\Installer;

use Lotgd\Installer\HeadlessInstall;
use Lotgd\Installer\Installer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The command-line install for containers. It must never create an account
 * nobody chose, never take a password on the command line, and never touch a
 * database that already holds a game.
 */
final class HeadlessInstallTest extends TestCase
{
    private const ENV = [
        'MYSQL_HOST' => 'db',
        'MYSQL_USER' => 'lotgduser',
        'MYSQL_PASSWORD' => 'secret',
        'MYSQL_DATABASE' => 'lotgd',
        'MYSQL_USEDATACACHE' => '1',
        'MYSQL_DATACACHEPATH' => '/var/cache/lotgd',
    ];

    public function testTheAdministratorNameIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no default administrator');

        (new HeadlessInstall(self::ENV))->parse([]);
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'too short' => ['ab'];
        yield 'too long' => [str_repeat('a', 26)];
        yield 'starts with a digit' => ['1admin'];
        yield 'space' => ['the admin'];
        yield 'markup' => ['<b>x</b>'];
        yield 'quote' => ["o'neil"];
    }

    #[DataProvider('invalidNames')]
    public function testInvalidNamesAreRefused(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new HeadlessInstall(self::ENV))->parse(['--admin=' . $name]);
    }

    public function testWithoutAPasswordOneIsGenerated(): void
    {
        $request = (new HeadlessInstall(self::ENV))->parse(['--admin=Oliver']);

        self::assertSame('Oliver', $request['admin']);
        self::assertTrue($request['generated']);
        self::assertSame(HeadlessInstall::GENERATED_PASSWORD_LENGTH, strlen($request['password']));
        self::assertSame('recommended', $request['modules']);
    }

    public function testGeneratedPasswordsAreRandomAndUnambiguous(): void
    {
        $passwords = [];
        for ($i = 0; $i < 50; $i++) {
            $password = HeadlessInstall::generatePassword();
            self::assertMatchesRegularExpression('/^[A-HJ-NP-Za-km-z2-9]{24}$/', $password);
            $passwords[$password] = true;
        }

        self::assertCount(50, $passwords);
    }

    public function testAPasswordIsReadFromStandardInput(): void
    {
        $stdin = $this->stream("a-long-enough-password\n");

        $request = (new HeadlessInstall(self::ENV))->parse(['--admin=Oliver', '--password-stdin'], $stdin);

        self::assertSame('a-long-enough-password', $request['password']);
        self::assertFalse($request['generated']);
    }

    public function testAShortPasswordFromStandardInputIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 12 characters');

        (new HeadlessInstall(self::ENV))->parse(['--admin=Oliver', '--password-stdin'], $this->stream("short\n"));
    }

    public function testAPasswordIsNeverTakenAsAnArgument(): void
    {
        // An argument shows up in the process list and the shell history.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown argument');

        (new HeadlessInstall(self::ENV))->parse(['--admin=Oliver', '--password=hunter2hunter2']);
    }

    public function testTheModuleSetIsChecked(): void
    {
        self::assertSame('none', (new HeadlessInstall(self::ENV))->parse(['--admin=Oliver', '--modules=none'])['modules']);

        $this->expectException(\InvalidArgumentException::class);
        (new HeadlessInstall(self::ENV))->parse(['--admin=Oliver', '--modules=all']);
    }

    public function testTheDatabaseComesFromTheEnvironment(): void
    {
        self::assertSame(
            [
                'DB_HOST' => 'db',
                'DB_USER' => 'lotgduser',
                'DB_PASS' => 'secret',
                'DB_NAME' => 'lotgd',
                'DB_PREFIX' => '',
                'DB_USEDATACACHE' => 1,
                'DB_DATACACHEPATH' => '/var/cache/lotgd',
            ],
            (new HeadlessInstall(self::ENV))->dbinfo()
        );
    }

    public function testTheCacheIsOffWithoutAPath(): void
    {
        $env = self::ENV;
        unset($env['MYSQL_DATACACHEPATH']);

        self::assertSame(0, (new HeadlessInstall($env))->dbinfo()['DB_USEDATACACHE']);
    }

    public function testMissingDatabaseVariablesAreNamed(): void
    {
        $env = self::ENV;
        unset($env['MYSQL_PASSWORD'], $env['MYSQL_DATABASE']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MYSQL_PASSWORD, MYSQL_DATABASE missing');

        (new HeadlessInstall($env))->dbinfo();
    }

    public function testAnExistingGameIsNotInstalledOver(): void
    {
        self::assertTrue(HeadlessInstall::alreadyInstalled(static fn (string $like): bool => $like === '%settings'));
        self::assertTrue(HeadlessInstall::alreadyInstalled(static fn (string $like): bool => $like === '%accounts'));
        self::assertFalse(HeadlessInstall::alreadyInstalled(static fn (string $like): bool => false));
    }

    public function testAGameWithATablePrefixIsNotInstalledOver(): void
    {
        $tables = ['lotgd_accounts', 'lotgd_settings'];
        $like = static function (string $pattern) use ($tables): bool {
            $regex = '/^' . str_replace('%', '.*', preg_quote($pattern, '/')) . '$/i';

            return preg_grep($regex, $tables) !== [];
        };

        self::assertTrue(HeadlessInstall::alreadyInstalled($like));
    }

    public function testNothingStandsInTheWayOutsideAContainer(): void
    {
        self::assertSame([], HeadlessInstall::preflight(null));
    }

    public function testTheCompletionMarkerMustBeWritable(): void
    {
        $directory = sys_get_temp_dir() . '/lotgd_headless_state_' . uniqid();
        mkdir($directory, 0700);

        try {
            self::assertSame([], HeadlessInstall::preflight($directory . '/installation-complete'));

            mkdir($directory . '/installation-complete');
            self::assertStringContainsString(
                'is a directory',
                implode("\n", HeadlessInstall::preflight($directory . '/installation-complete'))
            );
            rmdir($directory . '/installation-complete');

            self::assertStringContainsString(
                'not writable',
                implode("\n", HeadlessInstall::preflight($directory . '/missing/installation-complete'))
            );
        } finally {
            rmdir($directory);
        }
    }

    public function testNothingRunsAfterMigrationsThatDidNotComplete(): void
    {
        $stages = [];
        $installer = $this->installer($stages, true);

        try {
            HeadlessInstall::runStages($installer, $this->request('Admin', 'correct-horse'), [], [], static fn (): array => ['Version20250101000000']);
            self::fail('The stages went on after failed migrations.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('migrations did not complete', $exception->getMessage());
        }

        self::assertSame([7, 8, 9], $stages);
    }

    public function testAPasswordWithABackslashIsRefused(): void
    {
        // login.php strips backslashes from the password it is given.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('backslash');

        (new HeadlessInstall(self::ENV))->parse(['--admin=Admin', '--password-stdin'], $this->stream("safe\\password123\n"));
    }

    public function testEveryStageRunsWhenNothingFails(): void
    {
        $stages = [];
        $installer = $this->installer($stages, true);

        $noneMissing = static fn (): array => [];
        HeadlessInstall::runStages($installer, $this->request('Admin', 'correct-horse'), [], [], $noneMissing);

        self::assertSame([7, 8, 9, 10, 11], $stages);
    }

    public function testAnUnwrittenCompletionMarkerFailsTheInstallation(): void
    {
        $stages = [];
        $installer = $this->installer($stages, false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('completion marker');

        $noneMissing = static fn (): array => [];
        HeadlessInstall::runStages($installer, $this->request('Admin', 'correct-horse'), [], [], $noneMissing);
    }

    public function testOnlyRecommendedModulesThatExistAreInstalled(): void
    {
        $directory = sys_get_temp_dir() . '/lotgd_headless_modules_' . uniqid();
        mkdir($directory, 0700);
        touch($directory . '/abigail.php');
        touch($directory . '/lottery.php');

        try {
            self::assertSame(
                ['abigail' => 'install,activate', 'lottery' => 'install,activate'],
                HeadlessInstall::moduleOperations(['abigail', 'gone', 'lottery'], $directory)
            );
        } finally {
            unlink($directory . '/abigail.php');
            unlink($directory . '/lottery.php');
            rmdir($directory);
        }
    }

    public function testTheCommandRefusesToRunInAWebServer(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/install');

        self::assertStringContainsString("PHP_SAPI !== 'cli'", $source);
    }

    /**
     * @param list<int> $stages Receives the stages run, in order
     */
    private function installer(array &$stages, bool $markerWritten): Installer
    {
        $installer = $this->createStub(Installer::class);
        $installer->method('recordContainerInstallationCompletion')->willReturn($markerWritten);
        $installer->method('runStage')->willReturnCallback(static function (int $stage) use (&$stages): void {
            $stages[] = $stage;
        });

        return $installer;
    }

    /**
     * @return array{admin:string,password:string,generated:bool,modules:string}
     */
    private function request(string $admin, string $password): array
    {
        return ['admin' => $admin, 'password' => $password, 'generated' => false, 'modules' => 'none'];
    }

    /**
     * @return resource
     */
    private function stream(string $contents)
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }
}
