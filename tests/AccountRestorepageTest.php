<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A new character's restorepage is the village.
 *
 * It decides where a player lands when their session is restored, so a wrong
 * value strands them on a page they may not be allowed to see. Three places
 * have to agree: the schema default, the installer, and create.php.
 *
 * All three used to be checked with `/restorepage.*village\.php/s` over the
 * file text. With the `s` modifier that pattern spans the whole file, so any
 * mention of restorepage anywhere and any mention of village.php anywhere --
 * hundreds of lines apart, in unrelated code -- satisfied it. The schema is
 * data and can simply be read; the other two are assignments and can be
 * located rather than matched loosely.
 */
final class AccountRestorepageTest extends TestCase
{
    private const DEFAULT_RESTOREPAGE = 'village.php';

    /**
     * The schema default, read from the definition rather than matched in it.
     */
    public function testTheSchemaDefaultsRestorepageToTheVillage(): void
    {
        require_once dirname(__DIR__) . '/install/data/tables.php';

        $accounts = get_all_tables()['accounts'] ?? null;
        self::assertIsArray($accounts, 'install/data/tables.php must still define the accounts table');
        self::assertArrayHasKey('restorepage', $accounts);
        self::assertSame(self::DEFAULT_RESTOREPAGE, $accounts['restorepage']['default'] ?? null);
    }

    /**
     * create.php and the installer set the same value.
     *
     * Still read from the source -- both are statements inside long
     * procedural files with no seam to call -- but anchored to an assignment
     * or a bound parameter next to the column name, rather than to the two
     * words appearing somewhere in the file.
     */
    public function testTheAccountCreationPathsUseTheSameDefault(): void
    {
        foreach (['create.php', 'install/lib/Installer.php'] as $file) {
            $source = (string) file_get_contents(dirname(__DIR__) . '/' . $file);

            self::assertMatchesRegularExpression(
                '/restorepage[^;\n]{0,80}' . preg_quote(self::DEFAULT_RESTOREPAGE, '/') . '/',
                $source,
                "$file must set restorepage to " . self::DEFAULT_RESTOREPAGE . ' on the same statement'
            );
        }
    }

    /**
     * The page it names is a real page.
     *
     * Cheap, and it is the failure the loose pattern was least able to see:
     * every assertion above would still hold if village.php were renamed.
     */
    public function testTheDefaultRestorepageExists(): void
    {
        self::assertFileExists(dirname(__DIR__) . '/' . self::DEFAULT_RESTOREPAGE);
    }
}
