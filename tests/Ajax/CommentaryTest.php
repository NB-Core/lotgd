<?php

declare(strict_types=1);

namespace {
}

namespace Lotgd\Tests\Ajax {

    use Lotgd\Async\Handler\Commentary;
    use Lotgd\Tests\Stubs\Database;
    use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
    final class CommentaryTest extends TestCase
    {
        protected function setUp(): void
        {
            global $session, $output, $test_comment_rows;
            $session = [];
            $_SERVER['SCRIPT_NAME'] = 'test.php';
            $output = new class {
                public function appoencode($data, $priv = false)
                {
                    return $data;
                }
            };
            $test_comment_rows = [];
            eval(<<<'STUBS'
namespace Lotgd {
    class Commentary {
        public static function viewCommentary(
            string $section,
            string $message,
            int $limit,
            string $talkline,
            string $schema,
            bool $viewonly,
            int $returnLink
        ): string {
            return '<div class="block">Mocked Commentary</div>';
        }
        public static function renderCommentLine(array $row, bool $linkBios): string {
            return '<span>' . $row['comment'] . '</span>';
        }
    }
}
STUBS
            );

            require_once __DIR__ . '/../bootstrap.php';
            Database::$mockResults = [];
        }

        public function testCommentaryTextSetsInnerHtml(): void
        {
            $handler = new Commentary();
            $response = $handler->commentaryText([
            'section' => 'test-section',
            'schema' => 'schema',
            'viewonly' => true,
            ]);

            $commands = $response->getCommands();
            $this->assertCount(1, $commands);
            $this->assertSame('as', $commands[0]['cmd']);
            $this->assertSame('test-section', $commands[0]['id']);
            $this->assertSame('innerHTML', $commands[0]['prop']);
            $this->assertSame('<div class="block">Mocked Commentary</div>', $commands[0]['data']);
        }

        public function testCommentaryRefreshAppendsNewCommentsAndUpdatesScriptsWithoutNotifyOnInitialLoad(): void
        {
            global $test_comment_rows;
            $test_comment_rows = [
            [
                'commentid' => 1,
                'comment' => 'First',
                'acctid' => 1,
                'name' => 'User1',
                'superuser' => 0,
                'clanrank' => 0,
                'clanshort' => '',
            ],
            [
                'commentid' => 2,
                'comment' => 'Second',
                'acctid' => 2,
                'name' => 'User2',
                'superuser' => 0,
                'clanrank' => 0,
                'clanshort' => '',
            ],
            ];
            Database::$mockResults = [$test_comment_rows];

            $handler = new Commentary();
            $response = $handler->commentaryRefresh('test-section', 0);
            $commands = $response->getCommands();

            $this->assertSame('ap', $commands[0]['cmd']);
            $this->assertSame('test-section-comment', $commands[0]['id']);
            $expectedHtml = "<div data-cid='1'><span>First</span></div><div data-cid='2'><span>Second</span></div>";
            $this->assertSame($expectedHtml, $commands[0]['data']);

            $this->assertSame('js', $commands[1]['cmd']);
            $this->assertSame('lotgd_lastCommentId = 2;', $commands[1]['data']);

            $this->assertCount(2, $commands);
        }

        public function testCommentaryRefreshNotifiesWhenLastIdSmallerThanNewest(): void
        {
            global $test_comment_rows;
            $test_comment_rows = [
            [
                'commentid' => 2,
                'comment' => 'Second',
                'acctid' => 2,
                'name' => 'User2',
                'superuser' => 0,
                'clanrank' => 0,
                'clanshort' => '',
            ],
            ];
            Database::$mockResults = [$test_comment_rows];

            $handler = new Commentary();
            $response = $handler->commentaryRefresh('test-section', 1);
            $commands = $response->getCommands();

            $this->assertSame('ap', $commands[0]['cmd']);
            $this->assertSame('test-section-comment', $commands[0]['id']);
            $expectedHtml = "<div data-cid='2'><span>Second</span></div>";
            $this->assertSame($expectedHtml, $commands[0]['data']);

            $this->assertSame('js', $commands[1]['cmd']);
            $this->assertSame('lotgd_lastCommentId = 2;', $commands[1]['data']);

            $this->assertSame('js', $commands[2]['cmd']);
            $this->assertSame('lotgdCommentNotify(1);', $commands[2]['data']);
        }
    }
}
