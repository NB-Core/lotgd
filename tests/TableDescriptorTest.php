<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\MySQL\TableDescriptor;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

final class TableDescriptorTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists(Database::class);
        Database::$describe_rows = [];
        Database::$keys_rows = [];
    }

    public function testDefaultZeroIsDetected(): void
    {
        Database::$describe_rows = [
            [
                'Field' => 'noaddskillpoints',
                'Type' => 'tinyint unsigned',
                'Null' => 'NO',
                'Key' => '',
                'Default' => '0',
                'Extra' => '',
            ],
        ];
        $descriptor = TableDescriptor::tableCreateDescriptor('dummy');
        $this->assertSame('0', $descriptor['noaddskillpoints']['default']);
    }

    public function testDefaultNullIsDetected(): void
    {
        Database::$describe_rows = [
            [
                'Field' => 'somecolumn',
                'Type' => 'int',
                'Null' => 'YES',
                'Key' => '',
                'Default' => 'NULL',
                'Extra' => '',
            ],
        ];
        $descriptor = TableDescriptor::tableCreateDescriptor('dummy');
        $expected = [
            'name' => 'somecolumn',
            'type' => 'int',
            'default' => null,
        ];

        $this->assertSame($expected, $descriptor['somecolumn']);
    }
}
