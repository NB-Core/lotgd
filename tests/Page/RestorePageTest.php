<?php

declare(strict_types=1);

namespace Lotgd\Tests\Page;

use Lotgd\Page\RestorePage;
use PHPUnit\Framework\TestCase;

/**
 * A login returns to `restorepage` with a GET, so a form's target must not
 * become it: `configuration.php?op=save` answered that GET with 400 (the
 * core operation needs a posted form token), and the player landed there
 * after every login that followed saving the game settings.
 */
final class RestorePageTest extends TestCase
{
    private mixed $method;

    protected function setUp(): void
    {
        $this->method = $_SERVER['REQUEST_METHOD'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->method === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $this->method;
        }
    }

    public function testAPageShownByGetBecomesThePageToReturnTo(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $user = ['restorepage' => 'village.php'];

        RestorePage::remember($user, 'configuration.php');

        self::assertSame('configuration.php', $user['restorepage']);
    }

    public function testAPostedFormKeepsThePageThatShowedIt(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $user = ['restorepage' => 'configuration.php'];

        RestorePage::remember($user, 'configuration.php?op=save');

        self::assertSame('configuration.php', $user['restorepage']);
    }

    public function testTheMethodIsReadWhateverItsCase(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'post';
        $user = ['restorepage' => 'bank.php?op=deposit'];

        RestorePage::remember($user, 'bank.php?op=depositfinish');

        self::assertSame('bank.php?op=deposit', $user['restorepage']);
    }

    public function testWithoutAMethodItCountsAsAGet(): void
    {
        unset($_SERVER['REQUEST_METHOD']);
        $user = [];

        RestorePage::remember($user, 'village.php');

        self::assertSame('village.php', $user['restorepage']);
    }

    /**
     * Both places that note the request as the page to return to go through
     * RestorePage: the page start in common.php and the new day in
     * DateTime::checkDay(). Read from the source, as AccountRestorepageTest
     * does, because neither has a seam to call.
     */
    public function testThePageStartAndTheNewDayGoThroughIt(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['common.php' => 'PhpGenericEnvironment::getRequestUri()', 'src/Lotgd/DateTime.php' => '$requestUri'] as $file => $uri) {
            $source = file_get_contents($root . '/' . $file);
            self::assertIsString($source, "$file could not be read");
            self::assertStringContainsString("RestorePage::remember(\$session['user'], $uri)", $source, $file);
            self::assertDoesNotMatchRegularExpression(
                '/\[\'restorepage\'\]\s*=\s*' . preg_quote($uri, '/') . '/',
                $source,
                "$file must not store the request as restorepage directly"
            );
        }
    }
}
