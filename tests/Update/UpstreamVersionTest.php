<?php

declare(strict_types=1);

namespace Lotgd\Tests\Update;

use Lotgd\DataCache;
use Lotgd\Tests\Stubs\DummySettings;
use Lotgd\Update\UpstreamVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The version check against GitHub: what it compares, what it keeps, how
 * often it asks, and what it refuses to take from the response.
 */
final class UpstreamVersionTest extends TestCase
{
    private const RELEASE_API = 'https://github.com/NB-Core/lotgd/releases.atom';

    /** @var list<string> */
    private array $requests = [];

    /** @var array<string,?string> */
    private array $responses = [];

    private int $now = 1_000_000;

    protected function setUp(): void
    {
        DataCache::resetState();
    }

    private function upstream(DummySettings $settings): UpstreamVersion
    {
        return new UpstreamVersion(
            $settings,
            function (string $url): ?string {
                $this->requests[] = $url;

                return $this->responses[$url] ?? null;
            },
            fn (): int => $this->now
        );
    }

    /**
     * A releases feed as GitHub serves it, newest first.
     *
     * @param list<array{0:string,1?:string,2?:string}> $releases Tag, title, HTML notes
     */
    private static function feed(array $releases): string
    {
        $entries = '';
        foreach ($releases as $release) {
            $tag = $release[0];
            $entries .= '<entry><id>tag:github.com,2008:Repository/1/' . htmlspecialchars($tag) . '</id>'
                . '<updated>2026-04-10T20:50:48Z</updated>'
                . '<link rel="alternate" type="text/html" href="https://github.com/NB-Core/lotgd/releases/tag/' . htmlspecialchars(rawurlencode($tag)) . '"/>'
                . '<title>' . htmlspecialchars($release[1] ?? $tag) . '</title>'
                . '<content type="html">' . htmlspecialchars($release[2] ?? '') . '</content></entry>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?><feed xmlns="http://www.w3.org/2005/Atom" xml:lang="en-US">'
            . '<title>Release notes from lotgd</title>' . $entries . '</feed>';
    }

    private function release(string $tag, string $name = '', string $body = ''): string
    {
        return self::feed([[$tag, $name === '' ? $tag : $name, $body]]);
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function versions(): iterable
    {
        yield 'game version string' => ['2.0.7 +nb Edition', '2.0.7'];
        yield 'tag' => ['v2.0.5', '2.0.5'];
        yield 'release candidate' => ['v2.0.4-rc3', '2.0.4-rc3'];
        yield 'garbage' => ['nightly', null];
    }

    #[DataProvider('versions')]
    public function testVersionsAreNormalized(string $raw, ?string $expected): void
    {
        self::assertSame($expected, UpstreamVersion::normalizeVersion($raw));
    }

    public function testTheInstalledVersionIsComparedWithTheRelease(): void
    {
        self::assertSame(UpstreamVersion::STATUS_AHEAD, UpstreamVersion::status('2.0.7 +nb Edition', 'v2.0.5'));
        self::assertSame(UpstreamVersion::STATUS_UPDATE_AVAILABLE, UpstreamVersion::status('2.0.5 +nb Edition', 'v2.0.6'));
        self::assertSame(UpstreamVersion::STATUS_CURRENT, UpstreamVersion::status('2.0.5 +nb Edition', 'v2.0.5'));
        self::assertSame(UpstreamVersion::STATUS_UPDATE_AVAILABLE, UpstreamVersion::status('2.0.4-rc3', 'v2.0.4'));
        self::assertSame(UpstreamVersion::STATUS_UNKNOWN, UpstreamVersion::status('2.0.5', null));
        self::assertSame(UpstreamVersion::STATUS_UNKNOWN, UpstreamVersion::status('unknown', 'v2.0.5'));
    }

    public function testOnlyAFinalReleaseCounts(): void
    {
        self::assertTrue(UpstreamVersion::isFinalRelease('v2.0.5'));
        self::assertFalse(UpstreamVersion::isFinalRelease('v2.0.6-rc1'));
    }

    public function testTheReleaseIsAskedForOnceADay(): void
    {
        $settings = new DummySettings();
        $this->responses[self::RELEASE_API] = $this->release('v2.0.6');

        $first = $this->upstream($settings)->latestRelease();
        $this->now += UpstreamVersion::FRESH_FOR - 1;
        $second = $this->upstream($settings)->latestRelease();

        self::assertSame('v2.0.6', $first['tag'] ?? null);
        self::assertSame($first, $second);
        self::assertCount(1, $this->requests);

        $this->now += 1;
        $this->upstream($settings)->latestRelease();
        self::assertCount(2, $this->requests, 'asked again once a day has passed');
    }

    public function testAFailureIsRememberedForAnHour(): void
    {
        $settings = new DummySettings();

        self::assertNull($this->upstream($settings)->latestRelease());
        $this->now += UpstreamVersion::RETRY_AFTER - 1;
        self::assertNull($this->upstream($settings)->latestRelease());
        self::assertCount(1, $this->requests, 'no second request within the hour');

        $this->now += 1;
        $this->responses[self::RELEASE_API] = $this->release('v2.0.6');
        self::assertSame('v2.0.6', $this->upstream($settings)->latestRelease()['tag'] ?? null);
    }

    public function testNothingIsAskedWhenTheCheckIsOff(): void
    {
        $upstream = $this->upstream(new DummySettings([UpstreamVersion::SETTING_ENABLED => 0]));

        self::assertFalse($upstream->enabled());
        self::assertNull($upstream->latestRelease());
        self::assertNull($upstream->announcedRelease('2.0.0'));
        self::assertNull($upstream->releaseNotes());
        self::assertSame(['version' => null, 'head' => null], $upstream->master());
        self::assertSame([], $this->requests);
    }

    public function testTheLinkIsBuiltHereAndTheNameIsPlainText(): void
    {
        $this->responses[self::RELEASE_API] = $this->release('v2.0.6', "`\$Big <b>release</b>\nnow");

        $release = $this->upstream(new DummySettings())->latestRelease();

        self::assertSame('https://github.com/NB-Core/lotgd/releases/tag/v2.0.6', $release['url'] ?? null);
        self::assertSame('$Big <b>release</b> now', $release['name'] ?? null, 'no colour code, no line break; HTML is escaped on output');
        self::assertSame('2026-04-10', $release['published'] ?? null);
    }

    public function testAMalformedTagIsNotARelease(): void
    {
        $settings = new DummySettings();
        $this->responses[self::RELEASE_API] = $this->release("v2.0.6'><script>");
        $this->responses[self::RELEASE_API] .= 'not xml';

        self::assertNull($this->upstream($settings)->latestRelease());
        self::assertNull(UpstreamVersion::storedRelease($settings->getSetting(UpstreamVersion::SETTING_STATE)));
    }

    public function testTheStoredStateFitsTheSettingsColumn(): void
    {
        $settings = new DummySettings();
        $this->responses[self::RELEASE_API] = $this->release('v2.0.6', str_repeat('Ä', 300));

        $this->upstream($settings)->latestRelease();

        self::assertLessThanOrEqual(255, strlen((string) $settings->getSetting(UpstreamVersion::SETTING_STATE)));
        self::assertSame('v2.0.6', UpstreamVersion::storedRelease($settings->getSetting(UpstreamVersion::SETTING_STATE))['tag'] ?? null);
    }

    public function testReleaseNotesArePlainText(): void
    {
        $this->responses[self::RELEASE_API] = $this->release(
            'v2.0.6',
            '',
            "<h2>What&#39;s Changed</h2>\n<ul>\n<li>fix: one by <a href=\"https://evil.example\">@someone</a></li>\n<li>feat: two</li>\n</ul>"
        );

        self::assertSame(
            "What's Changed\n\n- fix: one by @someone\n- feat: two",
            $this->upstream(new DummySettings())->releaseNotes()
        );
    }

    public function testReleaseNotesAreShortened(): void
    {
        $this->responses[self::RELEASE_API] = $this->release('v2.0.6', '', str_repeat('x', UpstreamVersion::NOTES_LENGTH + 50));

        $notes = (string) $this->upstream(new DummySettings())->releaseNotes();

        self::assertSame(UpstreamVersion::NOTES_LENGTH + 1, mb_strlen($notes));
        self::assertStringEndsWith('…', $notes);
    }

    public function testAFailedLookupOfTheNotesKeepsTheKnownRelease(): void
    {
        $settings = new DummySettings();
        $this->responses[self::RELEASE_API] = $this->release('v2.0.6', '', 'notes');
        $this->upstream($settings)->latestRelease();

        unset($this->responses[self::RELEASE_API]);
        $upstream = $this->upstream($settings);

        self::assertNull($upstream->releaseNotes(), 'the data cache is off and GitHub does not answer');
        self::assertSame('v2.0.6', $upstream->latestRelease()['tag'] ?? null);
    }

    public function testTheGrottoAnnouncesOnlyANewerFinalRelease(): void
    {
        $this->responses[self::RELEASE_API] = $this->release('v2.0.6');
        self::assertSame('v2.0.6', $this->upstream(new DummySettings())->announcedRelease('2.0.5 +nb Edition')['tag'] ?? null);
        self::assertNull($this->upstream(new DummySettings())->announcedRelease('2.0.7 +nb Edition'), 'a development build is ahead');
        self::assertNull($this->upstream(new DummySettings())->announcedRelease('2.0.6 +nb Edition'), 'up to date');

        $this->responses[self::RELEASE_API] = self::feed([['v2.0.7-rc1'], ['v2.0.6']]);
        $upstream = $this->upstream(new DummySettings());
        self::assertSame('v2.0.6', $upstream->latestRelease()['tag'] ?? null, 'a release candidate is skipped');
        self::assertNull($upstream->announcedRelease('2.0.6 +nb Edition'), 'and not announced');
    }

    public function testMasterGivesTheDevelopmentVersionAndTheLastCommit(): void
    {
        $this->responses['https://raw.githubusercontent.com/NB-Core/lotgd/master/common.php']
            = "<?php\n// \$logd_version = \"9.9.9\";\n\$logd_version = \"2.0.8 +nb Edition\";\n";
        $this->responses['https://github.com/NB-Core/lotgd/commits/master.atom']
            = '<?xml version="1.0" encoding="UTF-8"?><feed xmlns="http://www.w3.org/2005/Atom"><entry>'
            . '<id>tag:github.com,2008:Grit::Commit/3cc8c0babbffac5cac6daf926f12579ab756b235</id>'
            . '<link type="text/html" rel="alternate" href="https://github.com/NB-Core/lotgd/commit/3cc8c0babbffac5cac6daf926f12579ab756b235"/>'
            . "<title>\n        Merge pull request #1575\n    </title><updated>2026-09-30T22:02:19Z</updated></entry></feed>";

        $master = $this->upstream(new DummySettings())->master();

        self::assertSame('2.0.8', $master['version']);
        self::assertSame([
            'sha' => '3cc8c0b',
            'date' => '2026-09-30',
            'title' => 'Merge pull request #1575',
            'url' => 'https://github.com/NB-Core/lotgd/commit/3cc8c0babbffac5cac6daf926f12579ab756b235',
        ], $master['head']);
    }

    public function testOnlyTheDeclarationLineOfCommonPhpIsRead(): void
    {
        self::assertNull(UpstreamVersion::versionFromCommonPhp('<?php echo "2.0.8";'));
        self::assertNull(UpstreamVersion::versionFromCommonPhp('$logd_version = "' . str_repeat('9', 70) . '";'));
        self::assertNull(UpstreamVersion::versionFromCommonPhp('$logd_version = "dev";'));
    }

    public function testTheGrottoNoticeIsLimitedToMegausersAndNeverAsksMaster(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/superuser.php');

        self::assertStringContainsString("if (\$session['user']['superuser'] & SU_MEGAUSER) {\n    \$installedVersion", $source);
        self::assertStringContainsString('->announcedRelease($installedVersion)', $source);
        self::assertStringNotContainsString('->master()', $source);
    }
}
