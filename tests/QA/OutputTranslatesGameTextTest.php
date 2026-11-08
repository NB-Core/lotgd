<?php

declare(strict_types=1);

namespace Lotgd\Tests\QA;

use PHPUnit\Framework\TestCase;

/**
 * output() translates its first argument only. output('%s', $text) hands the
 * translator the bare "%s", so the text is never translated: buff and
 * companion messages, shop texts and module texts all came out in the
 * original language although the translation table had them.
 *
 * Game text goes through output($text) -- a format with its arguments as
 * output($array) -- and text that must not be translated (player names,
 * mail bodies) through outputNotl() / output_notl().
 */
final class OutputTranslatesGameTextTest extends TestCase
{
    private const PATTERN = "/(?:->|\\b)output\\(\\s*(['\"])%s\\1\\s*,/";

    public function testNoCallHandsTheTranslatorABarePlaceholder(): void
    {
        $root = dirname(__DIR__, 2);
        $found = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $file): bool {
                    if ($file->isDir()) {
                        return !in_array($file->getFilename(), ['vendor', 'tools', 'tests', 'node_modules', '.git', 'data', 'cache'], true);
                    }

                    return $file->getExtension() === 'php';
                }
            )
        );

        foreach ($files as $file) {
            $lines = file($file->getPathname());
            if ($lines === false) {
                continue;
            }
            foreach ($lines as $number => $line) {
                if (preg_match(self::PATTERN, $line) === 1) {
                    $found[] = substr($file->getPathname(), strlen($root) + 1) . ':' . ($number + 1);
                }
            }
        }

        sort($found);
        self::assertSame(
            [],
            $found,
            "output('%s', ...) is never translated; use output(\$text) for game text or outputNotl() for text that is not:\n"
            . implode("\n", $found)
        );
    }
}
