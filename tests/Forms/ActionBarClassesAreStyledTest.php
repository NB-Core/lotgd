<?php

declare(strict_types=1);

namespace Lotgd\Tests\Forms;

use Lotgd\Forms;
use Lotgd\Tests\Support\StyleSheets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every class an action row actually emits must be styleable.
 *
 * The sibling of ButtonClassesAreStyledTest, and the reason it is separate is
 * the reason it is better: that one reads class *literals* out of the source,
 * and `Forms::actionBar()` no longer has any. It composes each control's class
 * from the container's -- 'action-bar' yields 'action-bar__link' -- so there is
 * nothing in the file to grep for, and a scanner looking for one would report
 * a clean sweep of nothing.
 *
 * So this renders a row and reads the class attributes out of the markup. That
 * is not a workaround; it is the stronger question. A source scan asks what the
 * code appears to say, and this asks what came out, which is what a browser
 * gets. It covers the composed classes, the caller-supplied override, and the
 * defaults of all three control kinds at once, none of which a literal search
 * could see.
 */
final class ActionBarClassesAreStyledTest extends TestCase
{
    /**
     * Every class attribute in a rendered row, as a flat list of classes.
     *
     * @return list<string>
     */
    private static function classesIn(string $html): array
    {
        preg_match_all('/class=\'([^\']*)\'/', $html, $matches);

        $classes = [];
        foreach ($matches[1] as $attribute) {
            foreach (preg_split('/\s+/', trim($attribute)) ?: [] as $class) {
                if ($class !== '') {
                    $classes[] = $class;
                }
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * One row of each kind of control, which is what a caller actually builds.
     *
     * @return list<array<string,mixed>>
     */
    private static function oneOfEachKind(): array
    {
        return [
            ['kind' => 'link', 'url' => 'taunt.php?op=edit&tauntid=3', 'label' => 'Edit'],
            [
                'kind' => 'post',
                'url' => 'taunt.php?op=del&tauntid=3',
                'label' => 'Delete',
                'confirm' => 'Sure?',
                'fields' => ['id' => 3],
            ],
            ['kind' => 'disabled', 'label' => 'Previous'],
        ];
    }

    /**
     * The scan sees the row at all.
     *
     * Asserted first because every other assertion here is of the form "no bad
     * class was found", and a regex that stopped matching satisfies all of them
     * at once. Three controls, each carrying two classes, plus the container.
     */
    public function testTheRenderedRowYieldsClasses(): void
    {
        $classes = self::classesIn(Forms::actionBar(self::oneOfEachKind(), 'action-bar'));

        self::assertContains('action-bar', $classes, 'the container class is missing from the markup');
        self::assertContains('action-bar__link', $classes, 'the composed control class is missing');
        self::assertContains('button', $classes, 'controls are no longer carrying the themed button class');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function containerClasses(): array
    {
        return [
            'mail' => ['mail-nav'],
            'admin' => ['action-bar'],
        ];
    }

    #[DataProvider('containerClasses')]
    public function testEveryClassInARenderedRowIsStyleable(string $container): void
    {
        $html = Forms::actionBar(self::oneOfEachKind(), $container);

        $unstyled = [];
        foreach (self::classesIn($html) as $class) {
            $missing = StyleSheets::themesMissing($class);
            if ($missing === []) {
                continue;
            }

            $unstyled[] = sprintf("  '%s' -- unstyled in %s", $class, implode(', ', $missing));
        }

        self::assertSame(
            [],
            $unstyled,
            sprintf("A '%s' row emits classes some bundled themes cannot style:\n", $container)
                . implode("\n", $unstyled)
                . "\n\nEvery theme needs a rule for the row's class and its __link,"
                . ' or players on that theme get the bare-chrome row this whole line of work was about.'
        );
    }

    /**
     * The container's name reaches the controls.
     *
     * The behaviour the composed class exists for: a caller names its row once
     * and the controls follow, rather than every entry repeating the class. If
     * this ever stops holding, admin rows silently inherit the mail row's class
     * and the test above keeps passing, because that class is styled too.
     */
    public function testTheControlClassFollowsTheContainer(): void
    {
        $admin = Forms::actionBar(self::oneOfEachKind(), 'action-bar');

        self::assertStringContainsString("class='action-bar'", $admin);
        self::assertStringNotContainsString('mail-nav', $admin, 'an admin row is wearing the mail row class');
        self::assertSame(3, substr_count($admin, "class='button action-bar__link'"));
    }

    /**
     * A caller can still override the class on a single entry.
     */
    public function testAnEntryCanOverrideItsOwnClass(): void
    {
        $html = Forms::actionBar([
            ['kind' => 'link', 'url' => 'x.php', 'label' => 'Edit', 'class' => 'button'],
            ['kind' => 'link', 'url' => 'y.php', 'label' => 'Ban'],
        ], 'action-bar');

        self::assertStringContainsString("class='button'>Edit</a>", $html);
        self::assertStringContainsString("class='button action-bar__link'>Ban</a>", $html);
    }
}
