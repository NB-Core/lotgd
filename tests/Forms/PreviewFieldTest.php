<?php

declare(strict_types=1);

namespace Lotgd\Tests\Forms;

use Lotgd\Forms;
use Lotgd\Output;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\DummySettings;
use Lotgd\TwigTemplate;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The commentary preview field, classic and for the partial client.
 *
 * A page the partial client swaps in does not run inline scripts, so for a
 * partial player the field describes its preview in data-lotgd-preview and
 * assets/js/lotgd.partial.js renders it. Everyone else keeps the inline
 * script exactly as before.
 *
 * In separate processes: the field reads the session user, the settings
 * singleton and the active Twig skin, all global.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PreviewFieldTest extends TestCase
{
    private ?string $cacheDir = null;

    protected function setUp(): void
    {
        Output::setInstance(null);
        $GLOBALS['session'] = ['user' => ['acctid' => 1, 'name' => 'Admin', 'superuser' => 0, 'prefs' => []]];
    }

    protected function tearDown(): void
    {
        TwigTemplate::deactivate();
        if ($this->cacheDir !== null && is_dir($this->cacheDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->cacheDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->cacheDir);
        }
    }

    public function testAClassicPlayerKeepsTheInlineScript(): void
    {
        Settings::setInstance(new DummySettings(['maxchars' => 200, 'partial_render' => 1]));

        $markup = $this->render();

        self::assertStringContainsString('<script language="JavaScript">', $markup);
        self::assertStringContainsString('function previewtextinsertcommentary(t, l)', $markup);
        self::assertStringContainsString("onKeyUp='previewtextinsertcommentary(", $markup);
        self::assertStringNotContainsString('data-lotgd-preview', $markup);
    }

    public function testAPartialPlayerGetsDeclarativeMarkupWithoutScript(): void
    {
        $this->enablePartialClient();

        $markup = $this->render();

        self::assertStringNotContainsString('<script', $markup);
        self::assertStringNotContainsString('onKeyUp', $markup);
        self::assertStringContainsString("<span id='charsleftinsertcommentary'></span>", $markup);
        self::assertStringContainsString("<div id='previewtextinsertcommentary'></div>", $markup);
        self::assertStringContainsString("size='40'maxlength='180'", $markup, 'The caller\'s attributes are kept as they were.');

        $config = $this->config($markup);
        self::assertSame('insertcommentary', $config['name']);
        self::assertSame('says', $config['talkline']);
        self::assertSame(200, $config['maxChars']);
        self::assertSame(180, $config['limit']);
        self::assertTrue($config['showCharsLeft']);
        self::assertFalse($config['gamemaster']);
        self::assertStringContainsString('Admin', $config['start']);
        self::assertArrayHasKey('!', $config['colors'], 'The colour map travels with the field.');
    }

    public function testTheGamemasterPrefixIsOnlyOfferedToGamemasters(): void
    {
        $this->enablePartialClient();
        $GLOBALS['session']['user']['superuser'] = SU_IS_GAMEMASTER;

        self::assertTrue($this->config($this->render())['gamemaster']);
    }

    private function enablePartialClient(): void
    {
        Settings::setInstance(new DummySettings(['maxchars' => 200, 'partial_render' => 1]));
        $GLOBALS['session']['user']['prefs'] = ['partialrender' => 1];
        $this->cacheDir = sys_get_temp_dir() . '/lotgd-preview-' . bin2hex(random_bytes(4));
        mkdir($this->cacheDir, 0700, true);
        TwigTemplate::init('aurora', $this->cacheDir);
    }

    private function render(): string
    {
        Forms::previewField('insertcommentary', 'Admin', 'says', true, ['size' => 40, 'maxlength' => 180]);

        return Output::getInstance()->getRawOutput();
    }

    /**
     * @return array<string,mixed>
     */
    private function config(string $markup): array
    {
        self::assertSame(1, preg_match('/data-lotgd-preview="([^"]*)"/', $markup, $match), $markup);

        return json_decode(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'), true, 512, JSON_THROW_ON_ERROR);
    }
}
