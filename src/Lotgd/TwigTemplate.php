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
        self::$templateDir = __DIR__ . '/../../templates_twig/' . $templateName;
        $loader = new FilesystemLoader(self::$templateDir);
        self::$env = new Environment($loader);
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
