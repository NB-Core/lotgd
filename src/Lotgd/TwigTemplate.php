<?php

declare(strict_types=1);

namespace Lotgd;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class TwigTemplate extends Template
{
    private static ?Environment $env = null;
    private static string $templateDir = '';
    /** Path used for caching compiled templates */
    private static string $cacheDir = '/tmp';
    /** Subdirectory within datacachepath for Twig cache */
    private const CACHE_SUBDIR = 'twig';
    /** Checks if Twig is enabled or not */
    private static bool $isTwigActive = false;

    public static function init(string $templateName, ?string $datacachePath = null): void
    {
        self::$templateDir = __DIR__ . '/../../templates_twig/' . $templateName;
        $loader = new FilesystemLoader(self::$templateDir);

        $options = ['auto_reload' => true];

        if ($datacachePath !== null && $datacachePath !== '') {
            self::$cacheDir = $datacachePath;
            $cacheDir = rtrim($datacachePath, '/\\') . '/' . self::CACHE_SUBDIR;
            if ((is_dir($cacheDir) || @mkdir($cacheDir, 0755, true)) && is_writable($cacheDir)) {
                $options['cache'] = $cacheDir;
            } else {
                // Leave cache disabled; optionally, could log in future
            }
        }

        self::$env = new Environment($loader, $options);

    // Add a twig filter for version busting (to enable cached resources based on file modify date as "buster")
        self::$env->addFilter(new \Twig\TwigFilter('ver', function ($path) {
            $full = $_SERVER['DOCUMENT_ROOT'] . $path;
            return $path . '?v=' . (file_exists($full) ? filemtime($full) : time());
        }));
        self::$isTwigActive = true;
    }


    public static function deactivate(): void
    {
        self::$env = null;
        self::$isTwigActive = false;
    }

    public static function render(string $view, array $context = []): string
    {
        if (self::$env === null) {
            throw new \RuntimeException('Twig environment is not initialized. Call init() before rendering.');
        }

        return self::$env->render($view, $context);
    }

    public static function isActive(): bool
    {
        return self::$isTwigActive;
    }

    public static function getPath(): string
    {
        return str_replace(__DIR__ . '/../../', '', self::$templateDir);
    }
}
