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

    public static function init(string $templateName, ?string $datacachePath = null): void
    {
        self::$templateDir = __DIR__ . '/../../templates_twig/' . $templateName;
        $loader = new FilesystemLoader(self::$templateDir);

        $options = ['auto_reload' => true];

        if ($datacachePath !== null && $datacachePath !== '') {
            self::$cacheDir = $datacachePath;
            $cacheDir = rtrim($datacachePath, '/\\') . '/' . self::CACHE_SUBDIR;
            if ((is_dir($cacheDir) || mkdir($cacheDir, 0755, true)) && is_writable($cacheDir)) {
                $options['cache'] = $cacheDir;
            }
        }

        self::$env = new Environment($loader, $options);
        if (!defined('TEMPLATE_IS_TWIG')) {
            define('TEMPLATE_IS_TWIG', true);
        }
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
        return defined('TEMPLATE_IS_TWIG') && TEMPLATE_IS_TWIG;
    }

    public static function getPath(): string
    {
        return str_replace(__DIR__ . '/../../', '', self::$templateDir);
    }
}
