<?php

declare(strict_types=1);

namespace Lotgd;

/**
 * Central registry for third-party frontend assets.
 *
 * Reads assets/vendor/manifest.json once and provides versioned
 * paths that can be used in both Twig templates and PHP pages.
 */
class AssetManifest
{
    private static ?array $manifest = null;
    private static string $basePath = '';

    private static function load(): void
    {
        if (self::$manifest !== null) {
            return;
        }

        self::$basePath = rtrim(dirname(__DIR__, 2), '/\\');
        $file = self::$basePath . '/assets/vendor/manifest.json';

        if (!file_exists($file)) {
            self::$manifest = [];
            return;
        }

        $data = json_decode(file_get_contents($file), true);
        self::$manifest = is_array($data) ? $data : [];
    }

    /**
     * Get the versioned URL for a given asset.
     *
     * @param string $library e.g. "bootstrap", "jquery", "datatables"
     * @param string $type    "css" or "js"
     */
    public static function url(string $library, string $type): string
    {
        self::load();

        if (!isset(self::$manifest[$library][$type])) {
            return '';
        }

        $path = '/' . ltrim(self::$manifest[$library][$type], '/');
        $fullPath = self::$basePath . '/' . $assetPath;
        $buster = file_exists($fullPath) ? filemtime($fullPath) : (self::$manifest[$library]['version'] ?? '0');

        return self::assetBasePath() . $assetPath . '?v=' . $buster;
    }

    private static function assetBasePath(): string
    {
        if (!Settings::hasInstance()) {
            return '';
        }

        $settings = Settings::getInstance();
        $serverUrl = $settings->getSetting('serverurl', '');
        if (!is_string($serverUrl) || $serverUrl === '') {
            return '';
        }

        $parsed = parse_url($serverUrl);
        if ($parsed === false) {
            return '';
        }

        $path = trim((string) ($parsed['path'] ?? ''), '/');
        if ($path === '') {
            return '';
        }

        return '/' . $path . '/';
    }

    /**
     * Get the declared version string for a library.
     */
    public static function version(string $library): string
    {
        self::load();

        return self::$manifest[$library]['version'] ?? '';
    }

    /**
     * Return all registered library names.
     *
     * @return string[]
     */
    public static function libraries(): array
    {
        self::load();

        return array_keys(self::$manifest);
    }
}
