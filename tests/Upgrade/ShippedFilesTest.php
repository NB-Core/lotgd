<?php

declare(strict_types=1);

namespace Lotgd\Tests\Upgrade;

use Lotgd\Upgrade\ShippedFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An FTP upload that silently skipped a file is the most common way a
 * shared-hosting installation breaks. These cases pin which files count as
 * required and how a missing one is found.
 */
final class ShippedFilesTest extends TestCase
{
    private ?string $root = null;
    private string|false $statePath = false;

    protected function setUp(): void
    {
        $this->statePath = getenv('LOTGD_STATE_PATH');
    }

    protected function tearDown(): void
    {
        if ($this->statePath === false) {
            putenv('LOTGD_STATE_PATH');
        } else {
            putenv('LOTGD_STATE_PATH=' . $this->statePath);
        }
        if ($this->root !== null) {
            $this->removeTree($this->root);
            $this->root = null;
        }
    }

    /**
     * @return iterable<string,array{string,bool}>
     */
    public static function paths(): iterable
    {
        yield 'entry point' => ['village.php', true];
        yield 'bootstrap' => ['common.php', true];
        yield 'access rules' => ['.htaccess', true];
        yield 'installer, deleted after installing' => ['installer.php', false];
        yield 'installer library' => ['install/lib/Installer.php', false];
        yield 'core class' => ['src/Lotgd/Settings.php', true];
        yield 'browser script under src' => ['src/Lotgd/e_dom.js', true];
        yield 'legacy wrapper' => ['lib/addnav.php', true];
        yield 'migration' => ['migrations/Version20250724000024.php', true];
        yield 'library' => ['vendor/autoload.php', true];
        yield 'page part' => ['pages/inn/inn_bartender.php', true];
        yield 'async endpoint' => ['async/process.php', true];
        yield 'module, may be removed' => ['modules/cities.php', false];
        yield 'theme, may be removed' => ['templates_twig/aurora/page.twig', false];
        yield 'image' => ['images/logo.png', false];
        yield 'test' => ['tests/bootstrap.php', false];
        yield 'documentation' => ['README.md', false];
        yield 'container file' => ['Dockerfile', false];
        yield 'the list itself' => ['src/Lotgd/Upgrade/shipped-files.txt', false];
    }

    #[DataProvider('paths')]
    public function testWhichFilesAreRequired(string $path, bool $required): void
    {
        self::assertSame($required, ShippedFiles::isRequired($path));
    }

    public function testTheInstallerIsListedButRecognizedAsOptional(): void
    {
        self::assertTrue(ShippedFiles::isInstallerFile('installer.php'));
        self::assertTrue(ShippedFiles::isInstallerFile('install/lib/Requirements.php'));
        self::assertFalse(ShippedFiles::isInstallerFile('src/Lotgd/Installer/x.php'));
    }

    public function testAListingIsFilteredSortedAndDeduplicated(): void
    {
        $listing = ['src/b.php', 'README.md', 'common.php', '', 'src/b.php', 'modules/x.php', 'lib\\a.php', 'installer.php'];

        self::assertSame(['common.php', 'installer.php', 'lib/a.php', 'src/b.php'], ShippedFiles::fromListing($listing));
    }

    public function testTheRenderedListReadsBackWithItsVersion(): void
    {
        $file = $this->root() . '/list.txt';
        file_put_contents($file, ShippedFiles::render(['common.php', 'src/a.php'], '2.1.0 +nb Edition'));

        self::assertSame(
            ['version' => '2.1.0 +nb Edition', 'paths' => ['common.php', 'src/a.php']],
            ShippedFiles::read($file)
        );
    }

    public function testMissingFilesAreNamed(): void
    {
        $root = $this->uploadWith(['common.php', 'src/a.php'], ['common.php', 'migrations/V2.php', 'src/a.php', 'src/b.php']);

        self::assertSame(['migrations/V2.php', 'src/b.php'], ShippedFiles::missing($root, 'v2', false, $root . '/list.txt'));
    }

    public function testACompleteUploadHasNothingMissing(): void
    {
        $root = $this->uploadWith(['common.php'], ['common.php']);

        self::assertSame([], ShippedFiles::missing($root, 'v2', false, $root . '/list.txt'));
    }

    public function testAMissingListIsItselfAMissingFile(): void
    {
        // It ships with the game; without it the upload is incomplete by
        // definition, and nothing else can be said.
        self::assertSame(
            [ShippedFiles::LIST_PATH],
            ShippedFiles::missing($this->root(), 'v2', false, $this->root() . '/absent.txt')
        );
    }

    public function testAListFromAnotherVersionCountsAsMissing(): void
    {
        // The new common.php arrived, the new list and a new migration have
        // not: the old list would call the upload complete.
        $root = $this->uploadWith(['common.php'], ['common.php'], 'v1');

        self::assertSame([ShippedFiles::LIST_PATH], ShippedFiles::missing($root, 'v2', false, $root . '/list.txt'));
        self::assertSame([], ShippedFiles::missing($root, null, false, $root . '/list.txt'), 'null skips the comparison');
    }

    public function testTheInstallerFilesCountOnlyForTheInstaller(): void
    {
        $root = $this->uploadWith(['common.php'], ['common.php', 'install/lib/Requirements.php', 'installer.php']);

        self::assertSame([], ShippedFiles::missing($root, 'v2', false, $root . '/list.txt'));
        self::assertSame(
            ['install/lib/Requirements.php', 'installer.php'],
            ShippedFiles::missing($root, 'v2', true, $root . '/list.txt')
        );
    }

    public function testTheVersionIsReadFromCommonPhpWithoutRunningIt(): void
    {
        $file = $this->root() . '/common.php';
        file_put_contents($file, "<?php\n\$x = 1;\n\$logd_version = \"2.0.7 +nb Edition\";\nexit;\n");

        self::assertSame('2.0.7 +nb Edition', ShippedFiles::versionOf($file));
        self::assertNull(ShippedFiles::versionOf($this->root() . '/absent.php'));
        self::assertSame(ShippedFiles::versionOf(dirname(__DIR__, 2) . '/common.php'), ShippedFiles::read()['version'] ?? null);
    }

    public function testTheCheckDoesNotApplyInAContainer(): void
    {
        putenv('LOTGD_STATE_PATH');
        self::assertTrue(ShippedFiles::applies());

        putenv('LOTGD_STATE_PATH=/var/lib/lotgd');
        self::assertFalse(ShippedFiles::applies());
    }

    public function testASummaryNamesAFewAndCountsTheRest(): void
    {
        self::assertSame('a, b and 3 more', ShippedFiles::summarize(['a', 'b', 'c', 'd', 'e'], 2));
        self::assertSame('a', ShippedFiles::summarize(['a']));
    }

    public function testTheShippedListNamesTheCoreFiles(): void
    {
        // Whether it is current is the workflow's business: it regenerates the
        // list on master after every push. This only guards the file itself.
        $list = ShippedFiles::read();
        self::assertNotNull($list, 'src/Lotgd/Upgrade/shipped-files.txt is missing');
        $paths = $list['paths'];

        self::assertContains('common.php', $paths);
        self::assertContains('vendor/autoload.php', $paths);
        self::assertSame($paths, ShippedFiles::fromListing($paths), 'the list holds only required paths, sorted');
    }

    /**
     * A game directory holding $present and a list naming $listed.
     *
     * @param list<string> $present
     * @param list<string> $listed
     */
    private function uploadWith(array $present, array $listed, string $version = 'v2'): string
    {
        $root = $this->root();
        foreach ($present as $path) {
            if (!is_dir(dirname($root . '/' . $path))) {
                mkdir(dirname($root . '/' . $path), 0700, true);
            }
            touch($root . '/' . $path);
        }
        file_put_contents($root . '/list.txt', ShippedFiles::render($listed, $version));

        return $root;
    }

    private function root(): string
    {
        if ($this->root === null) {
            $this->root = sys_get_temp_dir() . '/lotgd_shipped_' . uniqid();
            mkdir($this->root, 0700);
        }

        return $this->root;
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
