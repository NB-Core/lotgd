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
    #[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
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
            if (!class_exists('Lotgd\\Commentary', false)) {
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
            }

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
            $this->assertSame('node.assign', $commands[0]['name']);
            $this->assertSame('test-section', $commands[0]['args']['id']);
            $this->assertSame('innerHTML', $commands[0]['args']['attr']);
            $this->assertSame('<div class="block">Mocked Commentary</div>', $commands[0]['args']['value']);
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

            $this->assertSame('node.append', $commands[0]['name']);
            $this->assertSame('test-section-comment', $commands[0]['args']['id']);
            $expectedHtml = "<div data-cid='1'><span>First</span></div><div data-cid='2'><span>Second</span></div>";
            $this->assertSame($expectedHtml, $commands[0]['args']['value']);

            $this->assertSame('script.exec.expr', $commands[1]['name']);
            $this->assertInstanceOf(\Jaxon\Script\JsExpr::class, $commands[1]['args']['expr'] ?? null);
            $exprData = $commands[1]['args']['expr']->jsonSerialize();
            $this->assertSame('lotgd_lastCommentId', $exprData['calls'][1]['_name']);

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

            $this->assertSame('node.append', $commands[0]['name']);
            $this->assertSame('test-section-comment', $commands[0]['args']['id']);
            $expectedHtml = "<div data-cid='2'><span>Second</span></div>";
            $this->assertSame($expectedHtml, $commands[0]['args']['value']);

            $this->assertSame('script.exec.expr', $commands[1]['name']);
            $exprData = $commands[1]['args']['expr']->jsonSerialize();
            $this->assertSame('lotgd_lastCommentId', $exprData['calls'][1]['_name']);

            $this->assertSame('script.exec.call', $commands[2]['name']);
            $this->assertSame('lotgdCommentNotify', $commands[2]['args']['func']);
            $this->assertSame([1], $commands[2]['args']['args']);
        }
    }
}
