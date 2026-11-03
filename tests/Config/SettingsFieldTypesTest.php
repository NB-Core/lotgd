<?php

declare(strict_types=1);

namespace Lotgd\Tests\Config;

use PHPUnit\Framework\TestCase;

/**
 * Every entry of the Game Settings form names a field type Forms knows.
 *
 * Forms::showForm() splits an entry on commas: the label, then the type. A
 * comma inside a label or a note moves the type to the third field, and the
 * row comes out as a text input with the rest of the sentence as its "type"
 * (the Text formatting note was one, the realtime and live encounters
 * switches were others). The file assigns its arrays at run time and needs
 * the game's settings, so it is read as text: every string entry of an array
 * is checked.
 */
final class SettingsFieldTypesTest extends TestCase
{
    private const FILE = 'src/Lotgd/Config/configuration.php';

    public function testEveryEntryNamesAKnownFieldType(): void
    {
        $root = dirname(__DIR__, 2);
        $known = array_merge(['', 'title', 'note', 'text'], self::formsTypes($root));

        $source = file_get_contents($root . '/' . self::FILE);
        self::assertIsString($source);
        // 'text' and '' have no case of their own: the default draws a text field.
        // One entry per line: an optional "key" =>, then a double-quoted string.
        $count = preg_match_all('/^\s*(?:"[A-Za-z0-9_]+"\s*=>\s*)?"((?:[^"\\\\]|\\\\.)*)",?\s*$/m', $source, $matches);
        self::assertGreaterThan(100, $count, 'the settings entries were not found');

        $wrong = [];
        foreach ($matches[1] as $entry) {
            $parts = explode(',', stripcslashes($entry));
            $type = trim($parts[1] ?? '');
            if (!in_array($type, $known, true)) {
                $wrong[] = $type . '  <-  ' . $parts[0];
            }
        }

        self::assertSame([], $wrong, "A comma in a label or note moved the field type:\n" . implode("\n", $wrong));
    }

    /**
     * The types Forms renders, read from its switch so a new one needs no
     * change here.
     *
     * @return list<string>
     */
    private static function formsTypes(string $root): array
    {
        $forms = file_get_contents($root . '/src/Lotgd/Forms.php');
        self::assertIsString($forms);
        preg_match_all("/case '([a-z]+)':/", $forms, $matches);

        return array_values(array_unique($matches[1]));
    }
}
