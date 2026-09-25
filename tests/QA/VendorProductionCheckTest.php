<?php

declare(strict_types=1);

namespace Lotgd\Tests\QA;

use Lotgd\QA\VendorProductionCheck;
use PHPUnit\Framework\TestCase;

/**
 * The committed vendor/ is what a shared-hosting install runs on. These cases
 * are the ways it drifts: a development package slipping back in, a lock
 * update committed without vendor/, and files left behind by a removal.
 */
final class VendorProductionCheckTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $fixtureRoots = [];

    public function testConsistentProductionInstallHasNoProblems(): void
    {
        self::assertSame([], $this->check($this->lock(), $this->installed(), ['acme/lib', 'acme/util']));
    }

    public function testDevelopmentPackageInVendorIsReported(): void
    {
        $installed = $this->installed();
        $installed['versions']['phpunit/phpunit'] = $this->entry('12.5.33', 'ppp', true);

        $problems = $this->check($this->lock(), $installed, ['acme/lib', 'acme/util', 'phpunit/phpunit']);

        self::assertSame(['vendor/ has the development package phpunit/phpunit'], $problems);
    }

    public function testDevelopmentRequirementInTheLockIsReported(): void
    {
        $lock = $this->lock();
        $lock['packages-dev'][] = ['name' => 'phpunit/phpunit', 'version' => '12.5.33'];

        $problems = $this->check($lock, $this->installed(), ['acme/lib', 'acme/util']);

        self::assertCount(1, $problems);
        self::assertStringContainsString('development package phpunit/phpunit', $problems[0]);
        self::assertStringContainsString('tools/composer.json', $problems[0]);
    }

    public function testLockUpdatedWithoutVendorIsReported(): void
    {
        $lock = $this->lock();
        $lock['packages'][0]['version'] = '1.1.0';
        $lock['packages'][0]['dist']['reference'] = 'bbb';

        $problems = $this->check($lock, $this->installed(), ['acme/lib', 'acme/util']);

        self::assertSame(['acme/lib is locked at 1.1.0 but vendor/ has 1.0.0'], $problems);
    }

    public function testSameVersionAtADifferentCommitIsReported(): void
    {
        $lock = $this->lock();
        $lock['packages'][0]['dist']['reference'] = 'other';

        $problems = $this->check($lock, $this->installed(), ['acme/lib', 'acme/util']);

        self::assertSame(['acme/lib 1.0.0 is locked at a different commit than vendor/ has'], $problems);
    }

    public function testLockedPackageMissingFromVendorIsReported(): void
    {
        $installed = $this->installed();
        unset($installed['versions']['acme/util']);

        $problems = $this->check($this->lock(), $installed, ['acme/lib']);

        self::assertSame(['acme/util 2.0.0 is locked but not installed in vendor/'], $problems);
    }

    public function testPackageLeftInVendorAfterRemovalIsReported(): void
    {
        $installed = $this->installed();
        $installed['versions']['acme/gone'] = $this->entry('3.0.0', 'ggg');

        $problems = $this->check($this->lock(), $installed, ['acme/gone', 'acme/lib', 'acme/util']);

        self::assertSame(['vendor/ has acme/gone, which composer.lock does not list'], $problems);
    }

    public function testStrayDirectoryAndMissingDirectoryAreReported(): void
    {
        // A removal that deleted Composer's record but not the files, and
        // the reverse: an upload that lost a package directory.
        $problems = $this->check($this->lock(), $this->installed(), ['acme/lib', 'phpunit/phpunit']);

        self::assertSame([
            'vendor/phpunit/phpunit exists but Composer did not install it; delete it',
            'vendor/acme/util is recorded as installed but its directory is missing',
        ], $problems);
    }

    public function testProvidedAndRootEntriesAreNotPackages(): void
    {
        $installed = $this->installed();
        $installed['versions']['psr/log-implementation'] = ['dev_requirement' => false, 'provided' => ['1.0']];

        self::assertSame([], $this->check($this->lock(), $installed, ['acme/lib', 'acme/util']));
    }

    public function testTheCommittedVendorDirectoryPasses(): void
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $exit = (new VendorProductionCheck())->run(dirname(__DIR__, 2), $out, $err);
        rewind($err);

        self::assertSame(0, $exit, (string) stream_get_contents($err));
    }

    public function testMissingVendorIsReportedAsAProblem(): void
    {
        $root = $this->fixtureRoot();

        [$exit, $stderr] = $this->runOn($root);

        self::assertSame(1, $exit);
        self::assertStringContainsString('vendor/composer/installed.php is missing', $stderr);
    }

    public function testCorruptInstallRecordIsReportedAsAProblem(): void
    {
        // A half-uploaded vendor/ leaves exactly this behind. The check must
        // say so rather than die on the parse error.
        $root = $this->fixtureRoot();
        mkdir($root . '/vendor/composer', 0700, true);
        file_put_contents($root . '/vendor/composer/installed.php', "<?php return array(\n    'root' => array(\n");

        [$exit, $stderr] = $this->runOn($root);

        self::assertSame(1, $exit);
        self::assertStringContainsString('vendor/composer/installed.php could not be read', $stderr);
    }

    public function testTheScriptReportsWithoutAWorkingAutoloader(): void
    {
        // The script checks vendor/, so it must not need vendor/ to start.
        $root = $this->fixtureRoot();
        mkdir($root . '/scripts', 0700, true);
        mkdir($root . '/src/Lotgd/QA', 0700, true);
        $repo = dirname(__DIR__, 2);
        copy($repo . '/scripts/check-vendor-production.php', $root . '/scripts/check-vendor-production.php');
        copy($repo . '/src/Lotgd/QA/VendorProductionCheck.php', $root . '/src/Lotgd/QA/VendorProductionCheck.php');

        $process = proc_open(
            [PHP_BINARY, $root . '/scripts/check-vendor-production.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        self::assertSame(1, $exit, $stdout . $stderr);
        self::assertStringContainsString('vendor/composer/installed.php is missing', $stderr);
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtureRoots as $root) {
            $this->removeTree($root);
        }
        $this->fixtureRoots = [];
    }

    /**
     * A repository root holding only composer.lock.
     */
    private function fixtureRoot(): string
    {
        $root = sys_get_temp_dir() . '/lotgd_vendor_check_' . uniqid();
        mkdir($root, 0700);
        file_put_contents($root . '/composer.lock', (string) json_encode($this->lock()));
        $this->fixtureRoots[] = $root;

        return $root;
    }

    /**
     * @return array{0:int,1:string} Exit code and error output
     */
    private function runOn(string $root): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $exit = (new VendorProductionCheck())->run($root, $out, $err);
        rewind($err);

        return [$exit, (string) stream_get_contents($err)];
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }

    /**
     * @param array<string,mixed> $lock
     * @param array<string,mixed> $installed
     * @param list<string>        $directories
     *
     * @return list<string>
     */
    private function check(array $lock, array $installed, array $directories): array
    {
        return (new VendorProductionCheck())->findProblems($lock, $installed, $directories);
    }

    /**
     * @return array<string,mixed>
     */
    private function lock(): array
    {
        return [
            'packages' => [
                ['name' => 'acme/lib', 'version' => '1.0.0', 'dist' => ['reference' => 'aaa']],
                ['name' => 'acme/util', 'version' => '2.0.0', 'source' => ['reference' => 'uuu']],
            ],
            'packages-dev' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function installed(): array
    {
        return [
            'root' => ['name' => '__root__', 'dev' => false],
            'versions' => [
                '__root__' => ['install_path' => '/', 'dev_requirement' => false],
                'acme/lib' => $this->entry('1.0.0', 'aaa'),
                'acme/util' => $this->entry('2.0.0', 'uuu'),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function entry(string $version, string $reference, bool $dev = false): array
    {
        return [
            'pretty_version' => $version,
            'reference' => $reference,
            'install_path' => '/vendor/x',
            'dev_requirement' => $dev,
        ];
    }
}
