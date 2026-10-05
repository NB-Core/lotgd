<?php

declare(strict_types=1);

namespace Lotgd\Update;

use Lotgd\DataCache;
use Lotgd\Settings;

/**
 * Compares the installed game with the project on GitHub.
 *
 * Three things are looked up, all anonymously and without sending anything
 * about the server or its players:
 *
 * - the latest published release, which decides whether an administrator is
 *   told that an update is available;
 * - the version master's common.php declares, the development version;
 * - the latest commit on master, mentioned for information only.
 *
 * The release is kept in a setting, so the notice in the Superuser Grotto
 * costs at most one request a day even where the data cache is disabled, and
 * a failure is remembered for an hour so an unreachable GitHub does not slow
 * every visit. Master is looked up only by the Core News page.
 *
 * Everything GitHub returns is treated as untrusted: versions must have the
 * expected shape, names are reduced to plain text, and links are built here
 * from validated parts rather than taken from the response.
 */
final class UpstreamVersion
{
    public const REPOSITORY_URL = 'https://github.com/NB-Core/lotgd';
    public const RELEASES_URL = self::REPOSITORY_URL . '/releases';

    /** Switch: 1 to look things up, 0 to never contact GitHub. */
    public const SETTING_ENABLED = 'versioncheck';

    /** Where the latest release, or the last failure to fetch it, is kept. */
    public const SETTING_STATE = 'versioncheck_release';

    public const STATUS_CURRENT = 'current';
    public const STATUS_UPDATE_AVAILABLE = 'update';
    public const STATUS_AHEAD = 'ahead';
    public const STATUS_UNKNOWN = 'unknown';

    /** Seconds a successful lookup is reused. */
    public const FRESH_FOR = 86400;

    /** Seconds after a failed lookup before the next attempt. */
    public const RETRY_AFTER = 3600;

    /** Seconds a single request may take. */
    public const TIMEOUT = 3;

    /** Characters of release notes shown. */
    public const NOTES_LENGTH = 4000;

    // Atom feeds rather than the REST API: the API allows 60 anonymous
    // requests an hour per IP address, which a shared host exhausts.
    private const RELEASES_FEED = self::RELEASES_URL . '.atom';
    private const MASTER_FEED = self::REPOSITORY_URL . '/commits/master.atom';
    private const MASTER_COMMON = 'https://raw.githubusercontent.com/NB-Core/lotgd/master/common.php';

    private const CACHE_NOTES = 'github_release_notes';
    private const CACHE_MASTER = 'github_master';

    /** A release tag: v2.0.5, 2.0.4-rc3. */
    private const TAG_PATTERN = '/^v?\d{1,4}\.\d{1,4}\.\d{1,4}(?:-[0-9A-Za-z.]{1,16})?$/';

    private \Closure $fetch;
    private \Closure $clock;
    private ?string $notes = null;

    /**
     * @param callable(string):?string|null $fetch Body of a GET to the URL, or null on failure
     * @param callable():int|null           $clock Current Unix time
     */
    public function __construct(private Settings $settings, ?callable $fetch = null, ?callable $clock = null)
    {
        $this->fetch = \Closure::fromCallable($fetch ?? [self::class, 'httpGet']);
        $this->clock = \Closure::fromCallable($clock ?? 'time');
    }

    /**
     * Whether the administrator allows looking things up on GitHub.
     */
    public function enabled(): bool
    {
        return (int) $this->settings->getSetting(self::SETTING_ENABLED, 1) === 1;
    }

    /**
     * The latest published release, looked up at most once a day.
     *
     * @return array{tag:string,name:string,published:string,url:string}|null
     *         Null when the check is disabled or GitHub could not be asked
     */
    public function latestRelease(): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $state = self::decodeState($this->settings->getSetting(self::SETTING_STATE, ''));
        if ($state !== null && $this->isFresh($state)) {
            return self::releaseFromState($state);
        }

        return $this->refreshRelease();
    }

    /**
     * The release the Grotto should announce: one newer than the installed
     * game, and a final release, not a release candidate.
     *
     * @return array{tag:string,name:string,published:string,url:string}|null
     */
    public function announcedRelease(string $installedVersion): ?array
    {
        $release = $this->latestRelease();
        if ($release === null || ! self::isFinalRelease($release['tag'])) {
            return null;
        }

        return self::status($installedVersion, $release['tag']) === self::STATUS_UPDATE_AVAILABLE ? $release : null;
    }

    /**
     * Release notes of the latest release, plain text, shortened.
     *
     * Kept in the data cache when it is enabled; without it the Core News
     * page asks GitHub again, which is the only caller.
     */
    public function releaseNotes(): ?string
    {
        if (! $this->enabled()) {
            return null;
        }
        if ($this->notes !== null) {
            return $this->notes;
        }

        $cached = DataCache::getInstance()->datacache(self::CACHE_NOTES, self::FRESH_FOR);
        if (is_string($cached)) {
            return $this->notes = $cached;
        }

        $state = self::decodeState($this->settings->getSetting(self::SETTING_STATE, ''));
        if ($state !== null && isset($state['unavailable']) && $this->isFresh($state)) {
            return null;
        }

        // A failure here must not replace a release already known.
        $this->refreshRelease(false);

        return $this->notes;
    }

    /**
     * Development version and latest commit on master, for the Core News page.
     *
     * @return array{version:?string,head:?array{sha:string,date:string,title:string,url:string}}
     */
    public function master(): array
    {
        $none = ['version' => null, 'head' => null];
        if (! $this->enabled()) {
            return $none;
        }

        $cache = DataCache::getInstance();
        $cached = $cache->datacache(self::CACHE_MASTER, self::FRESH_FOR);
        if (is_array($cached) && array_key_exists('version', $cached) && array_key_exists('head', $cached)) {
            return [
                'version' => is_string($cached['version']) ? self::normalizeVersion($cached['version']) : null,
                'head' => is_array($cached['head']) ? self::commitFromState($cached['head']) : null,
            ];
        }

        $common = ($this->fetch)(self::MASTER_COMMON);
        $feed = ($this->fetch)(self::MASTER_FEED);
        $version = is_string($common) ? self::versionFromCommonPhp($common) : null;
        $head = null;
        // The newest entry is the head of master; older commits do not matter.
        $newest = is_string($feed) ? (self::atomEntries($feed)[0] ?? null) : null;
        if (
            $newest !== null
            && preg_match('#^' . preg_quote(self::REPOSITORY_URL, '#') . '/commit/([0-9a-f]{40})$#', $newest['link'], $matches) === 1
        ) {
            $head = ['sha' => $matches[1], 'date' => substr($newest['updated'], 0, 10), 'title' => $newest['title']];
        }
        if ($version !== null || $head !== null) {
            $cache->updatedatacache(self::CACHE_MASTER, ['version' => $version, 'head' => $head]);
        }

        return ['version' => $version, 'head' => $head === null ? null : self::commitFromState($head)];
    }

    /**
     * The latest release as last stored, without asking GitHub.
     *
     * @param mixed $stored Value of the {@see SETTING_STATE} setting
     *
     * @return array{tag:string,name:string,published:string,url:string}|null
     */
    public static function storedRelease(mixed $stored): ?array
    {
        $state = self::decodeState($stored);

        return $state === null ? null : self::releaseFromState($state);
    }

    /**
     * The x.y.z version in a version string, with a pre-release suffix if any.
     *
     * "2.0.7 +nb Edition" gives "2.0.7", "v2.0.4-rc3" gives "2.0.4-rc3".
     */
    public static function normalizeVersion(string $version): ?string
    {
        if (preg_match('/(?<![\d.])(\d{1,4}\.\d{1,4}\.\d{1,4})(-[0-9A-Za-z.]{1,16})?/', $version, $matches) !== 1) {
            return null;
        }

        return $matches[1] . ($matches[2] ?? '');
    }

    /**
     * Whether a tag names a final release rather than a pre-release.
     */
    public static function isFinalRelease(string $tag): bool
    {
        $version = self::normalizeVersion($tag);

        return $version !== null && ! str_contains($version, '-');
    }

    /**
     * How the installed version relates to another one.
     *
     * @return string One of the STATUS_* constants
     */
    public static function status(string $installedVersion, ?string $otherVersion): string
    {
        $installed = self::normalizeVersion($installedVersion);
        $other = $otherVersion === null ? null : self::normalizeVersion($otherVersion);
        if ($installed === null || $other === null) {
            return self::STATUS_UNKNOWN;
        }

        return match (version_compare($installed, $other)) {
            -1 => self::STATUS_UPDATE_AVAILABLE,
            0 => self::STATUS_CURRENT,
            default => self::STATUS_AHEAD,
        };
    }

    /**
     * The version a common.php declares in its $logd_version line.
     */
    public static function versionFromCommonPhp(string $source): ?string
    {
        if (preg_match('/^\$logd_version\s*=\s*"([^"\r\n]{1,64})";/m', $source, $matches) !== 1) {
            return null;
        }

        return self::normalizeVersion($matches[1]);
    }

    /**
     * Ask GitHub for the latest release and remember the answer.
     *
     * @param bool $recordFailure Whether a failed lookup is stored, which
     *                            hides the stored release for an hour
     *
     * @return array{tag:string,name:string,published:string,url:string}|null
     */
    private function refreshRelease(bool $recordFailure = true): ?array
    {
        $now = ($this->clock)();
        $body = ($this->fetch)(self::RELEASES_FEED);
        $latest = null;
        $tag = '';
        // Newest first; release candidates are published as releases too.
        foreach (is_string($body) ? self::atomEntries($body) : [] as $entry) {
            if (preg_match('#^' . preg_quote(self::RELEASES_URL, '#') . '/tag/([^/?\#]+)$#', $entry['link'], $matches) !== 1) {
                continue;
            }
            $candidate = rawurldecode($matches[1]);
            if (preg_match(self::TAG_PATTERN, $candidate) === 1 && self::isFinalRelease($candidate)) {
                $latest = $entry;
                $tag = $candidate;
                break;
            }
        }

        if ($latest === null) {
            if (! $recordFailure) {
                return null;
            }
            $this->settings->saveSetting(self::SETTING_STATE, (string) json_encode(['at' => $now, 'unavailable' => true]));

            return null;
        }

        $name = self::plainText($latest['title'], 60);
        $published = substr($latest['updated'], 0, 10);
        $state = [
            'at' => $now,
            'tag' => $tag,
            'name' => $name === '' ? $tag : $name,
            'published' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $published) === 1 ? $published : '',
        ];
        $encoded = (string) json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > 255) {
            // The settings column holds 255 characters; the tag is what counts.
            $state['name'] = $tag;
            $encoded = (string) json_encode($state, JSON_UNESCAPED_SLASHES);
        }
        $this->settings->saveSetting(self::SETTING_STATE, $encoded);

        $this->notes = self::shorten(self::htmlToText($latest['content']), self::NOTES_LENGTH);
        DataCache::getInstance()->updatedatacache(self::CACHE_NOTES, $this->notes);

        return self::releaseFromState($state);
    }

    /**
     * @param array<string,mixed> $state
     */
    private function isFresh(array $state): bool
    {
        $age = ($this->clock)() - (int) ($state['at'] ?? 0);
        $limit = isset($state['unavailable']) ? self::RETRY_AFTER : self::FRESH_FOR;

        return $age >= 0 && $age < $limit;
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function decodeState(mixed $stored): ?array
    {
        if (! is_string($stored) || $stored === '') {
            return null;
        }
        $state = json_decode($stored, true);

        return is_array($state) && is_int($state['at'] ?? null) ? $state : null;
    }

    /**
     * @param array<string,mixed> $state
     *
     * @return array{tag:string,name:string,published:string,url:string}|null
     */
    private static function releaseFromState(array $state): ?array
    {
        $tag = $state['tag'] ?? null;
        if (! is_string($tag) || preg_match(self::TAG_PATTERN, $tag) !== 1) {
            return null;
        }
        $name = self::plainText(is_string($state['name'] ?? null) ? $state['name'] : '', 60);
        $published = is_string($state['published'] ?? null) ? $state['published'] : '';

        return [
            'tag' => $tag,
            'name' => $name === '' ? $tag : $name,
            'published' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $published) === 1 ? $published : '',
            'url' => self::RELEASES_URL . '/tag/' . rawurlencode($tag),
        ];
    }

    /**
     * @param array<mixed> $head
     *
     * @return array{sha:string,date:string,title:string,url:string}|null
     */
    private static function commitFromState(array $head): ?array
    {
        $sha = $head['sha'] ?? null;
        if (! is_string($sha) || preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            return null;
        }
        $date = is_string($head['date'] ?? null) ? $head['date'] : '';

        return [
            'sha' => substr($sha, 0, 7),
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : '',
            'title' => self::plainText(is_string($head['title'] ?? null) ? $head['title'] : '', 120),
            'url' => self::REPOSITORY_URL . '/commit/' . $sha,
        ];
    }

    /**
     * The entries of an Atom feed, newest first, as plain strings.
     *
     * @return list<array{link:string,title:string,updated:string,content:string}>
     */
    private static function atomEntries(string $xml): array
    {
        // SimpleXML is not among the required extensions. Without it a feed
        // cannot be read, which is handled like a feed that did not arrive.
        if (! function_exists('simplexml_load_string')) {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $feed = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($feed === false) {
            return [];
        }

        $entries = [];
        foreach ($feed->children('http://www.w3.org/2005/Atom')->entry as $entry) {
            $link = '';
            foreach ($entry->link as $candidate) {
                // Read through attributes(): the entry was reached in the
                // Atom namespace, and $candidate['href'] would look elsewhere.
                $attributes = $candidate->attributes();
                $rel = (string) ($attributes['rel'] ?? '');
                if ($rel === '' || $rel === 'alternate') {
                    $link = (string) ($attributes['href'] ?? '');
                    break;
                }
            }
            $entries[] = [
                'link' => $link,
                'title' => trim((string) $entry->title),
                'updated' => (string) $entry->updated,
                'content' => (string) $entry->content,
            ];
        }

        return $entries;
    }

    /**
     * Release notes arrive as HTML; keep their text and line structure.
     */
    private static function htmlToText(string $html): string
    {
        $html = (string) preg_replace('#\s*<li[^>]*>#i', "\n- ", $html);
        $html = (string) preg_replace('#<br\s*/?>|</(?:p|h[1-6]|div|ul|ol|pre)>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace("/[ \t]+/", ' ', str_replace("\r", '', $text));

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /**
     * Text safe to pass through the game's output: no colour codes, no
     * control characters, limited length.
     */
    private static function plainText(string $text, int $length): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F`]+/u', ' ', $text);

        return trim(self::shorten(trim($text), $length));
    }

    private static function shorten(string $text, int $length): string
    {
        return mb_strlen($text, 'UTF-8') > $length ? rtrim(mb_substr($text, 0, $length, 'UTF-8')) . '…' : $text;
    }

    /**
     * GET a URL with a short timeout.
     *
     * A failure of any kind -- no network, allow_url_fopen off, an HTTP
     * error, GitHub's rate limit -- is an answer of null, which the callers
     * remember and show as "could not be checked".
     */
    private static function httpGet(string $url): ?string
    {
        $headers = ['User-Agent: LotGD version check'];

        if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
            $context = stream_context_create(['http' => [
                'timeout' => self::TIMEOUT,
                'header' => implode("\r\n", $headers) . "\r\n",
            ]]);
            // file_get_contents() reports an unreachable host as a warning;
            // the null answer below is how that failure is handled.
            set_error_handler(static fn (): bool => true);
            try {
                $body = file_get_contents($url, false, $context);
            } finally {
                restore_error_handler();
            }

            return is_string($body) && $body !== '' ? $body : null;
        }

        if (! function_exists('curl_init')) {
            return null;
        }
        $handle = curl_init($url);
        if ($handle === false) {
            return null;
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_FAILONERROR => true,
        ]);
        $body = curl_exec($handle);
        curl_close($handle);

        return is_string($body) && $body !== '' ? $body : null;
    }
}
