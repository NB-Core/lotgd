<?php

namespace Jaxon\Utils\Tests;

use Jaxon\Utils\Config\Config;
use Jaxon\Utils\Config\ConfigReader;
use Jaxon\Utils\Config\Exception\DataDepth;
use Jaxon\Utils\Config\Exception\FileAccess;
use Jaxon\Utils\Config\Exception\FileContent;
use Jaxon\Utils\Config\Exception\FileExtension;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    /**
     * @var ConfigReader
     */
    protected $xConfigReader;

    /**
     * @var Config
     */
    protected $xConfig;

    protected function setUp(): void
    {
        $this->xConfigReader = new ConfigReader();
        $this->xConfig = new Config(['core' => ['language' => 'en']]);
        $this->xConfig->setOption('core.prefix.function', 'jaxon_');
    }

    public function testPhpConfigReader()
    {
        $this->xConfigReader->load($this->xConfig, __DIR__ . '/config/config.php', 'jaxon');
        $this->assertEquals('en', $this->xConfig->getOption('core.language'));
        $this->assertEquals('jaxon_', $this->xConfig->getOption('core.prefix.function'));
        $this->assertFalse($this->xConfig->getOption('core.debug.on'));
        $this->assertFalse($this->xConfig->hasOption('core.debug.off'));
    }

    public function testYamlConfigReader()
    {
        $this->xConfigReader->load($this->xConfig, __DIR__ . '/config/config.yaml', 'jaxon');
        $this->assertEquals('en', $this->xConfig->getOption('core.language'));
        $this->assertEquals('jaxon_', $this->xConfig->getOption('core.prefix.function'));
        $this->assertFalse($this->xConfig->getOption('core.debug.on'));
        $this->assertFalse($this->xConfig->hasOption('core.debug.off'));
    }

    public function testJsonConfigReader()
    {
        $this->xConfigReader->load($this->xConfig, __DIR__ . '/config/config.json', 'jaxon');
        $this->assertEquals('en', $this->xConfig->getOption('core.language'));
        $this->assertEquals('jaxon_', $this->xConfig->getOption('core.prefix.function'));
        $this->assertFalse($this->xConfig->getOption('core.debug.on'));
        $this->assertFalse($this->xConfig->hasOption('core.debug.off'));
    }

    public function testReadOptionNames()
    {
        $this->xConfigReader->load($this->xConfig, __DIR__ . '/config/config.json');
        $aOptionNames = $this->xConfig->getOptionNames('jaxon.core');
        $this->assertIsArray($aOptionNames);
        $this->assertCount(3, $aOptionNames);
    }

    public function testSimpleArrayValues()
    {
        $this->xConfigReader->load($this->xConfig, __DIR__ . '/config/array.php');
        $aOption = $this->xConfig->getOption('core.array');
        $this->assertIsArray($aOption);
        $this->assertCount(4, $aOption);
        $this->assertEmpty($this->xConfig->getOptionNames('jaxon.array'));
    }

    public function testSetOptionsError()
    {
        // The key is missing
        $this->assertFalse($this->xConfig->setOptions(['core' => []], 'core.missing'));
        // The key is not an array
        $this->assertFalse($this->xConfig->setOptions(['core' => ['string' => 'String']], 'core.string'));
        $this->assertFalse($this->xConfig->hasOption('core.string'));
    }

    public function testSetOptionsDataDepth()
    {
        $this->expectException(DataDepth::class);
        $this->xConfig->setOptions([
            'core' => [
                'one' => [
                    'two' => [
                        'three' => [
                            'four' => [
                                'five' => [
                                    'six' => [
                                        'seven' => [
                                            'eight' => [
                                                'nine' => [
                                                    'ten' => [
                                                        'param' => 'Value',
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testEmptyFileName()
    {
        $this->assertEmpty($this->xConfigReader->read(''));
    }

    public function testMissingPhpFile()
    {
        $this->expectException(FileAccess::class);
        $this->xConfigReader->load($this->xConfig, __DIR__ . '/config/missing.php');
    }

    public function testMissingJsonFile()
    {
        $this->expectException(FileAccess::class);
        $this->xConfigReader->load($this->xConfig, __DIR__ . '/config/missing.json');
    }

    public function testMissingYamlFile()
    {
        $this->expectException(FileAccess::class);
        $this->xConfigReader->load($this->xConfig, __DIR__ . '/config/missing.yml');
    }

    public function testErrorInPhpFile()
    {
        $this->expectException(FileContent::class);
        $this->xConfigReader->load($this->xConfig, __DIR__ . '/config/error.php');
    }

    public function testErrorInJsonFile()
    {
        $this->expectException(FileContent::class);
        $this->xConfigReader->load($this->xConfig, __DIR__ . '/config/error.json');
    }

    public function testErrorInYamlFile()
    {
        $this->expectException(FileContent::class);
        $this->xConfigReader->load($this->xConfig, __DIR__ . '/config/error.yml');
    }

    public function testUnsupportedFileExtension()
    {
        $this->expectException(FileExtension::class);
        $this->xConfigReader->load($this->xConfig, __DIR__ . '/config/config.ini');
    }
}
