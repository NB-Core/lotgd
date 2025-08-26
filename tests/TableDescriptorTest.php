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
        Database::$full_columns_rows = [];
        Database::$table_status_rows = [['Collation' => 'utf8mb4_unicode_ci']];
        Database::$collation_rows = [];
    }

    public function testDefaultZeroIsDetected(): void
    {
        Database::$full_columns_rows = [
            [
                'Field' => 'noaddskillpoints',
                'Type' => 'tinyint unsigned',
                'Null' => 'NO',
                'Default' => '0',
                'Extra' => '',
                'Collation' => null,
            ],
        ];
        $descriptor = TableDescriptor::tableCreateDescriptor('dummy');
        $this->assertSame('0', $descriptor['noaddskillpoints']['default']);
    }

    public function testDefaultNullIsDetected(): void
    {
        Database::$full_columns_rows = [
            [
                'Field' => 'somecolumn',
                'Type' => 'int',
                'Null' => 'YES',
                'Default' => 'NULL',
                'Extra' => '',
                'Collation' => null,
            ],
        ];
        $descriptor = TableDescriptor::tableCreateDescriptor('dummy');
        $expected = [
            'name' => 'somecolumn',
            'type' => 'int',
            'null' => true,
            'default' => null,
        ];

        $this->assertSame($expected, $descriptor['somecolumn']);
    }

    public function testCollationIsCaptured(): void
    {
        Database::$full_columns_rows = [
            [
                'Field' => 'body',
                'Type' => 'text',
                'Null' => 'NO',
                'Default' => null,
                'Extra' => '',
                'Collation' => 'latin1_swedish_ci',
            ],
        ];
        Database::$table_status_rows = [['Collation' => 'latin1_swedish_ci']];
        $descriptor = TableDescriptor::tableCreateDescriptor('dummy');
        $this->assertSame('latin1_swedish_ci', $descriptor['body']['collation']);
        $this->assertSame('latin1_swedish_ci', $descriptor['collation']);
    }

    public function testCollationWithoutUnderscoreDoesNotSetCharset(): void
    {
        Database::$full_columns_rows = [
            [
                'Field' => 'body',
                'Type' => 'text',
                'Null' => 'NO',
                'Default' => null,
                'Extra' => '',
                'Collation' => 'utf8mb4',
            ],
        ];
        $descriptor = TableDescriptor::tableCreateDescriptor('dummy');
        $this->assertArrayNotHasKey('charset', $descriptor['body']);
        $sql = TableDescriptor::descriptorCreateSql($descriptor['body']);
        $this->assertStringNotContainsString('CHARACTER SET', $sql);
    }

    public function testSynctableAltersTableCollation(): void
    {
        Database::$full_columns_rows = [
            [
                'Field' => 'body',
                'Type' => 'text',
                'Null' => 'NO',
                'Default' => null,
                'Extra' => '',
                'Collation' => 'latin1_swedish_ci',
            ],
        ];
        Database::$keys_rows = [];
        Database::$table_status_rows = [['Collation' => 'latin1_swedish_ci']];
        $descriptor = [
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'body' => ['name' => 'body', 'type' => 'text'],
        ];
        TableDescriptor::synctable('dummy', $descriptor);
        $this->assertStringContainsString(
            'CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            Database::$lastSql
        );
    }

    public function testSynctableNoChangeWhenSchemaMatches(): void
    {
        Database::$full_columns_rows = [
            [
                'Field' => 'body',
                'Type' => 'text',
                'Null' => 'NO',
                'Default' => null,
                'Extra' => '',
                'Collation' => 'latin1_swedish_ci',
            ],
        ];
        Database::$keys_rows = [];
        Database::$table_status_rows = [['Collation' => 'latin1_swedish_ci']];
        $descriptor = [
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'body' => ['name' => 'body', 'type' => 'text'],
        ];
        TableDescriptor::synctable('dummy', $descriptor);
        $this->assertStringContainsString(
            'CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            Database::$lastSql
        );

        Database::$full_columns_rows = [
            [
                'Field' => 'body',
                'Type' => 'text',
                'Null' => 'NO',
                'Default' => null,
                'Extra' => '',
                'Collation' => 'utf8mb4_unicode_ci',
            ],
        ];
        Database::$keys_rows = [];
        Database::$table_status_rows = [['Collation' => 'utf8mb4_unicode_ci']];
        Database::$lastSql = '';
        TableDescriptor::synctable('dummy', $descriptor);
        $this->assertStringNotContainsString('CHANGE', Database::$lastSql);
    }

    public function testSynctableNoChangeWithoutCollationInDescriptor(): void
    {
        Database::$full_columns_rows = [
            [
                'Field' => 'body',
                'Type' => 'text',
                'Null' => 'NO',
                'Default' => null,
                'Extra' => '',
                'Collation' => 'utf8mb4_unicode_ci',
            ],
        ];
        Database::$keys_rows = [];
        Database::$table_status_rows = [['Collation' => 'utf8mb4_unicode_ci']];
        Database::$lastSql = '';
        $descriptor = [
            'body' => ['name' => 'body', 'type' => 'text'],
        ];
        TableDescriptor::synctable('dummy', $descriptor);
        $this->assertStringNotContainsString('CHANGE', Database::$lastSql);
    }

    public function testSynctableDetectsColumnCollationMismatch(): void
    {
        Database::$full_columns_rows = [
            [
                'Field' => 'body',
                'Type' => 'text',
                'Null' => 'NO',
                'Default' => null,
                'Extra' => '',
                'Collation' => 'latin1_swedish_ci',
            ],
        ];
        Database::$keys_rows = [];
        Database::$table_status_rows = [['Collation' => 'utf8mb4_unicode_ci']];
        Database::$lastSql = '';
        $descriptor = [
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'body' => ['name' => 'body', 'type' => 'text'],
        ];
        TableDescriptor::synctable('dummy', $descriptor);
        $this->assertStringContainsString(
            'CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            Database::$lastSql
        );
    }

    public function testSynctableDerivesCollationFromCharset(): void
    {
        Database::$full_columns_rows = [
            [
                'Field' => 'body',
                'Type' => 'text',
                'Null' => 'NO',
                'Default' => null,
                'Extra' => '',
                'Collation' => 'utf8mb4_unicode_ci',
            ],
        ];
        Database::$keys_rows = [];
        Database::$table_status_rows = [['Collation' => 'utf8mb4_unicode_ci']];
        Database::$collation_rows = [[], [['Collation' => 'latin1_swedish_ci']]];
        $descriptor = [
            'charset' => 'latin1',
            'body' => ['name' => 'body', 'type' => 'text'],
        ];
        TableDescriptor::synctable('dummy', $descriptor);
        $this->assertStringContainsString(
            'CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci',
            Database::$lastSql
        );
    }
}
