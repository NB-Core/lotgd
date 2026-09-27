<?php

declare(strict_types=1);

namespace Lotgd\Tests\Doctrine;

use Lotgd\Doctrine\DbconnectPath;
use PHPUnit\Framework\TestCase;

/**
 * The Docker image keeps dbconnect.php in its persistent state volume and
 * links it into the read-only game directory. Doctrine refused that link
 * because the target lies outside the game directory, which broke every
 * Doctrine query of an installed container, the settings included.
 */
final class DbconnectPathTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/lotgd_dbconnect_path_' . uniqid();
        mkdir($this->base . '/game', 0700, true);
        mkdir($this->base . '/state', 0700, true);
        mkdir($this->base . '/elsewhere', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (['game/dbconnect.php', 'state/dbconnect.php', 'elsewhere/dbconnect.php'] as $file) {
            if (is_link($this->base . '/' . $file) || is_file($this->base . '/' . $file)) {
                unlink($this->base . '/' . $file);
            }
        }
        foreach (['game', 'state', 'elsewhere', ''] as $directory) {
            rmdir($this->base . '/' . $directory);
        }
    }

    public function testAFileInTheGameDirectoryIsUsed(): void
    {
        file_put_contents($this->base . '/game/dbconnect.php', '<?php return [];');

        self::assertSame(
            realpath($this->base . '/game/dbconnect.php'),
            DbconnectPath::resolve($this->base . '/game', null)
        );
    }

    public function testALinkIntoTheContainerStateDirectoryIsUsed(): void
    {
        file_put_contents($this->base . '/state/dbconnect.php', '<?php return [];');
        symlink($this->base . '/state/dbconnect.php', $this->base . '/game/dbconnect.php');

        self::assertSame(
            realpath($this->base . '/state/dbconnect.php'),
            DbconnectPath::resolve($this->base . '/game', $this->base . '/state')
        );
    }

    public function testALinkAnywhereElseIsRefused(): void
    {
        file_put_contents($this->base . '/elsewhere/dbconnect.php', '<?php return [];');
        symlink($this->base . '/elsewhere/dbconnect.php', $this->base . '/game/dbconnect.php');

        self::assertNull(DbconnectPath::resolve($this->base . '/game', $this->base . '/state'));
        self::assertNull(DbconnectPath::resolve($this->base . '/game', null));
    }

    public function testASiblingDirectorySharingTheNamePrefixIsRefused(): void
    {
        // "/srv/game-old" starts with "/srv/game" as a string, but is not inside it.
        mkdir($this->base . '/game-old', 0700);
        file_put_contents($this->base . '/game-old/dbconnect.php', '<?php return [];');
        symlink($this->base . '/game-old/dbconnect.php', $this->base . '/game/dbconnect.php');

        try {
            self::assertNull(DbconnectPath::resolve($this->base . '/game', null));
        } finally {
            unlink($this->base . '/game-old/dbconnect.php');
            rmdir($this->base . '/game-old');
        }
    }

    public function testAMissingFileIsReportedAsMissing(): void
    {
        self::assertNull(DbconnectPath::resolve($this->base . '/game', $this->base . '/state'));
    }
}
