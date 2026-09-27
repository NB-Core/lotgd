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

    public function testAListingIsFilteredSortedAndDeduplicated(): void
    {
        $listing = ['src/b.php', 'README.md', 'common.php', '', 'src/b.php', 'modules/x.php', 'lib\\a.php'];

        self::assertSame(['common.php', 'lib/a.php', 'src/b.php'], ShippedFiles::fromListing($listing));
    }

    public function testTheRenderedListReadsBackWithoutItsComments(): void
    {
        $file = $this->root() . '/list.txt';
        file_put_contents($file, ShippedFiles::render(['common.php', 'src/a.php']));

        self::assertSame(['common.php', 'src/a.php'], ShippedFiles::read($file));
    }

    public function testMissingFilesAreNamed(): void
    {
        $root = $this->root();
        mkdir($root . '/src', 0700, true);
        touch($root . '/common.php');
        touch($root . '/src/a.php');
        file_put_contents($root . '/list.txt', ShippedFiles::render(['common.php', 'migrations/V2.php', 'src/a.php', 'src/b.php']));

        self::assertSame(['migrations/V2.php', 'src/b.php'], ShippedFiles::missing($root, $root . '/list.txt'));
    }

    public function testACompleteUploadHasNothingMissing(): void
    {
        $root = $this->root();
        touch($root . '/common.php');
        file_put_contents($root . '/list.txt', ShippedFiles::render(['common.php']));

        self::assertSame([], ShippedFiles::missing($root, $root . '/list.txt'));
    }

    public function testWithoutTheListNothingCanBeSaid(): void
    {
        self::assertNull(ShippedFiles::missing($this->root(), $this->root() . '/absent.txt'));
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
        $paths = ShippedFiles::read();

        self::assertNotNull($paths, 'src/Lotgd/Upgrade/shipped-files.txt is missing');
        self::assertContains('common.php', $paths);
        self::assertContains('vendor/autoload.php', $paths);
        self::assertSame($paths, ShippedFiles::fromListing($paths), 'the list holds only required paths, sorted');
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
