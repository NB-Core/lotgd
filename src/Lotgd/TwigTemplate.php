<?php
declare(strict_types=1);

namespace Lotgd;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class TwigTemplate extends Template
{
    private static ?Environment $env = null;
    private static string $templateDir = '';

    public static function init(string $templateName): void
    {
        global $settings;

        self::$templateDir = __DIR__ . '/../../templates_twig/' . $templateName;
        $loader = new FilesystemLoader(self::$templateDir);

        $baseDir = ($settings instanceof Settings)
            ? $settings->getSetting('datacachepath', '/tmp')
            : '/tmp';
        $cacheDir = rtrim($baseDir, '/\\') . '/twig';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }

        self::$env = new Environment($loader, [
            'cache' => $cacheDir,
            'auto_reload' => true,
        ]);
        define('TEMPLATE_IS_TWIG', true);
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
