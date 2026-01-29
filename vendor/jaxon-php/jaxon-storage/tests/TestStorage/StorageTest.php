<?php

namespace Jaxon\Storage\Tests\TestStorage;

use Jaxon\Config\Config;
use Jaxon\Config\ConfigSetter;
use Jaxon\Storage\StorageException;
use Jaxon\Storage\StorageManager;
use Lagdo\Facades\ContainerWrapper;
use League\Flysystem\CorruptedPathDetected;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function dirname;
use function file_get_contents;

class StorageTest extends TestCase
{
    /**
     * @var StorageManager
     */
    protected $xManager;

    /**
     * @var ConfigSetter
     */
    protected $xConfigSetter;

    /**
     * @var string
     */
    protected $sInputDir;

    public static function setUpBeforeClass(): void
    {
        ContainerWrapper::setContainer(new class implements ContainerInterface {
            private $xLogger = null;

            public function has(string $class): bool
            {
                return $class === LoggerInterface::class;
            }

            public function get(string $class): mixed
            {
                return $class !== LoggerInterface::class ? null :
                    ($this->xLogger ??= new NullLogger());
            }
        }, false);
    }

    public function setUp(): void
    {
        $this->sInputDir = dirname(__DIR__) . '/files';
        $this->xManager = new StorageManager();
        $this->xConfigSetter = new ConfigSetter();
    }

    private function setConfigOptions(array $aOptions, string $sPrefix = '')
    {
        $this->xManager->setConfig($this->xConfigSetter->newConfig($aOptions, $sPrefix));
    }

    /**
     * @throws StorageException
     */
    public function testStorageReader()
    {
        $xInputStorage = $this->xManager->adapter('local')->make($this->sInputDir);
        $sInputContent = $xInputStorage->read('hello.txt');

        $this->assertEquals(file_get_contents("{$this->sInputDir}/hello.txt"), $sInputContent);
    }

    public function testAdapterAndDirOptions()
    {
        $this->setConfigOptions([
            'adapters' => [
                'files' => [
                    'alias' => 'local',
                    'options' => [
                        'lazyRootCreation' => false, // Create dirs if they don't exist.
                    ],
                ],
            ],
            'stores' => [
                'files' => [
                    'adapter' => 'files',
                    'dir' => $this->sInputDir,
                    'options' => [
                        'config' => [
                            'public_url' => '/static/files',
                        ],
                    ],
                ],
            ],
        ]);

        $xInputStorage = $this->xManager->get('files');
        $sInputContent = $xInputStorage->read('hello.txt');

        $this->assertEquals(file_get_contents("{$this->sInputDir}/hello.txt"), $sInputContent);
        $this->assertEquals('/static/files/hello.txt', $xInputStorage->publicUrl('hello.txt'));
    }

    public function testWriteError()
    {
        $this->setConfigOptions([
            'adapters' => [
                'files' => [
                    'alias' => 'local',
                    'options' => [
                        'lazyRootCreation' => true, // Don't create dirs if they don't exist.
                    ],
                ],
            ],
            'stores' => [
                'files' => [
                    'adapter' => 'files',
                    'dir' => dirname(__DIR__ . '/files'),
                    'options' => [
                        'config' => [
                            'public_url' => '/static/files',
                        ],
                    ],
                ],
            ],
        ]);

        $this->expectException(CorruptedPathDetected::class);
        $xInputStorage = $this->xManager->get('files');
        $sInputContent = $xInputStorage->read("\0hello.txt");
    }

    public function testStorageWriter()
    {
        $this->xManager->register('memory', fn() => new InMemoryFilesystemAdapter());
        $this->setConfigOptions([
            'adapter' => 'memory',
            'dir' => 'files',
            'options' => [],
        ], 'stores.memory');

        $xInputStorage = $this->xManager->adapter('local')->make($this->sInputDir);
        $sInputContent = $xInputStorage->read('hello.txt');

        $xOutputStorage = $this->xManager->get('memory');
        $xOutputStorage->write('hello.txt', $sInputContent);
        $sOutputContent = $xOutputStorage->read('hello.txt');

        $this->assertEquals($sOutputContent, $sInputContent);
    }

    public function testErrorUnknownAdapter()
    {
        $this->expectException(StorageException::class);
        $xUnknownStorage = $this->xManager->adapter('unknown')->make($this->sInputDir);
    }

    public function testErrorUnknownConfig()
    {
        $this->expectException(StorageException::class);
        $xUnknownStorage = $this->xManager->get('unknown');
    }

    public function testErrorIncorrectConfigAdapter()
    {
        $this->setConfigOptions([
            'adapter' => null,
            'dir' => 'files',
            'options' => [],
        ], 'stores.custom');

        $this->expectException(StorageException::class);
        $xErrorStorage = $this->xManager->get('custom');
    }

    public function testErrorIncorrectConfigDir()
    {
        $this->setConfigOptions([
            'adapter' => 'memory',
            'dir' => null,
            'options' => [],
        ], 'stores.custom');

        $this->expectException(StorageException::class);
        $xErrorStorage = $this->xManager->get('custom');
    }

    public function testErrorIncorrectConfigOptions()
    {
        $this->setConfigOptions([
            'adapter' => 'memory',
            'dir' => 'files',
            'options' => null,
        ], 'stores.custom');

        $this->expectException(StorageException::class);
        $xErrorStorage = $this->xManager->get('custom');
    }
}
