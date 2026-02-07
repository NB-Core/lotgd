<?php

/**
 * StorageManager.php
 *
 * File storage manager.
 *
 * @package jaxon-storage
 * @author Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @copyright 2025 Thierry Feuzeu <thierry.feuzeu@gmail.com>
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/jaxon-php/jaxon-core
 */

namespace Jaxon\Storage;

use Jaxon\Config\Config;
use Jaxon\Utils\Translation\Translator;
use Lagdo\Facades\Logger;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Closure;

use function count;
use function dirname;
use function is_a;
use function is_array;
use function is_string;

class StorageManager
{
    /**
     * @var array<string, Closure>
     */
    protected $aAdapters = [];

    /**
     * @var array
     */
    private $aCurrentAdapter = [];

    /**
     * @var Config|null
     */
    protected Config|null $xConfig = null;

    /**
     * @var Closure|null
     */
    protected Closure|null $xConfigGetter = null;

    /**
     * The constructor
     *
     * @param Config|Closure|null $xConfig
     * @param Translator|null $xTranslator
     */
    public function __construct(Config|Closure|null $xConfig = null,
        protected Translator|null $xTranslator = null)
    {
        $this->registerDefaults();

        if($xConfig !== null)
        {
            $this->setConfig($xConfig);
        }
        if($xTranslator !== null)
        {
            $this->loadTranslations($xTranslator);
        }
    }

    /**
     * @param Config|Closure $xConfig
     *
     * @return self
     */
    public function setConfig(Config|Closure $xConfig): self
    {
        $this->xConfig = null;
        $this->xConfigGetter = null;
        is_a($xConfig, Config::class) ?
            $this->xConfig = $xConfig : $this->xConfigGetter = $xConfig;

        return $this;
    }

    /**
     * @return void
     */
    private function loadTranslations(Translator $xTranslator): void
    {
        // Translation directory
        $sTranslationDir = dirname(__DIR__) . '/translations';
        // Load the storage translations
        $xTranslator->loadTranslations("$sTranslationDir/en/storage.php", 'en');
        $xTranslator->loadTranslations("$sTranslationDir/fr/storage.php", 'fr');
        $xTranslator->loadTranslations("$sTranslationDir/es/storage.php", 'es');
    }

    /**
     * Get a translator with the translations loaded.
     *
     * @return Translator
     */
    public function translator(): Translator
    {
        if($this->xTranslator !== null)
        {
            return $this->xTranslator;
        }

        $this->xTranslator = new Translator();
        $this->loadTranslations($this->xTranslator);

        return $this->xTranslator;
    }

    /**
     * @param string $sAdapter
     * @param Closure|string $xFactory
     *
     * @return void
     */
    public function register(string $sAdapter, Closure|string $xFactory): void
    {
        if(isset($this->aAdapters[$sAdapter]))
        {
            return;
        }

        if(is_string($xFactory))
        {
            // The adapter is an alias.
            if(!isset($this->aAdapters[$xFactory]))
            {
                Logger::error("Jaxon Storage: adapter '{$xFactory}' not configured.");
                throw new StorageException($this->translator()->trans('errors.storage.adapter'));
            }
            $xFactory = $this->aAdapters[$xFactory];
        }

        $this->aAdapters[$sAdapter] = $xFactory;
    }

    /**
     * Register the file storage adapters
     *
     * @return void
     */
    private function registerDefaults(): void
    {
        // Local file system adapter
        $this->register('local', fn(string $sRootDir, array $aOptions) =>
            new LocalFilesystemAdapter($sRootDir, ...$aOptions));
    }

    /**
     * @param string $sAdapter
     * @param array $aAdapterOptions
     *
     * @return self
     */
    public function adapter(string $sAdapter, array $aAdapterOptions = []): self
    {
        $this->aCurrentAdapter = [
            'adapter' => $sAdapter,
            'options' => $aAdapterOptions,
        ];
        return $this;
    }

    /**
     * @param string $sRootDir
     * @param array $aOptions
     *
     * @return Filesystem
     * @throws StorageException
     */
    public function make(string $sRootDir, array $aOptions = []): Filesystem
    {
        $sAdapter = $this->aCurrentAdapter['adapter'] ?? '';
        if(!isset($this->aAdapters[$sAdapter]))
        {
            Logger::error("Jaxon Storage: adapter '$sAdapter' not configured.");
            throw new StorageException($this->translator()->trans('errors.storage.adapter'));
        }

        // Make the adapter.
        $xAdapter = $this->aAdapters[$sAdapter]($sRootDir, $this->aCurrentAdapter['options']);
        // Reset the current adapter.
        $this->aCurrentAdapter = [];

        return new Filesystem($xAdapter, ...$aOptions);
    }

    /**
     * @throws StorageException
     * @return Config
     */
    private function config(): Config
    {
        if($this->xConfig !== null)
        {
            return $this->xConfig;
        }
        if($this->xConfigGetter !== null)
        {
            return $this->xConfig = ($this->xConfigGetter)();
        }

        Logger::error("Jaxon Storage: No config getter set.");
        throw new StorageException($this->translator()->trans('errors.storage.getter'));
    }

    /**
     * @param string $sAdapter
     *
     * @return self
     */
    private function setCurrentAdapter(string $sAdapter): self
    {
        $xConfig = $this->config();

        $aOptions = $xConfig->getOption("adapters.$sAdapter", []);
        if(!is_array($aOptions))
        {
            Logger::error("Jaxon Storage: incorrect values in 'adapters.$sAdapter' options.");
            throw new StorageException($this->translator()->trans('errors.storage.options'));
        }

        if(count($aOptions) === 2 &&
            is_string($aOptions['alias'] ?? null) &&
            is_array($aOptions['options'] ?? null))
        {
            $this->register($sAdapter, $aOptions['alias']);
            $aOptions = $aOptions['options'];
        }

        return $this->adapter($sAdapter, $aOptions);
    }

    /**
     * @param string $sOptionName
     *
     * @return Filesystem
     * @throws StorageException
     */
    public function get(string $sOptionName): Filesystem
    {
        $xConfig = $this->config();

        $sAdapter = $xConfig->getOption("stores.$sOptionName.adapter");
        $sRootDir = $xConfig->getOption("stores.$sOptionName.dir");
        $aOptions = $xConfig->getOption("stores.$sOptionName.options", []);
        if(!is_string($sAdapter) || !is_string($sRootDir) || !is_array($aOptions))
        {
            Logger::error("Jaxon Storage: incorrect values in 'stores.$sOptionName' options.");
            throw new StorageException($this->translator()->trans('errors.storage.options'));
        }

        return $this->setCurrentAdapter($sAdapter)->make($sRootDir, $aOptions);
    }
}
