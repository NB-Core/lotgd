<?php

declare(strict_types=1);

namespace Lotgd\Tests\Release;

use PHPUnit\Framework\TestCase;

use function Lotgd\Scripts\ReleaseNotes\changelogSections;
use function Lotgd\Scripts\ReleaseNotes\previousRelease;
use function Lotgd\Scripts\ReleaseNotes\releaseBody;

require_once dirname(__DIR__, 2) . '/scripts/release-notes.php';

/**
 * The text of a GitHub release, built from CHANGELOG.md by
 * scripts/release-notes.php.
 */
final class ReleaseNotesTest extends TestCase
{
    private const CHANGELOG = <<<'MD'
        # Changelog

        Intro text.

        ---

        ## [Unreleased]

        ### Added

        - Not released yet.

        ## [2.0.8] – 2026-10-05

        ### Added

        - Eight.

        ## [2.0.7] – 2026-09-09

        - Seven, never published.

        ## [2.0.5] – 2026-04-10

        - Five.

        ---

        *Footer.*
        MD;

    public function testOnlyTheVersionWithoutAPreviousRelease(): void
    {
        self::assertSame("## [2.0.8] – 2026-10-05\n\n### Added\n\n- Eight.", changelogSections(self::CHANGELOG, '2.0.8', null));
    }

    public function testUnpublishedVersionsUpToThePreviousReleaseAreIncluded(): void
    {
        $sections = changelogSections(self::CHANGELOG, '2.0.8', '2.0.5');

        self::assertStringStartsWith('## [2.0.8]', $sections);
        self::assertStringContainsString("## [2.0.7] – 2026-09-09\n\n- Seven, never published.", $sections);
        self::assertStringNotContainsString('2.0.5', $sections);
        self::assertStringNotContainsString('Unreleased', $sections);
    }

    public function testTheLastSectionStopsBeforeTheFooter(): void
    {
        self::assertSame("## [2.0.5] – 2026-04-10\n\n- Five.", changelogSections(self::CHANGELOG, '2.0.5', null));
    }

    public function testAVersionWithoutASectionIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);
        changelogSections(self::CHANGELOG, '2.0.9', '2.0.8');
    }

    public function testAMissingPreviousReleaseIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);
        changelogSections(self::CHANGELOG, '2.0.8', '2.0.4');
    }

    public function testThePreviousReleaseIsTheNewestOlderFinalTag(): void
    {
        $tags = ['v2.0.4-rc3', 'v2.0.4', 'v2.0.5', 'v2.0.8', 'v2.0.9'];

        self::assertSame('2.0.5', previousRelease('2.0.8', $tags));
        self::assertSame('2.0.4', previousRelease('2.0.5', $tags));
        self::assertNull(previousRelease('2.0.0', $tags));
    }

    public function testTheGeneratedTextIsFoldedAwayBelowTheChangelog(): void
    {
        $body = releaseBody('## [2.0.8]', "## What's Changed\r\n* A PR", '2.0.8');

        self::assertStringStartsWith("<!-- changelog:start -->\n## [2.0.8]\n", $body);
        self::assertStringContainsString("<details>\n<summary>Merged pull requests</summary>\n\n## What's Changed\n* A PR\n\n</details>", $body);
        self::assertStringContainsString('blob/v2.0.8/UPGRADING.md', $body);
    }

    public function testRunningAgainReplacesOnlyTheChangelog(): void
    {
        $first = releaseBody('## [2.0.8] old', '* A PR', '2.0.8');

        self::assertSame($first, releaseBody('## [2.0.8] old', $first, '2.0.8'), 'idempotent');

        $second = releaseBody('## [2.0.8] new', $first, '2.0.8');
        self::assertStringContainsString('## [2.0.8] new', $second);
        self::assertStringNotContainsString('old', $second);
        self::assertSame(1, substr_count($second, '<details>'), 'nothing nested');
    }

    public function testAnEmptyReleaseGetsOnlyTheChangelog(): void
    {
        self::assertStringNotContainsString('<details>', releaseBody('## [2.0.8]', '', '2.0.8'));
    }
}
