<?php

declare(strict_types=1);

namespace Lotgd\Tests\Doctrine;

use Lotgd\Doctrine\EntityFingerprint;
use PHPUnit\Framework\TestCase;

/**
 * The metadata cache namespace follows the entity files, so an update that
 * maps a new column (accounts.version, for one) is not served the mapping of
 * the release before from a cache that outlived the deploy.
 */
final class EntityFingerprintTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/lotgd_entity_fp_' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        file_put_contents($this->dir . '/Account.php', '<?php // one column');
    }

    protected function tearDown(): void
    {
        array_map('unlink', (array) glob($this->dir . '/*'));
        rmdir($this->dir);
    }

    public function testAChangedEntityFileGivesAnotherFingerprint(): void
    {
        $before = EntityFingerprint::of($this->dir);
        self::assertSame($before, EntityFingerprint::of($this->dir), 'Stable while nothing changes.');

        file_put_contents($this->dir . '/Account.php', '<?php // one column, and the version');
        clearstatcache();

        self::assertNotSame($before, EntityFingerprint::of($this->dir));
    }

    public function testANewEntityFileGivesAnotherFingerprint(): void
    {
        $before = EntityFingerprint::of($this->dir);
        file_put_contents($this->dir . '/Clan.php', '<?php');

        self::assertNotSame($before, EntityFingerprint::of($this->dir));
    }
}
