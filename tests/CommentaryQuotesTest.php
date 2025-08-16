<?php

declare(strict_types=1);

namespace {
    if (!function_exists('comment_sanitize')) {
        function comment_sanitize($in)
        {
            return \Lotgd\Sanitize::commentSanitize($in);
        }
    }
    if (!function_exists('sanitize_mb')) {
        function sanitize_mb($str)
        {
            return \Lotgd\Sanitize::sanitizeMb($str);
        }
    }
}

namespace Lotgd\Tests {

    use Lotgd\Commentary;
    use Lotgd\Output;
    use Lotgd\Tests\Stubs\Database;
    use PHPUnit\Framework\TestCase;

    final class CommentaryQuotesTest extends TestCase
    {
        protected function setUp(): void
        {
            global $session, $output;
            $session = ['user' => ['acctid' => 1, 'loggedin' => true, 'superuser' => 0]];
            $output = new Output();
            $_SERVER['REQUEST_URI'] = '/';
        }

        public function testRenderedCommentContainsQuotesWithoutSlashes(): void
        {
            $section = 'test-section';
            $author = 1;
            $comment = 'He said "hi" and it\'s good';

            Commentary::injectRawComment($section, $author, $comment);

            $row = [
            'acctid' => $author,
            'name' => 'Tester',
            'clanrank' => 0,
            'clanshort' => '',
            'superuser' => 0,
            'comment' => $comment,
            'commentid' => 1,
            'postdate' => date('Y-m-d H:i:s'),
            'section' => $section,
            ];

            $rendered = Commentary::renderCommentLine($row, false);
            $plain = preg_replace('/`./', '', html_entity_decode($rendered, ENT_QUOTES));

            $this->assertStringContainsString($comment, $plain);
            $this->assertStringNotContainsString('\\"', $rendered);
            $this->assertStringNotContainsString("\\'", $rendered);
        }
    }

}
