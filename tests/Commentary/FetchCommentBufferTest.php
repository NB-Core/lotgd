<?php

declare(strict_types=1);

namespace Lotgd\Tests\Commentary;

use Lotgd\Commentary;
use Lotgd\DataCache;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\CacheDummySettings;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Commentary::fetchCommentBuffer() pagination and caching.
 *
 * @group commentary
 */
final class FetchCommentBufferTest extends TestCase
{
    private string $cacheDir;
    private \ReflectionMethod $fetchMethod;
    private \ReflectionMethod $buildSqlMethod;

    protected function setUp(): void
    {
        class_exists(Database::class);
        Database::$mockResults = [];
        Database::$queryCacheResults = [];
        Database::resetDoctrineConnection();

        $this->cacheDir = sys_get_temp_dir() . '/lotgd_commentary_cache_' . uniqid();
        mkdir($this->cacheDir, 0700, true);
        DataCache::resetState();
        $GLOBALS['settings'] = new CacheDummySettings([
            'datacachepath' => $this->cacheDir,
            'usedatacache'  => 1,
        ]);

        $_SERVER['SCRIPT_NAME'] = '/village.php';

        $this->fetchMethod = new \ReflectionMethod(Commentary::class, 'fetchCommentBuffer');
        $this->fetchMethod->setAccessible(true);

        $this->buildSqlMethod = new \ReflectionMethod(Commentary::class, 'buildCommentFetchSql');
        $this->buildSqlMethod->setAccessible(true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cacheDir)) {
            foreach (glob($this->cacheDir . '/*') as $file) {
                unlink($file);
            }
            rmdir($this->cacheDir);
        }
        unset($GLOBALS['settings']);
        DataCache::resetState();
        $_SERVER['SCRIPT_NAME'] = '';
    }

    // ---------------------------------------------------------------
    // 1. Cache write on first page
    // ---------------------------------------------------------------

    public function testFirstPageResultsAreCachedWhenDataCacheEnabled(): void
    {
        $section = 'village';
        $rows = [
            ['commentid' => 10, 'comment' => 'hello', 'author' => 1, 'name' => 'A', 'acctid' => 1, 'superuser' => 0, 'clanrank' => 0, 'clanshort' => '', 'postdate' => '2025-01-01 00:00:00', 'section' => $section],
            ['commentid' => 9, 'comment' => 'world', 'author' => 2, 'name' => 'B', 'acctid' => 2, 'superuser' => 0, 'clanrank' => 0, 'clanshort' => '', 'postdate' => '2025-01-01 00:00:00', 'section' => $section],
        ];

        // Queue mock result for the DB query
        Database::$mockResults[] = $rows;

        // Call fetchCommentBuffer with first page params: com=0, cid=0
        $result = $this->fetchMethod->invoke(null, $section, 10, 0, 0);

        $this->assertSame($rows, $result);

        // Verify the result was cached
        $cached = DataCache::getInstance()->datacache("comments-$section", 900);
        $this->assertIsArray($cached);
        $this->assertSame($rows, $cached);
    }

    // ---------------------------------------------------------------
    // 2. Cache read on subsequent first-page calls
    // ---------------------------------------------------------------

    public function testFirstPageServesFromCacheOnSecondCall(): void
    {
        $section = 'inn';
        $rows = [
            ['commentid' => 5, 'comment' => 'cached', 'author' => 1, 'name' => 'C', 'acctid' => 1, 'superuser' => 0, 'clanrank' => 0, 'clanshort' => '', 'postdate' => '2025-01-01 00:00:00', 'section' => $section],
        ];

        // Seed the cache directly
        DataCache::setCacheEntry("comments-$section", $rows);

        // No mock results queued — if it hits DB it would return default stub data
        $result = $this->fetchMethod->invoke(null, $section, 10, 0, 0);

        $this->assertSame($rows, $result);
    }

    // ---------------------------------------------------------------
    // 3. Cache bypass on moderation page
    // ---------------------------------------------------------------

    public function testCacheBypassedOnModerationPage(): void
    {
        $section = 'village';
        $cachedRows = [
            ['commentid' => 99, 'comment' => 'stale', 'author' => 1, 'name' => 'Old', 'acctid' => 1, 'superuser' => 0, 'clanrank' => 0, 'clanshort' => '', 'postdate' => '2025-01-01 00:00:00', 'section' => $section],
        ];
        $freshRows = [
            ['commentid' => 100, 'comment' => 'fresh', 'author' => 2, 'name' => 'New', 'acctid' => 2, 'superuser' => 0, 'clanrank' => 0, 'clanshort' => '', 'postdate' => '2025-01-02 00:00:00', 'section' => $section],
        ];

        // Seed cache with stale data
        DataCache::setCacheEntry("comments-$section", $cachedRows);

        // Simulate moderation page
        $_SERVER['SCRIPT_NAME'] = '/moderate.php';

        // Queue fresh DB result
        Database::$mockResults[] = $freshRows;

        $result = $this->fetchMethod->invoke(null, $section, 10, 0, 0);

        // Should get fresh data, not cached data
        $this->assertSame($freshRows, $result);
        $this->assertNotSame($cachedRows, $result);
    }

    // ---------------------------------------------------------------
    // 4. No cache on paginated pages (com > 0)
    // ---------------------------------------------------------------

    public function testNoCacheOnPaginatedPages(): void
    {
        $section = 'village';
        $rows = [
            ['commentid' => 3, 'comment' => 'old', 'author' => 1, 'name' => 'D', 'acctid' => 1, 'superuser' => 0, 'clanrank' => 0, 'clanshort' => '', 'postdate' => '2025-01-01 00:00:00', 'section' => $section],
        ];

        Database::$mockResults[] = $rows;

        // com=1 (second page), cid=0
        $result = $this->fetchMethod->invoke(null, $section, 10, 1, 0);

        $this->assertSame($rows, $result);

        // Verify nothing was cached for this section
        $cached = DataCache::getInstance()->datacache("comments-$section", 900);
        $this->assertFalse($cached);
    }

    // ---------------------------------------------------------------
    // 5. SQL branch: cid=0 uses ORDER BY ... DESC LIMIT offset, limit
    // ---------------------------------------------------------------

    public function testBuildSqlForFirstPageUsesDescOrder(): void
    {
        $sql = $this->buildSqlMethod->invoke(null, 0, 10, 5);

        $this->assertStringContainsString('ORDER BY commentid DESC', $sql);
        $this->assertStringContainsString('LIMIT 5, 10', $sql);
        $this->assertStringNotContainsString(':commentid', $sql);
    }

    // ---------------------------------------------------------------
    // 6. SQL branch: cid!=0 uses commentid > :commentid ASC LIMIT
    // ---------------------------------------------------------------

    public function testBuildSqlForCidScrollUsesAscOrder(): void
    {
        $sql = $this->buildSqlMethod->invoke(null, 42, 10, 0);

        $this->assertStringContainsString('commentid > :commentid', $sql);
        $this->assertStringContainsString('ORDER BY commentid ASC', $sql);
        $this->assertStringContainsString('LIMIT 10', $sql);
    }

    // ---------------------------------------------------------------
    // 7. cid!=0 results are reversed
    // ---------------------------------------------------------------

    public function testCidScrollResultsAreReversed(): void
    {
        $section = 'shade';
        $rows = [
            ['commentid' => 11, 'comment' => 'first', 'author' => 1, 'name' => 'E', 'acctid' => 1, 'superuser' => 0, 'clanrank' => 0, 'clanshort' => '', 'postdate' => '2025-01-01 00:00:00', 'section' => $section],
            ['commentid' => 12, 'comment' => 'second', 'author' => 2, 'name' => 'F', 'acctid' => 2, 'superuser' => 0, 'clanrank' => 0, 'clanshort' => '', 'postdate' => '2025-01-01 00:00:01', 'section' => $section],
        ];

        Database::$mockResults[] = $rows;

        // cid=5 triggers the ASC branch; results should be reversed
        $result = $this->fetchMethod->invoke(null, $section, 10, 0, 5);

        $this->assertSame(12, $result[0]['commentid']);
        $this->assertSame(11, $result[1]['commentid']);
    }
}
