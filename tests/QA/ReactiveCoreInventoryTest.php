<?php

declare(strict_types=1);

namespace Lotgd\Tests\QA;

use Lotgd\QA\ReactiveCoreInventory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReactiveCoreInventoryTest extends TestCase
{
    /**
     * @return iterable<string,array{string,string,string}>
     */
    public static function detectedSnippets(): iterable
    {
        yield 'saveUser' => ['Accounts::saveUser();', ReactiveCoreInventory::CATEGORY_FINISH_PATH, 'saveUser()'];
        yield 'redirect' => ["Redirect::redirect('village.php');", ReactiveCoreInventory::CATEGORY_FINISH_PATH, 'redirect()'];
        yield 'legacy footer' => ['page_footer();', ReactiveCoreInventory::CATEGORY_FINISH_PATH, 'page_footer()'];
        yield 'exit' => ['exit();', ReactiveCoreInventory::CATEGORY_FINISH_PATH, 'exit'];
        yield 'accounts update' => [
            "\$c->executeStatement('UPDATE ' . Database::prefix('accounts') . ' SET gold = 0');",
            ReactiveCoreInventory::CATEGORY_ACCOUNTS_WRITE,
            'UPDATE accounts',
        ];
        yield 'accounts update interpolated' => [
            "\$sql = \"UPDATE \$accounts SET gold = 0\";",
            ReactiveCoreInventory::CATEGORY_ACCOUNTS_WRITE,
            'UPDATE accounts',
        ];
        yield 'lock tables' => ["Database::query('LOCK TABLES settings WRITE');", ReactiveCoreInventory::CATEGORY_TRANSACTION_BREAKER, 'LOCK TABLES'];
        yield 'ddl' => ["\$sql = 'ALTER TABLE foo ADD x INT';", ReactiveCoreInventory::CATEGORY_TRANSACTION_BREAKER, 'ALTER TABLE'];
        yield 'table maintenance' => ["\$conn->executeQuery('OPTIMIZE TABLE ' . \$t);", ReactiveCoreInventory::CATEGORY_TRANSACTION_BREAKER, 'OPTIMIZE TABLE'];
        yield 'own transaction' => ['$conn->beginTransaction();', ReactiveCoreInventory::CATEGORY_TRANSACTION_BREAKER, 'beginTransaction()'];
        yield 'phpmailer' => ['$m = new PHPMailer(true);', ReactiveCoreInventory::CATEGORY_SIDE_EFFECT, 'new PHPMailer'];
        yield 'cache write' => ["invalidatedatacache('x');", ReactiveCoreInventory::CATEGORY_SIDE_EFFECT, 'invalidatedatacache()'];
        yield 'pullurl' => ["PullUrl::pull('https://example.org');", ReactiveCoreInventory::CATEGORY_SIDE_EFFECT, 'PullUrl::pull()'];
        yield 'inline script' => ["rawoutput('<script>alert(1)</script>');", ReactiveCoreInventory::CATEGORY_INLINE_SCRIPT, '<script>'];
    }

    #[DataProvider('detectedSnippets')]
    public function testDetectsSnippet(string $code, string $category, string $detail): void
    {
        $findings = (new ReactiveCoreInventory())->scanSource("<?php\n" . $code . "\n", 'example.php');

        $this->assertContains(
            ['file' => 'example.php', 'line' => 2, 'category' => $category, 'detail' => $detail],
            $findings
        );
    }

    public function testIgnoresCommentsDeclarationsAndUnrelatedCalls(): void
    {
        $source = <<<'PHP'
        <?php
        // Accounts::saveUser(); UPDATE accounts SET gold = 0; <script>
        /** LOCK TABLES in a docblock */
        function saveUser(): void {}
        $sql = 'SELECT gold FROM ' . Database::prefix('accounts');
        $sql = 'UPDATE ' . Database::prefix('accounts_output') . ' SET output = 1';
        $git->pull('origin');
        $mailer->mail('x');
        PHP;

        $this->assertSame([], (new ReactiveCoreInventory())->scanSource($source, 'example.php'));
    }

    public function testCollectScansCoreRootsAndExtraRootsButSkipsTestsAndTheArchive(): void
    {
        $root = sys_get_temp_dir() . '/lotgd-inventory-' . bin2hex(random_bytes(4));
        $modules = $root . '-modules';
        mkdir($root . '/src', 0777, true);
        mkdir($root . '/tests', 0777, true);
        mkdir($modules . '/forest', 0777, true);
        mkdir($modules . '/_old_dragonprime_snapshot', 0777, true);
        file_put_contents($root . '/village.php', "<?php\npage_footer();\n");
        file_put_contents($root . '/src/A.php', "<?php\n\$c->beginTransaction();\n");
        file_put_contents($root . '/tests/T.php', "<?php\nexit();\n");
        file_put_contents($modules . '/forest/m.php', "<?php\nredirect('forest.php');\n");
        file_put_contents($modules . '/_old_dragonprime_snapshot/old.php', "<?php\nredirect('forest.php');\n");

        try {
            $findings = (new ReactiveCoreInventory())->collect($root, [$modules]);
        } finally {
            foreach ([$root . '/village.php', $root . '/src/A.php', $root . '/tests/T.php', $modules . '/forest/m.php', $modules . '/_old_dragonprime_snapshot/old.php'] as $file) {
                unlink($file);
            }
            rmdir($root . '/src');
            rmdir($root . '/tests');
            rmdir($root);
            rmdir($modules . '/forest');
            rmdir($modules . '/_old_dragonprime_snapshot');
            rmdir($modules);
        }

        $files = array_column($findings, 'file');
        $this->assertContains('village.php', $files);
        $this->assertContains('src/A.php', $files);
        $this->assertContains(basename($modules) . '/forest/m.php', $files);
        $this->assertNotContains('tests/T.php', $files);
        $this->assertNotContains(basename($modules) . '/_old_dragonprime_snapshot/old.php', $files);

        $summary = ReactiveCoreInventory::summarize($findings);
        $this->assertSame(2, $summary[ReactiveCoreInventory::CATEGORY_FINISH_PATH]);
        $this->assertSame(1, $summary[ReactiveCoreInventory::CATEGORY_TRANSACTION_BREAKER]);
    }
}
