<?php

declare(strict_types=1);

namespace Lotgd\Modules;

/**
 * Centralized storage for module-related state.
 *
 * Legacy globals such as $module_prefs, $module_settings, $currenthook and
 * $mostrecentmodule have been encapsulated here. Use the provided accessors to
 * read or modify these values instead of relying on global variables.
 */
class ModuleManager
{
    /** @var array<string, array<int|string, array<string, mixed>>> */
    private static array $prefs = [];

    /** @var array<string, array<string, mixed>> */
    private static array $settings = [];

    private static string $currentHook = '';

    private static string $recentModule = '';

    /**
     * Obtain a reference to the module prefs array.
     */
    public static function &prefs(): array
    {
        return self::$prefs;
    }

    /**
     * Replace the entire module prefs array.
     */
    public static function setPrefs(array $prefs): void
    {
        self::$prefs = $prefs;
    }

    /**
     * Obtain a reference to the module settings array.
     */
    public static function &settings(): array
    {
        return self::$settings;
    }

    /**
     * Replace the entire module settings array.
     */
    public static function setSettings(array $settings): void
    {
        self::$settings = $settings;
    }

    public static function getCurrentHook(): string
    {
        return self::$currentHook;
    }

    public static function setCurrentHook(string $hook): void
    {
        self::$currentHook = $hook;
    }

    public static function getMostRecentModule(): string
    {
        return self::$recentModule;
    }

    public static function setMostRecentModule(string $module): void
    {
        self::$recentModule = $module;
    }
}
